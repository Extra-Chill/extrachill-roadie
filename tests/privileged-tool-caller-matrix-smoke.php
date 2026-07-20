<?php
/**
 * Regression matrix for authoritative caller checks on privileged tools.
 *
 * Run with: php tests/privileged-tool-caller-matrix-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/contribute-code-bootstrap.php';
require_once __DIR__ . '/_stub-github-credential-resolver.php';

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

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return (object) array(
			'user_login'   => 'user-' . $user_id,
			'display_name' => 'User ' . $user_id,
		);
	}
}

require_once dirname( __DIR__ ) . '/inc/tools/class-propose-code-change.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-apply-code-change.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-file-feature-request.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-code.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-page.php';

class ECRoadie_TestCallerInspectCode extends ECRoadie_InspectCode {
	public function authorize( array $parameters ) {
		return $this->check_team_capability( $parameters );
	}
}

class ECRoadie_TestCallerInspectPage extends ECRoadie_InspectPage {
	public function authorize( array $parameters ) {
		return $this->check_team_capability( $parameters );
	}

	public function cookie_header( int $calling_user_id ): string {
		return $this->caller_cookie_header( $calling_user_id );
	}
}

class ECRoadie_TestCallerFeatureRequest extends ECRoadie_FileFeatureRequest {
	public function attribution( array $parameters ): string {
		return $this->augment_body_with_attribution( 'Body', $parameters );
	}
}

$propose      = new ECRoadie_ProposeCodeChange();
$apply        = new ECRoadie_ApplyCodeChange();
$feature      = new ECRoadie_TestCallerFeatureRequest();
$inspect      = new ECRoadie_TestCallerInspectCode();
$inspect_page = new ECRoadie_TestCallerInspectPage();

$runtime_owner = 900;
$delegated     = 102;
$unrelated     = 101;
$revoked       = 103;

$GLOBALS['extrachill_roadie_test_state']['current_user_id'] = $runtime_owner;
$GLOBALS['extrachill_roadie_test_state']['caps_by_user']     = array(
	$runtime_owner => array(
		EXTRACHILL_ROADIE_PROPOSE_CODE_CAP => true,
		'access_roadie'                    => true,
		'manage_options'                   => true,
	),
	$delegated => array(
		EXTRACHILL_ROADIE_PROPOSE_CODE_CAP => true,
		'access_roadie'                    => true,
	),
	$unrelated => array(),
	$revoked   => array(),
);

$assert_permission_denied = static function ( array $result, string $label ): void {
	roadie_test_assert( false === ( $result['success'] ?? true ), $label . ' must fail.' );
	roadie_test_assert( false !== stripos( (string) ( $result['error'] ?? '' ), 'permission' ), $label . ' must fail at the caller capability gate.' );
};

$run_privileged_matrix = static function ( int $caller, string $label ) use ( $propose, $apply, $feature, $inspect, $inspect_page, $assert_permission_denied ): void {
	$parameters = array( 'calling_user_id' => $caller );
	$assert_permission_denied( $propose->handle_tool_call( $parameters + array( 'task_description' => 'Change code.' ) ), $label . ' propose_code_change' );
	$assert_permission_denied( $apply->handle_tool_call( $parameters + array( 'artifact_id' => 'artifact-id' ) ), $label . ' apply_code_change' );
	$assert_permission_denied( $feature->handle_tool_call( $parameters + array( 'action' => 'file_issue' ) ), $label . ' file_feature_request' );
	roadie_test_assert( true !== $inspect->authorize( $parameters ), $label . ' inspect_code must fail.' );
	roadie_test_assert( true !== $inspect_page->authorize( $parameters ), $label . ' inspect_page must fail.' );
};

// A privileged runtime owner must never elevate an unrelated, revoked, or
// explicitly absent acting caller.
$run_privileged_matrix( $unrelated, 'unrelated caller' );
$run_privileged_matrix( $revoked, 'revoked caller' );
$run_privileged_matrix( 0, 'zero caller' );

// A delegated caller is authorized as themselves, independently of the more
// privileged runtime owner. Benign validation failures prove each capability
// gate passed without triggering side effects.
$delegated_parameters = array( 'calling_user_id' => $delegated );
$propose_result = $propose->handle_tool_call( $delegated_parameters + array( 'task_description' => '' ) );
$apply_result   = $apply->handle_tool_call( $delegated_parameters + array( 'artifact_id' => '' ) );
$feature_result = $feature->handle_tool_call( $delegated_parameters + array( 'action' => 'invalid' ) );
roadie_test_assert( false === stripos( (string) ( $propose_result['error'] ?? '' ), 'permission' ), 'delegated caller should pass propose capability authorization.' );
roadie_test_assert( false === stripos( (string) ( $apply_result['error'] ?? '' ), 'permission' ), 'delegated caller should pass apply capability authorization.' );
roadie_test_assert( false === stripos( (string) ( $feature_result['error'] ?? '' ), 'permission' ), 'delegated caller should pass feature-request capability authorization.' );
roadie_test_assert( true === $inspect->authorize( $delegated_parameters ), 'delegated caller should pass inspect_code authorization as themselves.' );
roadie_test_assert( true === $inspect_page->authorize( $delegated_parameters ), 'delegated caller should pass inspect_page authorization as themselves.' );

$attribution = $feature->attribution( $delegated_parameters );
roadie_test_assert( false !== strpos( $attribution, 'WP user #102' ), 'feature-request attribution must name the delegated caller.' );
roadie_test_assert( false === strpos( $attribution, 'WP user #900' ), 'feature-request attribution must not name the runtime owner.' );
$zero_attribution = $feature->attribution( array( 'calling_user_id' => 0 ) );
roadie_test_assert( false === strpos( $zero_attribution, 'WP user #900' ), 'zero-caller attribution must not fall back to the runtime owner.' );

$_COOKIE['wordpress_logged_in_test'] = 'runtime-owner-cookie';
roadie_test_assert( '' === $inspect_page->cookie_header( $delegated ), 'delegated inspect_page must not forward the runtime owner cookie.' );
roadie_test_assert( '' !== $inspect_page->cookie_header( $runtime_owner ), 'same-user inspect_page may forward its own WordPress cookie.' );

$propose_source = file_get_contents( dirname( __DIR__ ) . '/inc/tools/class-propose-code-change.php' );
roadie_test_assert( false !== strpos( (string) $propose_source, "'proposer_user_id' => \$calling_user_id" ), 'sandbox attribution must use the resolved acting caller.' );

echo "Roadie privileged tool caller matrix smoke passed.\n";
