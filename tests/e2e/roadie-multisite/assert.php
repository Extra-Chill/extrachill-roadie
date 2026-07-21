<?php

use AgentsAPI\AI\WP_Agent_Chat_Run_Control;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Message;
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

function roadie_e2e_roadie_input( string $ability, array $input, int $user_id ): array {
	$input['principal'] = WP_Agent_Execution_Principal::user_session(
		$user_id,
		'roadie',
		WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST
	);
	return apply_filters(
		'frontend_agent_chat_ability_input',
		$input,
		$ability,
		new WP_REST_Request(),
		'roadie',
		array( 'agent_slug' => 'roadie' )
	);
}

function roadie_e2e_rest( string $method, string $route, array $params, int $user_id ): WP_REST_Response {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $params );
	$response = rest_do_request( $request );
	return rest_ensure_response( $response );
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

$revisions = roadie_e2e_rest( 'GET', '/wp/v2/artist_profile/' . $artist . '/revisions', array(), $owner );
roadie_e2e_assert( 200 === $revisions->get_status() && count( (array) $revisions->get_data() ) > 0, 'Artist owner could not read revisions.' );

$autosave_denied = roadie_e2e_rest(
	'POST',
	'/wp/v2/artist_profile/' . $artist . '/autosaves',
	array( 'content' => 'Stranger autosave content.' ),
	$stranger
);
roadie_e2e_assert( 403 === $autosave_denied->get_status(), 'Unrelated user autosave did not fail with 403.' );
if ( $revision_id > 0 ) {
	$revision_denied = roadie_e2e_rest( 'DELETE', '/wp/v2/artist_profile/' . $artist . '/revisions/' . $revision_id, array(), $stranger );
	roadie_e2e_assert( 403 === $revision_denied->get_status(), 'Unrelated user revision delete did not fail with 403.' );
}
$deleted = roadie_e2e_rest( 'DELETE', '/wp/v2/artist_profile/' . $created_id, array( 'force' => true ), $owner );
roadie_e2e_assert( 200 === $deleted->get_status(), 'Reciprocal artist owner could not delete over REST.' );
restore_current_blog();

// Create one canonical network conversation, then use it from every product surface.
wp_set_current_user( $owner );
switch_to_blog( (int) $sites['main'] );
$create_input = roadie_e2e_roadie_input( 'agents/chat', array( 'agent' => 'roadie', 'context' => 'chat,roadie' ), $owner );
$created_session = roadie_e2e_ability( 'agents/create-conversation-session', $create_input );
roadie_e2e_assert( ! is_wp_error( $created_session ), 'Conversation creation failed.' );
$session_id = (string) ( $created_session['session']['session_id'] ?? '' );
roadie_e2e_assert( '' !== $session_id, 'Conversation creation returned no session ID.' );
restore_current_blog();

switch_to_blog( (int) $sites['artist'] );
$listed = roadie_e2e_ability( 'agents/list-conversation-sessions', roadie_e2e_roadie_input( 'agents/list-conversation-sessions', array(), $owner ) );
roadie_e2e_assert( $session_id === (string) ( $listed['sessions'][0]['session_id'] ?? '' ), 'Artist site did not list the canonical conversation.' );
restore_current_blog();

switch_to_blog( (int) $sites['community'] );
$loaded = roadie_e2e_ability( 'agents/get-conversation-session', roadie_e2e_roadie_input( 'agents/get-conversation-session', array( 'session_id' => $session_id ), $owner ) );
roadie_e2e_assert( $session_id === (string) ( $loaded['session']['session_id'] ?? '' ), 'Community site did not read the canonical conversation.' );
$foreign_read = roadie_e2e_ability( 'agents/get-conversation-session', roadie_e2e_roadie_input( 'agents/get-conversation-session', array( 'session_id' => $session_id ), $stranger ) );
roadie_e2e_assert( is_wp_error( $foreign_read ), 'Unrelated user read another owner\'s conversation.' );
restore_current_blog();

