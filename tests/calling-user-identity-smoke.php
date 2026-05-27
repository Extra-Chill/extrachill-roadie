<?php
/**
 * Smoke test: calling-user identity propagation through Roadie tools.
 *
 * Verifies that manage_user_profile defaults to acting on calling_user_id
 * when no explicit user_id is provided, and that the resolved user reaches
 * the cross-site REST helper unchanged.
 *
 * Run with: php tests/calling-user-identity-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';

require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-user-profile.php';

function ec_roadie_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// --- Scenario 1: calling_user_id present, no explicit user_id ---
// Tool should default to the calling user. Cross-site REST gets user_id=38.
ec_roadie_test_reset();
ec_roadie_test_login_as( 38 );
$tool = new ECRoadie_ManageUserProfile();

$result = $tool->handle_tool_call(
	array(
		'action'          => 'update',
		'bio'             => 'New bio from chat session.',
		'calling_user_id' => 38, // What the DM loop merges into $parameters.
	)
);

ec_roadie_smoke_assert( ( $result['success'] ?? false ) === true, 'Update should succeed when calling user acts on self.' );

$calls = $GLOBALS['ec_roadie_test_rest_calls'];
ec_roadie_smoke_assert( 1 === count( $calls ), 'Exactly one REST call should be made.' );
ec_roadie_smoke_assert( '/users/me/profile' === $calls[0]['path'], 'Call should target /users/me/profile.' );
ec_roadie_smoke_assert( 38 === $calls[0]['effective_user'], 'REST call should authenticate as calling user #38, not site admin.' );
ec_roadie_smoke_assert( 38 === ( $calls[0]['args']['user_id'] ?? 0 ), 'user_id arg must be forwarded into ec_cross_site_rest_request.' );

// --- Scenario 2: admin caller targets another user via explicit user_id ---
ec_roadie_test_reset();
ec_roadie_test_login_as( 1 );
ec_roadie_test_grant_cap( 1, 'manage_options' );

$result = $tool->handle_tool_call(
	array(
		'action'          => 'update',
		'bio'             => 'Admin updates another user.',
		'user_id'         => 99,
		'calling_user_id' => 1,
	)
);

ec_roadie_smoke_assert( ( $result['success'] ?? false ) === true, 'Admin acting on another user should be allowed.' );

$calls = $GLOBALS['ec_roadie_test_rest_calls'];
ec_roadie_smoke_assert( 1 === count( $calls ), 'Exactly one REST call should be made.' );
ec_roadie_smoke_assert( 99 === ( $calls[0]['args']['user_id'] ?? 0 ), 'Explicit user_id override should reach the cross-site helper.' );

// --- Scenario 3: calling user falls back to current user when payload is silent ---
// REST-initiated invocation with no chat loop — should still resolve to current user.
ec_roadie_test_reset();
ec_roadie_test_login_as( 42 );

$result = $tool->handle_tool_call(
	array(
		'action' => 'get',
		// No calling_user_id and no user_id — REST/CLI context.
	)
);

ec_roadie_smoke_assert( ( $result['success'] ?? false ) === true, 'Get should succeed with current-user fallback.' );

$calls = $GLOBALS['ec_roadie_test_rest_calls'];
ec_roadie_smoke_assert( 42 === ( $calls[0]['args']['user_id'] ?? 0 ), 'Fallback to current user (#42) should reach the cross-site helper.' );

// --- Scenario 4: no user at all → clean denial, no REST call ---
ec_roadie_test_reset();
ec_roadie_test_login_as( 0 );

$result = $tool->handle_tool_call(
	array(
		'action' => 'update',
		'bio'    => 'Anonymous attempt.',
	)
);

ec_roadie_smoke_assert( false === ( $result['success'] ?? true ), 'Anonymous caller should be denied.' );
ec_roadie_smoke_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'Denied call must not reach REST.' );
ec_roadie_smoke_assert( str_contains( $result['error'] ?? '', 'No user context' ), 'Denial message should explain the missing user context.' );

echo "Roadie calling-user identity smoke passed (12 assertions).\n";
