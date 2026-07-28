<?php
/**
 * Reusable venue-scoped Roadie agent instances.
 *
 * @package ExtraChillRoadie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_ROADIE_VENUE_AGENT_SLUG  = 'roadie-venue';
const EXTRACHILL_ROADIE_VENUE_VOICES_PATH = '/wp-abilities/v1/abilities/extrachill/get-managed-venue-voices/run';

/** Register the reusable venue operator definition without a default instance. */
function extrachill_roadie_register_venue_agent(): void {
	if ( ! function_exists( 'wp_register_agent' ) ) {
		return;
	}

	wp_register_agent(
		EXTRACHILL_ROADIE_VENUE_AGENT_SLUG,
		array(
			'label'          => __( 'Venue Roadie', 'extrachill-roadie' ),
			'description'    => __( 'A reusable venue-scoped Extra Chill operator.', 'extrachill-roadie' ),
			'owner_resolver' => static fn(): int => 0,
			'default_config' => array(
				'default_model'    => 'gpt-5.5',
				'default_provider' => 'openai',
				'description'      => 'A venue-scoped Extra Chill assistant.',
				'tool_policy'      => array(
					'mode'  => 'deny',
					'tools' => array( 'progress_story' ),
				),
			),
			'memory_seeds'   => array(
				'SOUL.md' => EXTRACHILL_ROADIE_PLUGIN_DIR . 'bundles/roadie/memory/agent/SOUL.md',
			),
			'meta'           => array(
				'source_plugin' => 'extrachill-roadie',
				'source_type'   => 'plugin',
			),
		)
	);
}
add_action( 'wp_agents_api_init', 'extrachill_roadie_register_venue_agent' );

/** Register Roadie's ability category when another Extra Chill plugin has not. */
function extrachill_roadie_register_ability_category(): void {
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill' ) ) {
		return;
	}
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			'extrachill',
			array(
				'label'       => __( 'Extra Chill', 'extrachill-roadie' ),
				'description' => __( 'Extra Chill platform abilities.', 'extrachill-roadie' ),
			)
		);
	}
}
add_action( 'wp_abilities_api_categories_init', 'extrachill_roadie_register_ability_category' );

/** Register the bounded current-user venue instance selector. */
function extrachill_roadie_register_venue_agent_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/select-venue-roadie',
		array(
			'label'               => __( 'Select Venue Roadie', 'extrachill-roadie' ),
			'description'         => __( 'Materializes and returns the current user\'s selected venue-scoped Roadie identity.', 'extrachill-roadie' ),
			'category'            => 'extrachill',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'venue_term_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'venue_term_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'agent_id'      => array( 'type' => 'integer' ),
					'agent_slug'    => array( 'type' => 'string' ),
					'owner_user_id' => array( 'type' => 'integer' ),
					'instance_key'  => array( 'type' => 'string' ),
					'venue'         => array( 'type' => 'object' ),
				),
				'required'             => array( 'agent_id', 'agent_slug', 'owner_user_id', 'instance_key', 'venue' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_roadie_select_venue_agent',
			'permission_callback' => static fn(): bool => get_current_user_id() > 0,
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'extrachill_roadie_register_venue_agent_ability' );

/**
 * Fetch the Events-owned self-scoped managed venue projection.
 *
 * @return array<string,array<string,mixed>>|WP_Error Voices keyed by reference.
 */
