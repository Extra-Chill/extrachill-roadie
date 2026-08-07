<?php
/** Regression coverage for canonical artist ownership and ability dispatch. */

declare(strict_types=1);

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}

require_once dirname( __DIR__ ) . '/inc/owner-capabilities.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-artist-profile.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-link-page.php';

function roadie_owner_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function roadie_owner_artist( int $id, string $name ): array {
	return array(
		'id'                => $id,
		'name'              => $name,
		'slug'              => strtolower( str_replace( ' ', '-', $name ) ),
		'profile_image_url' => null,
	);
}

function roadie_owner_link_page( int $artist_id ): array {
	return array(
		'artist_id'   => $artist_id,
		'link_page_id' => 900 + $artist_id,
		'links'       => array(),
		'css_vars'    => array(),
		'socials'     => array(),
		'settings'    => array(),
	);
}

$artist_ability_names = array(
	'extrachill/get-artist-data',
	'extrachill/create-artist',
	'extrachill/update-artist',
);

ec_roadie_test_reset();
ec_roadie_test_login_as( 42 );
$GLOBALS['ec_roadie_test_rest_response'] = static function ( string $site_key, string $method, string $path, array $args ) use ( $artist_ability_names ): array {
	unset( $args );
	roadie_owner_assert( 'artist' === $site_key, 'Capability discovery must target the Artist site.' );
	roadie_owner_assert( 'GET' === $method, 'Capability discovery must use GET.' );
	roadie_owner_assert( '/wp-abilities/v1/abilities' === $path, 'Capability discovery must use the core collection route.' );
	return array_map(
		static fn( string $name ): array => array( 'name' => $name, 'category' => 'extrachill-artist-platform' ),
		$artist_ability_names
	);
};

$GLOBALS['ec_roadie_test_registered_tools'] = array();
new ECRoadie_ManageArtistProfile();
new ECRoadie_ManageLinkPage();
$available_tools = extrachill_roadie_filter_owner_tools( $GLOBALS['ec_roadie_test_registered_tools'] );
roadie_owner_assert( isset( $available_tools['manage_artist_profile'] ), 'Artist tool should be advertised when every owner ability is discoverable.' );
roadie_owner_assert( ! isset( $available_tools['manage_link_page'] ), 'Link-page tool must not be advertised when one or more required abilities are unavailable.' );

unset( $GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] );
$available_tools = extrachill_roadie_filter_owner_tools( $GLOBALS['ec_roadie_test_registered_tools'] );
roadie_owner_assert( array() === $available_tools, 'Owner-backed tools must not be advertised without the Users owner ability.' );
$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] = array();

roadie_owner_assert(
	array() === extrachill_roadie_index_artist_abilities( array( array( 'name' => 123, 'category' => 'extrachill-artist-platform' ) ) ),
	'Malformed discovery responses must not advertise capabilities.'
);

$artist_tool = new ECRoadie_ManageArtistProfile();
$link_tool   = new ECRoadie_ManageLinkPage();

// A stale raw metadata value must not restore access filtered by the owner.
ec_roadie_test_set_user_meta( 42, '_artist_profile_ids', array( 999 ) );
$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] = array();
$GLOBALS['ec_roadie_test_rest_calls'] = array();
$result = $artist_tool->handle_tool_call( array( 'action' => 'list', 'calling_user_id' => 42 ) );
roadie_owner_assert( false === ( $result['success'] ?? true ), 'Stale membership should resolve as no artists.' );
roadie_owner_assert( array() === $GLOBALS['ec_roadie_test_rest_calls'], 'Membership resolution must not read artist data independently.' );

// Multiple valid artists come directly from the owner response for disambiguation.
$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] = array(
	roadie_owner_artist( 10, 'First Artist' ),
	roadie_owner_artist( 20, 'Second Artist' ),
);
$result = $link_tool->handle_tool_call( array( 'action' => 'get', 'calling_user_id' => 42 ) );
roadie_owner_assert( false === ( $result['success'] ?? true ), 'Multiple artists should require disambiguation.' );
roadie_owner_assert( 2 === count( $result['data']['artists'] ?? array() ), 'Disambiguation must preserve every owner-returned artist.' );

// Malformed membership rows fail closed.
$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] = array( array( 'id' => 10, 'name' => 'Missing contract fields' ) );
$result = $artist_tool->handle_tool_call( array( 'action' => 'list', 'calling_user_id' => 42 ) );
roadie_owner_assert( false === ( $result['success'] ?? true ), 'Malformed owner membership responses must fail.' );
roadie_owner_assert( str_contains( $result['error'] ?? '', 'invalid response' ), 'Malformed membership should report its contract failure.' );

