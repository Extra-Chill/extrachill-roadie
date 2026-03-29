<?php
/**
 * Permission bridges — connects EC team membership to DM agent access.
 *
 * @package ExtraChillRoadie
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grant team members access to the roadie agent.
 *
 * Hooks `datamachine_can_access_agent` to check EC team membership
 * when the agent in question is the roadie agent. This bridges
 * EC's custom team meta to DM's permission system.
 *
 * @since 0.3.0
 *
 * @param bool   $can_access   Whether the user can access the agent.
 * @param int    $agent_id     Agent ID.
 * @param int    $user_id      User ID.
 * @param string $minimum_role Minimum role required.
 * @return bool
 */
function extrachill_roadie_team_access_bridge( bool $can_access, int $agent_id, int $user_id, string $minimum_role ): bool {
	// If already granted, no need to check further.
	if ( $can_access ) {
		return true;
	}

	// Only apply to the roadie agent.
	$roadie_agent_id = extrachill_roadie_get_agent_id();
	if ( $roadie_agent_id <= 0 || $agent_id !== $roadie_agent_id ) {
		return $can_access;
	}

	// Check EC team membership.
	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id ) ) {
		return true;
	}

	return $can_access;
}
add_filter( 'datamachine_can_access_agent', 'extrachill_roadie_team_access_bridge', 10, 4 );

/**
 * Get the roadie agent ID from the frontend chat config.
 *
 * Resolves the agent slug from the current site's config and caches it.
 *
 * @since 0.3.0
 *
 * @return int Agent ID, or 0 if not configured.
 */
function extrachill_roadie_get_agent_id(): int {
	static $agent_id = null;

	if ( $agent_id !== null ) {
		return $agent_id;
	}

	$agent_id = 0;

	if ( ! function_exists( 'data_machine_frontend_chat_get_config' ) ) {
		return $agent_id;
	}

	$config = data_machine_frontend_chat_get_config();
	if ( empty( $config['agent_slug'] ) ) {
		return $agent_id;
	}

	if ( ! function_exists( 'data_machine_frontend_chat_resolve_agent' ) ) {
		return $agent_id;
	}

	$agent = data_machine_frontend_chat_resolve_agent( $config['agent_slug'] );
	if ( $agent && ! empty( $agent['agent_id'] ) ) {
		$agent_id = (int) $agent['agent_id'];
	}

	return $agent_id;
}
