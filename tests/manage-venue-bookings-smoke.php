<?php
/** Standalone contract coverage for the Roadie venue booking adapter. */

declare(strict_types=1);

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['roadie_booking_filters'][ $hook ][] = $callback;
}
function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['roadie_booking_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function absint( $value ): int { return abs( (int) $value ); }
function ec_get_site_url( string $site ): string { return 'events' === $site ? 'https://events.extrachill.test' : ''; }
function get_current_blog_id(): int { return (int) ( $GLOBALS['roadie_booking_current_blog'] ?? 1 ); }
function extrachill_roadie_resolve_venue_agent_scope( int $agent_id, int $user_id ) {
	if ( 900 !== $agent_id ) {
		return null;
	}
	return 41 === $user_id ? array( 'venue_term_id' => 77 ) : new WP_Error( 'forbidden', 'The acting human does not own this venue agent instance.' );
}

require_once dirname( __DIR__ ) . '/inc/tools/caller.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-venue-bookings.php';

function booking_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$bookings = array(
	10 => array( 'id' => 10, 'venue_term_id' => 77, 'artist_name' => 'Lo-Fi Band', 'status' => 'under_review', 'version' => 3, 'assignee_user_id' => 41, 'requested_start_at' => '2026-09-01 20:00:00', 'requested_end_at' => '2026-09-01 23:00:00' ),
	20 => array( 'id' => 20, 'venue_term_id' => 88, 'artist_name' => 'Other Band', 'status' => 'submitted', 'version' => 1 ),
);
$GLOBALS['roadie_booking_attempts'] = array();
$GLOBALS['roadie_booking_current_blog'] = 1;
$GLOBALS['ec_roadie_test_rest_response'] = static function ( string $site, string $method, string $path, array $args ) use ( &$bookings ) {
	$user_id = (int) ( $args['user_id'] ?? 0 );
	$input   = (array) ( $args['body']['input'] ?? array() );
	$ability = preg_replace( '#^/wp-abilities/v1/abilities/(.+)/run$#', '$1', $path );
	$GLOBALS['roadie_booking_attempts'][] = compact( 'site', 'method', 'path', 'args', 'user_id', 'ability' );
	$allowed_venue = 41 === $user_id ? 77 : ( 42 === $user_id ? 88 : 0 );

	if ( 'extrachill/list-venue-bookings' === $ability ) {
		if ( (int) ( $input['venue_term_id'] ?? 0 ) !== $allowed_venue ) {
			return new WP_Error( 'venue_action_forbidden', 'You are not authorized to perform this venue action.' );
		}
		return array_values( array_filter( $bookings, static fn( array $booking ): bool => $booking['venue_term_id'] === $allowed_venue ) );
	}
	if ( 'extrachill/get-venue-booking' === $ability ) {
		$booking = $bookings[ (int) ( $input['booking_id'] ?? 0 ) ] ?? null;
		return is_array( $booking ) && $booking['venue_term_id'] === $allowed_venue ? $booking : new WP_Error( 'venue_action_forbidden', 'You are not authorized to perform this venue action.' );
	}
	if ( in_array( $ability, array( 'extrachill/get-venue-booking-activity', 'extrachill/list-booking-communications' ), true ) ) {
		return array();
	}
	if ( 'extrachill/list-booking-holds' === $ability ) {
		if ( (int) ( $input['venue_term_id'] ?? 0 ) !== $allowed_venue ) {
			return new WP_Error( 'venue_action_forbidden', 'You are not authorized to perform this venue action.' );
		}
		return array( array( 'id' => 5, 'booking_id' => 10, 'venue_term_id' => 77, 'status' => 'active', 'version' => 2 ) );
	}
	$booking = $bookings[ (int) ( $input['booking_id'] ?? 0 ) ] ?? null;
	if ( ! is_array( $booking ) || $booking['venue_term_id'] !== $allowed_venue ) {
		return new WP_Error( 'venue_action_forbidden', 'You are not authorized to perform this venue action.' );
	}
	if ( ( isset( $input['expected_version'] ) && (int) $input['expected_version'] !== (int) $booking['version'] ) || ( isset( $input['expected_booking_version'] ) && (int) $input['expected_booking_version'] !== (int) $booking['version'] ) ) {
		return new WP_Error( 'booking_version_conflict', 'The booking changed. Refresh and retry with its current version.' );
	}
	if ( 'extrachill/transition-venue-booking' === $ability && 'completed' === ( $input['to_status'] ?? '' ) ) {
		return new WP_Error( 'invalid_booking_transition', 'That booking lifecycle transition is not allowed.' );
	}
	if ( 'extrachill/create-booking-hold' === $ability ) {
		return new WP_Error( 'booking_hold_conflict', 'The selected venue space conflicts with an active hold.' );
	}
	if ( 'extrachill/send-booking-message' === $ability ) {
		$key = (string) ( $input['idempotency_key'] ?? '' );
		return array( 'intent_id' => 'same-key' === $key ? 501 : 502, 'idempotency_key' => $key );
	}
	return $booking;
};

$tool = new ECRoadie_ManageVenueBookings();
$definition = $tool->getToolDefinition();
booking_assert( ! in_array( 'assign_booking', $definition['parameters']['properties']['action']['enum'], true ), 'Assignment action is absent from the tool contract.' );
booking_assert( ! isset( $definition['parameters']['properties']['assignee_user_id'] ), 'Assignee input is absent from the tool contract.' );
booking_assert(
	true === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/list-venue-bookings/run', array() ),
	'Main-site booking ability calls require the Events bootstrap.'
);
booking_assert(
	false === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'events', 'GET', '/wp-abilities/v1/abilities/extrachill/list-venue-bookings/run', array() ),
	'Non-POST requests retain the transport default.'
);
booking_assert(
	false === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/unrelated/run', array() ),
	'Unrelated Events abilities retain the transport default.'
);
booking_assert(
	false === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/assign-venue-booking/run', array() ),
	'Removed assignment ability is not bootstrapped.'
);
booking_assert(
	false === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'main', 'POST', '/wp-abilities/v1/abilities/extrachill/list-venue-bookings/run', array() ),
	'Unrelated sites retain the transport default.'
);
$GLOBALS['roadie_booking_current_blog'] = 7;
booking_assert(
	false === ECRoadie_ManageVenueBookings::use_http_loopback( false, 'events', 'POST', '/wp-abilities/v1/abilities/extrachill/get-venue-booking-activity/run', array() ),
	'Events-origin booking calls can use the in-process route.'
);
$GLOBALS['roadie_booking_current_blog'] = 1;

