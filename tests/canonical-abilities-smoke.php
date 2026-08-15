<?php
/** Standalone regression coverage for client-neutral Roadie ability policy. */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'EXTRACHILL_ROADIE_AGENT_SLUG', 'roadie' );
define( 'EXTRACHILL_ROADIE_AGENT_NAME', 'Roadie' );

$GLOBALS['_roadie_filters'] = array();
$GLOBALS['_roadie_user']    = 7;

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['_roadie_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}
function apply_filters( string $hook, $value, ...$args ) {
	$priorities = $GLOBALS['_roadie_filters'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as [ $callback, $accepted_args ] ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}
function sanitize_title( $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $value ) ?? '' ), '-' ); }
function sanitize_key( $value ): string { return sanitize_title( $value ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ): string { return (string) $value; }
function absint( $value ): int { return abs( (int) $value ); }
function get_current_user_id(): int { return (int) $GLOBALS['_roadie_user']; }
function get_current_network_id(): int { return 1; }
function get_bloginfo( string $key ): string { unset( $key ); return 'Extra Chill'; }
function home_url(): string { return 'https://extrachill.com'; }
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); }
function untrailingslashit( string $value ): string { return rtrim( $value, '/' ); }
function get_home_url( int $blog_id, string $path = '/' ): string { unset( $path ); return 7 === $blog_id ? 'https://events.extrachill.com' : 'https://extrachill.com'; }
function get_site( int $blog_id ): object { return (object) array( 'blog_id' => $blog_id, 'site_id' => 1 ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_salt( string $scheme = 'auth' ): string { unset( $scheme ); return 'roadie-canonical-test-salt'; }

class WP_REST_Request {
	public function get_param( string $key ) { unset( $key ); return null; }
	public function get_header( string $key ): string { unset( $key ); return ''; }
}
class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function roadie_canonical_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';
require_once dirname( __DIR__ ) . '/inc/canonical-abilities.php';

$input = extrachill_roadie_compose_canonical_ability_input(
	'agents/chat',
	array(
		'agent'          => 'roadie',
		'message'        => 'hello',
		'client_context' => array( 'source' => 'block', 'client_name' => 'extrachill-app' ),
	)
);
roadie_canonical_assert( ! is_wp_error( $input ), 'Singleton Roadie input must compose.' );
roadie_canonical_assert( array( 'workspace_type' => 'network', 'workspace_id' => '1' ) === $input['workspace'], 'Singleton Roadie must use the network workspace.' );
roadie_canonical_assert( array( 'chat', 'roadie' ) === $input['modes'], 'Canonical chat must activate chat and Roadie modes.' );
roadie_canonical_assert( 'extrachill-app' === $input['client_context']['client_name'] && 'extrachill.com' === $input['client_context']['site_host'], 'Native context must be preserved and server context appended.' );

$list = extrachill_roadie_compose_canonical_ability_input( 'agents/list-conversation-sessions', array( 'agent' => 'roadie' ) );
roadie_canonical_assert( 'chat,roadie' === $list['context'], 'Session listing must select Roadie conversation context.' );
roadie_canonical_assert( $input['workspace'] === $list['workspace'], 'Chat and history must share one workspace.' );

$other = array( 'agent' => 'other', 'message' => 'untouched' );
roadie_canonical_assert( $other === extrachill_roadie_compose_canonical_ability_input( 'agents/chat', $other ), 'Non-Roadie agents must remain untouched.' );

$executed  = array();
$permitted = array();
$args      = extrachill_roadie_decorate_canonical_ability(
	array(
		'execute_callback'    => static function ( array $callback_input ) use ( &$executed ): array { $executed = $callback_input; return $callback_input; },
		'permission_callback' => static function ( array $callback_input ) use ( &$permitted ): bool { $permitted = $callback_input; return true; },
	),
	'agents/chat'
);
$raw = array( 'agent' => 'roadie', 'message' => 'same input' );
roadie_canonical_assert( true === $args['permission_callback']( $raw ), 'Decorated permission must preserve the original decision.' );
$args['execute_callback']( $raw );
roadie_canonical_assert( $permitted === $executed && isset( $executed['workspace'], $executed['modes'] ), 'Permission and execution must receive identical composed input.' );

$denied = new WP_Error( 'denied', 'No access.' );
$error_args = extrachill_roadie_decorate_canonical_ability(
	array(
		'execute_callback'    => static fn( array $callback_input ): array => $callback_input,
		'permission_callback' => static fn( array $callback_input ) => $denied,
	),
	'agents/chat'
);
roadie_canonical_assert( $denied === $error_args['permission_callback']( $raw ), 'Decorated permissions must preserve WP_Error denials.' );

$origin = array(
	'workspace' => array( 'workspace_type' => 'network', 'workspace_id' => '1' ),
	'metadata'  => array( 'datamachine' => array( 'context' => array( 'wordpress' => array( 'blog_id' => 7 ) ) ) ),
);
$pending = extrachill_roadie_compose_canonical_ability_input(
	'agents/resolve-pending-action',
	array(
		'agent'   => 'roadie',
		'context' => array( 'roadie_origin' => $origin ),
	)
);
roadie_canonical_assert( 7 === $pending['context']['wordpress']['blog_id'], 'Pending origin must preserve its server-stamped blog.' );
roadie_canonical_assert( $origin['workspace'] === $pending['context']['workspace'], 'Pending workspace must survive inside canonical resolver context.' );
roadie_canonical_assert( ! isset( $pending['context']['roadie_origin'] ), 'Untrusted origin envelope must not reach the resolver unchanged.' );
roadie_canonical_assert( 'user:7' === $pending['resolver'], 'Pending resolver identity must be derived from the authenticated user.' );

$scope = apply_filters(
	'datamachine_pending_action_current_scope',
	array( 'workspace_type' => 'site', 'workspace_id' => '7' ),
	$pending
);
roadie_canonical_assert( 'network' === $scope['workspace_type'] && '1' === $scope['workspace_id'], 'Validated workspace must override ambient pending-action scope.' );
$forged_scope = apply_filters(
	'datamachine_pending_action_current_scope',
	array( 'workspace_type' => 'site', 'workspace_id' => '7' ),
	array(
		'action_id' => 'different-action',
		'context'   => $pending['context'],
	)
);
roadie_canonical_assert( 'site' === $forged_scope['workspace_type'] && '7' === $forged_scope['workspace_id'], 'A copied or forged scope proof must not affect non-Roadie resolution.' );

$invalid = extrachill_roadie_apply_pending_action_origin(
	array(),
	array(
		'workspace' => array( 'workspace_type' => 'network', 'workspace_id' => '2' ),
		'metadata'  => array( 'datamachine' => array( 'context' => array( 'wordpress' => array( 'blog_id' => 7 ) ) ) ),
	)
);
roadie_canonical_assert( PHP_INT_MAX === $invalid['context']['wordpress']['blog_id'] && ! isset( $invalid['context']['workspace'] ), 'Invalid cross-network origin must fail closed.' );

echo "Roadie canonical ability policy smoke passed.\n";
