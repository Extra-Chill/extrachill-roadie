<?php
/**
 * Smoke tests for Roadie's cross-subsite conversation scope.
 *
 * Run with: php tests/network-chat-scope-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['roadie_scope_filters']    = array();
$GLOBALS['roadie_scope_blog_id']    = 1;
$GLOBALS['roadie_scope_network_id'] = 9;

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['roadie_scope_filters'][ $hook ][] = $callback;
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['roadie_scope_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function sanitize_title( $value ): string {
	return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $value ) ), '-' );
}

function sanitize_key( $value ): string {
	return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function esc_url_raw( $value ): string {
	return filter_var( (string) $value, FILTER_SANITIZE_URL );
}

function get_current_network_id(): int {
	return (int) $GLOBALS['roadie_scope_network_id'];
}

function get_bloginfo( string $show ): string {
	unset( $show );
	return 1 === $GLOBALS['roadie_scope_blog_id'] ? 'Extra Chill' : 'Extra Chill Events';
}

function home_url(): string {
	return 1 === $GLOBALS['roadie_scope_blog_id'] ? 'https://extrachill.com' : 'https://events.extrachill.com';
}

function wp_parse_url( string $url, int $component ) {
	return parse_url( $url, $component );
}

function __( $text, $domain = null ): string {
	unset( $domain );
	return (string) $text;
}

class WP_REST_Request {
	private array $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_header( string $key ): string {
		unset( $key );
		return '';
	}
}

function roadie_scope_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

define( 'EXTRACHILL_ROADIE_AGENT_SLUG', 'roadie' );
define( 'EXTRACHILL_ROADIE_AGENT_NAME', 'Roadie' );
require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';

$inputs = array();
foreach ( array( 1, 7 ) as $blog_id ) {
	$GLOBALS['roadie_scope_blog_id'] = $blog_id;
	$inputs[ $blog_id ]              = apply_filters(
		'frontend_agent_chat_chat_input',
		array( 'agent' => 'roadie' ),
		new WP_REST_Request( array( 'page_url' => home_url() . '/calendar/' ) ),
		'roadie',
		array()
	);
}

$expected_workspace = array(
	'workspace_type' => 'network',
	'workspace_id'   => '9',
);
roadie_scope_assert( $expected_workspace === $inputs[1]['workspace'], 'Main-site chat should use the network workspace.' );
roadie_scope_assert( $expected_workspace === $inputs[7]['workspace'], 'Subsite chat should use the same network workspace.' );
roadie_scope_assert( array( 'chat', 'roadie' ) === $inputs[1]['modes'], 'Roadie chat should retain the combined execution modes.' );
roadie_scope_assert( 'extrachill.com' === $inputs[1]['client_context']['site_host'], 'Main-site origin context should be preserved.' );
roadie_scope_assert( 'events.extrachill.com' === $inputs[7]['client_context']['site_host'], 'Subsite origin context should be preserved independently of transcript scope.' );

$history = apply_filters(
	'frontend_agent_chat_session_list_input',
	array(
		'agent'   => 'roadie',
		'context' => 'chat',
	),
	new WP_REST_Request(),
	'roadie',
	array()
);
roadie_scope_assert( 'chat,roadie' === $history['context'], 'History should query Roadie\'s exact combined context.' );
roadie_scope_assert( $expected_workspace === $history['workspace'], 'History should query the same network workspace as creation.' );

$other_agent = apply_filters(
	'frontend_agent_chat_session_list_input',
	array( 'context' => 'chat' ),
	new WP_REST_Request(),
	'other-agent',
	array()
);
roadie_scope_assert( ! isset( $other_agent['workspace'] ), 'Other agents should keep their existing workspace behavior.' );

echo "Roadie network chat scope smoke passed (8 assertions).\n";
