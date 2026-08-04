<?php
/** Venue booking operations delegated to Extra Chill Events. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Thin, caller-bound adapter over Events-owned booking abilities. */
class ECRoadie_ManageVenueBookings extends ECRoadie_PlatformTool {
	protected string $site_key  = 'events';
	protected string $tool_slug = 'manage_venue_bookings';
	private const ABILITY_ROUTE_PREFIX = '/wp-abilities/v1/abilities/';
	private const ABILITY_ROUTE_SUFFIX = '/run';
	private const BOOTSTRAP_ABILITIES  = array(
		'extrachill/list-venue-bookings',
		'extrachill/get-venue-booking',
		'extrachill/get-venue-booking-activity',
		'extrachill/list-booking-holds',
		'extrachill/list-booking-communications',
		'extrachill/correct-venue-booking-intake',
		'extrachill/select-venue-booking-performance',
		'extrachill/transition-venue-booking',
		'extrachill/create-booking-hold',
		'extrachill/release-booking-hold',
		'extrachill/send-booking-message',
		'extrachill/convert-booking-to-event',
	);

	private const ABILITIES = array(
		'list_bookings'      => 'extrachill/list-venue-bookings',
		'inspect_booking'    => 'extrachill/get-venue-booking',
		'list_holds'         => 'extrachill/list-booking-holds',
		'correct_details'    => 'extrachill/correct-venue-booking-intake',
		'select_performance' => 'extrachill/select-venue-booking-performance',
		'transition_booking' => 'extrachill/transition-venue-booking',
		'create_hold'        => 'extrachill/create-booking-hold',
		'release_hold'       => 'extrachill/release-booking-hold',
		'send_message'       => 'extrachill/send-booking-message',
		'convert_to_event'   => 'extrachill/convert-booking-to-event',
	);

	private const REQUIRED = array(
		'list_bookings'      => array(),
		'inspect_booking'    => array( 'booking_id' ),
		'list_holds'         => array(),
		'correct_details'    => array( 'booking_id', 'expected_version' ),
		'select_performance' => array( 'booking_id', 'expected_version', 'space_key', 'start_at', 'end_at' ),
		'transition_booking' => array( 'booking_id', 'expected_version', 'to_status' ),
		'create_hold'        => array( 'booking_id', 'expected_version' ),
		'release_hold'       => array( 'booking_id', 'hold_id', 'expected_version', 'reason' ),
		'send_message'       => array( 'booking_id', 'idempotency_key', 'template', 'recipient', 'message', 'reply_to' ),
		'convert_to_event'   => array( 'booking_id', 'expected_version' ),
	);

	private const ALLOWED = array(
		'list_bookings'      => array( 'venue_term_id', 'status', 'requested_from', 'requested_to', 'limit', 'offset' ),
		'inspect_booking'    => array( 'booking_id' ),
		'list_holds'         => array( 'venue_term_id', 'booking_id', 'status', 'range_start', 'range_end', 'limit', 'offset' ),
		'correct_details'    => array( 'booking_id', 'expected_version', 'contact_name', 'contact_email', 'contact_phone', 'requested_space_key', 'requested_start_at', 'requested_end_at', 'intake' ),
		'select_performance' => array( 'booking_id', 'expected_version', 'space_key', 'start_at', 'end_at' ),
		'transition_booking' => array( 'booking_id', 'expected_version', 'to_status', 'note' ),
		'create_hold'        => array( 'booking_id', 'expected_version' ),
		'release_hold'       => array( 'booking_id', 'hold_id', 'expected_version', 'reason' ),
		'send_message'       => array( 'booking_id', 'idempotency_key', 'template', 'recipient', 'subject', 'template_version', 'message', 'reply_to', 'send_at', 'expected_statuses' ),
		'convert_to_event'   => array( 'booking_id', 'expected_version' ),
	);

	public function __construct() {
		$this->registerTool( $this->tool_slug, array( $this, 'getToolDefinition' ), array( 'roadie' ), array( 'access_level' => 'authenticated' ) );
		add_filter( 'ec_cross_site_use_http_loopback', array( self::class, 'use_http_loopback' ), 10, 5 );
	}

