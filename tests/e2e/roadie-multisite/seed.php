<?php

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Roadie E2E requires multisite.' );
}

$host = wp_parse_url( network_home_url( '/' ), PHP_URL_HOST );
if ( ! is_string( $host ) || '' === $host ) {
	throw new RuntimeException( 'Could not resolve the fixture network host.' );
}

$sites = array( 'main' => get_main_site_id() );
foreach ( array( 'artist', 'events', 'community' ) as $site_key ) {
	$path     = '/' . $site_key . '/';
	$existing = get_sites( array( 'domain' => $host, 'path' => $path, 'number' => 1 ) );
	$site_id  = $existing ? (int) $existing[0]->blog_id : wpmu_create_blog( $host, $path, 'Roadie ' . ucfirst( $site_key ), 1 );
	if ( is_wp_error( $site_id ) || ! $site_id ) {
		throw new RuntimeException( 'Could not create the ' . $site_key . ' fixture site.' );
	}
	$sites[ $site_key ] = (int) $site_id;
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
	add_user_to_blog( $site_id, (int) $owner_id, 'author' );
	add_user_to_blog( $site_id, (int) $stranger_id, 'subscriber' );
	switch_to_blog( $site_id );
	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules();
	restore_current_blog();
}

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
register_taxonomy( 'artist', 'post', array( 'show_in_rest' => true ) );
$canonical = wp_insert_term( 'Roadie Canonical Artist', 'artist', array( 'slug' => 'roadie-canonical-artist' ) );
if ( is_wp_error( $canonical ) ) {
	throw new RuntimeException( $canonical->get_error_message() );
}
update_term_meta( $canonical['term_id'], '_artist_profile_id', (int) $artist_id );
restore_current_blog();

switch_to_blog( $sites['events'] );
register_taxonomy( 'artist', DATA_MACHINE_EVENTS_POST_TYPE, array( 'show_in_rest' => true ) );
register_taxonomy( 'location', DATA_MACHINE_EVENTS_POST_TYPE, array( 'hierarchical' => true, 'show_in_rest' => true ) );
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
wp_set_object_terms( $event_id, array( (int) $local_artist['term_id'] ), 'artist' );
wp_set_object_terms( $event_id, array( (int) $location['term_id'] ), 'location' );
wp_set_object_terms( $event_id, array( (int) $venue_result['term_id'] ), 'venue' );
\DataMachineEvents\Core\EventDatesTable::create_table();
\DataMachineEvents\Core\EventDatesTable::upsert( $event_id, '2030-07-21 20:30:00', '2030-07-21 23:00:00', 'publish' );
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
