<?php

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Engine\AI\Actions\PendingActionHelper;

$fixture = get_site_option( 'roadie_e2e_fixture', array() );
$sites   = $fixture['sites'] ?? array();
$owner   = (int) ( $fixture['owner_id'] ?? 0 );
$stranger = (int) ( $fixture['stranger_id'] ?? 0 );
$artist  = (int) ( $fixture['artist_id'] ?? 0 );
$passes  = 0;

function roadie_e2e_assert( $condition, string $message ): void {
	global $passes;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$passes;
}

function roadie_e2e_ability( string $name, array $input ) {
	$ability = wp_get_ability( $name );
	roadie_e2e_assert( $ability instanceof WP_Ability, $name . ' is not registered.' );
	return $ability->execute( $input );
}

function roadie_e2e_rest( string $method, string $route, array $params, int $user_id ): WP_REST_Response {
	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $params );
	$response = rest_do_request( $request );
	return rest_ensure_response( $response );
}

function roadie_e2e_stage_artist_action( int $user_id, int $artist_id, int $artist_blog_id, string $marker ): array {
	wp_set_current_user( $user_id );
	return PendingActionHelper::stage(
		array(
			'kind'          => 'roadie_e2e_artist_action',
			'summary'       => 'Exercise Roadie artist authorization at the stored origin.',
			'apply_input'   => array(
				'artist_id'      => $artist_id,
				'artist_blog_id' => $artist_blog_id,
				'marker'         => $marker,
			),
			'preview_data'  => array( 'artist_id' => $artist_id, 'marker' => $marker ),
			'user_id'       => $user_id,
			'authorization' => array(
				'operation' => 'roadie_e2e_artist_action',
				'target'    => array( 'artist_id' => $artist_id ),
			),
		)
	);
}

function roadie_e2e_pending_origin( array $staged ): array {
	return array(
		'workspace' => $staged['payload']['workspace'] ?? array(),
		'metadata'  => $staged['payload']['metadata'] ?? array(),
	);
}

roadie_e2e_assert( count( $sites ) === 4, 'Main, Artist, Events, and Community sites were not seeded.' );
roadie_e2e_assert( $owner > 0 && $stranger > 0 && $artist > 0, 'Fixture identities are incomplete.' );

// One network user ID and one reciprocal artist membership must survive every site context.
foreach ( $sites as $site_key => $site_id ) {
	switch_to_blog( (int) $site_id );
	$current_owner = get_user_by( 'login', 'roadie_owner' );
	roadie_e2e_assert( $current_owner && $owner === (int) $current_owner->ID, 'Shared owner identity changed on ' . $site_key . '.' );
	roadie_e2e_assert( is_user_member_of_blog( $owner, (int) $site_id ), 'Owner is not a member of ' . $site_key . '.' );
	restore_current_blog();
}
roadie_e2e_assert( in_array( $artist, array_map( 'intval', (array) get_user_meta( $owner, '_artist_profile_ids', true ) ), true ), 'User-side artist membership is missing.' );
switch_to_blog( (int) $sites['artist'] );
roadie_e2e_assert( in_array( $owner, array_map( 'intval', (array) get_post_meta( $artist, '_artist_member_ids', true ) ), true ), 'Artist-side membership is missing.' );
roadie_e2e_assert( ec_user_can_manage_artist_object( $owner, $artist ), 'Reciprocal owner cannot manage the artist.' );
roadie_e2e_assert( ! ec_user_can_manage_artist_object( $stranger, $artist ), 'Unrelated user can manage the artist.' );

// Exercise WordPress core's real CPT REST, revision, and autosave controllers.
$created = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile',
	array( 'title' => 'REST-created Roadie Artist', 'content' => 'Created over REST.', 'status' => 'publish' ),
	1
);
roadie_e2e_assert( 201 === $created->get_status(), 'Administrator could not create artist_profile over REST.' );
$created_id = (int) ( $created->get_data()['id'] ?? 0 );
roadie_e2e_assert( $created_id > 0 && ec_add_artist_membership( $owner, $created_id ), 'REST-created artist could not be assigned reciprocally.' );

$updated = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile/' . $artist,
	array( 'content' => 'Owner-updated artist profile content.' ),
	$owner
);
roadie_e2e_assert( 200 === $updated->get_status(), 'Reciprocal artist owner could not update over REST.' );

$denied = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile/' . $artist,
	array( 'content' => 'Forged stranger update.' ),
	$stranger
);
roadie_e2e_assert( 403 === $denied->get_status(), 'Unrelated user artist update did not fail with 403.' );

