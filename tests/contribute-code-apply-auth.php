<?php
/**
 * Smoke test for issue #22: apply_code_change uses GitHubCredentialResolver
 * for git push + gh pr create, threads the token via per-command env, and
 * never putenv()s GITHUB_TOKEN globally.
 *
 * Strategy: poke the command-builder protected methods directly via a test
 * subclass, exercise the resolver test seam with the stub resolver, and
 * statically scan the source for `putenv( 'GITHUB_TOKEN'` to lock in the
 * "no global env" rule.
 *
 * Run with: php tests/contribute-code-apply-auth.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';
require_once __DIR__ . '/_stub-github-credential-resolver.php';

// WP_Error stub for resolver-failure path.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// BaseTool stub.
if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "tool_name" => $tool_name );
			}
			protected function buildDiagnosticErrorResponse( string $error, string $type, string $tool_name, array $a = array(), array $b = array() ): array {
				return array( "success" => false, "error" => $error, "type" => $type, "tool_name" => $tool_name );
			}
		}'
	);
}

// Capability cap constant.
if ( ! defined( 'EXTRACHILL_ROADIE_PROPOSE_CODE_CAP' ) ) {
	require_once dirname( __DIR__ ) . '/inc/contribute-code/capabilities.php';
}

require_once dirname( __DIR__ ) . '/inc/tools/class-apply-code-change.php';

// Test subclass exposes protected command builders + seam overrides.
final class ECRoadie_TestableApplyCodeChange extends ECRoadie_ApplyCodeChange {
	public array $captured_commands     = array();
	public array $captured_resolve_calls = array();
	/** @var array<string,mixed>|WP_Error */
	public $resolve_return = array(
		'mode'          => 'app',
		'token'         => 'ghs_TEST_TOKEN',
		'authorization' => 'Bearer ghs_TEST_TOKEN',
		'profile_id'    => 'homeboy-ci',
	);
	public bool $is_configured = true;

	public function call_build_push_command( string $worktree_path, string $branch, string $token ): string {
		return $this->build_push_command( $worktree_path, $branch, $token );
	}

	public function call_build_pr_create_command(
		string $worktree_path,
		string $repo,
		string $default_branch,
		string $branch,
		string $title,
		string $body,
		string $token
	): string {
		return $this->build_pr_create_command( $worktree_path, $repo, $default_branch, $branch, $title, $body, $token );
	}

	public function call_redact_token( string $output, string $token ): string {
		return $this->redact_token( $output, $token );
	}

	protected function resolver_is_configured(): bool {
		return $this->is_configured;
	}

	protected function resolve_github_credential( string $repo ) {
		$this->captured_resolve_calls[] = $repo;
		return $this->resolve_return;
	}

	protected function run_command( string $cmd ): array {
		$this->captured_commands[] = $cmd;
		// Default: pretend the command succeeded with no output.
		return array(
			'exit_code' => 0,
			'output'    => '',
		);
	}
}

$tool = new ECRoadie_TestableApplyCodeChange();

// --- build_push_command -----------------------------------------------
$push_cmd = $tool->call_build_push_command( '/tmp/wt', 'roadie/foo', 'ghs_TEST_TOKEN' );

roadie_test_assert(
	false !== strpos( $push_cmd, 'http.extraheader="Authorization: Bearer' ),
	'push command must thread the token via http.extraheader, not a remote URL'
);
roadie_test_assert(
	false !== strpos( $push_cmd, 'ghs_TEST_TOKEN' ),
	'push command must contain the resolved token'
);
roadie_test_assert(
	false !== strpos( $push_cmd, "'roadie/foo'" ),
	'push command must reference the branch'
);
roadie_test_assert(
	false === strpos( $push_cmd, 'GITHUB_TOKEN' ),
	'push command must NOT reference GITHUB_TOKEN env var'
);
roadie_test_assert(
	0 === strpos( $push_cmd, "cd '/tmp/wt' && git " ),
	'push command must cd into the worktree first'
);

// --- build_pr_create_command ------------------------------------------
$pr_cmd = $tool->call_build_pr_create_command(
	'/tmp/wt',
	'Extra-Chill/foo',
	'main',
	'roadie/foo',
	'fix(foo): test title',
	'PR body',
	'ghs_TEST_TOKEN'
);

roadie_test_assert(
	1 === preg_match( "#^cd '/tmp/wt' && GH_TOKEN='ghs_TEST_TOKEN' gh pr create #", $pr_cmd ),
	'gh pr create must start with `cd <worktree> && GH_TOKEN=<token> gh pr create`'
);
roadie_test_assert(
	false !== strpos( $pr_cmd, "--repo 'Extra-Chill/foo'" ),
	'gh pr create must pass --repo'
);
roadie_test_assert(
	false !== strpos( $pr_cmd, "--base 'main'" ),
	'gh pr create must pass --base'
);
roadie_test_assert(
	false !== strpos( $pr_cmd, "--head 'roadie/foo'" ),
	'gh pr create must pass --head'
);
roadie_test_assert(
	false === strpos( $pr_cmd, 'GITHUB_TOKEN' ),
	'gh pr create must NOT reference GITHUB_TOKEN env var'
);