$list = $tool->handle_tool_call( array( 'action' => 'list_bookings', 'calling_user_id' => 41, 'effective_agent_id' => 900 ) );
booking_assert( true === ( $list['success'] ?? false ) && 10 === $list['data'][0]['id'], 'Authorized member lists only the selected venue.' );
booking_assert( ! isset( $list['data'][0]['assignee_user_id'] ), 'Booking lists omit assignee fields.' );
booking_assert( 77 === $GLOBALS['roadie_booking_attempts'][0]['args']['body']['input']['venue_term_id'], 'Venue agent pins exact venue.' );
booking_assert( 41 === $GLOBALS['roadie_booking_attempts'][0]['args']['user_id'], 'Caller identity reaches Events.' );
booking_assert( true === $GLOBALS['ec_roadie_test_rest_calls'][0]['loopback'], 'Booking dispatch selects the target-bootstrap transport.' );
booking_assert( 41 === $GLOBALS['ec_roadie_test_rest_calls'][0]['effective_user'], 'Target-bootstrap dispatch preserves the authoritative caller identity.' );

$calls_before_anonymous = count( $GLOBALS['roadie_booking_attempts'] );
$anonymous = $tool->handle_tool_call( array( 'action' => 'list_bookings', 'calling_user_id' => 0, 'venue_term_id' => 77 ) );
booking_assert( false === ( $anonymous['success'] ?? true ) && $calls_before_anonymous === count( $GLOBALS['roadie_booking_attempts'] ), 'Anonymous callers are denied before target dispatch.' );

