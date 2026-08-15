<?php
/**
 * Client-neutral Roadie policy for canonical Agents API abilities.
 *
 * @package ExtraChillRoadie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decorate canonical ability callbacks before WordPress registers them.
 *
 * Permission and execution receive the same server-composed input so native,
 * browser, and other ability clients cannot drift on Roadie's workspace.
 *
 * @param array  $args Ability registration arguments.
 * @param string $name Ability name.
 * @return array
 */
function extrachill_roadie_decorate_canonical_ability( array $args, string $name ): array {
	if ( ! in_array( $name, extrachill_roadie_canonical_ability_names(), true ) ) {
		return $args;
	}

	$execute    = $args['execute_callback'] ?? null;
	$permission = $args['permission_callback'] ?? null;
	if ( is_callable( $execute ) ) {
		$args['execute_callback'] = static function ( array $input ) use ( $execute, $name ) {
			$input = extrachill_roadie_compose_canonical_ability_input( $name, $input );
			return is_wp_error( $input ) ? $input : call_user_func( $execute, $input );
		};
	}
	if ( is_callable( $permission ) ) {
		$args['permission_callback'] = static function ( array $input ) use ( $permission, $name ) {
			$input = extrachill_roadie_compose_canonical_ability_input( $name, $input );
			if ( is_wp_error( $input ) ) {
				return $input;
			}

			$result = call_user_func( $permission, $input );
			return is_wp_error( $result ) ? $result : (bool) $result;
		};
	}

	return $args;
}
add_filter( 'wp_register_ability_args', 'extrachill_roadie_decorate_canonical_ability', 10, 2 );

/** @return string[] Canonical abilities that carry Roadie's conversation scope. */
function extrachill_roadie_canonical_ability_names(): array {
	return array(
		'agents/chat',
		'agents/queue-chat-message',
		'agents/list-conversation-sessions',
		'agents/create-conversation-session',
		'agents/get-conversation-session',
		'agents/delete-conversation-session',
		'agents/update-conversation-session-title',
		'agents/mark-conversation-session-read',
		'agents/get-chat-run',
		'agents/list-chat-run-events',
		'agents/cancel-chat-run',
		'agents/resolve-pending-action',
	);
}

/**
 * Apply Roadie policy to one canonical ability input.
 *
 * The client identifies an agent returned by canonical discovery. Roadie then
 * derives workspace and mode policy on the server. Non-Roadie inputs are left
 * untouched.
 *
 * @param string $ability Ability name.
 * @param array  $input Ability input.
 * @return array|WP_Error
 */
function extrachill_roadie_compose_canonical_ability_input( string $ability, array $input ) {
	$agent = sanitize_title( (string) ( $input['agent'] ?? '' ) );
	$scope = extrachill_roadie_canonical_agent_scope( $agent );
	if ( is_wp_error( $scope ) ) {
		return $scope;
	}
	if ( null === $scope ) {
		return $input;
	}

	$input['agent']     = (string) $scope['agent'];
	$input['workspace'] = $scope['workspace'];

	if ( in_array( $ability, array( 'agents/chat', 'agents/queue-chat-message' ), true ) ) {
		$input['modes']          = extrachill_roadie_compose_modes( $input['modes'] ?? array() );
		$input['client_context'] = extrachill_roadie_append_server_context(
			is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array()
		);
	}

	if ( 'agents/list-conversation-sessions' === $ability || 'agents/create-conversation-session' === $ability ) {
		$input['context'] = implode( ',', extrachill_roadie_compose_modes( array() ) );
	}

	if ( 'agents/resolve-pending-action' === $ability ) {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$input['resolver'] = 'user:' . $user_id;
		}
		$context = is_array( $input['context'] ?? null ) ? $input['context'] : array();
		$origin  = is_array( $context['roadie_origin'] ?? null ) ? $context['roadie_origin'] : array();
		if ( ! empty( $origin ) ) {
			unset( $context['roadie_origin'] );
			$input['context'] = $context;
			$input            = extrachill_roadie_apply_pending_action_origin( $input, $origin );
		}
	}

	return $input;
}

/**
 * Resolve server-owned scope for a canonical Roadie identity.
 *
 * @return array{agent:string,workspace:array{workspace_type:string,workspace_id:string}}|WP_Error|null
 */
function extrachill_roadie_canonical_agent_scope( string $agent ) {
	if ( EXTRACHILL_ROADIE_AGENT_SLUG === $agent ) {
		return array(
			'agent'     => EXTRACHILL_ROADIE_AGENT_SLUG,
			'workspace' => extrachill_roadie_conversation_workspace(),
		);
	}

	// Venue identities retain the existing FAC path until canonical callers can
	// derive the same agent-owned transcript owner as the browser integration.
	return null;
}

/** Add server-known site context without interpreting browser-only state. */
function extrachill_roadie_append_server_context( array $client_context ): array {
	$site_name = get_bloginfo( 'name' );
	if ( '' !== (string) $site_name ) {
		$client_context['site'] = sanitize_text_field( (string) $site_name );
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( is_string( $site_host ) && '' !== $site_host ) {
		$client_context['site_host'] = $site_host;
	}

	return $client_context;
}

/**
 * Apply a validated pending-action workspace to Data Machine's caller scope.
 *
 * @param array $scope Existing Data Machine scope.
 * @param array $input Resolver input.
 * @return array
 */
function extrachill_roadie_pending_action_scope( array $scope, array $input ): array {
	$context   = is_array( $input['context'] ?? null ) ? $input['context'] : array();
	$workspace = is_array( $context['workspace'] ?? null ) ? $context['workspace'] : array();
	$wordpress = is_array( $context['wordpress'] ?? null ) ? $context['wordpress'] : array();
	$type      = sanitize_key( (string) ( $workspace['workspace_type'] ?? '' ) );
	$id        = trim( (string) ( $workspace['workspace_id'] ?? '' ) );
	$blog_id   = absint( $wordpress['blog_id'] ?? 0 );
	$proof     = (string) ( $context['_roadie_scope_proof'] ?? '' );
	$expected  = extrachill_roadie_pending_action_scope_proof( $input, $type, $id, $blog_id );

	if ( '' !== $proof && hash_equals( $expected, $proof ) && $blog_id > 0 && extrachill_roadie_pending_action_origin_is_valid( $type, $id, $blog_id ) ) {
		$scope['workspace_type'] = $type;
		$scope['workspace_id']   = $id;
	}

	return $scope;
}
add_filter( 'datamachine_pending_action_current_scope', 'extrachill_roadie_pending_action_scope', 10, 2 );
