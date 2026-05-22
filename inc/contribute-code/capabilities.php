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

/**
 * Env var name holding the GitHub token used by the apply-back tool.
 *
 * Apply-back shells out to `gh pr create` (and underlying `git push`) on the
 * host. Both pick up `GITHUB_TOKEN` from the process environment. The token
 * never crosses into the sandbox — sandboxes don't push.
 *
 * Override the env var name via the `extrachill_roadie_apply_github_token_env`
 * filter if your host exports the token under a different name.
 *
 * @since 0.7.0
 * @return string
 */
function extrachill_roadie_apply_github_token_env_name(): string {
	/**
	 * Filter the env var name holding the GitHub token for apply-back.
	 *
	 * @since 0.7.0
	 *
	 * @param string $default Default env var name.
	 */
	return (string) apply_filters( 'extrachill_roadie_apply_github_token_env', 'GITHUB_TOKEN' );
}

/**
 * Check whether the apply-back GitHub token env var is present and non-empty.
 *
 * @since 0.7.0
 * @return bool
 */
function extrachill_roadie_apply_github_token_present(): bool {
	$name = extrachill_roadie_apply_github_token_env_name();
	if ( '' === $name ) {
		return false;
	}
	$value = getenv( $name );
	return is_string( $value ) && '' !== trim( $value );
}