	/** Force target-site bootstrap for Events-owned booking abilities. */
	public static function use_http_loopback( bool $use_http, string $site_key, string $method, string $path, array $args ): bool {
		unset( $args );

		if ( $use_http || 'events' !== $site_key || 'POST' !== $method ) {
			return $use_http;
		}

		$ability = 0 === strpos( $path, self::ABILITY_ROUTE_PREFIX ) && self::ABILITY_ROUTE_SUFFIX === substr( $path, -strlen( self::ABILITY_ROUTE_SUFFIX ) )
			? substr( $path, strlen( self::ABILITY_ROUTE_PREFIX ), -strlen( self::ABILITY_ROUTE_SUFFIX ) )
			: '';
		if ( ! in_array( $ability, self::BOOTSTRAP_ABILITIES, true ) ) {
			return $use_http;
		}

		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
		return $events_blog_id <= 0 || ! function_exists( 'get_current_blog_id' ) || $events_blog_id !== (int) get_current_blog_id();
	}

	public function getToolDefinition(): array {
		$datetime = array( 'type' => array( 'string', 'null' ), 'description' => 'UTC date/time in YYYY-MM-DD HH:MM:SS format.' );
		return array(
			'class'              => self::class,
			'method'             => 'handle_tool_call',
			'action_kind'        => 'extrachill_roadie_manage_venue_booking',
			'action_summary'     => 'Apply the proposed venue booking operation.',
			'parameter_bindings' => array(
				'calling_user_id'    => array( 'source' => 'caller_context', 'path' => 'calling_user_id', 'authoritative' => true ),
				'effective_agent_id' => array( 'source' => 'caller_context', 'path' => 'agent_id', 'authoritative' => true ),
			),
			'description'        => 'Manage an authorized venue calendar through Extra Chill Events. List bookings or holds, inspect a booking with its timeline, holds and correspondence, correct inquiry details, select performance dates, transition lifecycle state, create or release holds, propose an email, or convert a confirmed booking to its canonical event. Events enforces venue membership, conflicts, lifecycle, versions and idempotency. Confirmation, cancellation, email and conversion wait for human approval.',
			'parameters'         => array(
				'type'                 => 'object',
				'required'             => array( 'action' ),
				'additionalProperties' => false,
				'properties'           => array(
					'action'              => array( 'type' => 'string', 'enum' => array_keys( self::ABILITIES ) ),
					'calling_user_id'     => array( 'type' => 'integer' ),
					'effective_agent_id'  => array( 'type' => 'integer' ),
					'venue_term_id'       => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Events venue term ID. Omit for a venue-specific Roadie.' ),
					'booking_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
					'hold_id'             => array( 'type' => 'integer', 'minimum' => 1 ),
					'expected_version'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'status'              => array( 'type' => array( 'string', 'null' ) ),
					'to_status'           => array( 'type' => 'string', 'enum' => array( 'submitted', 'needs_info', 'under_review', 'negotiating', 'held', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) ),
					'note'                => array( 'type' => array( 'string', 'null' ), 'maxLength' => 1000 ),
					'reason'              => array( 'type' => 'string', 'maxLength' => 255 ),
					'requested_from'      => $datetime,
					'requested_to'        => $datetime,
					'range_start'         => $datetime,
					'range_end'           => $datetime,
					'requested_start_at'  => $datetime,
					'requested_end_at'    => $datetime,
					'limit'               => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					'offset'              => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
					'contact_name'        => array( 'type' => array( 'string', 'null' ), 'maxLength' => 255 ),
					'contact_email'       => array( 'type' => array( 'string', 'null' ), 'format' => 'email' ),
					'contact_phone'       => array( 'type' => array( 'string', 'null' ), 'maxLength' => 64 ),
					'requested_space_key' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 64 ),
					'intake'              => array( 'type' => 'object', 'additionalProperties' => true ),
					'space_key'           => array( 'type' => 'string', 'maxLength' => 64 ),
					'start_at'            => $datetime,
					'end_at'              => $datetime,
					'idempotency_key'     => array( 'type' => 'string', 'maxLength' => 120 ),
					'template'            => array( 'type' => 'string', 'enum' => array( 'operator_message', 'follow_up', 'hold_expiring' ) ),
					'recipient'           => array( 'type' => 'string', 'format' => 'email' ),
					'subject'             => array( 'type' => 'string', 'maxLength' => 200 ),
					'template_version'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'message'             => array( 'type' => 'string', 'maxLength' => 10000 ),
					'reply_to'            => array( 'type' => 'string', 'format' => 'email' ),
					'send_at'             => $datetime,
					'expected_statuses'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'uniqueItems' => true ),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );
		$action  = (string) ( $parameters['action'] ?? '' );
		$user_id = extrachill_roadie_resolve_acting_caller( $parameters );
		if ( $user_id <= 0 ) {
			return $this->buildErrorResponse( 'Authentication is required to manage venue bookings.', $this->tool_slug );
		}
		if ( ! isset( self::ABILITIES[ $action ] ) ) {
			return $this->buildErrorResponse( 'A valid venue booking action is required.', $this->tool_slug );
		}
		foreach ( self::REQUIRED[ $action ] as $required ) {
			if ( ! array_key_exists( $required, $parameters ) ) {
				return $this->buildErrorResponse( $required . ' is required for ' . $action . '.', $this->tool_slug );
			}
		}

		$scope = function_exists( 'extrachill_roadie_resolve_venue_agent_scope' ) ? extrachill_roadie_resolve_venue_agent_scope( (int) ( $parameters['effective_agent_id'] ?? 0 ), $user_id ) : null;
		if ( is_wp_error( $scope ) ) {
			return $this->buildErrorResponse( $scope->get_error_message(), $this->tool_slug );
		}
		$input = array_intersect_key( $parameters, array_flip( self::ALLOWED[ $action ] ) );
		if ( in_array( $action, array( 'list_bookings', 'list_holds' ), true ) ) {
			$input = $this->pin_list_to_scope( $input, $scope );
			if ( isset( $input['error'] ) ) {
				return $this->buildErrorResponse( $input['error'], $this->tool_slug );
			}
		}
		if ( 'create_hold' === $action ) {
			$input['expected_booking_version'] = $input['expected_version'];
			unset( $input['expected_version'] );
		}
		if ( is_array( $scope ) && ! in_array( $action, array( 'list_bookings', 'list_holds' ), true ) ) {
			$scoped = $this->get_booking( (int) ( $input['booking_id'] ?? 0 ), $user_id );
			if ( ! ( $scoped['success'] ?? false ) ) {
				return $scoped;
			}
			$booking = $this->unwrap( $scoped['data'] ?? array() );
			if ( (int) ( $booking['venue_term_id'] ?? 0 ) !== (int) $scope['venue_term_id'] ) {
				return $this->buildErrorResponse( 'Permission denied: this booking is outside the selected venue.', $this->tool_slug );
			}
		}
		if ( 'inspect_booking' === $action ) {
			return $this->inspect_booking( (int) $input['booking_id'], $user_id );
		}
		if ( 'release_hold' === $action ) {
			unset( $input['booking_id'] );
		}
		$result = $this->run_ability( self::ABILITIES[ $action ], $input, $user_id );
		if ( $result['success'] ?? false ) {
			$result['data'] = $this->present_result( $this->unwrap( $result['data'] ?? array() ) );
		}
		return $result;
	}