$autosave = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile/' . $artist . '/autosaves',
	array( 'content' => 'Owner autosave content.' ),
	$owner
);
roadie_e2e_assert( in_array( $autosave->get_status(), array( 200, 201 ), true ), 'Artist owner could not create an autosave.' );
$revision_id = (int) ( $autosave->get_data()['id'] ?? 0 );
roadie_e2e_assert( $revision_id > 0, 'Artist autosave returned no revision ID.' );

$revisions = roadie_e2e_rest( 'GET', '/wp/v2/artist_profile/' . $artist . '/revisions', array(), $owner );
roadie_e2e_assert( 200 === $revisions->get_status() && count( (array) $revisions->get_data() ) > 0, 'Artist owner could not read revisions.' );

$autosave_denied = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile/' . $artist . '/autosaves',
	array( 'content' => 'Stranger autosave content.' ),
	$stranger
);
roadie_e2e_assert( 403 === $autosave_denied->get_status(), 'Unrelated user autosave did not fail with 403.' );
$revision_denied = roadie_e2e_rest( 'DELETE', '/wp/v2/artist_profile/' . $artist . '/revisions/' . $revision_id, array(), $stranger );
roadie_e2e_assert( 403 === $revision_denied->get_status(), 'Unrelated user revision delete did not fail with 403.' );
$deleted = roadie_e2e_rest( 'DELETE', '/wp/v2/artist_profile/' . $created_id, array( 'force' => true ), $owner );
roadie_e2e_assert( 200 === $deleted->get_status(), 'Reciprocal artist owner could not delete over REST.' );
restore_current_blog();

// Create and continue one canonical conversation through FAC's real agents/chat route.
switch_to_blog( (int) $sites['main'] );
wp_set_current_user( $owner );
$roadie_agent_id = extrachill_roadie_get_agent_id();
$access_diagnostics = array(
	'access_roadie'       => user_can( $owner, 'access_roadie' ),
	'roadie_agent_id'     => $roadie_agent_id,
	'host_filter'         => (bool) apply_filters( 'datamachine_can_access_agent', false, $roadie_agent_id, $owner, 'viewer' ),
	'canonical_access'    => WP_Agent_Access::can_current_principal_access_agent( 'roadie', WP_Agent_Access_Grant::ROLE_VIEWER ),
	'bridge_filter_count' => has_filter( 'wp_agent_can_access_agent' ),
);
roadie_e2e_assert(
	$access_diagnostics['access_roadie'] && $access_diagnostics['host_filter'] && $access_diagnostics['canonical_access'],
	'Roadie team access did not reach the canonical agent gate: ' . wp_json_encode( $access_diagnostics )
);
$chat_permission_diagnostics = array();
$canonical_filter_diagnostics = array();
add_filter(
	'wp_agent_can_access_agent',
	static function ( $allowed, $principal, $agent_id, $minimum_role ) use ( &$canonical_filter_diagnostics ) {
		$canonical_filter_diagnostics = array(
			'allowed'        => (bool) $allowed,
			'acting_user_id' => (int) ( $principal->acting_user_id ?? 0 ),
			'agent_id'       => (string) $agent_id,
			'minimum_role'   => (string) $minimum_role,
		);
		return $allowed;
	},
	PHP_INT_MAX,
	4
);
add_filter(
	'agents_chat_permission',
	static function ( bool $allowed, array $input ) use ( &$chat_permission_diagnostics, &$canonical_filter_diagnostics ): bool {
		$identity  = ( new AgentIdentityResolver() )->resolve_agent_identity( (string) ( $input['agent'] ?? '' ) );
		$principal = WP_Agent_Access::get_current_principal();
		$chat_permission_diagnostics = array(
			'allowed'                    => $allowed,
			'current_user_id'            => get_current_user_id(),
			'agent'                      => $input['agent'] ?? null,
			'input_principal'            => $input['principal'] ?? null,
			'current_principal_user_id'  => (int) ( $principal->acting_user_id ?? 0 ),
			'resolved_agent_slug'        => $identity->agent_slug,
			'canonical_access_at_filter' => WP_Agent_Access::can_current_principal_access_agent( $identity->agent_slug, WP_Agent_Access_Grant::ROLE_VIEWER ),
			'canonical_filter'           => $canonical_filter_diagnostics,
		);
		return $allowed;
	},
	PHP_INT_MAX,
	2
);
$first_turn = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat',
	array(
		'agent'      => 'roadie',
		'message'    => 'First deterministic Roadie message.',
		'page_url'   => get_home_url( (int) $sites['main'], '/' ),
		'page_title' => 'Roadie Main',
	),
	$owner
);
roadie_e2e_assert(
	200 === $first_turn->get_status(),
	'FAC could not create the Roadie conversation through agents/chat: ' . wp_json_encode(
		array(
			'response'   => $first_turn->get_data(),
			'permission' => $chat_permission_diagnostics,
		)
	)
);
$first_data   = (array) ( $first_turn->get_data()['data'] ?? array() );
$session_id  = (string) ( $first_data['session_id'] ?? '' );
$first_run_id = (string) ( $first_data['run_id'] ?? '' );
roadie_e2e_assert( '' !== $session_id && '' !== $first_run_id, 'FAC chat returned no session or run ID.' );
roadie_e2e_assert( 'Deterministic Roadie initial reply.' === ( $first_data['response'] ?? '' ), 'FAC did not return the deterministic production-path reply.' );
restore_current_blog();