$inspect = $tool->handle_tool_call( array( 'action' => 'inspect_booking', 'booking_id' => 10, 'calling_user_id' => 41, 'effective_agent_id' => 900 ) );
booking_assert( 10 === ( $inspect['data']['booking']['id'] ?? 0 ), 'Authorized member can inspect booking state.' );
booking_assert( ! isset( $inspect['data']['booking']['assignee_user_id'] ), 'Booking inspection omits assignee fields.' );
booking_assert( 5 === ( $inspect['data']['holds'][0]['id'] ?? 0 ), 'Inspection includes authoritative holds.' );
booking_assert( str_contains( $inspect['data']['booking']['management_url'], 'venue_id=77&booking_id=10' ), 'Inspection returns management URL.' );

$cross = $tool->handle_tool_call( array( 'action' => 'inspect_booking', 'booking_id' => 20, 'calling_user_id' => 41, 'effective_agent_id' => 900 ) );
booking_assert( false === ( $cross['success'] ?? true ), 'Selected venue cannot inspect another venue.' );
$unrelated = $tool->handle_tool_call( array( 'action' => 'inspect_booking', 'booking_id' => 10, 'calling_user_id' => 99 ) );
booking_assert( false === ( $unrelated['success'] ?? true ), 'Unrelated user cannot read a booking.' );
$other_member = $tool->handle_tool_call( array( 'action' => 'transition_booking', 'booking_id' => 10, 'expected_version' => 3, 'to_status' => 'under_review', 'calling_user_id' => 42 ) );
booking_assert( false === ( $other_member['success'] ?? true ), 'Different-venue member cannot mutate a booking.' );

$calls_before_assignment = count( $GLOBALS['roadie_booking_attempts'] );
$assignment = $tool->handle_tool_call( array( 'action' => 'assign_booking', 'booking_id' => 10, 'assignee_user_id' => 41, 'expected_version' => 3, 'calling_user_id' => 41 ) );
booking_assert( false === ( $assignment['success'] ?? true ) && $calls_before_assignment === count( $GLOBALS['roadie_booking_attempts'] ), 'Removed assignment action cannot dispatch.' );
$invalid = $tool->handle_tool_call( array( 'action' => 'transition_booking', 'booking_id' => 10, 'expected_version' => 3, 'to_status' => 'completed', 'calling_user_id' => 41 ) );
booking_assert( false === ( $invalid['success'] ?? true ), 'Owning lifecycle rules survive delegation.' );
$conflict = $tool->handle_tool_call( array( 'action' => 'create_hold', 'booking_id' => 10, 'expected_version' => 3, 'calling_user_id' => 41 ) );
booking_assert( false === ( $conflict['success'] ?? true ), 'Owning hold conflicts survive delegation.' );

foreach ( array( array( 'action' => 'send_message' ), array( 'action' => 'transition_booking', 'to_status' => 'confirmed' ), array( 'action' => 'transition_booking', 'to_status' => 'cancelled' ), array( 'action' => 'convert_to_event' ) ) as $input ) {
	booking_assert( 'preview' === apply_filters( 'datamachine_tool_action_policy', 'direct', 'manage_venue_bookings', 'chat', array( 'input' => $input ) ), 'Consequential chat operation is staged.' );
}
booking_assert( 'forbidden' === apply_filters( 'datamachine_tool_action_policy', 'direct', 'manage_venue_bookings', 'pipeline', array( 'input' => array( 'action' => 'convert_to_event' ) ) ), 'Approval cannot be bypassed outside chat.' );

$message = array( 'action' => 'send_message', 'booking_id' => 10, 'idempotency_key' => 'same-key', 'template' => 'operator_message', 'recipient' => 'artist@example.com', 'message' => 'Hello', 'reply_to' => 'venue@example.com', 'calling_user_id' => 41 );
$first   = $tool->handle_tool_call( $message );
$second  = $tool->handle_tool_call( $message );
booking_assert( 501 === $first['data']['intent_id'] && 501 === $second['data']['intent_id'], 'Retries preserve owning idempotency.' );
$calls = array_values( array_filter( $GLOBALS['roadie_booking_attempts'], static fn( array $call ): bool => 'extrachill/send-booking-message' === $call['ability'] ) );
booking_assert( 'same-key' === $calls[0]['args']['body']['input']['idempotency_key'] && 'same-key' === $calls[1]['args']['body']['input']['idempotency_key'], 'Idempotency key passes through unchanged.' );

echo "Roadie venue booking smoke passed.\n";
