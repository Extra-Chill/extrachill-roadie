<?php
/** Smoke tests for canonical Local Scene user tooling. */

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-user-profile.php';

function ec_roadie_local_scene_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

ec_roadie_test_reset();
ec_roadie_test_login_as( 38 );
$tool = new ECRoadie_ManageUserProfile();

$result = $tool->handle_tool_call(
	array(
		'action'                 => 'update',
		'local_scene'            => 'charleston-sc',
		'local_scene_visibility' => 'private',
		'calling_user_id'        => 38,
	)
);
ec_roadie_local_scene_assert( true === ( $result['success'] ?? false ), 'Canonical Local Scene update should succeed.' );
ec_roadie_local_scene_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'Local Scene updates must not use the legacy profile REST route.' );
$call = $GLOBALS['ec_roadie_test_ability_calls'][0];
ec_roadie_local_scene_assert( 'extrachill/update-user-settings' === $call['name'], 'Update must use the Users settings Ability.' );
ec_roadie_local_scene_assert( array( 'local_scene' => 'charleston-sc', 'local_scene_visibility' => 'private' ) === $call['input'], 'Canonical scene and visibility must be forwarded unchanged.' );
ec_roadie_local_scene_assert( 38 === $call['effective_user'], 'Ability must execute as the acting user.' );
ec_roadie_local_scene_assert( 38 === get_current_user_id(), 'Original user context must be restored.' );

ec_roadie_test_reset();
ec_roadie_test_login_as( 38 );
$tool->handle_tool_call( array( 'action' => 'update', 'local_city' => 'nashville-tn', 'calling_user_id' => 38 ) );
$call = $GLOBALS['ec_roadie_test_ability_calls'][0];
ec_roadie_local_scene_assert( array( 'local_scene' => 'nashville-tn' ) === $call['input'], 'Legacy local_city must alias to canonical local_scene.' );

ec_roadie_test_reset();
ec_roadie_test_login_as( 38 );
$GLOBALS['ec_roadie_test_rest_response'] = array(
	'local_city'  => 'Secret City',
	'local_scene' => array( 'slug' => 'secret-city' ),
);
$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-settings'] = array(
	'local_scene'            => array( 'slug' => 'secret-city' ),
	'local_scene_visibility' => 'private',
);
$result = $tool->handle_tool_call( array( 'action' => 'get', 'calling_user_id' => 38 ) );
ec_roadie_local_scene_assert( 'private' === $result['data']['local_scene_visibility'], 'Read output must expose the visibility preference.' );
ec_roadie_local_scene_assert( null === $result['data']['local_scene'], 'Private canonical scene must be redacted.' );
ec_roadie_local_scene_assert( '' === $result['data']['local_city'], 'Private legacy city must be redacted.' );

echo "Roadie Local Scene smoke passed (10 assertions).\n";
