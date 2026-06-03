# Extra Chill Roadie

Extra Chill platform integration for [Frontend Agent Chat](https://github.com/Automattic/frontend-agent-chat), powered by [Data Machine](https://github.com/Extra-Chill/data-machine) agents. Roadie gives the Extra Chill chat agent the ability to manage artist profiles, link pages, user profiles, and community forums — and, for team members, to file GitHub issues and ship sandboxed code changes — all through natural language chat.

## What It Does

Roadie is the bridge between Extra Chill's platform features and Data Machine's chat system. It registers **eight chat tools** via the `datamachine_tools` filter and composes a role-aware operating context (the `roadie` agent mode) into the AI prompt. The tool surface a caller actually sees depends on their **tier** (public / team / admin).

| Tool | Surface | Tier | Description |
|------|---------|------|-------------|
| `manage_artist_profile` | artist.extrachill.com | team+ | Create, list, get, and update artist profiles |
| `manage_link_page` | artist.extrachill.com | team+ | Full link-in-bio page management (links, socials, styles, settings) |
| `manage_user_profile` | extrachill.com (network) | team+ | View and update the community profile (bio, title, city, links) |
| `manage_community` | community.extrachill.com | team+ | Browse forums, create topics, post replies, manage notifications |
| `propose_code_change` | sandbox | team+ | Dispatch a sandboxed coding agent that produces a reviewable patch + preview |
| `apply_code_change` | host | team+ | Apply an approved sandbox artifact: commit, push, open a PR |
| `file_feature_request` | GitHub | team+ | File / look up GitHub issues against the right EC repo (repo auto-inferred) |
| `present_question` | chat UI | public | Render a multiple-choice question as clickable buttons |

The four cross-site management tools require authentication (`access_level: authenticated`) and auto-resolve the artist ID when the user has a single artist profile. The code-contribution and feature-request tools additionally require the `extrachill_propose_code` capability. `present_question` is the only `public`-access tool and is the only tool visible to public-tier callers.

## Role-Aware Tier Surface

Roadie resolves every caller to one of three tiers and tailors both the **visible tool set** and the **prompt guidance** accordingly.

| Tier | Who | Tool surface |
|------|-----|--------------|
| `public` | Logged-out / non-team callers, and system/pipeline runs (`calling_user_id <= 0`) | `present_question` only |
| `team` | `extra_chill_team` members (have the `access_roadie` cap) | All 8 tools |
| `admin` | `manage_options` users | All 8 tools, plus act-on-behalf-of another user via explicit `user_id` |

Tier resolution lives in `extrachill_roadie_user_tier( int $user_id )` (`inc/permissions.php`) — a single auditable capability→tier map (highest wins): `manage_options` → admin, `access_roadie` → team, otherwise public.

Two enforcement layers back this:

1. **Tool visibility** — `extrachill_roadie_filter_tools_by_tier()` hooks `datamachine_resolved_tools` and, for public-tier callers, `unset()`s the seven management tools returned by `extrachill_roadie_managed_tool_slugs()` (everything except `present_question`). This avoids offering the model dead options.
2. **Per-call gates** — independent of visibility: cross-site write capability checks, `assert_acting_user_allowed()` (admin-only act-on-behalf-of), and `current_user_can( 'extrachill_propose_code' )` on the code/issue tools.

## Architecture

Roadie follows the Extra Chill platform pattern: **business logic lives in domain plugins** (extrachill-users, extrachill-api, extrachill-artist-platform, etc.), and Roadie provides the AI-facing tool interface on top.

```
User (chat) → Data Machine → Roadie Tool → ec_cross_site_rest_request() → Subsite REST API → Ability
```

### ECRoadie_PlatformTool

The four cross-site management tools extend `ECRoadie_PlatformTool` (`inc/tools/class-ec-platform-tool.php`), which extends Data Machine's `BaseTool` and provides:

- **`rest_request( $method, $path, $args )`** — Cross-site REST calls via `ec_cross_site_rest_request()`. Pass `user_id` in `$args` to authenticate the request as that user; the underlying helper wraps `wp_set_current_user()` in a try/finally so context restores cleanly.
- **`get_blog_id()`** — Site key resolution for safe data reads via `switch_to_blog()`.
- **`get_calling_user_id( $parameters )`** — Reads `calling_user_id` from the merged tool parameters. Data Machine's loop merges the invocation payload into `$parameters` before calling `handle_tool_call()`, so the human caller is always available as `$parameters['calling_user_id']`.
- **`resolve_acting_user_id( $parameters )`** — Returns the user the tool should act as. Priority: explicit `user_id` input → `calling_user_id` → `get_current_user_id()`.
- **`assert_acting_user_allowed( $acting, $parameters )`** — Returns a clean permission-denied response (or `null` when allowed). Non-admin callers attempting to act on another user are refused.

Each tool declares a `$site_key` (e.g. `'artist'`, `'community'`, `'main'`) and a `$tool_slug` for error context. The code-contribution, feature-request, and present-question tools extend `BaseTool` directly (they don't make cross-site REST calls).

### Agent Mode

Roadie registers a `roadie` execution mode with Data Machine's `AgentModeRegistry`. The mode is the operating context for the EC platform tool surface — it composes EC-specific guidance (network topology, tool selection, identity contract, editorial voice, operating posture) into the AI prompt.

```php
// inc/agent-mode/register.php
add_action( 'datamachine_agent_modes', function () {
    \DataMachine\Engine\AI\AgentModeRegistry::register( 'roadie', 45, array(
        'label'       => __( 'Extra Chill Platform', 'extrachill-roadie' ),
        'description' => __( 'Artist profiles, link pages, user profiles, community forum, and personal user-scoped operations on the EC multisite network.', 'extrachill-roadie' ),
    ) );
} );

add_filter( 'datamachine_agent_mode_roadie', 'extrachill_roadie_mode_guidance', 10, 2 );
```

The mode is **agent-agnostic** — any agent (extra-chill-bot, roadie, or a custom one) can run in this mode and inherit the same platform guidance. Priority `45` places it after `data-machine-editor` (`40`) so editor mode wins when both are active in the same invocation (rare).

The guidance is **role-aware**: `extrachill_roadie_mode_guidance()` resolves the caller's `calling_user_id` → tier and delegates to `extrachill_roadie_compose_guidance( $tier, $uid )`, which assembles tier-specific sections (intro, tool guidance, identity contract, operating posture) on top of shared sections (network topology, editorial voice). The composed prompt is titled `# Extra Chill Platform Context`.

### Calling-User Identity Contract

Every Roadie tool sees the human caller via `$parameters['calling_user_id']`. The chat orchestrator sets this from the chat session caller; pipeline runs and system tasks set it to `0`.

The contract for tool wiring:

1. Resolve the acting user once at the top of `handle_tool_call()`:
   ```php
   $acting_user_id = $this->resolve_acting_user_id( $parameters );
   $denied = $this->assert_acting_user_allowed( $acting_user_id, $parameters );
   if ( null !== $denied ) {
       return $denied;
   }
   ```
2. Pass `'user_id' => $acting_user_id` into every `rest_request()` so the cross-site helper switches context correctly.
3. For tools that read user-scoped data directly (e.g. `get_user_meta`), use `$acting_user_id` instead of `get_current_user_id()`.
4. Public read actions (e.g. `list_forums`) can skip the resolve/assert pair entirely.

Admin agents may target another user by passing an explicit `user_id` input; non-admins attempting that get a clean permission-denied response.

### Permissions

Roadie bridges Extra Chill team membership to Data Machine's agent access system:

- Hooks `datamachine_can_access_agent` to grant EC team members access to the Roadie agent.
- Uses the network-wide `access_roadie` capability (granted to the `extra_chill_team` role) as the source of truth.
- Grants `extrachill_propose_code` to administrators, editors, and `extra_chill_team` by default (filterable via `extrachill_roadie_propose_code_roles`).
- Bridges the events read/write capabilities (`datamachine_events_read_capability` / `datamachine_events_write_capability`).
- Agent policy (name, status, redirect URIs) is synced on `plugins_loaded` (priority 20).

### Bridge Onboarding

For external chat clients (like Beeper/Matrix via [mautrix-data-machine](https://github.com/Extra-Chill/mautrix-data-machine)), Roadie provides onboarding configuration via the `datamachine_bridge_onboarding_config` filter:

- Welcome message, description, login instructions.
- Room name and topic for the chat bridge.
- Consumer-facing capability list (artist profile, link page, user profile, community).
- Avatar URL (filterable via `extrachill_roadie_bridge_avatar_url`).

The onboarding capability list is intentionally end-user-facing — the team-gated code and issue tools aren't advertised there because a public bridge user would never see them.

## Chat Tool Reference

### manage_artist_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list` | List the current user's artist profiles | — |
| `get` | Get artist profile details | `artist_id` (auto-resolved) |
| `create` | Create a new artist profile | `name` |
| `update` | Update an existing artist profile | `artist_id` (auto-resolved), plus fields to update |

Optional fields for create/update: `bio`, `genre`, `local_city`, `profile_image_id` (0 to remove), `header_image_id` (0 to remove).

### manage_link_page

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the full link page | `artist_id` (auto-resolved) |
| `add_link` | Add a single link | `url`, `text`, optional `section` |
| `remove_link` | Remove a link | `url` or `link_id` |
| `save_links` | Replace all link sections | `links` array |
| `save_socials` | Replace social links | `socials` array |
| `save_styles` | Update CSS variables (keys must start `--link-page-`) | `css_vars` object |
| `save_settings` | Update settings | `settings` object |

Convenience actions (`add_link`, `remove_link`) handle the fetch-modify-save cycle internally so the AI doesn't need multi-step orchestration.

### manage_user_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the current user's profile | — |
| `update` | Update bio, title, or city | At least one of `custom_title`, `bio`, `local_city` |
| `update_links` | Replace profile links | `links` array |

Profile links support types: `website`, `facebook`, `instagram`, `twitter`, `youtube`, `tiktok`, `spotify`, `soundcloud`, `bandcamp`, `github`, `other`. These are the links on the user's community profile — distinct from artist link pages.

### manage_community

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list_forums` | Browse available forums (public read) | — |
| `list_topics` | List topics (optionally filtered by forum) | Optional `forum_id`, `page`, `per_page` |
| `get_topic` | Read a topic with replies | `topic_id` |
| `create_topic` | Post a new topic | `forum_id`, `title`, `content` |
| `create_reply` | Reply to a topic | `topic_id`, `content` |
| `get_notifications` | Check notifications | Optional `unread` |
| `mark_notifications_read` | Mark all as read | — |

The first three actions are public reads (no user context); the rest resolve and authorize the acting user.

### propose_code_change

Dispatches a sandboxed coding agent ([WP Codebox](https://github.com/chubes4/wp-codebox) Playground) that implements the described change against the subsite's stack and returns a reviewable patch artifact + live preview URL. **It does not push code or open a PR.**

| Param | Description |
|-------|-------------|
| `task_description` (required) | Plain-language description of the change |

Returns `status: pending-approval`, `artifact_id`, `preview_url`, `summary`, `changed_files`. Requires the `extrachill_propose_code` capability. See [docs/contribute-code.md](docs/contribute-code.md) for the full flow.

### apply_code_change

Applies a previously-approved artifact: creates a worktree per affected repo, applies the patch, commits with a conventional commit message, pushes, and opens a pull request.

| Param | Description |
|-------|-------------|
| `artifact_id` (required) | The `artifact_id` returned by `propose_code_change` |
| `commit_message_hint` (optional) | Hint for the commit subject |

Returns `pr_urls`. Only call after the user has explicitly approved the proposed change. Requires `extrachill_propose_code`.

### file_feature_request

Files or looks up GitHub issues against the appropriate Extra Chill repo. **`repo` is optional** — when omitted, it's auto-inferred from the current subsite (active subsite plugin slug, then theme slug) via the slug→repo registry. The response includes a `repo_inferred` flag so the model can confirm an inferred repo once rather than interrogating the user.

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `file_issue` | Create a new issue | `title`, `body` (repo optional/inferred) |
| `list_recent_issues` | Find existing open issues to dedupe against | — (repo optional/inferred) |
| `comment_on_issue` | Add a comment to an existing issue | `issue_number`, `body` (repo optional/inferred) |

Default labels (`roadie-submitted`, `feature-request`) are applied to filed issues; the repo allowlist and labels are filterable. The repo must be present in the registry; cross-org repos are rejected. Requires `extrachill_propose_code`.

### present_question

Renders a multiple-choice question as clickable buttons in the chat UI. Use when the answer is one of a small, well-defined set of options. Clicking a choice sends its `message` back as the user's next turn automatically.

| Param | Description |
|-------|-------------|
| `question` (required) | The question text |
| `choices` (required) | Array of `{ label, message, description? }` objects |
| `allow_freeform` (optional) | Signal the UI may also offer a free-text answer |

No capability gate, no side effects — `access_level: public`. This is the only tool offered to public-tier callers.

## Sandbox-Backed Code Contribution

`propose_code_change` + `apply_code_change` implement a human-gated, sandbox-backed code-contribution flow: team members describe a change in chat, a Playground sandbox implements it and returns a patch + preview, and on explicit approval Roadie applies the patch to a fresh worktree on the host, commits, pushes, and opens a PR. The sandbox never touches GitHub, never holds the GitHub token, and never reaches production.

The slug→repo registry (`inc/contribute-code/repo-map.php`, filter `extrachill_roadie_repo_map`) maps active components on a subsite to GitHub repos and is shared by both the code-contribution recipe builder and `file_feature_request`'s repo inference.

Full setup, security model, architecture diagram, and smoke test: **[docs/contribute-code.md](docs/contribute-code.md)**.

## Dependencies

- **[Data Machine](https://github.com/Extra-Chill/data-machine)** — Provides `BaseTool`, the `datamachine_tools` filter, the `AgentModeRegistry`, and the GitHub-issue abilities.
- **[Frontend Agent Chat](https://github.com/Automattic/frontend-agent-chat)** — The chat widget Roadie surfaces in; Roadie bridges its config + theme tokens.
- **[Extra Chill Multisite](https://github.com/Extra-Chill/extrachill-multisite)** — Provides `ec_cross_site_rest_request()` for internal cross-site HTTP.
- **[Extra Chill API](https://github.com/Extra-Chill/extrachill-api)** — REST endpoints and route affinity middleware.
- **[Extra Chill Users](https://github.com/Extra-Chill/extrachill-users)** — User profile abilities, team membership, and the `access_roadie` capability.
- **[WP Codebox](https://github.com/chubes4/wp-codebox)** — Playground sandboxes for `propose_code_change` (code contribution only).

## Requirements

- WordPress 6.9+
- PHP 7.4+
- Data Machine plugin (network-activated)
- Extra Chill Multisite plugin (network-activated)

## Filters

### Hooks Roadie provides

| Filter | Description |
|--------|-------------|
| `extrachill_roadie_repo_map` | Slug→repo registry (shared by code contribution + feature requests) |
| `extrachill_roadie_allowed_redirect_uris` | External redirect URIs allowed for bridge auth |
| `extrachill_roadie_bridge_avatar_url` | Override the Roadie avatar URL |
| `extrachill_roadie_propose_code_roles` | Roles granted `extrachill_propose_code` (default: administrator, editor, extra_chill_team) |
| `extrachill_roadie_subsite_context` | Detected subsite context (active theme + plugins) |
| `extrachill_roadie_excluded_plugin_slugs` | Platform plugins excluded from subsite detection |
| `extrachill_roadie_workspace_root` | DMC workspace root path (default `/var/lib/datamachine/workspace`) |
| `extrachill_roadie_recipe` | Built sandbox recipe |
| `extrachill_roadie_inherit_connectors` | Connector→metadata map for sandbox credential inheritance |
| `extrachill_roadie_preview_hold_seconds` | Sandbox preview TTL (default 900, max 3600) |
| `extrachill_roadie_inherit_connector_names` | Connector names passed to the sandbox (default `['openai']`) |
| `extrachill_roadie_propose_code_input` | Input to the `wp-codebox/run-agent-task` ability |
| `extrachill_roadie_artifacts_root` | wp-codebox artifacts root path |
| `extrachill_roadie_apply_commit_name` / `extrachill_roadie_apply_commit_email` | Commit author identity for apply-back |
| `extrachill_roadie_feature_request_allowed_repos` | Repo allowlist for `file_feature_request` |
| `extrachill_roadie_feature_request_default_labels` | Default labels for filed issues |
| `extrachill_roadie_feature_request_attribution_lines` | Issue/comment attribution footer |
| `extrachill_roadie_file_issue_input` / `_list_issues_input` / `_comment_issue_input` | Inputs to the GitHub-issue abilities |

### Hooks Roadie consumes

| Hook | Purpose |
|------|---------|
| `datamachine_tools` | Registers all eight chat tools (via BaseTool) |
| `datamachine_resolved_tools` | Filters the tool surface by caller tier |
| `datamachine_agent_modes` | Registers the `roadie` execution mode with AgentModeRegistry |
| `datamachine_agent_mode_roadie` | Provides the role-aware roadie mode guidance |
| `datamachine_can_access_agent` | Bridges EC team membership to Roadie agent access |
| `datamachine_events_read_capability` / `datamachine_events_write_capability` | Bridges events read/write caps |
| `datamachine_bridge_onboarding_config` | Provides Roadie-specific onboarding for bridge clients |
| `wp_codebox_resolve_inheritance` | Resolves sandbox credential inheritance (e.g. OpenAI key) |
| `frontend_agent_chat_config` / `frontend_agent_chat_chat_input` / `frontend_agent_chat_queue_input` | Bridges chat config + page context |
| `user_has_cap` | Grants `extrachill_propose_code` to configured roles |

## File Structure

```
extrachill-roadie.php              # Plugin bootstrap
inc/
  permissions.php                  # Tier resolution + team access bridge + agent policy sync
  frontend-chat.php                # Frontend Agent Chat config + page-context bridge
  onboarding.php                   # Bridge onboarding config (Beeper/Matrix)
  assets.php                       # Token-bridge CSS enqueue
  agent-mode/
    register.php                   # AgentModeRegistry registration + role-aware guidance
  tools/
    register.php                   # Tool registration + tier visibility filter
    class-ec-platform-tool.php     # Base for cross-site tools — REST helper, identity, permission guard
    class-manage-artist-profile.php
    class-manage-link-page.php
    class-manage-user-profile.php
    class-manage-community.php
    class-propose-code-change.php  # Dispatch sandbox coding agent
    class-apply-code-change.php    # Apply approved artifact → commit/push/PR
    class-file-feature-request.php # File/look up GitHub issues (repo auto-inferred)
    class-present-question.php     # Multiple-choice question cards
  contribute-code/
    subsite-context.php            # Detect active theme + subsite plugins for a blog
    repo-map.php                   # Slug→repo registry + lookup helpers
    recipe-builder.php             # Build the sandbox recipe (mounts, metadata)
    capabilities.php               # extrachill_propose_code capability grants
    inherit-resolver.php           # Bridge connector credentials into the sandbox
assets/
  css/token-bridge.css             # EC theme tokens → chat widget tokens
docs/
  CHANGELOG.md
  contribute-code.md               # Sandbox-backed code-contribution flow
homeboy.json                       # Homeboy deployment config
```

## Deployment

Managed via [Homeboy](https://github.com/Extra-Chill/homeboy):

```bash
homeboy build extrachill-roadie
homeboy deploy extrachill-roadie
```

## License

GPL v2 or later.
