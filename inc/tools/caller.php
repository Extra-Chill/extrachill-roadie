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
 * Canonical calling-user context takes precedence over ambient WordPress
 * state. An explicit zero means no human caller and must never inherit the
 * runtime, agent, or transcript owner. Direct REST/CLI calls without canonical
 * caller context may use their authenticated current user.
 *
 * @param array $parameters Merged tool parameters.
 * @return int Acting user ID, or 0 when no authenticated caller exists.
 */
function extrachill_roadie_resolve_acting_caller( array $parameters ): int {
	$has_caller_context = array_key_exists( 'calling_user_id', $parameters );
	$calling_user_id    = 0;

	if ( function_exists( 'datamachine_get_calling_user_id' ) ) {
		$calling_user_id = max( 0, (int) datamachine_get_calling_user_id( $parameters ) );
	} elseif ( $has_caller_context && is_numeric( $parameters['calling_user_id'] ) ) {
		$calling_user_id = max( 0, (int) $parameters['calling_user_id'] );
	}

	if ( $calling_user_id > 0 || $has_caller_context ) {
		return $calling_user_id;
	}

	$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	return max( 0, $current_user_id );
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
