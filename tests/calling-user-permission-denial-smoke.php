<?php
/**
 * Smoke test: non-admin caller trying to act on another user's behalf
 * gets a clean permission denial (no stack trace, no silent success).
 *
 * Exercises every tool that accepts user_id (artist profile, link page,
 * user profile, community) to confirm the assert_acting_user_allowed()
 * guard in ECRoadie_PlatformTool refuses cross-user impersonation by
 * non-admins.
 *
 * Run with: php tests/calling-user-permission-denial-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';

require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-artist-profile.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-link-page.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-user-profile.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-community.php';

function ec_roadie_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Drive a tool call, assert that it returned a clean permission denial,
 * and confirm no cross-site REST call was made.
 */
function ec_roadie_assert_denied( array $result, string $context ): void {
	ec_roadie_smoke_assert( is_array( $result ), $context . ': result must be an array (not a stack trace).' );
	ec_roadie_smoke_assert( false === ( $result['success'] ?? true ), $context . ': result should signal failure.' );
	ec_roadie_smoke_assert( isset( $result['error'] ), $context . ': result must include an error message.' );
	ec_roadie_smoke_assert( 'permission' === ( $result['error_type'] ?? '' ), $context . ': error_type should be permission.' );
	ec_roadie_smoke_assert(
		str_contains( strtolower( $result['error'] ), 'permission denied' ),
		$context . ': error message should say "Permission denied".'
	);
	ec_roadie_smoke_assert(
		0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ),
		$context . ': denied call must not reach the cross-site REST helper.'
	);
}

// Non-admin caller (#38) tries to act on user #99 across every tool.
$non_admin = 38;
$victim    = 99;

// --- manage_user_profile ---
ec_roadie_test_reset();
ec_roadie_test_login_as( $non_admin );

$tool   = new ECRoadie_ManageUserProfile();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'update',
		'bio'             => 'Impersonation attempt.',
		'user_id'         => $victim,
		'calling_user_id' => $non_admin,
	)
);
ec_roadie_assert_denied( $result, 'manage_user_profile non-admin impersonation' );

// --- manage_artist_profile ---
ec_roadie_test_reset();
ec_roadie_test_login_as( $non_admin );

$tool   = new ECRoadie_ManageArtistProfile();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'create',
		'name'            => 'Hijacked Artist',
		'user_id'         => $victim,
		'calling_user_id' => $non_admin,
	)
);
ec_roadie_assert_denied( $result, 'manage_artist_profile non-admin impersonation' );

// --- manage_link_page ---
ec_roadie_test_reset();
ec_roadie_test_login_as( $non_admin );

$tool   = new ECRoadie_ManageLinkPage();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'get',
		'artist_id'       => 123,
		'user_id'         => $victim,
		'calling_user_id' => $non_admin,
	)
);
ec_roadie_assert_denied( $result, 'manage_link_page non-admin impersonation' );

// --- manage_community ---
ec_roadie_test_reset();
ec_roadie_test_login_as( $non_admin );

$tool   = new ECRoadie_ManageCommunity();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'create_reply',
		'topic_id'        => 456,
		'content'         => 'Posted as someone else.',
		'user_id'         => $victim,
		'calling_user_id' => $non_admin,
	)
);
ec_roadie_assert_denied( $result, 'manage_community non-admin impersonation' );

// --- Sanity: admin caller IS allowed to act on another user ---
ec_roadie_test_reset();
ec_roadie_test_login_as( 1 );
ec_roadie_test_grant_cap( 1, 'manage_options' );

$tool   = new ECRoadie_ManageUserProfile();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'update',
		'bio'             => 'Legitimate admin edit.',
		'user_id'         => $victim,
		'calling_user_id' => 1,
	)
);
ec_roadie_smoke_assert( ( $result['success'] ?? false ) === true, 'Admin should be allowed to act on another user.' );

// --- Sanity: read-only community actions don't require user context ---
ec_roadie_test_reset();
ec_roadie_test_login_as( 0 );

$tool   = new ECRoadie_ManageCommunity();
$result = $tool->handle_tool_call( array( 'action' => 'list_forums' ) );
ec_roadie_smoke_assert(
	( $result['success'] ?? false ) === true,
	'list_forums should succeed without a user context (public read).'
);

echo "Roadie calling-user permission denial smoke passed (28 assertions).\n";