switch_to_blog( (int) $sites['artist'] );
$second_turn = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat',
	array(
		'agent'      => 'roadie',
		'message'    => 'Second deterministic Roadie message.',
		'session_id' => $session_id,
		'page_url'   => get_home_url( (int) $sites['artist'], '/' ),
		'page_title' => 'Roadie Artist Platform',
	),
	$owner
);
roadie_e2e_assert(
	200 === $second_turn->get_status(),
	'FAC could not continue the conversation through agents/chat: ' . wp_json_encode( $second_turn->get_data() )
);
$second_data = (array) ( $second_turn->get_data()['data'] ?? array() );
roadie_e2e_assert( $session_id === (string) ( $second_data['session_id'] ?? '' ), 'FAC continuation changed the canonical session ID.' );
roadie_e2e_assert( 'Deterministic Roadie continuation reply.' === ( $second_data['response'] ?? '' ), 'FAC continuation bypassed the deterministic production runtime.' );
$conversation_text = wp_json_encode( $second_data['conversation'] ?? array() );
roadie_e2e_assert( str_contains( (string) $conversation_text, 'First deterministic Roadie message.' ), 'Continuation lost the first user turn.' );
roadie_e2e_assert( str_contains( (string) $conversation_text, 'Second deterministic Roadie message.' ), 'Continuation did not persist the second user turn.' );

$listed_response = roadie_e2e_rest( 'GET', '/frontend-agent-chat/v1/chat/sessions', array( 'agent' => 'roadie' ), $owner );
$listed          = (array) ( $listed_response->get_data()['data'] ?? array() );
roadie_e2e_assert( 200 === $listed_response->get_status(), 'Artist site could not list FAC sessions.' );
roadie_e2e_assert( $session_id === (string) ( $listed['sessions'][0]['id'] ?? $listed['sessions'][0]['session_id'] ?? '' ), 'Artist site did not list the canonical conversation.' );
restore_current_blog();

switch_to_blog( (int) $sites['community'] );
$loaded_response = roadie_e2e_rest( 'GET', '/frontend-agent-chat/v1/chat/' . $session_id, array( 'agent' => 'roadie' ), $owner );
roadie_e2e_assert( 200 === $loaded_response->get_status(), 'Community site did not read the canonical conversation through FAC.' );
$loaded_data = (array) ( $loaded_response->get_data()['data'] ?? array() );
roadie_e2e_assert( $session_id === (string) ( $loaded_data['session_id'] ?? '' ), 'FAC returned the wrong canonical conversation.' );
roadie_e2e_assert( str_contains( (string) wp_json_encode( $loaded_data['conversation'] ?? array() ), 'Second deterministic Roadie message.' ), 'FAC session read lost the continued turn.' );
$foreign_read = roadie_e2e_rest( 'GET', '/frontend-agent-chat/v1/chat/' . $session_id, array( 'agent' => 'roadie' ), $stranger );
roadie_e2e_assert( 403 === $foreign_read->get_status() || 404 === $foreign_read->get_status(), 'Unrelated user read another owner\'s conversation.' );
restore_current_blog();

switch_to_blog( (int) $sites['events'] );
$titled_response = roadie_e2e_rest( 'POST', '/frontend-agent-chat/v1/chat/' . $session_id . '/title', array( 'agent' => 'roadie', 'title' => 'Roadie Network Journey' ), $owner );
roadie_e2e_assert( 200 === $titled_response->get_status() && 'Roadie Network Journey' === ( $titled_response->get_data()['data']['title'] ?? '' ), 'Events site could not title the canonical conversation through FAC.' );
restore_current_blog();

