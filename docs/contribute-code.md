# Sandbox-backed contribute-code flow

The `propose_code_change` chat tool dispatches a [WP Codebox](https://github.com/chubes4/wp-codebox) sandbox that makes a bounded code change against the current subsite's stack and opens a pull request on GitHub for human review.

This document covers the platform-operator side — what to configure so the flow actually runs.

## How the flow works

1. A team member (admin or editor by default) opens Roadie chat on any subsite.
2. They describe a code change in natural language.
3. The model calls the `propose_code_change` tool.
4. Roadie detects the active theme + subsite-specific plugins, builds a recipe (mounts + repo metadata), and invokes the `wp-codebox/run-agent-task` ability.
5. WP Codebox boots a WordPress Playground sandbox with those mounts, plus the agent stack (Data Machine, DMC, AI providers) as read-only references.
6. The sandboxed agent implements the change, commits with conventional-commit messages, pushes the branch, and opens a PR via DMC Code's GitHub tools.
7. WP Codebox captures an artifact bundle on teardown and exposes a live preview URL (held for 15 minutes by default).
8. Roadie surfaces the artifact summary, changed files, PR URL, and preview URL back to chat.

## Configuration

### 1. The GitHub token

The sandboxed agent needs a `GITHUB_TOKEN` to push and open PRs. Per the WP Codebox `secret_env` contract, **only the env var name** is passed in the ability input. The actual value lives in the WordPress PHP process environment.

In `wp-config.php` (or a `.env` loaded by WordPress), add:

```php
// Platform bot token used by the contribute-code sandbox.
putenv( 'GITHUB_TOKEN=' . 'ghp_XXXXXXXXXXXXXXXX' );
```

Use a personal access token (classic or fine-grained) for the platform bot identity. It needs `repo` scope (or fine-grained equivalent: contents read/write + pull requests read/write) on every repo the contribute-code flow might target.

If you prefer a different env var name (e.g. you already have `EXTRACHILL_BOT_GH_TOKEN` exported), set the network option:

```bash
wp --allow-root --path=/var/www/extrachill.com network meta update 1 extrachill_roadie_github_token_env EXTRACHILL_BOT_GH_TOKEN
```

Or filter:

```php
add_filter( 'extrachill_roadie_github_token_env_name', fn() => 'EXTRACHILL_BOT_GH_TOKEN' );
```

The tool fails with a clear error if the named env var isn't set in the parent process.

### 2. Capabilities

By default, administrators and editors get `extrachill_propose_code`. Override the role grant:

```php
add_filter( 'extrachill_roadie_propose_code_roles', function ( array $roles ) {
    return array( 'administrator', 'editor', 'author' );
} );
```

Or grant per-user via the standard `user_has_cap` filter / role editor.

### 3. Slug → repo map

The recipe builder maps active components on the subsite to GitHub repos via the `extrachill_roadie_repo_map` filter. Defaults cover the Extra Chill stack (theme + commonly active plugins + agent stack references).

Add a new plugin to the map:

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

If a contributor describes a change to a plugin that isn't in the map, the recipe still ships, but that plugin won't be mounted as editable, and the response includes the unmapped slug in `unmapped_plugins` for operator visibility.

### 4. Network-active boilerplate exclusion

Platform-wide plugins (Data Machine, AI providers, the roadie plugin itself, etc.) are excluded by default from the subsite's editable surface — they mount in via the agent stack as read-only references. Override the exclusion list:

```php
add_filter( 'extrachill_roadie_excluded_plugin_slugs', function ( array $slugs ) {
    $slugs[] = 'some-plugin-to-also-exclude';
    return $slugs;
} );
```

## Manual smoke test

To exercise the flow end-to-end against the live wp-codebox install:

1. **Prerequisites:**
   - `wp-codebox` plugin active on the network.
   - `GITHUB_TOKEN` (or your configured env var) exported in the WordPress PHP process.
   - Logged-in user with `extrachill_propose_code` capability.

2. **Trigger from CLI** (mimics what the chat layer does):

   ```bash
   wp --allow-root --path=/var/www/extrachill.com --url=community.extrachill.com \
       eval '
   $tool = new ECRoadie_ProposeCodeChange();
   $result = $tool->handle_tool_call( array(
       "task_description" => "Add a comment with the word ROADIE_SMOKE_TEST to the top of the main plugin file of extrachill-community."
   ) );
   echo wp_json_encode( $result, JSON_PRETTY_PRINT );'
   ```

3. **Verify:**
   - Response `success` is `true`.
   - `data.pr_url` points at a new PR in `Extra-Chill/extrachill-community`.
   - `data.preview_url` opens for ~15 minutes after dispatch.
   - The artifact bundle exists under the configured artifacts root.

4. **Clean up:** close the smoke-test PR without merging.

## Operational notes

- **Preview URL TTL:** controlled by `preview_hold_seconds` (default 900). Override via `extrachill_roadie_preview_hold_seconds` filter. Max 3600.
- **No nested DMC coordination:** the sandbox is self-contained. DMC + DMC Code run inside the Playground, not on the parent site.
- **PR review still happens on GitHub:** the flow opens the PR; merge stays with the human reviewer. There is no in-WordPress approval step.
- **Long-running sandboxes:** wp-codebox runs sandboxes synchronously. For long tasks, expect the chat round-trip to take however long the sandbox takes.