// --- redact_token ------------------------------------------------------
$leaky      = 'remote: error pushing to https://x-access-token:ghs_TEST_TOKEN@github.com/...';
$redacted   = $tool->call_redact_token( $leaky, 'ghs_TEST_TOKEN' );
roadie_test_assert(
	false === strpos( $redacted, 'ghs_TEST_TOKEN' ),
	'redact_token must strip the token from output'
);
roadie_test_assert(
	false !== strpos( $redacted, '[REDACTED]' ),
	'redact_token must insert [REDACTED] placeholder'
);
roadie_test_assert(
	'no token here' === $tool->call_redact_token( 'no token here', '' ),
	'redact_token must no-op on empty token'
);

// --- Resolver WP_Error short-circuits the apply flow ------------------
//
// We exercise the full handle_tool_call path with a missing artifact to
// hit the resolver_is_configured() gate first. Then we drive apply_to_mount
// directly via a focused harness to test the WP_Error short-circuit on a
// per-repo resolver call.

$tool_block         = new ECRoadie_TestableApplyCodeChange();
$tool_block->is_configured = false;
// User cap stub.
$GLOBALS['extrachill_roadie_test_state']['user_caps'][ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] = true;
$GLOBALS['extrachill_roadie_test_state']['current_user_id'] = 7;
$blocked = $tool_block->handle_tool_call( array( 'artifact_id' => 'whatever' ) );
roadie_test_assert(
	false === $blocked['success'],
	'apply must fail when resolver_is_configured() returns false'
);
roadie_test_assert(
	false !== strpos( $blocked['error'] ?? '', 'credentials are not configured' ),
	'apply error must reference credential profile system'
);
roadie_test_assert(
	empty( $tool_block->captured_commands ),
	'no shell commands must run when resolver is not configured'
);

// --- Resolver WP_Error path (configured but resolve() returns WP_Error) -
$resolver_error_tool = new ECRoadie_TestableApplyCodeChange();
$resolver_error_tool->is_configured  = true;
$resolver_error_tool->resolve_return = new WP_Error( 'github_app_token_exchange_failed', 'simulated upstream 401' );

// Drive apply_to_mount via reflection to bypass artifact-bundle loading.
$reflect = new ReflectionMethod( ECRoadie_TestableApplyCodeChange::class, 'apply_to_mount' );
$reflect->setAccessible( true );

$entry = array(
	'mount_index'   => 0,
	'mount'         => array(
		'mode'     => 'readwrite',
		'target'   => '/wordpress/wp-content/plugins/foo',
		'metadata' => array(
			'slug'           => 'foo',
			'repo'           => 'Extra-Chill/foo',
			'default_branch' => 'main',
		),
	),
	'changed_paths' => array( 'file.php' ),
);
$bundle = array(
	'id'           => 'artifact-foo',
	'_bundle_dir'  => sys_get_temp_dir(),
	'_patch_path'  => sys_get_temp_dir() . '/patch.diff',
	'context'      => array( 'proposer_user_id' => 1 ),
);

// extrachill_roadie_workspace_root() must exist; ensure primary clone path
// "exists" by pointing the helper at a real dir if needed.
if ( ! function_exists( 'extrachill_roadie_workspace_root' ) ) {
	function extrachill_roadie_workspace_root(): string {
		return sys_get_temp_dir() . '/ec-roadie-test-workspace';
	}
}
@mkdir( extrachill_roadie_workspace_root() . '/foo', 0700, true );

$result = $reflect->invoke( $resolver_error_tool, $entry, 'artifact-foo', '', $bundle );

roadie_test_assert(
	false === $result['success'],
	'apply_to_mount must fail when resolver returns WP_Error'
);
roadie_test_assert(
	false !== strpos( $result['error'] ?? '', 'GitHub credential resolution failed' ),
	'apply_to_mount error must explain the resolver failure'
);
roadie_test_assert(
	false !== strpos( $result['error'] ?? '', 'simulated upstream 401' ),
	'apply_to_mount error must include the underlying resolver message'
);
roadie_test_assert(
	empty( $resolver_error_tool->captured_commands ),
	'no shell commands must run after the resolver fails'
);

// --- Resolver is called with the repo selector -----------------------
roadie_test_assert(
	! empty( $resolver_error_tool->captured_resolve_calls ),
	'resolve_github_credential() must be invoked'
);
roadie_test_assert(
	'Extra-Chill/foo' === $resolver_error_tool->captured_resolve_calls[0],
	'resolve_github_credential() must receive the per-repo selector'
);

// --- Static check: no putenv('GITHUB_TOKEN'... in the apply source ----
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Smoke test runs outside WordPress; WP_Filesystem is not available.
$apply_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/tools/class-apply-code-change.php' );
roadie_test_assert(
	false === strpos( $apply_source, "putenv( 'GITHUB_TOKEN" ),
	'class-apply-code-change.php must not putenv() GITHUB_TOKEN (issue #22)'
);
roadie_test_assert(
	false === strpos( $apply_source, 'putenv("GITHUB_TOKEN' ),
	'class-apply-code-change.php must not putenv() GITHUB_TOKEN (double-quoted)'
);
roadie_test_assert(
	false === strpos( $apply_source, "getenv( 'GITHUB_TOKEN" ),
	'class-apply-code-change.php must not getenv(GITHUB_TOKEN) (issue #22)'
);

echo "contribute-code apply-auth smoke passed.\n";