switch_to_blog( (int) $sites['main'] );
$retitled_response = roadie_e2e_rest( 'GET', '/frontend-agent-chat/v1/chat/sessions', array( 'agent' => 'roadie' ), $owner );
$retitled_sessions = (array) ( $retitled_response->get_data()['data']['sessions'] ?? array() );
$retitled_session  = current( array_filter( $retitled_sessions, static fn( array $session ): bool => $session_id === (string) ( $session['id'] ?? $session['session_id'] ?? '' ) ) );
roadie_e2e_assert( is_array( $retitled_session ) && 'Roadie Network Journey' === ( $retitled_session['title'] ?? '' ), 'Session title did not persist back to the main site.' );
restore_current_blog();

// The production route created the run under Roadie's network workspace and user owner.
$store = ConversationStoreFactory::get();
$workspace = \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_array( extrachill_roadie_conversation_workspace() );
$owner_tuple = array( 'type' => 'user', 'key' => (string) $owner );
$production_run = WP_Agent_Chat_Run_Control::get_run( $first_run_id, $workspace, $owner_tuple );
roadie_e2e_assert( is_array( $production_run ) && $session_id === ( $production_run['session_id'] ?? '' ), 'FAC production run was not bound to Roadie workspace and owner.' );
$foreign_production_run = WP_Agent_Chat_Run_Control::get_run( $first_run_id, $workspace, array( 'type' => 'user', 'key' => (string) $stranger ) );
roadie_e2e_assert( null === $foreign_production_run, 'Foreign owner could inspect the FAC production run.' );

// Exercise FAC's queue route against an active run bound to that production session.
$run_id = WP_Agent_Chat_Run_Control::generate_run_id();
$run = WP_Agent_Chat_Run_Control::start_run( $run_id, $session_id, array( 'source' => 'roadie-e2e' ), $workspace, $owner_tuple, $store );
roadie_e2e_assert( ! is_wp_error( $run ) && 'running' === ( $run['status'] ?? '' ), 'Owner-bound run could not start.' );

switch_to_blog( (int) $sites['community'] );
$queued_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/queue',
	array(
		'agent'        => 'roadie',
		'session_id'   => $session_id,
		'run_id'       => $run_id,
		'message'      => 'Queue this owned follow-up.',
	),
	$owner
);
roadie_e2e_assert( 200 === $queued_response->get_status() && ! empty( $queued_response->get_data()['data']['queued_message_id'] ), 'FAC owner queue request failed.' );
$foreign_queued = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/queue',
	array(
		'agent'         => 'roadie',
		'session_id'    => $session_id,
		'run_id'        => $run_id,
		'message'       => 'Poison another owner\'s queue.',
	),
	$stranger
);
roadie_e2e_assert( 403 === $foreign_queued->get_status() || 404 === $foreign_queued->get_status(), 'Foreign owner poisoned the conversation queue.' );
restore_current_blog();

// Resolve through FAC so Roadie validates the untrusted origin before Data Machine routes it.
switch_to_blog( (int) $sites['events'] );
$staged = roadie_e2e_stage_artist_action( $owner, $artist, (int) $sites['artist'], 'stored-origin' );
roadie_e2e_assert( ! empty( $staged['staged'] ), 'Pending action could not be staged on Events.' );
$origin = roadie_e2e_pending_origin( $staged );
restore_current_blog();

switch_to_blog( (int) $sites['community'] );
$resolved_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/actions/resolve',
	array( 'action_id' => $staged['action_id'], 'decision' => 'accepted', 'origin' => $origin ),
	$owner
);
roadie_e2e_assert(
	200 === $resolved_response->get_status(),
	'Stored-origin pending action did not resolve through FAC: ' . wp_json_encode( $resolved_response->get_data() )
);
$resolved = (array) ( $resolved_response->get_data()['data'] ?? array() );
roadie_e2e_assert(
	$staged['action_id'] === ( $resolved['action_id'] ?? '' )
		&& 'accepted' === ( $resolved['decision'] ?? '' )
		&& (int) $sites['events'] === (int) ( $resolved['result']['result']['blog_id'] ?? 0 ),
	'Pending action did not execute at its stored Events origin: ' . wp_json_encode( $resolved )
);
restore_current_blog();

