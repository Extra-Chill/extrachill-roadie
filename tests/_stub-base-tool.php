<?php
/**
 * Test stub for \DataMachine\Engine\AI\Tools\BaseTool.
 *
 * Implements the minimal surface ECRoadie_PlatformTool depends on:
 *   - registerTool()             — recorded into $GLOBALS['ec_roadie_test_registered_tools'].
 *   - buildErrorResponse()       — standardized error array.
 *   - buildDiagnosticErrorResponse() — error array with diagnostic/remediation.
 *   - classifyErrorType()        — keyword-based classification.
 *
 * Tests can read $GLOBALS['ec_roadie_test_registered_tools'] to confirm
 * registration happened, or just exercise tool methods directly to verify
 * the calling_user_id contract.
 *
 * @package ExtraChillRoadie\Tests
 */

namespace DataMachine\Engine\AI\Tools;

abstract class BaseTool {
	protected bool $async = false;

	protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {
		$GLOBALS['ec_roadie_test_registered_tools'][ $toolName ] = array(
			'definition' => $toolDefinition,
			'modes'      => $modes,
			'meta'       => $meta,
		);
	}

	protected function buildErrorResponse( string $error, string $tool_name ): array {
		return array(
			'success'    => false,
			'error'      => $error,
			'error_type' => $this->classifyErrorType( $error ),
			'tool_name'  => $tool_name,
		);
	}

	protected function buildDiagnosticErrorResponse(
		string $error,
		string $error_type,
		string $tool_name,
		array $diagnostic = array(),
		array $remediation = array()
	): array {
		$response = array(
			'success'    => false,
			'error'      => $error,
			'error_type' => $error_type,
			'tool_name'  => $tool_name,
		);
		if ( ! empty( $diagnostic ) ) {
			$response['diagnostic'] = $diagnostic;
		}
		if ( ! empty( $remediation ) ) {
			$response['remediation'] = $remediation;
		}
		return $response;
	}

	protected function classifyErrorType( string $error ): string {
		$lower = strtolower( $error );
		if ( str_contains( $lower, 'not found' ) || str_contains( $lower, 'does not exist' ) ) {
			return 'not_found';
		}
		if ( str_contains( $lower, 'required' ) || str_contains( $lower, 'invalid' ) ) {
			return 'validation';
		}
		if ( str_contains( $lower, 'permission' ) || str_contains( $lower, 'denied' ) || str_contains( $lower, 'unauthorized' ) ) {
			return 'permission';
		}
		return 'system';
	}
}