	public function apply_pending( array $input ): array {
		$input['calling_user_id'] = get_current_user_id();
		return $this->handle_tool_call( $input );
	}

	public function can_resolve_pending( array $payload, string $decision, int $user_id ) {
		unset( $decision );
		$input      = is_array( $payload['apply_input'] ?? null ) ? $payload['apply_input'] : array();
		$booking_id = absint( $input['booking_id'] ?? 0 );
		return $user_id > 0 && $booking_id > 0 && ( $this->get_booking( $booking_id, $user_id )['success'] ?? false );
	}

	private function pin_list_to_scope( array $input, $scope ): array {
		if ( is_array( $scope ) ) {
			if ( isset( $input['venue_term_id'] ) && (int) $input['venue_term_id'] !== (int) $scope['venue_term_id'] ) {
				return array( 'error' => 'Permission denied: the requested venue is outside the selected venue scope.' );
			}
			$input['venue_term_id'] = (int) $scope['venue_term_id'];
		}
		return empty( $input['venue_term_id'] ) ? array( 'error' => 'venue_term_id is required when Roadie is not scoped to one venue.' ) : $input;
	}

	private function run_ability( string $ability, array $input, int $user_id ): array {
		return $this->rest_request( 'POST', '/wp-abilities/v1/abilities/' . $ability . '/run', array( 'body' => array( 'input' => $input ), 'user_id' => $user_id ) );
	}

	private function get_booking( int $booking_id, int $user_id ): array {
		return $this->run_ability( 'extrachill/get-venue-booking', array( 'booking_id' => $booking_id ), $user_id );
	}