switch_to_blog( (int) $sites['events'] );
roadie_e2e_assert( 'stored-origin' === ( get_option( 'roadie_e2e_resolved_action', array() )['marker'] ?? '' ), 'Pending handler did not execute at the Events origin.' );
$forged        = roadie_e2e_stage_artist_action( $owner, $artist, (int) $sites['artist'], 'forged-origin-must-not-run' );
$forged_origin = roadie_e2e_pending_origin( $forged );
restore_current_blog();

$forged_origin['workspace']['workspace_id'] = get_home_url( (int) $sites['main'] );
$forged_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/actions/resolve',
	array( 'action_id' => $forged['action_id'], 'decision' => 'accepted', 'origin' => $forged_origin ),
	$owner
);
$forged_data = (array) ( $forged_response->get_data()['data'] ?? array() );
roadie_e2e_assert( 200 === $forged_response->get_status() && 'Pending action origin could not be verified.' === ( $forged_data['result']['error'] ?? '' ), 'Forged site origin did not reach Roadie/Data Machine origin denial.' );

switch_to_blog( (int) $sites['events'] );
$foreign_network        = roadie_e2e_stage_artist_action( $owner, $artist, (int) $sites['artist'], 'foreign-network-must-not-run' );
$foreign_network_origin = roadie_e2e_pending_origin( $foreign_network );
$foreign_network_origin['workspace'] = array( 'workspace_type' => 'network', 'workspace_id' => '999' );
restore_current_blog();
$foreign_network_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/actions/resolve',
	array( 'action_id' => $foreign_network['action_id'], 'decision' => 'accepted', 'origin' => $foreign_network_origin ),
	$owner
);
$foreign_network_data = (array) ( $foreign_network_response->get_data()['data'] ?? array() );
roadie_e2e_assert( 200 === $foreign_network_response->get_status() && 'Pending action origin could not be verified.' === ( $foreign_network_data['result']['error'] ?? '' ), 'Foreign-network workspace did not reach Roadie/Data Machine origin denial.' );

// Owner scope denies another team member before handler execution.
switch_to_blog( (int) $sites['events'] );
$foreign_owner = roadie_e2e_stage_artist_action( $owner, $artist, (int) $sites['artist'], 'foreign-owner-must-not-run' );
$foreign_owner_origin = roadie_e2e_pending_origin( $foreign_owner );
restore_current_blog();
$foreign_owner_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/actions/resolve',
	array( 'action_id' => $foreign_owner['action_id'], 'decision' => 'accepted', 'origin' => $foreign_owner_origin ),
	$stranger
);
$foreign_owner_data = (array) ( $foreign_owner_response->get_data()['data'] ?? array() );
roadie_e2e_assert( 200 === $foreign_owner_response->get_status() && 'You do not have permission to resolve this pending action.' === ( $foreign_owner_data['result']['error'] ?? '' ), 'Unrelated user did not reach pending-action owner denial.' );

// A stranger-owned action reaches the handler and fails the real artist object capability.
switch_to_blog( (int) $sites['events'] );
$forbidden_artist = roadie_e2e_stage_artist_action( $stranger, $artist, (int) $sites['artist'], 'artist-capability-must-not-run' );
$forbidden_artist_origin = roadie_e2e_pending_origin( $forbidden_artist );
restore_current_blog();
$forbidden_artist_response = roadie_e2e_rest(
	'POST',
	'/frontend-agent-chat/v1/chat/actions/resolve',
	array( 'action_id' => $forbidden_artist['action_id'], 'decision' => 'accepted', 'origin' => $forbidden_artist_origin ),
	$stranger
);
$forbidden_artist_data = (array) ( $forbidden_artist_response->get_data()['data'] ?? array() );
roadie_e2e_assert( 200 === $forbidden_artist_response->get_status() && 'Resolver cannot edit the fixture artist.' === ( $forbidden_artist_data['result']['error'] ?? '' ), 'Unrelated user did not reach the real artist object-capability denial.' );
roadie_e2e_assert( 1 === (int) get_site_option( 'roadie_e2e_pending_apply_count', 0 ), 'A denied pending action reached the deterministic apply handler.' );