$GLOBALS['ec_roadie_test_ability_results']['extrachill/get-user-artists'] = array( roadie_owner_artist( 10, 'First Artist' ) );
$GLOBALS['ec_roadie_test_rest_response'] = static function ( string $site_key, string $method, string $path, array $args ) {
	roadie_owner_assert( 'artist' === $site_key, 'Operations must target the Artist site.' );
	if ( str_contains( $path, 'get-artist-data' ) ) {
		roadie_owner_assert( 'GET' === $method && 10 === ( $args['query']['input']['artist_id'] ?? 0 ), 'Read-only artist ability must use GET query input.' );
		return array_merge( roadie_owner_artist( 10, 'First Artist' ), array( 'bio' => '' ) );
	}
	if ( str_contains( $path, 'create-artist' ) || str_contains( $path, 'update-artist' ) ) {
		roadie_owner_assert( 'POST' === $method && isset( $args['body']['input'] ), 'Artist writes must use POST ability input.' );
		return array_merge( roadie_owner_artist( 10, 'First Artist' ), array( 'bio' => '' ) );
	}
	if ( str_contains( $path, 'get-link-page-data' ) ) {
		roadie_owner_assert( 'GET' === $method && 10 === ( $args['query']['input']['artist_id'] ?? 0 ), 'Read-only link-page ability must use GET query input.' );
		return $GLOBALS['ec_roadie_test_link_page'] ?? roadie_owner_link_page( 10 );
	}
	if ( str_contains( $path, 'save-social-links' ) ) {
		roadie_owner_assert( isset( $args['body']['input']['social_links'] ), 'Social writes must use the owner social_links field.' );
		return array( 'social_links' => $args['body']['input']['social_links'] );
	}
	if ( str_contains( $path, 'save-link-page-' ) ) {
		roadie_owner_assert( 'POST' === $method && isset( $args['body']['input']['artist_id'] ), 'Link-page writes must use POST ability input.' );
		return roadie_owner_link_page( 10 );
	}
	return array();
};

foreach ( array( 'get', 'create', 'update' ) as $action ) {
	$parameters = array( 'action' => $action, 'calling_user_id' => 42 );
	if ( 'create' === $action ) {
		$parameters['name'] = 'First Artist';
	} elseif ( 'update' === $action ) {
		$parameters['bio'] = 'Updated';
	}
	$result = $artist_tool->handle_tool_call( $parameters );
	roadie_owner_assert( true === ( $result['success'] ?? false ), "Artist {$action} should succeed with a valid owner response." );
}
$result = $artist_tool->handle_tool_call( array( 'action' => 'list', 'calling_user_id' => 42 ) );
roadie_owner_assert( true === ( $result['success'] ?? false ) && 1 === ( $result['data']['count'] ?? 0 ), 'Artist list should return the validated owner membership.' );

$link_operations = array(
	array( 'action' => 'get' ),
	array( 'action' => 'save_links', 'links' => array() ),
	array( 'action' => 'save_socials', 'socials' => array( array( 'type' => 'website', 'url' => 'https://example.com' ) ) ),
	array( 'action' => 'save_styles', 'css_vars' => array( '--link-page-text-color' => '#fff' ) ),
	array( 'action' => 'save_settings', 'settings' => array( 'redirect_enabled' => false ) ),
);
foreach ( $link_operations as $parameters ) {
	$parameters['calling_user_id'] = 42;
	$result = $link_tool->handle_tool_call( $parameters );
	roadie_owner_assert( true === ( $result['success'] ?? false ), "Link-page {$parameters['action']} should succeed with a valid owner response." );
}

$result = $link_tool->handle_tool_call( array( 'action' => 'add_link', 'url' => 'https://example.com/new', 'text' => 'New', 'calling_user_id' => 42 ) );
roadie_owner_assert( true === ( $result['success'] ?? false ), 'Link-page add_link should complete through get and save abilities.' );

$GLOBALS['ec_roadie_test_link_page']          = roadie_owner_link_page( 10 );
$GLOBALS['ec_roadie_test_link_page']['links'] = array(
	array(
		'section_title' => '',
		'links'         => array( array( 'id' => 'remove-me', 'link_text' => 'Old', 'link_url' => 'https://example.com/old' ) ),
	),
);
$result = $link_tool->handle_tool_call( array( 'action' => 'remove_link', 'link_id' => 'remove-me', 'calling_user_id' => 42 ) );
roadie_owner_assert( true === ( $result['success'] ?? false ), 'Link-page remove_link should complete through get and save abilities.' );
unset( $GLOBALS['ec_roadie_test_link_page'] );

$GLOBALS['ec_roadie_test_rest_response'] = array( 'id' => 10, 'name' => 'Missing slug' );
$result = $artist_tool->handle_tool_call( array( 'action' => 'get', 'artist_id' => 10, 'calling_user_id' => 42 ) );
roadie_owner_assert( false === ( $result['success'] ?? true ), 'Malformed Artist operation responses must fail closed.' );

$GLOBALS['ec_roadie_test_rest_response'] = array( 'artist_id' => 10, 'link_page_id' => 910 );
$result = $link_tool->handle_tool_call( array( 'action' => 'get', 'artist_id' => 10, 'calling_user_id' => 42 ) );
roadie_owner_assert( false === ( $result['success'] ?? true ), 'Malformed link-page responses must fail closed.' );

$artist_source = file_get_contents( dirname( __DIR__ ) . '/inc/tools/class-manage-artist-profile.php' );
$link_source   = file_get_contents( dirname( __DIR__ ) . '/inc/tools/class-manage-link-page.php' );
roadie_owner_assert( ! str_contains( (string) $artist_source, '_artist_profile_ids' ), 'Artist tool must not access raw membership metadata.' );
roadie_owner_assert( ! str_contains( (string) $link_source, '_artist_profile_ids' ), 'Link-page tool must not access raw membership metadata.' );

echo "Artist owner capability smoke passed.\n";