	private function inspect_booking( int $booking_id, int $user_id ): array {
		$booking = $this->get_booking( $booking_id, $user_id );
		if ( ! ( $booking['success'] ?? false ) ) {
			return $booking;
		}
		$booking_data = $this->unwrap( $booking['data'] ?? array() );
		$related = array(
			'activity_state' => $this->run_ability( 'extrachill/get-venue-booking-activity', array( 'booking_id' => $booking_id ), $user_id ),
			'holds'          => $this->run_ability( 'extrachill/list-booking-holds', array( 'venue_term_id' => (int) ( $booking_data['venue_term_id'] ?? 0 ), 'booking_id' => $booking_id ), $user_id ),
			'communications' => $this->run_ability( 'extrachill/list-booking-communications', array( 'booking_id' => $booking_id ), $user_id ),
		);
		foreach ( $related as $result ) {
			if ( ! ( $result['success'] ?? false ) ) {
				return $result;
			}
		}
		$data = array( 'booking' => $this->present_booking( $booking_data ) );
		foreach ( $related as $key => $result ) {
			$data[ $key ] = $this->unwrap( $result['data'] ?? array() );
		}
		return array( 'success' => true, 'tool_name' => $this->tool_slug, 'data' => $data );
	}

	private function unwrap( $data ) {
		return is_array( $data ) && array_key_exists( 'result', $data ) ? $data['result'] : $data;
	}

	private function present_result( $data ) {
		if ( is_array( $data ) && isset( $data['id'], $data['venue_term_id'], $data['version'] ) ) {
			return $this->present_booking( $data );
		}
		if ( is_array( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return array_map( fn( $item ) => is_array( $item ) && isset( $item['id'], $item['venue_term_id'], $item['version'] ) ? $this->present_booking( $item ) : $item, $data );
		}
		return $data;
	}

	private function present_booking( array $booking ): array {
		$fields  = array( 'id', 'public_id', 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'artist_name', 'requested_space_key', 'space_key', 'status', 'version', 'requested_start_at', 'requested_end_at', 'performance_start_at', 'performance_end_at', 'event_id', 'contact_name', 'contact_email', 'contact_phone', 'intake', 'production', 'deal', 'confirmed_deal', 'created_at', 'updated_at' );
		$summary = array_intersect_key( $booking, array_flip( $fields ) );
		$summary['management_url'] = $this->management_url( (int) ( $booking['venue_term_id'] ?? 0 ), (int) ( $booking['id'] ?? 0 ) );
		return $summary;
	}

	private function management_url( int $venue_id, int $booking_id ): string {
		if ( $venue_id <= 0 || $booking_id <= 0 || ! function_exists( 'ec_get_site_url' ) ) {
			return '';
		}
		return rtrim( (string) ec_get_site_url( 'events' ), '/' ) . '/venue-settings/?venue_id=' . $venue_id . '&booking_id=' . $booking_id . '#tab-calendar';
	}
}

/** Stage only actions whose external or public effects need consent. */
function extrachill_roadie_venue_booking_action_policy( string $policy, string $tool_name, string $mode, array $context ): string {
	if ( 'manage_venue_bookings' !== $tool_name ) {
		return $policy;
	}
	$input          = is_array( $context['input'] ?? null ) ? $context['input'] : array();
	$action         = (string) ( $input['action'] ?? '' );
	$needs_approval = in_array( $action, array( 'send_message', 'convert_to_event' ), true ) || ( 'transition_booking' === $action && in_array( (string) ( $input['to_status'] ?? '' ), array( 'confirmed', 'cancelled' ), true ) );
	return $needs_approval ? ( 'chat' === $mode ? 'preview' : 'forbidden' ) : $policy;
}
add_filter( 'datamachine_tool_action_policy', 'extrachill_roadie_venue_booking_action_policy', 20, 4 );

/** Replay accepted actions through the same adapter with fresh authorization. */
function extrachill_roadie_venue_booking_pending_handlers( $handlers ) {
	$handlers = is_array( $handlers ) ? $handlers : array();
	$tool     = new ECRoadie_ManageVenueBookings();
	$handlers['extrachill_roadie_manage_venue_booking'] = array( 'apply' => array( $tool, 'apply_pending' ), 'can_resolve' => array( $tool, 'can_resolve_pending' ) );
	return $handlers;
}
add_filter( 'datamachine_pending_action_handlers', 'extrachill_roadie_venue_booking_pending_handlers' );