// Canonical artist mapping must use the stored term ID, not the deliberately different slug.
switch_to_blog( (int) $sites['main'] );
$events_by_artist = roadie_e2e_ability(
	'extrachill-events/events-by-artist',
	array( 'artist_term_id' => (int) $fixture['artist_term_id'], 'scope' => 'upcoming' )
);
roadie_e2e_assert( ! is_wp_error( $events_by_artist ), 'Canonical artist-to-Events adapter failed.' );
roadie_e2e_assert( (int) $fixture['events_artist_id'] === (int) ( $events_by_artist['term_id'] ?? 0 ), 'Artist mapping did not preserve the canonical local term ID.' );
roadie_e2e_assert( 'deliberately-not-canonical' === ( $events_by_artist['term_slug'] ?? '' ), 'Artist mapping unexpectedly fell back to the canonical slug.' );
roadie_e2e_assert( (int) $fixture['event_id'] === (int) ( $events_by_artist['upcoming'][0]['event_id'] ?? 0 ), 'Mapped artist did not return its intended event.' );
restore_current_blog();

// Product adapters must preserve producer-owned venue and occurrence fields.
switch_to_blog( (int) $sites['events'] );
$venue_term = get_term( (int) $fixture['venue_id'], 'venue' );
roadie_e2e_assert( $venue_term instanceof WP_Term, 'Seeded venue term is unavailable on Events.' );
$venue = roadie_e2e_ability( 'extrachill/events-get-venue', array( 'id' => (int) $fixture['venue_id'] ) );
roadie_e2e_assert(
	! is_wp_error( $venue ),
	'Venue detail adapter failed: ' . ( is_wp_error( $venue ) ? $venue->get_error_code() . ': ' . $venue->get_error_message() : wp_json_encode( $venue ) )
);
foreach ( array( 'id', 'name', 'slug', 'address', 'city', 'state', 'zip', 'country', 'coordinates', 'timezone', 'website' ) as $field ) {
	roadie_e2e_assert( ! empty( $venue[ $field ] ), 'Venue adapter lost ' . $field . '.' );
}

$calendar = roadie_e2e_ability( 'extrachill/events-calendar', array( 'page' => 1 ) );
roadie_e2e_assert( ! is_wp_error( $calendar ), 'Event calendar adapter failed.' );
$calendar_event = null;
foreach ( $calendar['dates'] ?? array() as $date ) {
	foreach ( $date['events'] ?? array() as $event ) {
		if ( (int) ( $event['id'] ?? 0 ) === (int) $fixture['event_id'] ) {
			$calendar_event = $event;
		}
	}
}
roadie_e2e_assert( is_array( $calendar_event ), 'Seeded event was absent from the calendar adapter.' );
foreach ( array( 'id', 'title', 'datetime', 'end_datetime', 'venue', 'taxonomies', 'occurrence_display', 'ticket_url', 'permalink' ) as $field ) {
	roadie_e2e_assert( array_key_exists( $field, $calendar_event ) && null !== $calendar_event[ $field ], 'Event adapter lost ' . $field . '.' );
}
roadie_e2e_assert( ! empty( $calendar_event['venue'] ) && str_contains( (string) wp_json_encode( $calendar_event['venue'] ), 'The Royal American' ), 'Event adapter lost the seeded venue identity.' );
$taxonomy_evidence = (string) wp_json_encode( $calendar_event['taxonomies'] );
roadie_e2e_assert( str_contains( $taxonomy_evidence, 'Charleston' ), 'Event adapter lost seeded location taxonomy data.' );
roadie_e2e_assert( '' !== trim( (string) $calendar_event['occurrence_display'] ), 'Event adapter returned an empty occurrence display.' );
roadie_e2e_assert( get_permalink( (int) $fixture['event_id'] ) === $calendar_event['permalink'], 'Event adapter changed the event permalink.' );
roadie_e2e_assert( 'https://tickets.example.test/roadie-show?ref=e2e' === $calendar_event['ticket_url'], 'Event adapter changed the ticket URL.' );
roadie_e2e_assert( '2030-07-21T20:30:00' === $calendar_event['datetime'], 'Event adapter changed the start time.' );
roadie_e2e_assert( '2030-07-21T23:00:00' === $calendar_event['end_datetime'], 'Event adapter changed the end time.' );
restore_current_blog();

switch_to_blog( (int) $sites['artist'] );
$deleted_session = roadie_e2e_rest( 'DELETE', '/frontend-agent-chat/v1/chat/' . $session_id, array( 'agent' => 'roadie' ), $owner );
roadie_e2e_assert( 200 === $deleted_session->get_status() && ! empty( $deleted_session->get_data()['data']['deleted'] ), 'Artist site could not delete the canonical conversation through FAC.' );
restore_current_blog();

echo 'Roadie multisite artist journey passed (' . $passes . " assertions).\n";