function extrachill_roadie_get_managed_venue_voices() {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new WP_Error( 'roadie_venue_authority_unavailable', __( 'Venue authority is temporarily unavailable.', 'extrachill-roadie' ) );
	}

	$response = ec_cross_site_rest_request( 'events', 'GET', EXTRACHILL_ROADIE_VENUE_VOICES_PATH );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( ! is_array( $response ) || ! isset( $response['voices'] ) || ! is_array( $response['voices'] ) ) {
		return new WP_Error( 'roadie_venue_authority_invalid', __( 'Venue authority returned an invalid response.', 'extrachill-roadie' ) );
	}

	$voices = array();
	foreach ( $response['voices'] as $voice ) {
		if ( ! is_array( $voice ) ) {
			return new WP_Error( 'roadie_venue_authority_invalid', __( 'Venue authority returned an invalid venue.', 'extrachill-roadie' ) );
		}

		$term_id   = absint( $voice['term_id'] ?? 0 );
		$reference = sanitize_text_field( (string) ( $voice['reference'] ?? '' ) );
		if ( $term_id <= 0 || 'venue:' . $term_id !== $reference ) {
			return new WP_Error( 'roadie_venue_authority_invalid', __( 'Venue authority returned an invalid venue.', 'extrachill-roadie' ) );
		}

		$voices[ $reference ] = array(
			'reference'   => $reference,
			'term_id'     => $term_id,
			'name'        => sanitize_text_field( (string) ( $voice['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $voice['slug'] ?? '' ) ),
			'url'         => esc_url_raw( (string) ( $voice['url'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $voice['description'] ?? '' ) ),
		);
	}

	return $voices;
}

/** Materialize the selected venue identity after current authority is proven. */
function extrachill_roadie_select_venue_agent( array $input ) {
	$user_id       = get_current_user_id();
	$venue_term_id = absint( $input['venue_term_id'] ?? 0 );
	$reference     = 'venue:' . $venue_term_id;
	$voices        = extrachill_roadie_get_managed_venue_voices();

	if ( $user_id <= 0 ) {
		return new WP_Error( 'roadie_venue_authentication_required', __( 'Authentication is required.', 'extrachill-roadie' ) );
	}
	if ( is_wp_error( $voices ) ) {
		return $voices;
	}
	if ( $venue_term_id <= 0 || ! isset( $voices[ $reference ] ) ) {
		return new WP_Error( 'roadie_venue_not_managed', __( 'You do not actively manage that venue.', 'extrachill-roadie' ) );
	}
	if ( ! function_exists( 'wp_materialize_agent_identity' ) ) {
		return new WP_Error( 'roadie_venue_materializer_unavailable', __( 'Venue Roadie materialization is unavailable.', 'extrachill-roadie' ) );
	}

	try {
		$identity = wp_materialize_agent_identity(
			EXTRACHILL_ROADIE_VENUE_AGENT_SLUG,
			null,
			array(
				'owner_user_id' => $user_id,
				'instance_key'  => $reference,
				'meta'          => array( 'label' => $voices[ $reference ]['name'] . ' Roadie' ),
			)
		);
	} catch ( Throwable $exception ) {
		return new WP_Error( 'roadie_venue_materialization_failed', $exception->getMessage() );
	}

	if ( ! $identity instanceof \AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ) {
		return new WP_Error( 'roadie_venue_materialization_failed', __( 'Venue Roadie could not be materialized.', 'extrachill-roadie' ) );
	}

	return array(
		'agent_id'      => (int) $identity->id,
		'agent_slug'    => $identity->scope->agent_slug,
		'owner_user_id' => $identity->scope->owner_user_id,
		'instance_key'  => $identity->scope->instance_key,
		'venue'         => $voices[ $reference ],
	);
}

/**
 * Resolve and reauthorize a materialized venue identity for one operation.
 *
 * @return array<string,mixed>|WP_Error|null Null means the singleton Roadie path.
 */
function extrachill_roadie_resolve_venue_agent_scope( int $agent_id, int $acting_user_id ) {
	if ( $agent_id <= 0 || ! class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
		return null;
	}

	$agent = ( new \DataMachine\Core\Database\Agents\Agents() )->get_agent( $agent_id );
	if ( ! is_array( $agent ) || EXTRACHILL_ROADIE_VENUE_AGENT_SLUG !== sanitize_title( (string) ( $agent['agent_slug'] ?? '' ) ) ) {
		return null;
	}

	$owner_user_id = (int) ( $agent['owner_id'] ?? 0 );
	$instance_key  = strtolower( trim( (string) ( $agent['instance_key'] ?? '' ) ) );
	if ( $acting_user_id <= 0 || $owner_user_id !== $acting_user_id ) {
		return new WP_Error( 'roadie_venue_principal_mismatch', __( 'The acting human does not own this venue agent instance.', 'extrachill-roadie' ) );
	}
	if ( 1 !== preg_match( '/^venue:([1-9][0-9]*)$/', $instance_key, $matches ) ) {
		return new WP_Error( 'roadie_venue_scope_invalid', __( 'The venue agent identity has an invalid scope.', 'extrachill-roadie' ) );
	}

	$voices = extrachill_roadie_get_managed_venue_voices();
	if ( is_wp_error( $voices ) ) {
		return $voices;
	}
	if ( ! isset( $voices[ $instance_key ] ) ) {
		return new WP_Error( 'roadie_venue_authority_revoked', __( 'The acting human no longer manages this venue.', 'extrachill-roadie' ) );
	}

	return array(
		'agent_id'      => $agent_id,
		'agent_slug'    => EXTRACHILL_ROADIE_VENUE_AGENT_SLUG,
		'owner_user_id' => $owner_user_id,
		'instance_key'  => $instance_key,
		'venue_term_id' => (int) $matches[1],
		'public_voice'  => $instance_key,
		'venue'         => $voices[ $instance_key ],
	);
}

/** Return the identity-specific canonical workspace. */
function extrachill_roadie_venue_agent_workspace( int $agent_id ): array {
	return array(
		'workspace_type' => 'agent',
		'workspace_id'   => (string) $agent_id,
	);
}

/** Run a local cross-site operation with accountable human/effective agent separation. */
function extrachill_roadie_with_venue_agent_principal( int $acting_user_id, int $agent_id, callable $callback ) {
	if ( ! class_exists( '\\AgentsAPI\\AI\\WP_Agent_Execution_Principal' ) ) {
		return $callback();
	}

	$principal = \AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
		$acting_user_id,
		(string) $agent_id,
		\AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_CHAT,
		array(),
		'agent:' . $agent_id
	);
	$filter    = static function ( $resolved ) use ( $principal ) {
		return null === $resolved ? $principal : $resolved;
	};
	add_filter( 'agents_api_execution_principal', $filter, 100 );
	try {
		return $callback();
	} finally {
		remove_filter( 'agents_api_execution_principal', $filter, 100 );
	}
}
