<?php

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Roadie E2E requires multisite.' );
}

$host = wp_parse_url( network_home_url( '/' ), PHP_URL_HOST );
if ( ! is_string( $host ) || '' === $host ) {
	throw new RuntimeException( 'Could not resolve the fixture network host.' );
}

$sites         = array( 'main' => get_main_site_id() );
$site_topology = array(
	'community'       => 2,
	'shop'            => 3,
	'artist'          => 4,
	'placeholder-five' => 5,
	'placeholder-six' => 6,
	'events'          => 7,
);
foreach ( $site_topology as $site_key => $expected_id ) {
	$path     = '/' . $site_key . '/';
	$existing = get_sites( array( 'domain' => $host, 'path' => $path, 'number' => 1 ) );
	$site_id  = $existing ? (int) $existing[0]->blog_id : wpmu_create_blog( $host, $path, 'Roadie ' . ucfirst( $site_key ), 1 );
	if ( is_wp_error( $site_id ) || ! $site_id ) {
		throw new RuntimeException( 'Could not create the ' . $site_key . ' fixture site.' );
	}
	if ( $expected_id !== (int) $site_id ) {
		throw new RuntimeException( sprintf( 'Expected %s to use canonical blog ID %d; got %d.', $site_key, $expected_id, $site_id ) );
	}
	if ( in_array( $site_key, array( 'community', 'artist', 'events' ), true ) ) {
		$sites[ $site_key ] = (int) $site_id;
	}
}
update_site_option( 'roadie_e2e_sites', $sites );

$owner_id = username_exists( 'roadie_owner' );
if ( ! $owner_id ) {
	$owner_id = wp_create_user( 'roadie_owner', 'roadie-owner-password', 'roadie-owner@example.test' );
}
$stranger_id = username_exists( 'roadie_stranger' );
if ( ! $stranger_id ) {
	$stranger_id = wp_create_user( 'roadie_stranger', 'roadie-stranger-password', 'roadie-stranger@example.test' );
}
if ( is_wp_error( $owner_id ) || is_wp_error( $stranger_id ) ) {
	throw new RuntimeException( 'Could not create Roadie fixture users.' );
}

foreach ( $sites as $site_id ) {
	add_user_to_blog( $site_id, (int) $owner_id, 'extra_chill_team' );
	add_user_to_blog( $site_id, (int) $stranger_id, 'extra_chill_team' );
	switch_to_blog( $site_id );
	( new WP_User( (int) $owner_id ) )->set_role( 'extra_chill_team' );
	( new WP_User( (int) $stranger_id ) )->set_role( 'extra_chill_team' );
	update_option( 'permalink_structure', '/%postname%/' );
	$site_key = array_search( $site_id, $sites, true );
	update_option( 'blogname', 'Roadie ' . ucfirst( (string) $site_key ) );
	flush_rewrite_rules();
	restore_current_blog();
}

wp_set_current_user( 1 );
$agents = new \DataMachine\Core\Database\Agents\Agents();
$roadie = $agents->get_by_slug( 'roadie' );
if ( ! $roadie ) {
	$created_agent = \DataMachine\Abilities\AgentAbilities::createAgent(
		array(
			'agent_slug' => 'roadie',
			'agent_name' => 'Roadie',
			'owner_id'   => (int) $owner_id,
			'site_scope' => null,
			'config'     => array(
				'default_provider' => 'roadie-e2e',
				'default_model'    => 'roadie-e2e-model',
			),
		)
	);
	if ( empty( $created_agent['success'] ) ) {
		throw new RuntimeException( 'Could not create the Roadie fixture agent: ' . ( $created_agent['error'] ?? 'unknown error' ) );
	}
} else {
	$config                     = is_array( $roadie['agent_config'] ?? null ) ? $roadie['agent_config'] : array();
	$config['default_provider'] = 'roadie-e2e';
	$config['default_model']    = 'roadie-e2e-model';
	$agents->update_agent( (int) $roadie['agent_id'], array( 'agent_config' => $config ) );
}

update_site_option(
	'frontend_agent_chat_config',
	array(
		'enabled'    => true,
		'agent_slug' => 'roadie',
		'layout'     => 'floating',
	)
);
update_site_option( 'roadie_e2e_pending_apply_count', 0 );

switch_to_blog( $sites['artist'] );
$artist_id = wp_insert_post(
	array(
		'post_type'    => 'artist_profile',
		'post_status'  => 'publish',
		'post_title'   => 'Roadie Canonical Artist',
		'post_content' => 'Initial artist profile content.',
		'post_author'  => (int) $owner_id,
	),
	true
);
restore_current_blog();
if ( is_wp_error( $artist_id ) ) {
	throw new RuntimeException( $artist_id->get_error_message() );
}
if ( ! ec_add_artist_membership( (int) $owner_id, (int) $artist_id ) ) {
	$failure = function_exists( 'ec_get_artist_membership_failure' ) ? ec_get_artist_membership_failure() : null;
	throw new RuntimeException( $failure instanceof WP_Error ? $failure->get_error_message() : 'Could not create reciprocal membership.' );
}

