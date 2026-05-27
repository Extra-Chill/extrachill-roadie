<?php
/**
 * Apply Code Change Tool
 *
 * Chat tool that takes a previously-generated artifact_id from
 * propose_code_change, reads the artifact bundle from disk, and on the host:
 *
 *   1. For each readwrite mount with changes:
 *      a. Resolves the workspace clone path from mount.metadata.repo /
 *         mount.metadata.baselineSource.
 *      b. Creates a per-task worktree via DMC's
 *         `datamachine-code workspace worktree add` CLI on a fresh branch.
 *      c. Applies the patch (scoped to that mount's changed files).
 *      d. Stages, commits with a conventional commit message.
 *      e. Pushes the branch to origin.
 *      f. Opens a PR via `gh pr create` against the mount's repo.
 *
 *   2. Returns one PR URL per repo (could be multiple if the sandbox touched
 *      multiple components).
 *
 * The sandbox never runs git. All git operations live here, on the host.
 * GitHub credentials are minted per-repo via DataMachineCode\Support\GitHubCredentialResolver
 * (App-mode installation tokens or PAT, per the configured credential profile)
 * and threaded into each shell-out via per-command env, never via global putenv().
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Support\GitHubCredentialResolver;

class ECRoadie_ApplyCodeChange extends BaseTool {

	protected string $tool_slug = 'apply_code_change';

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Apply a previously-generated sandbox artifact: create a worktree for each affected repo, apply the patch, commit with a conventional commit message, push the branch, and open a pull request. Only call this AFTER the user has explicitly approved the proposed change (do not auto-apply). Requires artifact_id from a prior propose_code_change call.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'artifact_id' ),
				'properties' => array(
					'artifact_id'         => array(
						'type'        => 'string',
						'description' => 'Artifact bundle id returned by propose_code_change.',
					),
					'commit_message_hint' => array(
						'type'        => 'string',
						'description' => 'Optional one-line conventional-commit hint (e.g. "fix(community): typo in reply notification"). When omitted, derived from the artifact review summary.',
					),
				),
			),
		);
	}

	/**
	 * @param array $parameters
	 * @param array $tool_def
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		if ( ! current_user_can( EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ) ) {
			return $this->buildErrorResponse(
				'You do not have permission to apply code changes. Ask an administrator to grant the "extrachill_propose_code" capability.',
				$this->tool_slug
			);
		}

		$artifact_id = trim( (string) ( $parameters['artifact_id'] ?? '' ) );
		if ( '' === $artifact_id ) {
			return $this->buildErrorResponse( 'artifact_id is required.', $this->tool_slug );
		}

		if ( ! $this->resolver_is_configured() ) {
			return $this->buildErrorResponse(
				'GitHub credentials are not configured. Apply-back resolves a token per repo via the Data Machine credential profile system. Verify with `wp --allow-root --path=/var/www/extrachill.com datamachine-code github status`, and configure via the `datamachine/update-settings` ability with `github_credential_profiles` + `github_default_profile_id`.',
				$this->tool_slug
			);
		}

		// 1. Resolve the artifact bundle from disk.
		$bundle = $this->load_artifact_bundle( $artifact_id );
		if ( is_wp_error( $bundle ) ) {
			return $this->buildErrorResponse( $bundle->get_error_message(), $this->tool_slug );
		}

		// 2. Group changed files by mount index → repo metadata.
		$grouped = $this->group_changes_by_mount( $bundle );
		if ( empty( $grouped ) ) {
			return $this->buildErrorResponse(
				'Artifact contains no readwrite-mount changes to apply.',
				$this->tool_slug
			);
		}

		// 3. For each affected mount, create worktree + apply + commit + push + PR.
		$commit_hint = trim( (string) ( $parameters['commit_message_hint'] ?? '' ) );
		$results     = array();
		$any_failure = false;

		foreach ( $grouped as $entry ) {
			$result = $this->apply_to_mount( $entry, $artifact_id, $commit_hint, $bundle );
			$results[] = $result;
			if ( empty( $result['success'] ) ) {
				$any_failure = true;
			}
		}

		return array(
			'success'   => ! $any_failure,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'artifact_id' => $artifact_id,
				'mounts'      => $results,
				'pr_urls'     => array_values( array_filter( array_map( static fn( $r ) => (string) ( $r['pr_url'] ?? '' ), $results ) ) ),
			),
		);
	}

	/**
	 * Load and validate an artifact bundle from disk.
	 *
	 * Walks the configured artifacts root (network option or filter) looking
	 * for a manifest.json whose id matches $artifact_id.
	 *
	 * @param string $artifact_id
	 * @return array<string,mixed>|WP_Error
	 */
	protected function load_artifact_bundle( string $artifact_id ) {
		$root = $this->resolve_artifacts_root();
		if ( '' === $root || ! is_dir( $root ) ) {
			return new WP_Error( 'roadie_artifacts_root_missing', 'WP Codebox artifacts root is not configured or does not exist.' );
		}

		// wp-codebox writes each runtime's artifacts under a runtime-id
		// subdirectory of $root. Scan one level deep for matching manifest.
		$candidates = glob( rtrim( $root, '/' ) . '/*/manifest.json' ) ?: array();
		foreach ( $candidates as $manifest_path ) {
			$json = @file_get_contents( $manifest_path );
			if ( false === $json ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( (string) ( $decoded['id'] ?? '' ) === $artifact_id ) {
				$decoded['_bundle_dir']      = dirname( $manifest_path );
				$decoded['_patch_path']      = dirname( $manifest_path ) . '/files/patch.diff';
				$decoded['_changed_files']  = dirname( $manifest_path ) . '/files/changed-files.json';
				return $decoded;
			}
		}

		return new WP_Error( 'roadie_artifact_not_found', sprintf( 'No artifact bundle found for id "%s" under %s.', $artifact_id, $root ) );
	}

	/**
	 * Resolve the configured wp-codebox artifacts root.
	 *
	 * @return string
	 */
	protected function resolve_artifacts_root(): string {
		$root = '';
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$root = (string) get_site_option( 'wp_codebox_artifacts_root', '' );
		}
		if ( '' === $root && function_exists( 'get_option' ) ) {
			$root = (string) get_option( 'wp_codebox_artifacts_root', '' );
		}
		/**
		 * Filter the artifacts root used by apply_code_change.
		 *
		 * @since 0.7.0
		 *
		 * @param string $root Configured root.
		 */
		return (string) apply_filters( 'extrachill_roadie_artifacts_root', $root );
	}

	/**
	 * Group changed_files by mount index, attaching mount metadata.
	 *
	 * Returns an array of:
	 *   array(
	 *     'mount_index'   => int,
	 *     'mount'         => array{source,target,mode,metadata},
	 *     'changed_paths' => string[],  // sandbox-relative paths
	 *   )
	 *
	 * @param array $bundle
	 * @return array<int,array<string,mixed>>
	 */
	protected function group_changes_by_mount( array $bundle ): array {
		$mounts = (array) ( $bundle['mounts'] ?? array() );

		$changed_files_path = (string) ( $bundle['_changed_files'] ?? '' );
		$changed_files      = array();
		if ( '' !== $changed_files_path && is_file( $changed_files_path ) ) {
			$decoded = json_decode( (string) file_get_contents( $changed_files_path ), true );
			if ( is_array( $decoded ) ) {
				$changed_files = (array) ( $decoded['files'] ?? array() );
			}
		}

		$grouped = array();
		foreach ( $changed_files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$idx   = (int) ( $file['mountIndex'] ?? -1 );
			$path  = (string) ( $file['path'] ?? '' );
			if ( $idx < 0 || '' === $path || ! isset( $mounts[ $idx ] ) ) {
				continue;
			}
			$mount = $mounts[ $idx ];
			if ( ( $mount['mode'] ?? '' ) !== 'readwrite' ) {
				continue;
			}

			if ( ! isset( $grouped[ $idx ] ) ) {
				$grouped[ $idx ] = array(
					'mount_index'   => $idx,
					'mount'         => $mount,
					'changed_paths' => array(),
				);
			}
			$grouped[ $idx ]['changed_paths'][] = $path;
		}

		return array_values( $grouped );
	}

	/**
	 * Apply the patch to a single mount's repo.
	 *
	 * @param array  $entry       Grouped mount entry.
	 * @param string $artifact_id
	 * @param string $commit_hint
	 * @param array  $bundle
	 * @return array<string,mixed>
	 */
	protected function apply_to_mount( array $entry, string $artifact_id, string $commit_hint, array $bundle ): array {
		$mount    = (array) $entry['mount'];
		$metadata = (array) ( $mount['metadata'] ?? array() );
		$slug     = (string) ( $metadata['slug'] ?? '' );
		$repo     = (string) ( $metadata['repo'] ?? '' );

		if ( '' === $slug || '' === $repo ) {
			return array(
				'success' => false,
				'slug'    => $slug,
				'repo'    => $repo,
				'error'   => 'Mount missing slug or repo metadata.',
			);
		}

		$default_branch = (string) ( $metadata['default_branch'] ?? 'main' );
		$branch         = $this->build_branch_name( $artifact_id, $slug );
		$workspace_root = extrachill_roadie_workspace_root();
		$primary_path   = $workspace_root . '/' . $slug;
		$worktree_slug  = $this->branch_to_slug( $branch );
		$worktree_path  = $workspace_root . '/' . $slug . '@' . $worktree_slug;

		if ( ! is_dir( $primary_path ) ) {
			return array(
				'success' => false,
				'slug'    => $slug,
				'repo'    => $repo,
				'error'   => 'Workspace primary clone is missing: ' . $primary_path,
			);
		}

		// Resolve GitHub credentials once per repo. App-mode installation
		// tokens are valid for ~1 hour, which comfortably covers push + PR
		// creation. The token is threaded into shell-outs via per-command
		// env (http.extraheader for git, GH_TOKEN= prefix for gh) — never
		// putenv'd globally.
		$credential = $this->resolve_github_credential( $repo );
		if ( is_wp_error( $credential ) ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'GitHub credential resolution failed: ' . $credential->get_error_message(),
			);
		}
		$token = (string) ( $credential['token'] ?? '' );
		if ( '' === $token ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'GitHub credential resolver returned an empty token.',
			);
		}

		// 1. Create a fresh worktree via DMC.
		$worktree = $this->run_command(
			sprintf(
				'wp --allow-root --path=%s datamachine-code workspace worktree add %s %s --from=%s --skip-bootstrap --skip-context-injection 2>&1',
				escapeshellarg( ABSPATH ),
				escapeshellarg( $slug ),
				escapeshellarg( $branch ),
				escapeshellarg( 'origin/' . $default_branch )
			)
		);
		if ( 0 !== $worktree['exit_code'] && ! is_dir( $worktree_path ) ) {
			return array(
				'success' => false,
				'slug'    => $slug,
				'repo'    => $repo,
				'branch'  => $branch,
				'error'   => 'Failed to create worktree: ' . $worktree['output'],
			);
		}

		// 2. Translate sandbox paths in patch back to host paths.
		// captureMountDiffs writes diffs under files/diffs/mount-<idx>.patch
		// already scoped to one mount. Prefer that over the combined patch.
		$idx               = (int) $entry['mount_index'];
		$mount_patch_path  = $bundle['_bundle_dir'] . '/files/diffs/mount-' . $idx . '.patch';
		$patch_source      = is_file( $mount_patch_path ) ? $mount_patch_path : (string) ( $bundle['_patch_path'] ?? '' );

		if ( '' === $patch_source || ! is_file( $patch_source ) ) {
			return array(
				'success' => false,
				'slug'    => $slug,
				'repo'    => $repo,
				'branch'  => $branch,
				'error'   => 'Patch file missing for mount index ' . $idx,
			);
		}

		$patch_contents = (string) file_get_contents( $patch_source );
		$translated     = $this->translate_patch_paths( $patch_contents, (string) $mount['target'] );
		$tmp_patch      = tempnam( sys_get_temp_dir(), 'roadie-patch-' );
		file_put_contents( $tmp_patch, $translated );

		// 3. Apply the patch inside the worktree.
		$apply = $this->run_command(
			sprintf(
				'cd %s && git apply --whitespace=nowarn %s 2>&1',
				escapeshellarg( $worktree_path ),
				escapeshellarg( $tmp_patch )
			)
		);
		@unlink( $tmp_patch );

		if ( 0 !== $apply['exit_code'] ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'git apply failed: ' . $apply['output'],
			);
		}

		// 4. Commit.
		$commit_message = $this->build_commit_message( $commit_hint, $bundle, $slug );
		$commit         = $this->run_command(
			sprintf(
				'cd %s && git add -A && git -c user.email=%s -c user.name=%s commit -m %s 2>&1',
				escapeshellarg( $worktree_path ),
				escapeshellarg( $this->commit_email() ),
				escapeshellarg( $this->commit_name() ),
				escapeshellarg( $commit_message )
			)
		);
		if ( 0 !== $commit['exit_code'] ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'git commit failed: ' . $commit['output'],
			);
		}

		// 5. Push. Token authorization passed via -c http.extraheader so
		// the credential never lands in a remote URL, in a credential cache,
		// or in any global git config. Per-command, per-invocation only.
		$push = $this->run_command( $this->build_push_command( $worktree_path, $branch, $token ) );
		$push['output'] = $this->redact_token( (string) $push['output'], $token );
		if ( 0 !== $push['exit_code'] ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'git push failed: ' . $push['output'],
			);
		}

		// 6. Open PR.
		$proposer_id   = (int) ( $bundle['context']['proposer_user_id'] ?? 0 );
		$proposer_line = $proposer_id > 0 ? sprintf( "\n\nProposed via roadie chat by user %d.", $proposer_id ) : '';
		$pr_body       = $commit_message . $proposer_line . "\n\nArtifact id: `" . $bundle['id'] . "`";

		// gh pr create reads GH_TOKEN from its own per-command env — passed
		// inline in the command string, never via putenv() on the PHP process.
		$pr = $this->run_command(
			$this->build_pr_create_command( $worktree_path, $repo, $default_branch, $branch, $commit_message, $pr_body, $token )
		);
		$pr['output'] = $this->redact_token( (string) $pr['output'], $token );
		if ( 0 !== $pr['exit_code'] ) {
			return array(
				'success'       => false,
				'slug'          => $slug,
				'repo'          => $repo,
				'branch'        => $branch,
				'worktree_path' => $worktree_path,
				'error'         => 'gh pr create failed: ' . $pr['output'],
			);
		}

		$pr_url = $this->extract_pr_url( $pr['output'] );

		return array(
			'success'       => true,
			'slug'          => $slug,
			'repo'          => $repo,
			'branch'        => $branch,
			'worktree_path' => $worktree_path,
			'pr_url'        => $pr_url,
			'changed_paths' => $entry['changed_paths'],
		);
	}

	/**
	 * Run a shell command and capture exit code + combined output.
	 *
	 * @param string $cmd
	 * @return array{exit_code:int,output:string}
	 */
	protected function run_command( string $cmd ): array {
		$output = array();
		$code   = 1;
		exec( $cmd, $output, $code );
		return array(
			'exit_code' => (int) $code,
			'output'    => implode( "\n", $output ),
		);
	}

	/**
	 * Rewrite a unified diff so sandbox paths like
	 * `/wordpress/wp-content/plugins/<slug>/foo.php` become repo-root-relative
	 * `foo.php`, matching the on-disk layout of the workspace clone.
	 *
	 * @param string $patch
	 * @param string $sandbox_target Mount target inside sandbox, e.g.
	 *                                /wordpress/wp-content/plugins/<slug>
	 * @return string
	 */
	protected function translate_patch_paths( string $patch, string $sandbox_target ): string {
		$prefix = ltrim( $sandbox_target, '/' );

		// Patch lines look like:  diff --git a/wordpress/wp-content/plugins/foo/file.php b/wordpress/...
		//                         --- a/wordpress/...
		//                         +++ b/wordpress/...
		$patterns = array(
			'#^(diff --git )a/' . preg_quote( $prefix, '#' ) . '/(.+) b/' . preg_quote( $prefix, '#' ) . '/(.+)$#m',
			'#^(--- )a/' . preg_quote( $prefix, '#' ) . '/(.+)$#m',
			'#^(\+\+\+ )b/' . preg_quote( $prefix, '#' ) . '/(.+)$#m',
		);
		$replacements = array(
			'$1a/$2 b/$3',
			'$1a/$2',
			'$1b/$2',
		);

		return (string) preg_replace( $patterns, $replacements, $patch );
	}

	/**
	 * Build a branch name unique to this artifact + slug.
	 *
	 * @param string $artifact_id
	 * @param string $slug
	 * @return string
	 */
	protected function build_branch_name( string $artifact_id, string $slug ): string {
		$short = substr( preg_replace( '/[^a-z0-9]+/i', '', $artifact_id ), 0, 12 );
		return sprintf( 'roadie/%s-%s', $slug, strtolower( $short ) );
	}

	/**
	 * Convert a branch name to the worktree slug DMC uses on disk.
	 *
	 * Per DMC convention: slashes become dashes in the slug.
	 *
	 * @param string $branch
	 * @return string
	 */
	protected function branch_to_slug( string $branch ): string {
		return str_replace( '/', '-', $branch );
	}

	/**
	 * Build the conventional-commit message.
	 *
	 * @param string $hint
	 * @param array  $bundle
	 * @param string $slug
	 * @return string
	 */
	protected function build_commit_message( string $hint, array $bundle, string $slug ): string {
		if ( '' !== $hint ) {
			return $hint;
		}
		$summary = '';
		$review_path = $bundle['_bundle_dir'] . '/files/review.json';
		if ( is_file( $review_path ) ) {
			$decoded = json_decode( (string) file_get_contents( $review_path ), true );
			if ( is_array( $decoded ) ) {
				$summary = trim( (string) ( $decoded['summary'] ?? '' ) );
			}
		}
		if ( '' === $summary ) {
			$summary = 'roadie sandbox change';
		}
		// First line only, prefix as fix(<slug>): or feat(<slug>): if not already conventional.
		$first = strtok( $summary, "\n" );
		if ( $first === false ) {
			$first = $summary;
		}
		if ( ! preg_match( '/^(fix|feat|refactor|chore|docs|test|build|ci|perf|style)(\(.+\))?:/', $first ) ) {
			$first = sprintf( 'fix(%s): %s', $slug, $first );
		}
		return $first;
	}

	protected function commit_name(): string {
		return (string) apply_filters( 'extrachill_roadie_apply_commit_name', 'Extra Chill Bot' );
	}

	protected function commit_email(): string {
		return (string) apply_filters( 'extrachill_roadie_apply_commit_email', 'bot@extrachill.com' );
	}

	protected function extract_pr_url( string $output ): string {
		if ( preg_match( '#https://github\.com/[^\s]+/pull/\d+#', $output, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/**
	 * Test seam: ask the resolver whether GitHub auth is configured at all.
	 *
	 * @return bool
	 */
	protected function resolver_is_configured(): bool {
		return GitHubCredentialResolver::isConfigured();
	}

	/**
	 * Test seam: resolve a GitHub credential scoped to the given repo.
	 *
	 * Returns the resolver's success array shape:
	 *   array{ mode, token, authorization, profile_id, cached?, expires_at? }
	 * or a WP_Error on failure.
	 *
	 * @param string $repo `owner/repo` slug.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function resolve_github_credential( string $repo ) {
		return GitHubCredentialResolver::resolve( null, null, array( 'repo' => $repo ) );
	}

	/**
	 * Build the `git push` shell command, threading the resolved token via
	 * `-c http.extraheader=...` so the credential never touches global git
	 * config, the credential cache, or the remote URL.
	 *
	 * Exposed as a protected method so tests can capture the constructed
	 * command string without executing it.
	 *
	 * @param string $worktree_path Absolute path to the worktree on disk.
	 * @param string $branch        Branch to push.
	 * @param string $token         Resolved GitHub token (PAT or ghs_... installation token).
	 * @return string
	 */
	protected function build_push_command( string $worktree_path, string $branch, string $token ): string {
		return sprintf(
			'cd %s && git -c http.extraheader="Authorization: Bearer %s" push -u origin %s 2>&1',
			escapeshellarg( $worktree_path ),
			escapeshellarg( $token ),
			escapeshellarg( $branch )
		);
	}

	/**
	 * Build the `gh pr create` shell command with the resolved token passed
	 * as a per-command `GH_TOKEN=` env prefix — never via putenv() on the
	 * global PHP process.
	 *
	 * @param string $worktree_path
	 * @param string $repo
	 * @param string $default_branch
	 * @param string $branch
	 * @param string $title
	 * @param string $body
	 * @param string $token
	 * @return string
	 */
	protected function build_pr_create_command(
		string $worktree_path,
		string $repo,
		string $default_branch,
		string $branch,
		string $title,
		string $body,
		string $token
	): string {
		return sprintf(
			'cd %s && GH_TOKEN=%s gh pr create --repo %s --base %s --head %s --title %s --body %s 2>&1',
			escapeshellarg( $worktree_path ),
			escapeshellarg( $token ),
			escapeshellarg( $repo ),
			escapeshellarg( $default_branch ),
			escapeshellarg( $branch ),
			escapeshellarg( $title ),
			escapeshellarg( $body )
		);
	}

	/**
	 * Redact a token from arbitrary command output before it surfaces in
	 * tool responses, logs, or artifact bundles. Belt-and-suspenders: most
	 * git/gh commands shouldn't echo the token, but `set -x`-style traces,
	 * 401 responses, or push URLs can leak it.
	 *
	 * @param string $output Raw command output.
	 * @param string $token  Token to redact.
	 * @return string
	 */
	protected function redact_token( string $output, string $token ): string {
		if ( '' === $token ) {
			return $output;
		}
		return str_replace( $token, '[REDACTED]', $output );
	}
}
