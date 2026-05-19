<?php
/**
 * Smoke tests for Roadie frontend chat branding.
 *
 * Run with: php tests/frontend-chat-branding-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['extrachill_roadie_test_filters'] = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['extrachill_roadie_test_filters'][ $hook ][] = $callback;
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['extrachill_roadie_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function sanitize_title( $value ): string {
	$value = strtolower( (string) $value );
	$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function roadie_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/permissions.php';
require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';

$roadie_config = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'roadie' ) );
roadie_smoke_assert( 'Roadie' === ( $roadie_config['fab_label'] ?? '' ), 'Roadie config should brand the frontend chat launcher.' );

$default_config = apply_filters( 'frontend_agent_chat_config', array() );
roadie_smoke_assert( 'Roadie' === ( $default_config['fab_label'] ?? '' ), 'Empty config should default to Roadie branding when Roadie owns the consumer plugin.' );

$other_config = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'other-agent' ) );
roadie_smoke_assert( ! isset( $other_config['fab_label'] ), 'Other explicitly configured agents should keep their launcher branding.' );

echo "Roadie frontend chat branding smoke passed (3 assertions).\n";
