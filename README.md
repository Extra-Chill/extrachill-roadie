# Extra Chill Roadie

Extra Chill platform chat tools for [Data Machine](https://github.com/Extra-Chill/data-machine) agents. Roadie gives AI agents the ability to manage artist profiles, link pages, user profiles, and community forums on the Extra Chill network — all through natural language chat.

## What It Does

Roadie is the bridge between Extra Chill's platform features and Data Machine's chat system. It registers four chat tools via the `datamachine_tools` filter, each wrapping cross-site REST calls to the appropriate subsite in the multisite network:

| Tool | Site | Description |
|------|------|-------------|
| `manage_artist_profile` | artist.extrachill.com | Create, list, get, and update artist profiles |
| `manage_link_page` | artist.extrachill.com | Full link-in-bio page management (links, socials, styles, settings) |
| `manage_user_profile` | extrachill.com | View and update community profile (bio, title, city, links) |
| `manage_community` | community.extrachill.com | Browse forums, create topics, post replies, manage notifications |

All tools require authentication (`access_level: authenticated`). Artist-scoped tools auto-resolve the artist ID when the user has a single artist profile.

## Architecture

Roadie follows the Extra Chill platform pattern: **business logic lives in domain plugins** (extrachill-users, extrachill-api, etc.), and Roadie provides the AI-facing tool interface on top.

```
User (chat) → Data Machine → Roadie Tool → ec_cross_site_rest_request() → Subsite REST API → Ability
```

### ECRoadie_PlatformTool

All tools extend `ECRoadie_PlatformTool`, which extends Data Machine's `BaseTool` and provides:

- **`rest_request( $method, $path, $args )`** — Cross-site REST calls via `ec_cross_site_rest_request()`. Pass `user_id` in `$args` to authenticate the request as that user; the underlying helper wraps `wp_set_current_user()` in a try/finally so context restores cleanly.
- **`get_blog_id()`** — Site key resolution for safe data reads via `switch_to_blog()`.
- **`get_calling_user_id( $parameters )`** — Reads `calling_user_id` from the merged tool parameters. Data Machine's loop merges the invocation payload into `$parameters` before calling `handle_tool_call()`, so the human caller is always available as `$parameters['calling_user_id']`.
- **`resolve_acting_user_id( $parameters )`** — Returns the user the tool should act as. Priority: explicit `user_id` input → `calling_user_id` → `get_current_user_id()`.
- **`assert_acting_user_allowed( $acting, $parameters )`** — Returns a clean permission-denied response (or `null` when allowed). Non-admin callers attempting to act on another user are refused.

Each tool declares a `$site_key` (e.g. `'artist'`, `'community'`, `'main'`) and a `$tool_slug` for error context.

### Agent Mode

Roadie registers a `roadie` execution mode with Data Machine's `AgentModeRegistry`. The mode is the operating context for the EC platform tool surface — it composes EC-specific guidance (network topology, tool selection, identity contract, editorial voice, operating posture) into the AI prompt at directive priority 22.

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

### Adding a New Roadie Tool

The pattern that lands a new EC platform tool:

1. Create `inc/tools/class-<name>.php` extending `ECRoadie_PlatformTool`.
2. Declare `$site_key` (which subsite the route belongs to) and `$tool_slug`.
3. In the constructor, call `$this->registerTool( '<slug>', [ $this, 'getToolDefinition' ], [ 'chat' ], [ 'access_level' => 'authenticated' ] )`.
4. Implement `getToolDefinition()` — include an optional `user_id` input parameter in the schema so admins can override.
5. Implement `handle_tool_call( array $parameters, array $tool_def = array() )` — start with the resolve + assert pair above.
6. Use `$this->rest_request()` for every cross-site call. Pass `user_id` in `$args`.
7. Add the `require_once` + `new ECRoadie_<Name>()` lines to `inc/tools/register.php`.

Mode-specific guidance for the new tool can be folded into the existing `extrachill_roadie_mode_guidance()` callback — there is no need for a separate mode unless the tool lives in a different operating context.

### Permissions

Roadie bridges Extra Chill team membership to Data Machine's agent access system:

- Hooks `datamachine_can_access_agent` to grant EC team members access to the Roadie agent
- Uses `ec_is_team_member()` as the source of truth
- Agent policy (name, status, redirect URIs) is synced on `plugins_loaded`

### Bridge Onboarding

For external chat clients (like Beeper/Matrix via [mautrix-data-machine](https://github.com/Extra-Chill/mautrix-data-machine)), Roadie provides onboarding configuration via the `datamachine_bridge_onboarding_config` filter:

- Welcome message, description, login instructions
- Room name and topic for the chat bridge
- Capability list (artist profile, link page, user profile, community)
- Avatar URL (filterable via `extrachill_roadie_bridge_avatar_url`)

## Chat Tool Reference

### manage_artist_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list` | List the current user's artist profiles | — |
| `get` | Get artist profile details | `artist_id` (auto-resolved) |
| `create` | Create a new artist profile | `name` |
| `update` | Update an existing artist profile | `artist_id` (auto-resolved), plus fields to update |

Optional fields for create/update: `bio`, `genre`, `local_city`, `profile_image_id`, `header_image_id`.

### manage_link_page

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the full link page | `artist_id` (auto-resolved) |
| `add_link` | Add a single link | `url`, `text`, optional `section` |
| `remove_link` | Remove a link | `url` or `link_id` |
| `save_links` | Replace all link sections | `links` array |
| `save_socials` | Replace social links | `socials` array |
| `save_styles` | Update CSS variables | `css_vars` object |
| `save_settings` | Update settings | `settings` object |

Convenience actions (`add_link`, `remove_link`) handle the fetch-modify-save cycle internally so the AI doesn't need multi-step orchestration.

### manage_user_profile

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `get` | View the current user's profile | — |
| `update` | Update bio, title, or city | At least one of `custom_title`, `bio`, `local_city` |
| `update_links` | Replace profile links | `links` array |

Profile links support types: `website`, `facebook`, `instagram`, `twitter`, `youtube`, `tiktok`, `spotify`, `soundcloud`, `bandcamp`, `github`, `other`.

### manage_community

| Action | Description | Required Params |
|--------|-------------|-----------------|
| `list_forums` | Browse available forums | — |
| `list_topics` | List topics (optionally filtered by forum) | Optional `forum_id` |
| `get_topic` | Read a topic with replies | `topic_id` |
| `create_topic` | Post a new topic | `forum_id`, `title`, `content` |
| `create_reply` | Reply to a topic | `topic_id`, `content` |
| `get_notifications` | Check notifications | Optional `unread` filter |
| `mark_notifications_read` | Mark all as read | — |

## Dependencies

- **[Data Machine](https://github.com/Extra-Chill/data-machine)** — Provides `BaseTool` class and the `datamachine_tools` filter
- **[Extra Chill Multisite](https://github.com/Extra-Chill/extrachill-multisite)** — Provides `ec_cross_site_rest_request()` for internal cross-site HTTP
- **[Extra Chill API](https://github.com/Extra-Chill/extrachill-api)** — REST endpoints and route affinity middleware
- **[Extra Chill Users](https://github.com/Extra-Chill/extrachill-users)** — User profile abilities and `ec_is_team_member()`

## Requirements

- WordPress 6.9+
- PHP 7.4+
- Data Machine plugin (network-activated)
- Extra Chill Multisite plugin (network-activated)

## Filters

| Filter | Description |
|--------|-------------|
| `datamachine_tools` | Registers all four chat tools (via BaseTool) |
| `datamachine_agent_modes` | Registers the `roadie` execution mode with AgentModeRegistry |
| `datamachine_agent_mode_roadie` | Provides the roadie mode guidance string (network topology, tool selection, identity contract, editorial voice, operating posture) |
| `datamachine_can_access_agent` | Bridges EC team membership to Roadie agent access |
| `datamachine_bridge_onboarding_config` | Provides Roadie-specific onboarding for bridge clients |
| `extrachill_roadie_allowed_redirect_uris` | External redirect URIs allowed for bridge auth |
| `extrachill_roadie_bridge_avatar_url` | Override the Roadie avatar URL |

## File Structure

```
extrachill-roadie.php          # Plugin bootstrap
inc/
  permissions.php              # Team access bridge + agent policy sync
  onboarding.php               # Bridge onboarding config (Beeper/Matrix)
  agent-mode/
    register.php               # AgentModeRegistry registration + roadie mode guidance
  tools/
    register.php               # Tool registration on plugins_loaded
    class-ec-platform-tool.php # Base class — REST helper, calling-user resolution, permission guard
    class-manage-artist-profile.php
    class-manage-link-page.php
    class-manage-user-profile.php
    class-manage-community.php
docs/
  CHANGELOG.md
homeboy.json                   # Homeboy deployment config
```

## Deployment

Managed via [Homeboy](https://github.com/Extra-Chill/homeboy):

```bash
homeboy build extrachill-roadie
homeboy deploy extrachill-roadie
```

## License

GPL v2 or later.
