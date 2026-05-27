<?php
/**
 * Smoke test that propose_code_change AND apply_code_change register via the
 * `datamachine_tools` filter when the BaseTool class is available.
 *
 * Run with: php tests/contribute-code-tool-registration.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';
require_once __DIR__ . '/_stub-github-credential-resolver.php';

// WP_Error stub for the apply-tool error-path assertions below.
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

// Provide a stub BaseTool so the tool classes can be loaded.
if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {
				$GLOBALS["extrachill_roadie_test_state"]["registered_tools"][ $toolName ] = array(
					"definition" => $toolDefinition,
					"modes"      => $modes,
					"meta"       => $meta,
				);
			}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "tool_name" => $tool_name );
			}
			protected function buildDiagnosticErrorResponse( string $error, string $type, string $tool_name, array $a = array(), array $b = array() ): array {
				return array( "success" => false, "error" => $error, "type" => $type, "tool_name" => $tool_name );
			}
		}'
	);
}

$GLOBALS['extrachill_roadie_test_state']['registered_tools'] = array();

require_once dirname( __DIR__ ) . '/inc/tools/class-propose-code-change.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-apply-code-change.php';
$propose = new ECRoadie_ProposeCodeChange();
$apply   = new ECRoadie_ApplyCodeChange();

// --- Both tools register ------------------------------------------------
foreach ( array( 'propose_code_change', 'apply_code_change' ) as $slug ) {
	roadie_test_assert(
		isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools'][ $slug ] ),
		$slug . ' must register via registerTool()'
	);
	$reg = $GLOBALS['extrachill_roadie_test_state']['registered_tools'][ $slug ];
	roadie_test_assert(
		in_array( 'chat', $reg['modes'], true ),
		$slug . ' must register for chat mode'
	);
	roadie_test_assert(
		'authenticated' === ( $reg['meta']['access_level'] ?? '' ),
		$slug . ' must require authenticated access_level'
	);
}

// --- propose_code_change definition ------------------------------------
$propose_def = $propose->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $propose_def['method'], 'propose method' );
roadie_test_assert(
	in_array( 'task_description', $propose_def['parameters']['required'] ?? array(), true ),
	'propose: task_description required'
);

// --- apply_code_change definition ---------------------------------------
$apply_def = $apply->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $apply_def['method'], 'apply method' );
roadie_test_assert(
	in_array( 'artifact_id', $apply_def['parameters']['required'] ?? array(), true ),
	'apply: artifact_id required'
);

// --- Capability enforcement: contributor blocked on propose ------------
roadie_test_reset_filters();
$GLOBALS['extrachill_roadie_test_state']['user_caps'] = array();
$result_blocked = $propose->handle_tool_call( array( 'task_description' => 'add a typo fix' ) );
roadie_test_assert(
	false === $result_blocked['success'],
	'propose capability check must block users without extrachill_propose_code'
);
roadie_test_assert(
	false !== strpos( $result_blocked['error'] ?? '', 'permission' ),
	'propose error must mention permission'
);

// --- Capability enforcement: contributor blocked on apply ---------------
$result_apply_blocked = $apply->handle_tool_call( array( 'artifact_id' => 'artifact-bundle-foo' ) );
roadie_test_assert(
	false === $result_apply_blocked['success'],
	'apply capability check must block users without extrachill_propose_code'
);

// --- Empty inputs ------------------------------------------------------
$GLOBALS['extrachill_roadie_test_state']['user_caps'][ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] = true;

$result_empty = $propose->handle_tool_call( array( 'task_description' => '' ) );
roadie_test_assert(
	false === $result_empty['success'],
	'empty propose task_description must fail'
);

$result_empty_id = $apply->handle_tool_call( array( 'artifact_id' => '' ) );
roadie_test_assert(
	false === $result_empty_id['success'],
	'empty apply artifact_id must fail'
);

// --- Apply: resolver-not-configured fails with clear error --------------
// Issue #22: apply-back gates on GitHubCredentialResolver::isConfigured()
// rather than a process-env GITHUB_TOKEN check.
\DataMachineCode\Support\GitHubCredentialResolver::$test_is_configured = false;
$result_no_token = $apply->handle_tool_call( array( 'artifact_id' => 'artifact-bundle-foo' ) );
roadie_test_assert(
	false === $result_no_token['success'],
	'apply must fail when GitHubCredentialResolver::isConfigured() is false'
);
roadie_test_assert(
	false !== strpos( $result_no_token['error'] ?? '', 'credentials are not configured' )
		|| false !== strpos( $result_no_token['error'] ?? '', 'datamachine-code github status' ),
	'apply error must reference the credential profile system, not a GITHUB_TOKEN env var'
);
roadie_test_assert(
	false === strpos( $result_no_token['error'] ?? '', 'GITHUB_TOKEN' ),
	'apply error must NOT mention GITHUB_TOKEN env var (issue #22)'
);
\DataMachineCode\Support\GitHubCredentialResolver::ec_roadie_test_reset();

// --- Propose: missing wp_get_ability surfaces a useful error -----------
$result_no_ability = $propose->handle_tool_call( array( 'task_description' => 'do a thing' ) );
roadie_test_assert(
	false === $result_no_ability['success'],
	'missing abilities API must surface an error'
);

echo "contribute-code tool-registration smoke passed.\n";
