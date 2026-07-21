<?php
/**
 * Smoke tests for Roadie's canonical workspace and approval-origin filters.
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

function absint( $value ): int {
	return abs( (int) $value );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function get_current_network_id(): int {
	return (int) $GLOBALS['roadie_scope_network_id'];
}

function get_site( int $blog_id ) {
	return in_array( $blog_id, array( 1, 7 ), true ) ? (object) array( 'blog_id' => $blog_id ) : null;
}

function get_home_url( int $blog_id, string $path = '' ): string {
	$base = 1 === $blog_id ? 'https://extrachill.com' : 'https://events.extrachill.com';
	return $base . $path;
}

function get_bloginfo( string $show ): string {
	unset( $show );
	return 1 === $GLOBALS['roadie_scope_blog_id'] ? 'Extra Chill' : 'Extra Chill Events';
}

function home_url(): string {
	return get_home_url( (int) $GLOBALS['roadie_scope_blog_id'] );
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

$expected_workspace = array(
	'workspace_type' => 'network',
	'workspace_id'   => '9',
);
$workspace_abilities = array(
	'agents/chat',
	'agents/queue-chat-message',
	'agents/list-conversation-sessions',
	'agents/get-conversation-session',
	'agents/mark-conversation-session-read',
	'agents/delete-conversation-session',
	'agents/update-conversation-session-title',
	'agents/get-chat-run',
	'agents/list-chat-run-events',
	'agents/cancel-chat-run',
);

foreach ( $workspace_abilities as $ability ) {
	$input = apply_filters(
		'frontend_agent_chat_ability_input',
		array( 'context' => 'chat' ),
		$ability,
		new WP_REST_Request(),
		'roadie',
		array( 'agent_slug' => 'roadie' )
	);
	roadie_scope_assert( $expected_workspace === $input['workspace'], $ability . ' should receive the canonical network workspace.' );
	if ( 'agents/list-conversation-sessions' === $ability ) {
		roadie_scope_assert( 'chat,roadie' === $input['context'], 'History should query Roadie\'s exact combined context.' );
	}
}

$legacy_chat = apply_filters(
	'frontend_agent_chat_chat_input',
	array( 'agent' => 'roadie' ),
	new WP_REST_Request( array( 'page_url' => 'https://events.extrachill.com/calendar/' ) ),
	'roadie',
	array()
);
roadie_scope_assert( ! isset( $legacy_chat['workspace'] ), 'Legacy chat filter should no longer own workspace assignment.' );
roadie_scope_assert( array( 'chat', 'roadie' ) === $legacy_chat['modes'], 'Legacy chat filter should retain combined execution modes.' );

$other_agent = apply_filters(
	'frontend_agent_chat_ability_input',
	array(),
	'agents/get-conversation-session',
	new WP_REST_Request(),
	'other-agent',
	array( 'agent_slug' => 'other-agent' )
);
roadie_scope_assert( ! isset( $other_agent['workspace'] ), 'Other agents should keep their existing workspace behavior.' );

$valid_origin = array(
	'workspace' => array(
		'workspace_type' => 'site',
		'workspace_id'   => 'https://events.extrachill.com',
	),
	'metadata'  => array(
		'datamachine' => array(
			'context' => array(
				'wordpress' => array( 'blog_id' => 7 ),
				'trace_id'  => 'must-not-project',
			),
		),
		'private'     => 'must-not-project',
	),
	'context'   => array( 'browser_supplied' => 'must-not-project' ),
);
$request      = new WP_REST_Request( array( 'client_context' => array( 'wordpress' => array( 'blog_id' => 1 ) ) ) );
$resolved     = apply_filters(
	'frontend_agent_chat_pending_action_resolve_input',
	array( 'action_id' => 'act_valid', 'decision' => 'accepted' ),
	$request,
	$valid_origin,
	array( 'agent_slug' => 'roadie' )
);
roadie_scope_assert( $valid_origin['workspace'] === $resolved['workspace'], 'Validated stored workspace should be projected unchanged.' );
roadie_scope_assert( array( 'wordpress' => array( 'blog_id' => 7 ) ) === $resolved['context'], 'Only canonical WordPress origin should be projected.' );
roadie_scope_assert( ! isset( $resolved['metadata'] ), 'Opaque pending-action metadata must not reach the resolver.' );
roadie_scope_assert( ! isset( $resolved['context']['browser_supplied'] ), 'Browser client context must never become resolver context.' );

$forged_origin                         = $valid_origin;
$forged_origin['workspace']['workspace_id'] = 'https://extrachill.com';
$forged                                = apply_filters(
	'frontend_agent_chat_pending_action_resolve_input',
	array( 'action_id' => 'act_forged', 'decision' => 'accepted' ),
	$request,
	$forged_origin,
	array( 'agent_slug' => 'roadie' )
);
roadie_scope_assert( PHP_INT_MAX === $forged['context']['wordpress']['blog_id'], 'Forged workspace/origin pairs should fail closed.' );
roadie_scope_assert( ! isset( $forged['workspace'] ), 'Forged workspaces should not be projected.' );

echo "Roadie network chat scope smoke passed (20 assertions).\n";
