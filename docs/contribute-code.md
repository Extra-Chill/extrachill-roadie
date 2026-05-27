# Sandbox-backed contribute-code flow

Team members chat with Roadie on any subsite, describe a code change, and Roadie:

1. Dispatches a [WP Codebox](https://github.com/chubes4/wp-codebox) Playground sandbox that implements the change against the subsite's stack.
2. Surfaces the resulting **patch artifact** + **live preview URL** back to chat for human review.
3. On explicit approval, applies the patch to a fresh workspace worktree on the host, commits with a conventional commit message, pushes, and opens a pull request on GitHub.

The sandbox never touches GitHub, never touches production code, and never holds the GitHub token. All git operations happen on the host **after** a human approves the artifact in chat.

## Two chat tools, two-step flow

| Tool | When called | What it does |
|---|---|---|
| `propose_code_change` | User describes a change | Dispatches sandbox, returns `artifact_id` + `preview_url` + `summary` + `changed_files`. **No git operations.** |
| `apply_code_change` | After user explicitly approves the artifact | Reads artifact from disk, creates worktree per affected repo, applies patch, commits, pushes, opens PR. Returns `pr_urls`. |

The structural human gate is: the LLM is told in `propose_code_change`'s description not to auto-call `apply_code_change` — it must wait for the user to approve. Capability check on both tools (`extrachill_propose_code`) is the second gate.

## Setup

### 1. Host prerequisites

- `wp-codebox` plugin installed and network-activated on the network.
- `wp-codebox` CLI binary available on the VPS (see `wp_codebox_bin` network option).
- DMC workspace clones present at `/var/lib/datamachine/workspace/<repo>` for every theme and plugin slug in the `extrachill_roadie_repo_map`. Create with `wp datamachine-code workspace clone <repo>`.
- `gh` CLI installed on the VPS.

### 2. wp-codebox network options

```bash
# Path to wp-codebox CLI
wp --allow-root --path=/var/www/extrachill.com network meta update 1 wp_codebox_bin /usr/local/bin/wp-codebox

# Where artifact bundles get written
wp --allow-root --path=/var/www/extrachill.com network meta update 1 wp_codebox_artifacts_root /var/lib/datamachine/artifacts/wp-codebox

# Component paths wp-codebox auto-mounts into every sandbox (the agent stack)
wp --allow-root --path=/var/www/extrachill.com network meta update 1 wp_codebox_component_paths \
  '{"agents_api":"/var/lib/datamachine/workspace/agents-api","data_machine":"/var/lib/datamachine/workspace/data-machine","data_machine_code":"/var/lib/datamachine/workspace/data-machine-code","provider_plugins":["/var/lib/datamachine/workspace/ai-provider-for-openai"]}' \
  --format=json
```

Roadie does **not** include the agent stack in its own recipe — wp-codebox handles it via these network options. Including them would double-mount.

### 3. GitHub credentials (host-only, for apply-back)

The `apply_code_change` tool shells out to `git push` and `gh pr create` on the host. GitHub auth is resolved per-repo via the Data Machine credential profile system (`DataMachineCode\Support\GitHubCredentialResolver`), which supports both classic PATs and GitHub App installation tokens. **The token never enters the sandbox** — sandboxes don't push.

For each shell-out, apply-back:

1. Calls `GitHubCredentialResolver::resolve( [ 'repo' => $repo ] )` to mint a fresh credential scoped to the target repo (picks the matching profile by `allowed_repos`, falls back to the default profile).
2. Threads the resulting token into the shell command via per-command env: `git -c http.extraheader="Authorization: Bearer <token>" push ...` and `GH_TOKEN=<token> gh pr create ...`.
3. Never `putenv()`s the token globally — there is no leakage into the PHP process env, into the sandbox, or into any subprocess that wasn't explicitly built with the credential.

When the configured profile uses App mode, the resolver mints a short-lived installation token (`ghs_...`, ~1 hour TTL) on each call. PRs opened by apply-back are then authored by the GitHub App's bot identity (e.g. `homeboy-ci[bot]`), not by a personal account.

#### Verify configuration

```bash
wp --allow-root --path=/var/www/extrachill.com datamachine-code github status
```

This prints the default profile id, the configured mode, and a configured/not-configured summary per profile. Both `apply_code_change` and `propose_code_change` fail fast with an explicit error if `GitHubCredentialResolver::isConfigured()` is false.

#### Configure profiles

Profiles live in the `github_credential_profiles` setting, with `github_default_profile_id` pointing at the fallback. Write via the `datamachine/update-settings` ability (REST, CLI, or chat). The resolver also reads the legacy single-credential keys (`github_pat`, `github_auth_mode`, `github_app_*`) when the new structure is empty, so existing installs keep working until operators migrate.

Tokens never need to be exported in the PHP-FPM environment, in `wp-config.php`, or anywhere else outside the credential profile store.

### 4. OpenAI credentials (inheritance, bridged from options to env)

The sandboxed coding agent uses OpenAI by default. The credential lives in the parent site's `connectors_ai_openai_api_key` option (WordPress 7.0 connectors API).

Roadie hooks `wp_codebox_resolve_inheritance` (shipped in [chubes4/wp-codebox#89](https://github.com/chubes4/wp-codebox/pull/89), foundation for [#88](https://github.com/chubes4/wp-codebox/issues/88)). When `propose_code_change` dispatches with `inherit: { connectors: ['openai'] }`, our resolver:

1. Reads the value from `connectors_ai_openai_api_key`.
2. `putenv()`s it as `OPENAI_API_KEY` on the host PHP process.
3. Returns metadata `{ provider: 'openai', model: 'gpt-5', secretEnv: ['OPENAI_API_KEY'] }` to wp-codebox.

WP Codebox then carries the `OPENAI_API_KEY` env var name through `secret_env` into the sandbox, where `php-ai-client`'s `ProviderRegistry` resolves it via `getenv()` at provider registration time.

**This is a temporary bridge.** When the wp-codebox inheritance contract grows to support filter-returned values (the remaining scope on #88), the `putenv()` bridge will be removed and the filter will return the value directly.

Override the connector→metadata map for new connectors or different models:

```php
add_filter( 'extrachill_roadie_inherit_connectors', function ( array $map ) {
    $map['openai']['model'] = 'gpt-5-mini';
    return $map;
} );
```

### 5. Capabilities

By default, administrators and editors get `extrachill_propose_code`. Override:

```php
add_filter( 'extrachill_roadie_propose_code_roles', function ( array $roles ) {
    return array( 'administrator', 'editor', 'author' );
} );
```

### 6. Slug → repo map

The recipe builder maps active components on the subsite to GitHub repos via the `extrachill_roadie_repo_map` filter. Defaults cover the Extra Chill theme + commonly active plugins. Add new entries:

```php
add_filter( 'extrachill_roadie_repo_map', function ( array $map ) {
    $map['new-extra-chill-plugin'] = array(
        'repo'                        => 'Extra-Chill/new-extra-chill-plugin',
        'default_branch'              => 'main',
        'repo_root_relative_to_mount' => '',
        'kind'                        => 'plugin',
    );
    return $map;
} );
```

If a contributor describes a change to a plugin that isn't in the map, the recipe still ships but that plugin won't be mounted as editable. The response includes the unmapped slug in `unmapped_plugins` for operator visibility.

## Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│  community.extrachill.com — user chats with Roadie                 │
│    "fix the typo in reply notification text"                       │
└──────────────────────────────┬─────────────────────────────────────┘
                               │
                propose_code_change(task_description)
                               │
┌──────────────────────────────▼─────────────────────────────────────┐
│  Roadie (host)                                                      │
│  1. Capability check                                                │
│  2. Detect subsite context (active theme + plugins, minus network) │
│  3. Build recipe:                                                   │
│       mounts from /var/lib/datamachine/workspace/<repo>             │
│       metadata.baselineSource = source                              │
│  4. wp-codebox/run-agent-task with:                                 │
│       inherit.connectors: ['openai']                                │
│       preview_hold_seconds: 900                                     │
└──────────────────────────────┬─────────────────────────────────────┘
                               │
┌──────────────────────────────▼─────────────────────────────────────┐
│  WP Codebox sandbox (PHP-WASM Playground)                           │
│  - Boots fresh WP                                                   │
│  - Mounts workspace clones into in-memory VFS                       │
│  - Activates agent stack (agents-api, DM, DMC, OpenAI provider)     │
│  - Resolves OPENAI_API_KEY via php-ai-client connectors             │
│  - Runs the agent loop; agent edits files in VFS                    │
│  - Teardown: captureMountDiffs() emits patch.diff + review.json     │
│  - Holds preview URL for 900s                                       │
└──────────────────────────────┬─────────────────────────────────────┘
                               │ artifact bundle
┌──────────────────────────────▼─────────────────────────────────────┐
│  Roadie chat                                                        │
│    Shows: summary, preview URL, changed files, artifact_id          │
│    Says: "Approve to commit & open PR"                              │
└──────────────────────────────┬─────────────────────────────────────┘
                               │  user types "approve"
              apply_code_change(artifact_id)
                               │
┌──────────────────────────────▼─────────────────────────────────────┐
│  Roadie (host) — apply-back                                         │
│  For each readwrite mount with changes:                             │
│    1. wp datamachine-code workspace worktree add <repo> <branch>    │
│    2. Translate sandbox paths → repo-root paths in patch            │
│    3. git apply                                                     │
│    4. git commit -m "fix(<slug>): <summary>"                        │
│    5. git push origin <branch>                                      │
│    6. gh pr create (GH_TOKEN from per-command env, freshly minted   │
│         by GitHubCredentialResolver — never global putenv)          │
│  Returns pr_urls back to chat                                       │
└────────────────────────────────────────────────────────────────────┘
```

Sandbox boundary: nothing crosses except (1) mounted host files into VFS at boot, (2) artifact bundle out on teardown. No git, no GitHub token, no network egress from the sandbox process.

## Manual smoke test

```bash
# 1. Verify env is set up
wp --allow-root --path=/var/www/extrachill.com network meta get 1 wp_codebox_bin
wp --allow-root --path=/var/www/extrachill.com network meta get 1 wp_codebox_component_paths
wp --allow-root --path=/var/www/extrachill.com datamachine-code github status

# 2. Trigger propose tool
wp --allow-root --path=/var/www/extrachill.com --url=community.extrachill.com eval '
$tool = new ECRoadie_ProposeCodeChange();
$result = $tool->handle_tool_call( array(
    "task_description" => "Add a one-line comment with the word ROADIE_SMOKE_TEST near the top of the main plugin file of extrachill-community."
) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT );'

# Note the artifact_id from the output. Visit the preview_url to confirm
# the change applied inside the sandbox.

# 3. Approve and apply
wp --allow-root --path=/var/www/extrachill.com --url=community.extrachill.com eval '
$tool = new ECRoadie_ApplyCodeChange();
$result = $tool->handle_tool_call( array( "artifact_id" => "ARTIFACT_ID_HERE" ) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT );'

# Expect: data.pr_urls[0] = https://github.com/Extra-Chill/extrachill-community/pull/N

# 4. Close the smoke-test PR on GitHub without merging.
```

## Operational notes

- **Preview URL TTL:** controlled by `preview_hold_seconds` (default 900). Override via `extrachill_roadie_preview_hold_seconds` filter. Max 3600.
- **Worktree convention:** apply-back creates worktrees as `<repo>@roadie-<slug>-<short-artifact-id>`. These are tracked in the DMC workspace registry and can be cleaned up via `wp datamachine-code workspace worktree mark-cleanup-eligible <handle>` after PR merge.
- **Commit identity:** all commits authored by `Extra Chill Bot <bot@extrachill.com>` (configured via `extrachill_roadie_apply_commit_name` / `extrachill_roadie_apply_commit_email` filters). The PR author on GitHub is whoever owns the resolved credential profile — when using the default `homeboy-ci` App profile, PRs are authored by `homeboy-ci[bot]`.
- **No release / no deploy:** apply-back opens a PR; merge and release stay manual on GitHub. There is no path from chat to production deploy.

## Upstream dependencies & open work

| Issue | Status | What it gives us |
|---|---|---|
| [chubes4/wp-codebox#88](https://github.com/chubes4/wp-codebox/issues/88) | Partial (closed via #89) | Declarative inheritance contract for connectors/settings. The `inherit` block + `wp_codebox_resolve_inheritance` filter are live. |
| [chubes4/wp-codebox#89](https://github.com/chubes4/wp-codebox/pull/89) | Merged | Transport foundation for #88. Provider/model/secret_env-names crossover via the filter. |
| Remaining #88 scope | Open | Agent identity inheritance (custom SOUL/MEMORY for the sandbox agent) + filter-returned secret values (eliminating the putenv() bridge). |

When the remaining #88 scope lands, the `inherit-resolver.php` bridge becomes obsolete: the filter will return values directly instead of `putenv()`ing them, and we can also pass an `inherit.agents` block to give the sandboxed agent a tailored persona.
