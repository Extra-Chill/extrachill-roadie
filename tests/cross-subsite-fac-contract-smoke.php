<?php
/**
 * Cross-subsite integration smoke against the real Frontend Agent Chat adapter.
 *
 * Run with:
 * ROADIE_FRONTEND_AGENT_CHAT_DIR=/path/to/frontend-agent-chat php tests/cross-subsite-fac-contract-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE' ) ) {
	define( 'FRONTEND_AGENT_CHAT_BROWSER_COOKIE', 'frontend_agent_chat_browser' );
}

$fac_dir = getenv( 'ROADIE_FRONTEND_AGENT_CHAT_DIR' ) ?: '/var/lib/datamachine/workspace/frontend-agent-chat';
if ( ! is_file( $fac_dir . '/inc/config.php' ) || ! is_file( $fac_dir . '/inc/rest.php' ) ) {
	echo "Roadie cross-subsite FAC contract smoke skipped (set ROADIE_FRONTEND_AGENT_CHAT_DIR).\n";
	exit( 0 );
}

$GLOBALS['roadie_fac_filters']       = array();
$GLOBALS['roadie_fac_blog_id']       = 1;
$GLOBALS['roadie_fac_sessions']      = array();
$GLOBALS['roadie_fac_calls']         = array();
$GLOBALS['roadie_fac_read_supported'] = false;
$GLOBALS['roadie_fac_pending']       = array(
	'act_valid' => array(
		'workspace' => array( 'workspace_type' => 'site', 'workspace_id' => 'https://events.extrachill.com' ),
		'blog_id'   => 7,
	),
);

class WP_Error {
	public function __construct( public string $code, public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string {
		return $this->code;
	}
}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
	public const DELETABLE = 'DELETE';
}

class WP_REST_Response {}

class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $name ) {
		return $this->params[ $name ] ?? null;
	}
	public function get_header( string $name ): string {
		unset( $name );
		return '';
	}
	public function get_route(): string {
		return '';
	}
	public function offsetExists( mixed $offset ): bool {
		return isset( $this->params[ $offset ] );
	}
	public function offsetGet( mixed $offset ): mixed {
		return $this->params[ $offset ] ?? null;
	}
	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->params[ $offset ] = $value;
	}
	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

class RoadieFACContractAbility {
	public function __construct( private string $name ) {}

	public function execute( array $input ) {
		$GLOBALS['roadie_fac_calls'][] = array( $this->name, $input, $GLOBALS['roadie_fac_blog_id'] );

		if ( 'agents/resolve-pending-action' === $this->name ) {
			$stored  = $GLOBALS['roadie_fac_pending'][ $input['action_id'] ?? '' ] ?? null;
			$blog_id = (int) ( $input['context']['wordpress']['blog_id'] ?? 0 );
			if ( ! is_array( $stored ) || $stored['blog_id'] !== $blog_id || $stored['workspace'] !== ( $input['workspace'] ?? null ) ) {
				return new WP_Error( 'pending_action_origin_denied' );
			}
			return array( 'action_id' => $input['action_id'], 'decision' => $input['decision'] );
		}

		$workspace = is_array( $input['workspace'] ?? null ) ? $input['workspace'] : array();
		if ( 'agents/chat' === $this->name ) {
			$session_id = (string) ( $input['session_id'] ?? '' );
			if ( '' === $session_id ) {
				$session_id = 'roadie-session';
				$GLOBALS['roadie_fac_sessions'][ $session_id ] = array(
					'session_id'     => $session_id,
					'workspace_type' => $workspace['workspace_type'] ?? '',
					'workspace_id'   => $workspace['workspace_id'] ?? '',
					'title'          => '',
					'messages'       => array(),
					'metadata'       => array(),
				);
			}
			if ( ! self::owns( $session_id, $workspace ) ) {
				return new WP_Error( 'workspace_mismatch' );
			}
			$GLOBALS['roadie_fac_sessions'][ $session_id ]['messages'][] = array( 'role' => 'user', 'content' => (string) ( $input['message'] ?? '' ) );
			return array( 'session_id' => $session_id, 'messages' => $GLOBALS['roadie_fac_sessions'][ $session_id ]['messages'] );
		}

		if ( 'agents/list-conversation-sessions' === $this->name ) {
			return array(
				'sessions' => array_values(
					array_filter(
						$GLOBALS['roadie_fac_sessions'],
						static fn( array $session ): bool => self::matches( $session, $workspace )
					)
				),
			);
		}

		$session_id = (string) ( $input['session_id'] ?? '' );
		if ( ! self::owns( $session_id, $workspace ) ) {
			return new WP_Error( 'workspace_mismatch' );
		}

		return match ( $this->name ) {
			'agents/queue-chat-message' => array( 'queued_message_id' => 'queued-1', 'session_id' => $session_id, 'run_id' => 'run-1' ),
			'agents/get-conversation-session' => array( 'session' => $GLOBALS['roadie_fac_sessions'][ $session_id ] ),
			'agents/mark-conversation-session-read' => self::mark_read( $session_id ),
			'agents/delete-conversation-session' => self::delete( $session_id ),
			'agents/update-conversation-session-title' => self::title( $session_id, (string) ( $input['title'] ?? '' ) ),
			'agents/get-chat-run' => self::run( $session_id, $input, 'running' ),
			'agents/list-chat-run-events' => self::run( $session_id, $input, 'running' ) + array( 'events' => array(), 'cursor' => '', 'has_more' => false ),
			'agents/cancel-chat-run' => self::run( $session_id, $input, 'cancelling' ) + array( 'cancelled' => true ),
			default => array(),
		};
	}

	private static function matches( array $session, array $workspace ): bool {
		return ( $session['workspace_type'] ?? '' ) === ( $workspace['workspace_type'] ?? '' )
			&& ( $session['workspace_id'] ?? '' ) === ( $workspace['workspace_id'] ?? '' );
	}

	private static function owns( string $session_id, array $workspace ): bool {
		$session = $GLOBALS['roadie_fac_sessions'][ $session_id ] ?? null;
		return is_array( $session ) && self::matches( $session, $workspace );
	}

	private static function mark_read( string $session_id ): array {
		$GLOBALS['roadie_fac_sessions'][ $session_id ]['last_read_at'] = '2026-07-21T12:00:00Z';
		return array( 'persisted' => true, 'last_read_at' => '2026-07-21T12:00:00Z' );
	}

	private static function delete( string $session_id ): array {
		unset( $GLOBALS['roadie_fac_sessions'][ $session_id ] );
		return array( 'deleted' => true );
	}

	private static function title( string $session_id, string $title ): array {
		$GLOBALS['roadie_fac_sessions'][ $session_id ]['title'] = $title;
		return array( 'session' => $GLOBALS['roadie_fac_sessions'][ $session_id ] );
	}

	private static function run( string $session_id, array $input, string $status ): array {
		return array( 'run_id' => (string) ( $input['run_id'] ?? '' ), 'session_id' => $session_id, 'status' => $status );
	}
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['roadie_fac_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	ksort( $GLOBALS['roadie_fac_filters'][ $hook ] );
	return true;
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['roadie_fac_filters'][ $hook ] ?? array() as $callbacks ) {
		foreach ( $callbacks as $registration ) {
			list( $callback, $accepted_args ) = $registration;
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function add_action() {}
function register_rest_route() {}
function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}
function sanitize_title( $value ): string {
	return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $value ) ), '-' );
}
function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}
function sanitize_textarea_field( $value ): string {
	return trim( (string) $value );
}
function sanitize_key( $value ): string {
	return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
function esc_url_raw( $value ): string {
	return (string) $value;
}
function absint( $value ): int {
	return abs( (int) $value );
}
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}
function get_current_network_id(): int {
	return 9;
}
function get_current_blog_id(): int {
	return (int) $GLOBALS['roadie_fac_blog_id'];
}
function get_site( int $blog_id ) {
	return in_array( $blog_id, array( 1, 7 ), true ) ? (object) array( 'blog_id' => $blog_id ) : null;
}
function get_home_url( int $blog_id, string $path = '' ): string {
	return ( 1 === $blog_id ? 'https://extrachill.com' : 'https://events.extrachill.com' ) . $path;
}
function get_bloginfo( string $show ): string {
	unset( $show );
	return 1 === get_current_blog_id() ? 'Extra Chill' : 'Extra Chill Events';
}
function home_url(): string {
	return get_home_url( get_current_blog_id() );
}
function wp_parse_url( string $url, int $component ) {
	return parse_url( $url, $component );
}
function wp_html_excerpt( string $text, int $count, string $more = '' ): string {
	return strlen( $text ) > $count ? substr( $text, 0, $count ) . $more : $text;
}
function get_option( $name, $default = false ) {
	return 'frontend_agent_chat_config' === $name
		? array( 'enabled' => true, 'agent_slug' => 'roadie', 'default_agent_slug' => 'roadie' )
		: $default;
}
function is_multisite(): bool {
	return true;
}
function wp_parse_args( $args, $defaults = array() ): array {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}
function wp_has_ability( string $name ): bool {
	if ( 'agents/mark-conversation-session-read' === $name ) {
		return (bool) $GLOBALS['roadie_fac_read_supported'];
	}
	return in_array(
		$name,
		array(
			'agents/chat',
			'agents/queue-chat-message',
			'agents/list-conversation-sessions',
			'agents/get-conversation-session',
			'agents/delete-conversation-session',
			'agents/update-conversation-session-title',
			'agents/get-chat-run',
			'agents/list-chat-run-events',
			'agents/cancel-chat-run',
			'agents/resolve-pending-action',
		),
		true
	);
}
function wp_get_ability( string $name ) {
	return wp_has_ability( $name ) ? new RoadieFACContractAbility( $name ) : null;
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}
function is_user_logged_in(): bool {
	return false;
}
function get_current_user_id(): int {
	return 0;
}
function wp_unslash( $value ) {
	return $value;
}
function wp_salt( $scheme = 'auth' ): string {
	unset( $scheme );
	return 'roadie-fac-contract-salt';
}
function rest_ensure_response( $response ) {
	return $response;
}

define( 'EXTRACHILL_ROADIE_AGENT_SLUG', 'roadie' );
define( 'EXTRACHILL_ROADIE_AGENT_NAME', 'Roadie' );
require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';
require_once $fac_dir . '/inc/config.php';
require_once $fac_dir . '/inc/rest.php';

$_COOKIE[ FRONTEND_AGENT_CHAT_BROWSER_COOKIE ] = str_repeat( 'c', 64 );
$failures = array();
$passes   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $message;
};

$created = frontend_agent_chat_rest_send_message( new WP_REST_Request( array( 'message' => 'hello', 'agent' => 'roadie' ) ) );
$assert( 'roadie-session' === ( $created['data']['session_id'] ?? '' ), 'Main-site create failed.' );

$GLOBALS['roadie_fac_blog_id'] = 7;
$listed = frontend_agent_chat_rest_list_sessions( new WP_REST_Request( array( 'agent' => 'roadie' ) ) );
$assert( 1 === ( $listed['data']['total'] ?? 0 ), 'Subsite list did not discover the network session.' );
$list_call = end( $GLOBALS['roadie_fac_calls'] );
$assert( 'chat,roadie' === ( $list_call[1]['context'] ?? '' ), 'Subsite list did not retain the exact combined mode.' );

$loaded = frontend_agent_chat_rest_get_session( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session' ) ) );
$assert( 'roadie-session' === ( $loaded['data']['session_id'] ?? '' ), 'Subsite read failed.' );

$renamed = frontend_agent_chat_rest_update_session_title( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session', 'title' => 'Across the network' ) ) );
$assert( 'Across the network' === ( $renamed['data']['title'] ?? '' ), 'Subsite title update failed.' );

$continued = frontend_agent_chat_rest_send_message( new WP_REST_Request( array( 'message' => 'continue', 'agent' => 'roadie', 'session_id' => 'roadie-session' ) ) );
$assert( 2 === count( $continued['data']['conversation'] ?? array() ), 'Cross-subsite continuation did not append to the same session.' );

$queued = frontend_agent_chat_rest_queue_message( new WP_REST_Request( array( 'message' => 'queued', 'agent' => 'roadie', 'session_id' => 'roadie-session', 'run_id' => 'run-1' ) ) );
$assert( 'queued-1' === ( $queued['data']['queued_message_id'] ?? '' ), 'Queue did not resolve the network session.' );
$run = frontend_agent_chat_rest_get_run( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session', 'run_id' => 'run-1' ) ) );
$assert( 'running' === ( $run['data']['status'] ?? '' ), 'Run status did not resolve the network session.' );
$events = frontend_agent_chat_rest_list_run_events( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session', 'run_id' => 'run-1' ) ) );
$assert( 'running' === ( $events['data']['status'] ?? '' ), 'Run events did not resolve the network session.' );
$cancelled = frontend_agent_chat_rest_cancel_run( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session', 'run_id' => 'run-1' ) ) );
$assert( true === ( $cancelled['data']['cancelled'] ?? false ), 'Run cancellation did not resolve the network session.' );

$read = frontend_agent_chat_rest_mark_session_read( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session' ) ) );
$assert( false === ( $read['success'] ?? true ) && false === ( $read['data']['persisted'] ?? true ) && true === ( $read['data']['unsupported'] ?? false ), 'Unsupported read state reported fake persistence.' );
$GLOBALS['roadie_fac_read_supported'] = true;
$read = frontend_agent_chat_rest_mark_session_read( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session' ) ) );
$assert( true === ( $read['data']['persisted'] ?? false ), 'Supported read state did not persist.' );

$origin = array(
	'workspace' => array( 'workspace_type' => 'site', 'workspace_id' => 'https://events.extrachill.com' ),
	'metadata'  => array( 'datamachine' => array( 'context' => array( 'wordpress' => array( 'blog_id' => 7 ) ) ) ),
);
$approved = frontend_agent_chat_rest_resolve_pending_action( new WP_REST_Request( array( 'action_id' => 'act_valid', 'decision' => 'accepted', 'origin' => $origin, 'client_context' => array( 'wordpress' => array( 'blog_id' => 1 ) ) ) ) );
$assert( 'accepted' === ( $approved['data']['decision'] ?? '' ), 'Approval did not resolve against its originating site.' );

$forged = $origin;
$forged['workspace']['workspace_id'] = 'https://extrachill.com';
$denied = frontend_agent_chat_rest_resolve_pending_action( new WP_REST_Request( array( 'action_id' => 'act_valid', 'decision' => 'accepted', 'origin' => $forged ) ) );
$assert( $denied instanceof WP_Error && 'pending_action_origin_denied' === $denied->get_error_code(), 'Forged approval origin did not fail closed.' );

$deleted = frontend_agent_chat_rest_delete_session( new WP_REST_Request( array( 'agent' => 'roadie', 'session_id' => 'roadie-session' ) ) );
$assert( true === ( $deleted['data']['deleted'] ?? false ) && ! isset( $GLOBALS['roadie_fac_sessions']['roadie-session'] ), 'Subsite delete failed.' );

$network_workspace = array( 'workspace_type' => 'network', 'workspace_id' => '9' );
foreach ( $GLOBALS['roadie_fac_calls'] as $call ) {
	list( $ability, $input ) = $call;
	if ( 'agents/resolve-pending-action' === $ability ) {
		continue;
	}
	$assert( $network_workspace === ( $input['workspace'] ?? null ), $ability . ' did not receive Roadie\'s network workspace.' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Roadie cross-subsite FAC contract smoke passed ({$passes} assertions).\n";
