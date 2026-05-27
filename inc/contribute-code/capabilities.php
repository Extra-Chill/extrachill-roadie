<?php
/**
 * Capability mapping for the contribute-code chat tools.
 *
 * Adds `extrachill_propose_code` and grants it to administrators and editors
 * by default. Override the role grant via the
 * `extrachill_roadie_propose_code_roles` filter; override per-user via the
 * `user_has_cap` filter as usual.
 *
 * Both `propose_code_change` and `apply_code_change` gate on this cap. There
 * is no separate "approve" cap because chat approval already happens through
 * the same conversation surface — if you can propose, you can approve.
 *
 * @package ExtraChillRoadie\ContributeCode
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_ROADIE_PROPOSE_CODE_CAP = 'extrachill_propose_code';

/**
 * Roles that get `extrachill_propose_code` by default.
 *
 * @since 0.7.0
 * @return string[]
 */
function extrachill_roadie_propose_code_default_roles(): array {
	$defaults = array( 'administrator', 'editor' );

	/**
	 * Filter the roles that automatically get the contribute-code capability.
	 *
	 * @since 0.7.0
	 *
	 * @param string[] $defaults Default role slugs.
	 */
	$roles = apply_filters( 'extrachill_roadie_propose_code_roles', $defaults );

	if ( ! is_array( $roles ) ) {
		return $defaults;
	}

	return array_values( array_unique( array_map( 'strval', $roles ) ) );
}

/**
 * Grant the contribute-code capability via the `user_has_cap` filter.
 *
 * Using `user_has_cap` (instead of writing to role objects on activation)
 * keeps the grant role-driven and filterable, and avoids touching DB state
 * on plugin upgrades.
 *
 * @since 0.7.0
 *
 * @param array<string,bool> $allcaps All capabilities the user currently has.
 * @param array<int,string>  $caps    Required primitive capabilities for the requested cap.
 * @param array<int,mixed>   $args    [0] requested cap, [1] user id, [2] object id.
 * @param WP_User|null       $user    User object.
 * @return array<string,bool>
 */
function extrachill_roadie_grant_propose_code_cap( array $allcaps, array $caps, array $args, $user ): array {
	unset( $caps, $args );

	if ( ! $user || empty( $user->roles ) ) {
		return $allcaps;
	}

	if ( ! empty( $allcaps[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] ) ) {
		return $allcaps;
	}

	$granted_roles = extrachill_roadie_propose_code_default_roles();
	foreach ( (array) $user->roles as $role ) {
		if ( in_array( $role, $granted_roles, true ) ) {
			$allcaps[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] = true;
			return $allcaps;
		}
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'extrachill_roadie_grant_propose_code_cap', 10, 4 );

/*
 * Apply-back GitHub credentials are no longer sourced from the PHP process
 * environment. The previous `extrachill_roadie_apply_github_token_env_name()`
 * + `_present()` helpers (and the `extrachill_roadie_apply_github_token_env`
 * filter) were removed when apply-back switched to
 * `DataMachineCode\Support\GitHubCredentialResolver`. Tokens are now minted
 * per-repo via the configured credential profile and threaded into each
 * shell-out via per-command env. Verify configuration with:
 *
 *   wp --allow-root --path=/var/www/extrachill.com datamachine-code github status
 */
