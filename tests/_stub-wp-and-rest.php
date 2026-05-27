<?php
/**
 * Test stubs for WordPress + cross-site REST primitives.
 *
 * Reset between tests via ec_roadie_test_reset(). Records every
 * ec_cross_site_rest_request() invocation into
 * $GLOBALS['ec_roadie_test_rest_calls'] so smoke tests can assert that the
 * correct user_id reached the cross-site helper.
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function ec_roadie_test_reset(): void {
	$GLOBALS['ec_roadie_test_current_user']    = 0;
	$GLOBALS['ec_roadie_test_user_caps']       = array();
	$GLOBALS['ec_roadie_test_rest_calls']      = array();
	$GLOBALS['ec_roadie_test_registered_tools'] = $GLOBALS['ec_roadie_test_registered_tools'] ?? array();
	$GLOBALS['ec_roadie_test_rest_response']   = array( 'ok' => true );
}

ec_roadie_test_reset();

function get_current_user_id(): int {
	return (int) ( $GLOBALS['ec_roadie_test_current_user'] ?? 0 );
}

function ec_roadie_test_login_as( int $user_id ): void {
	$GLOBALS['ec_roadie_test_current_user'] = $user_id;
}

function ec_roadie_test_grant_cap( int $user_id, string $cap ): void {
	$GLOBALS['ec_roadie_test_user_caps'][ $user_id ][ $cap ] = true;
}

function user_can( $user, string $cap ): bool {
	$user_id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user;
	return ! empty( $GLOBALS['ec_roadie_test_user_caps'][ $user_id ][ $cap ] );
}

function current_user_can( string $cap ): bool {
	return user_can( get_current_user_id(), $cap );
}

function wp_set_current_user( int $user_id ): void {
	$GLOBALS['ec_roadie_test_current_user'] = $user_id;
}

function get_user_meta( int $user_id, string $key, bool $single = true ) {
	unset( $single );
	return $GLOBALS['ec_roadie_test_user_meta'][ $user_id ][ $key ] ?? '';
}

function ec_roadie_test_set_user_meta( int $user_id, string $key, $value ): void {
	$GLOBALS['ec_roadie_test_user_meta'][ $user_id ][ $key ] = $value;
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public string $code;
	public string $message;
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message(): string {
		return $this->message;
	}
}

function sprintf_safe( string $format, ...$args ): string {
	return vsprintf( $format, $args );
}

function ec_get_blog_id( string $key ): ?int {
	$map = array( 'main' => 1, 'community' => 2, 'artist' => 3 );
	return $map[ $key ] ?? null;
}

function switch_to_blog( int $blog_id ): void {
	$GLOBALS['ec_roadie_test_blog'] = $blog_id;
}

function restore_current_blog(): void {
	$GLOBALS['ec_roadie_test_blog'] = 1;
}

function get_post( int $id ) {
	return $GLOBALS['ec_roadie_test_posts'][ $id ] ?? null;
}

/**
 * Stub of ec_cross_site_rest_request: records the call shape, optionally
 * simulates the user-context switch the real helper performs, and returns
 * whatever the test sets in $GLOBALS['ec_roadie_test_rest_response'].
 */
function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) {
	$entry = array(
		'site_key' => $site_key,
		'method'   => $method,
		'path'     => $path,
		'args'     => $args,
		// Snapshot the effective user BEFORE the helper would switch context.
		// In real life ec_cross_site_rest_request wraps wp_set_current_user
		// in a try/finally; recording the requested user_id here is what tests
		// actually want to assert against.
		'effective_user' => isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id(),
	);
	$GLOBALS['ec_roadie_test_rest_calls'][] = $entry;

	$response = $GLOBALS['ec_roadie_test_rest_response'];
	if ( $response instanceof WP_Error ) {
		return $response;
	}
	return $response;
}