switch_to_blog( (int) $sites['events'] );
$titled = roadie_e2e_ability(
	'agents/update-conversation-session-title',
	roadie_e2e_roadie_input( 'agents/update-conversation-session-title', array( 'session_id' => $session_id, 'title' => 'Roadie Network Journey' ), $owner )
);
roadie_e2e_assert( 'Roadie Network Journey' === ( $titled['session']['title'] ?? '' ), 'Events site could not title the canonical conversation.' );
restore_current_blog();

$store = ConversationStoreFactory::get();
roadie_e2e_assert(
	$store->update_session(
		$session_id,
		array(
			WP_Agent_Message::text( 'user', 'Continue this Roadie journey.' ),
			WP_Agent_Message::text( 'assistant', 'The journey continued across the network.' ),
		),
		array( 'continued_from' => 'community' ),
		'e2e',
		'fixture'
	),
	'Canonical conversation continuation could not be persisted.'
);

switch_to_blog( (int) $sites['main'] );
$continued = roadie_e2e_ability( 'agents/get-conversation-session', roadie_e2e_roadie_input( 'agents/get-conversation-session', array( 'session_id' => $session_id ), $owner ) );
roadie_e2e_assert( 2 === count( $continued['session']['messages'] ?? array() ), 'Continued messages were not visible from main.' );
restore_current_blog();

// Bind a run and queue to the real session owner and prove a foreign owner is denied.
$workspace = \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_array( extrachill_roadie_conversation_workspace() );
$owner_tuple = array( 'type' => 'user', 'key' => (string) $owner );
$run_id = WP_Agent_Chat_Run_Control::generate_run_id();
$run = WP_Agent_Chat_Run_Control::start_run( $run_id, $session_id, array( 'source' => 'roadie-e2e' ), $workspace, $owner_tuple, $store );
roadie_e2e_assert( ! is_wp_error( $run ) && 'running' === ( $run['status'] ?? '' ), 'Owner-bound run could not start.' );

switch_to_blog( (int) $sites['community'] );
$queue_input = roadie_e2e_roadie_input(
	'agents/queue-chat-message',
	array(
		'agent'        => 'roadie',
		'session_id'   => $session_id,
		'run_id'       => $run_id,
		'message'      => 'Queue this owned follow-up.',
		'session_owner' => $owner_tuple,
	),
	$owner
);
$queued = roadie_e2e_ability( 'agents/queue-chat-message', $queue_input );
roadie_e2e_assert( ! is_wp_error( $queued ) && ! empty( $queued['queued_message_id'] ), 'Owner queue request failed.' );

$foreign_queue = roadie_e2e_roadie_input(
	'agents/queue-chat-message',
	array(
		'agent'         => 'roadie',
		'session_id'    => $session_id,
		'run_id'        => $run_id,
		'message'       => 'Poison another owner\'s queue.',
		'session_owner' => array( 'type' => 'user', 'key' => (string) $stranger ),
	),
	$stranger
);
$foreign_queued = roadie_e2e_ability( 'agents/queue-chat-message', $foreign_queue );
roadie_e2e_assert( is_wp_error( $foreign_queued ), 'Foreign owner poisoned the conversation queue.' );
restore_current_blog();

// Stage on Events and resolve only through the server-stored origin projection.
switch_to_blog( (int) $sites['events'] );
wp_set_current_user( $owner );
$staged = PendingActionHelper::stage(
	array(
		'kind'        => 'roadie_e2e_origin',
		'summary'     => 'Resolve only at the Events origin.',
		'apply_input' => array( 'marker' => 'stored-origin' ),
		'user_id'     => $owner,
	)
);
roadie_e2e_assert( ! empty( $staged['staged'] ), 'Pending action could not be staged on Events.' );
$origin = array(
	'workspace' => $staged['payload']['workspace'],
	'metadata'  => $staged['payload']['metadata'],
);
restore_current_blog();

