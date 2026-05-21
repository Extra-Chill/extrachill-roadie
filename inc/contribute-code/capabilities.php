<?php
/**
 * Capability mapping for the contribute-code chat tool.
 *
 * Adds `extrachill_propose_code` and grants it to administrators and editors
 * by default. Override the role grant via the
 * `extrachill_roadie_propose_code_roles` filter; override per-user via the
 * `user_has_cap` filter as usual.
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
 * Resolve the network option name that holds the platform bot GitHub token name.
 *
 * The option stores ONLY the env var NAME (e.g. "GITHUB_TOKEN"). The actual
 * token value lives in the WordPress PHP process environment — typically in
 * `wp-config.php` (`putenv()`) or `.env` — because that is what the WP
 * Codebox `secret_env` contract requires.
 *
 * @since 0.7.0
 * @return string
 */
function extrachill_roadie_github_token_option_name(): string {
	return 'extrachill_roadie_github_token_env';
}

/**
 * Resolve the configured GitHub token env var name.
 *
 * Defaults to `GITHUB_TOKEN` when the network option is unset. The actual
 * value is read from `getenv()` by WP Codebox at sandbox-dispatch time.
 *
 * @since 0.7.0
 * @return string Env var name (never empty).
 */
function extrachill_roadie_github_token_env_name(): string {
	$option = extrachill_roadie_github_token_option_name();
	$name   = '';
	if ( function_exists( 'get_site_option' ) ) {
		$name = (string) get_site_option( $option, '' );
	}
	if ( '' === $name && function_exists( 'get_option' ) ) {
		$name = (string) get_option( $option, '' );
	}
	if ( '' === $name ) {
		$name = 'GITHUB_TOKEN';
	}

	/**
	 * Filter the env var name that holds the platform bot GitHub token.
	 *
	 * @since 0.7.0
	 *
	 * @param string $name Env var name.
	 */
	return (string) apply_filters( 'extrachill_roadie_github_token_env_name', $name );
}

/**
 * Check whether the env var named by extrachill_roadie_github_token_env_name()
 * has a non-empty value in the parent process environment.
 *
 * Used by the chat tool to fail fast with a clear error message.
 *
 * @since 0.7.0
 * @return bool
 */
function extrachill_roadie_github_token_is_present(): bool {
	$name = extrachill_roadie_github_token_env_name();
	if ( '' === $name ) {
		return false;
	}
	$value = getenv( $name );
	return is_string( $value ) && '' !== trim( $value );
}