switch_to_blog( $sites['main'] );
if ( ! taxonomy_exists( 'artist' ) ) {
	throw new RuntimeException( 'The product-owned artist taxonomy is unavailable. The verified Extra Chill theme must be mounted and active; the Roadie fixture must not register it.' );
}
$canonical = wp_insert_term( 'Roadie Canonical Artist', 'artist', array( 'slug' => 'roadie-canonical-artist' ) );
if ( is_wp_error( $canonical ) ) {
	throw new RuntimeException( $canonical->get_error_message() );
}
update_term_meta( $canonical['term_id'], '_artist_profile_id', (int) $artist_id );
restore_current_blog();

switch_to_blog( $sites['events'] );
if ( ! taxonomy_exists( 'artist' ) || ! taxonomy_exists( 'location' ) ) {
	throw new RuntimeException( 'The product-owned artist/location taxonomies are unavailable on Events. The verified Extra Chill theme must be mounted and active.' );
}
if ( ! is_object_in_taxonomy( DATA_MACHINE_EVENTS_POST_TYPE, 'artist' ) || ! is_object_in_taxonomy( DATA_MACHINE_EVENTS_POST_TYPE, 'location' ) ) {
	throw new RuntimeException( 'Extra Chill Events did not attach the product-owned artist/location taxonomies to the event post type.' );
}
$local_artist = wp_insert_term( 'Different Local Slug', 'artist', array( 'slug' => 'deliberately-not-canonical' ) );
$location     = wp_insert_term( 'Charleston', 'location', array( 'slug' => 'charleston' ) );
$venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue(
	'The Royal American',
	array(
		'address'     => '970 Morrison Dr',
		'city'        => 'Charleston',
		'state'       => 'SC',
		'zip'         => '29403',
		'country'     => 'US',
		'coordinates' => '32.8007,-79.9362',
		'timezone'    => 'America/New_York',
		'website'     => 'https://theroyalamerican.com',
	)
);
if ( is_wp_error( $local_artist ) || is_wp_error( $location ) || empty( $venue_result['term_id'] ) ) {
	throw new RuntimeException( 'Could not seed canonical event taxonomy data.' );
}

$event_id = wp_insert_post(
	array(
		'post_type'    => DATA_MACHINE_EVENTS_POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Roadie Artist Journey Show',
		'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2030-07-21","startTime":"20:30:00","endDate":"2030-07-21","endTime":"23:00:00","venue":"The Royal American","ticketUrl":"https://tickets.example.test/roadie-show?ref=e2e"} /-->',
		'post_author'  => 1,
	),
	true
);
if ( is_wp_error( $event_id ) ) {
	throw new RuntimeException( $event_id->get_error_message() );
}
$event_artist_terms   = wp_set_object_terms( $event_id, array( (int) $local_artist['term_id'] ), 'artist' );
$event_location_terms = wp_set_object_terms( $event_id, array( (int) $location['term_id'] ), 'location' );
$event_venue_terms    = wp_set_object_terms( $event_id, array( (int) $venue_result['term_id'] ), 'venue' );
if ( is_wp_error( $event_artist_terms ) || is_wp_error( $event_location_terms ) || is_wp_error( $event_venue_terms ) ) {
	throw new RuntimeException( 'Could not attach product-owned taxonomies to the seeded event.' );
}
\DataMachineEvents\Core\EventDatesTable::create_table();
if ( ! \DataMachineEvents\Core\EventDatesTable::upsert( $event_id, '2030-07-21 20:30:00', '2030-07-21 23:00:00', 'publish' ) ) {
	throw new RuntimeException( 'Could not persist the seeded event occurrence.' );
}
restore_current_blog();

switch_to_blog( $sites['main'] );
update_term_meta( $canonical['term_id'], EXTRACHILL_EVENTS_ARTIST_TERM_META, (int) $local_artist['term_id'] );
restore_current_blog();

update_site_option(
	'roadie_e2e_fixture',
	array(
		'sites'             => $sites,
		'owner_id'          => (int) $owner_id,
		'stranger_id'       => (int) $stranger_id,
		'artist_id'         => (int) $artist_id,
		'artist_term_id'    => (int) $canonical['term_id'],
		'events_artist_id'  => (int) $local_artist['term_id'],
		'event_id'          => (int) $event_id,
		'venue_id'          => (int) $venue_result['term_id'],
	)
);