switch_to_blog( (int) $sites['community'] );
$resolve_input = apply_filters(
	'frontend_agent_chat_pending_action_resolve_input',
	array( 'action_id' => $staged['action_id'], 'decision' => 'accepted', 'resolver' => 'user:' . $owner ),
	new WP_REST_Request(),
	$origin,
	array( 'agent_slug' => 'roadie' )
);
$resolved = roadie_e2e_ability( 'agents/resolve-pending-action', $resolve_input );
roadie_e2e_assert( ! is_wp_error( $resolved ), 'Stored-origin pending action did not resolve.' );
restore_current_blog();

switch_to_blog( (int) $sites['events'] );
roadie_e2e_assert( 'stored-origin' === ( get_option( 'roadie_e2e_resolved_action', array() )['marker'] ?? '' ), 'Pending handler did not execute at the Events origin.' );
$forged = PendingActionHelper::stage(
	array(
		'kind'        => 'roadie_e2e_origin',
		'summary'     => 'Reject forged origin.',
		'apply_input' => array( 'marker' => 'must-not-run' ),
		'user_id'     => $owner,
	)
);
$forged_origin = array( 'workspace' => $forged['payload']['workspace'], 'metadata' => $forged['payload']['metadata'] );
restore_current_blog();

$forged_origin['workspace']['workspace_id'] = get_home_url( (int) $sites['main'] );
$forged_input = apply_filters(
	'frontend_agent_chat_pending_action_resolve_input',
	array( 'action_id' => $forged['action_id'], 'decision' => 'accepted', 'resolver' => 'user:' . $owner ),
	new WP_REST_Request(),
	$forged_origin,
	array( 'agent_slug' => 'roadie' )
);
roadie_e2e_assert( PHP_INT_MAX === ( $forged_input['context']['wordpress']['blog_id'] ?? 0 ), 'Forged site origin did not fail closed.' );
$forged_result = roadie_e2e_ability( 'agents/resolve-pending-action', $forged_input );
roadie_e2e_assert( is_wp_error( $forged_result ), 'Forged site origin resolved a pending action.' );

$foreign_network_origin = $origin;
$foreign_network_origin['workspace'] = array( 'workspace_type' => 'network', 'workspace_id' => '999' );
$foreign_network_input = apply_filters(
	'frontend_agent_chat_pending_action_resolve_input',
	array( 'action_id' => 'act_foreign_network', 'decision' => 'accepted' ),
	new WP_REST_Request(),
	$foreign_network_origin,
	array( 'agent_slug' => 'roadie' )
);
roadie_e2e_assert( PHP_INT_MAX === ( $foreign_network_input['context']['wordpress']['blog_id'] ?? 0 ), 'Foreign network workspace did not fail closed.' );

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
$venue = roadie_e2e_ability( 'extrachill/events-get-venue', array( 'id' => (int) $fixture['venue_id'] ) );
roadie_e2e_assert( ! is_wp_error( $venue ), 'Venue detail adapter failed.' );
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
roadie_e2e_assert( 'https://tickets.example.test/roadie-show?ref=e2e' === $calendar_event['ticket_url'], 'Event adapter changed the ticket URL.' );
roadie_e2e_assert( '2030-07-21T20:30:00' === $calendar_event['datetime'], 'Event adapter changed the start time.' );
roadie_e2e_assert( '2030-07-21T23:00:00' === $calendar_event['end_datetime'], 'Event adapter changed the end time.' );
restore_current_blog();

switch_to_blog( (int) $sites['artist'] );
$deleted_session = roadie_e2e_ability( 'agents/delete-conversation-session', roadie_e2e_roadie_input( 'agents/delete-conversation-session', array( 'session_id' => $session_id ), $owner ) );
roadie_e2e_assert( true === ( $deleted_session['deleted'] ?? false ), 'Artist site could not delete the canonical conversation.' );
restore_current_blog();

echo 'Roadie multisite artist journey passed (' . $passes . " assertions).\n";
