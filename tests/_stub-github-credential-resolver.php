<?php
/**
 * Test stub for DataMachineCode\Support\GitHubCredentialResolver.
 *
 * Defaults to "configured + returns a fake token" so smoke tests that only
 * care about command shape pass without per-test setup. Tests that need
 * other behavior reach into the static state directly:
 *
 *   GitHubCredentialResolver::$test_is_configured = false;
 *   GitHubCredentialResolver::$test_resolve_return = new WP_Error( 'x', 'no creds' );
 *   GitHubCredentialResolver::$test_resolve_return = array( 'token' => 'ghs_TEST_TOKEN', ... );
 *
 * Static call history is captured in
 * $GLOBALS['ec_roadie_test_resolver_calls'] for assertions about selector
 * shape (e.g. that `repo` was passed).
 *
 * @package ExtraChillRoadie\Tests
 */

namespace DataMachineCode\Support;

final class GitHubCredentialResolver {

	public static bool $test_is_configured = true;

	/** @var array<string,mixed>|\WP_Error */
	public static $test_resolve_return = array(
		'mode'          => 'app',
		'token'         => 'ghs_TEST_TOKEN',
		'authorization' => 'Bearer ghs_TEST_TOKEN',
		'profile_id'    => 'homeboy-ci',
		'cached'        => false,
		'expires_at'    => '2099-01-01T00:00:00Z',
	);

	public static function isConfigured(): bool {
		$GLOBALS['ec_roadie_test_resolver_calls'][] = array( 'method' => 'isConfigured' );
		return self::$test_is_configured;
	}

	/**
	 * @param callable|null            $http_request
	 * @param int|null                 $now
	 * @param array<string,mixed>|null $selector
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function resolve( ?callable $http_request = null, ?int $now = null, ?array $selector = null ) {
		$GLOBALS['ec_roadie_test_resolver_calls'][] = array(
			'method'   => 'resolve',
			'selector' => $selector,
		);
		return self::$test_resolve_return;
	}

	public static function ec_roadie_test_reset(): void {
		self::$test_is_configured  = true;
		self::$test_resolve_return = array(
			'mode'          => 'app',
			'token'         => 'ghs_TEST_TOKEN',
			'authorization' => 'Bearer ghs_TEST_TOKEN',
			'profile_id'    => 'homeboy-ci',
			'cached'        => false,
			'expires_at'    => '2099-01-01T00:00:00Z',
		);
		$GLOBALS['ec_roadie_test_resolver_calls'] = array();
	}
}
