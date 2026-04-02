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

use DataMachine\Core\Database\Agents\Agents;

/**
 * Canonical Roadie agent slug.
 */
const EXTRACHILL_ROADIE_AGENT_SLUG = 'roadie';

/**
 * Canonical Roadie agent name.
 */
const EXTRACHILL_ROADIE_AGENT_NAME = 'Roadie';

/**
 * Resolve redirect URIs allowed for browser-based bridge auth.
 *
 * Keep this explicit and auditable. Same-site callbacks are already allowed by
 * Data Machine core; this filter is for external bridge callback URLs.
 *
 * Override in environment-specific code with:
 * add_filter( 'extrachill_roadie_allowed_redirect_uris', fn() => array( ... ) );
 *
 * @since 0.3.1
 * @return string[]
 */
function extrachill_roadie_allowed_redirect_uris(): array {
	$defaults = array();

	/**
	 * Filter the external redirect URIs allowed for Roadie authorization.
	 *
	 * @since 0.3.1
	 *
	 * @param string[] $defaults Default patterns.
	 */
	$uris = apply_filters( 'extrachill_roadie_allowed_redirect_uris', $defaults );

	if ( ! is_array( $uris ) ) {
		return $defaults;
	}

	return array_values( array_filter( array_map( 'strval', $uris ) ) );
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

	// Use the existing Extra Chill team access bridge as the source of truth.
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

	$agent = extrachill_roadie_get_agent();
	if ( $agent && ! empty( $agent['agent_id'] ) ) {
		$agent_id = (int) $agent['agent_id'];
	}

	return $agent_id;
}

/**
 * Get the canonical Roadie agent row.
 *
 * @since 0.3.1
 * @return array|null
 */
function extrachill_roadie_get_agent(): ?array {
	static $agent = null;

	if ( null !== $agent ) {
		return $agent;
	}

	if ( ! class_exists( Agents::class ) ) {
		return null;
	}

	$repo  = new Agents();
	$agent = $repo->get_by_slug( EXTRACHILL_ROADIE_AGENT_SLUG );

	return $agent;
}

/**
 * Ensure Roadie exists and its auth policy stays explicit.
 *
 * - canonical slug/name
 * - active status
 * - explicit redirect allowlist for external bridge callbacks
 *
 * This is intentionally narrow and auditable.
 *
 * @since 0.3.1
 * @return void
 */
function extrachill_roadie_sync_agent_policy(): void {
	if ( ! class_exists( Agents::class ) ) {
		return;
	}

	$repo  = new Agents();
	$agent = $repo->get_by_slug( EXTRACHILL_ROADIE_AGENT_SLUG );

	if ( ! $agent ) {
		return;
	}

	$config               = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
	$existing_redirects   = array_values( array_filter( array_map( 'strval', $config['allowed_redirect_uris'] ?? array() ) ) );
	$configured_redirects = extrachill_roadie_allowed_redirect_uris();
	$merged_redirects     = array_values( array_unique( array_merge( $existing_redirects, $configured_redirects ) ) );

	$needs_update = false;

	if ( ( $agent['agent_name'] ?? '' ) !== EXTRACHILL_ROADIE_AGENT_NAME ) {
		$needs_update = true;
	}

	if ( ( $agent['status'] ?? '' ) !== 'active' ) {
		$needs_update = true;
	}

	if ( $merged_redirects !== $existing_redirects ) {
		$needs_update = true;
	}

	if ( ! $needs_update ) {
		return;
	}

	$config['allowed_redirect_uris'] = $merged_redirects;

	$updates = array(
		'agent_name'   => EXTRACHILL_ROADIE_AGENT_NAME,
		'status'       => 'active',
		'agent_config' => $config,
	);

	$repo->update_agent( (int) $agent['agent_id'], $updates );
}
