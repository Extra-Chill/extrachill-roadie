<?php
/**
 * Authoritative acting-caller helpers for Roadie tools.
 *
 * @package ExtraChillRoadie\Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the authenticated human on whose behalf a tool executes.
 *
 * The tool executor injects this value from trusted caller context. An
 * explicit zero means no human caller and must never inherit the runtime,
 * agent, transcript owner, or ambient WordPress user.
 *
 * @param array $parameters Merged tool parameters.
 * @return int Acting user ID, or 0 when no authenticated caller exists.
 */
function extrachill_roadie_resolve_acting_caller( array $parameters ): int {
	if ( ! array_key_exists( 'calling_user_id', $parameters ) || ! is_numeric( $parameters['calling_user_id'] ) ) {
		return 0;
	}

	return max( 0, (int) $parameters['calling_user_id'] );
}

/**
 * Check a capability against the authoritative acting caller.
 *
 * @param array  $parameters Merged tool parameters.
 * @param string $capability Required WordPress capability.
 * @return bool Whether the authenticated acting caller has the capability.
 */
function extrachill_roadie_acting_caller_can( array $parameters, string $capability ): bool {
	$user_id = extrachill_roadie_resolve_acting_caller( $parameters );
	return $user_id > 0 && function_exists( 'user_can' ) && user_can( $user_id, $capability );
}
