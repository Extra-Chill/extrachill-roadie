<?php
/**
 * Smoke test that the propose_code_change tool registers via
 * `datamachine_tools` filter when the BaseTool class is available.
 *
 * Run with: php tests/contribute-code-tool-registration.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// Provide a stub BaseTool so the tool class can be loaded.
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
$tool = new ECRoadie_ProposeCodeChange();

roadie_test_assert(
	isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools']['propose_code_change'] ),
	'propose_code_change must register via registerTool()'
);

$registration = $GLOBALS['extrachill_roadie_test_state']['registered_tools']['propose_code_change'];
roadie_test_assert(
	in_array( 'chat', $registration['modes'], true ),
	'tool must register for chat mode'
);
roadie_test_assert(
	'authenticated' === ( $registration['meta']['access_level'] ?? '' ),
	'tool must require authenticated access_level'
);

$definition = $tool->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $definition['method'], 'method must be handle_tool_call' );
roadie_test_assert(
	in_array( 'task_description', $definition['parameters']['required'] ?? array(), true ),
	'task_description must be a required parameter'
);
roadie_test_assert(
	isset( $definition['parameters']['properties']['task_description']['type'] )
		&& 'string' === $definition['parameters']['properties']['task_description']['type'],
	'task_description must be typed as string'
);

// --- Capability enforcement: contributor blocked -----------------------
roadie_test_reset_filters();
$GLOBALS['extrachill_roadie_test_state']['user_caps'] = array();
$result_blocked = $tool->handle_tool_call( array( 'task_description' => 'add a typo fix' ) );
roadie_test_assert(
	false === $result_blocked['success'],
	'capability check must block users without extrachill_propose_code'
);
roadie_test_assert(
	false !== strpos( $result_blocked['error'] ?? '', 'permission' ),
	'error message must mention permission'
);

// --- Empty task_description -------------------------------------------
$GLOBALS['extrachill_roadie_test_state']['user_caps'][ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] = true;
$result_empty = $tool->handle_tool_call( array( 'task_description' => '' ) );
roadie_test_assert(
	false === $result_empty['success'],
	'empty task_description must fail'
);
roadie_test_assert(
	false !== strpos( $result_empty['error'] ?? '', 'task_description' ),
	'error must mention task_description'
);

// --- Missing wp_get_ability surfaces a useful error -------------------
// wp_get_ability is intentionally undefined in this bootstrap.
$result_no_ability = $tool->handle_tool_call( array( 'task_description' => 'do a thing' ) );
roadie_test_assert(
	false === $result_no_ability['success'],
	'missing abilities API must surface an error'
);

echo "contribute-code tool-registration smoke passed.\n";
