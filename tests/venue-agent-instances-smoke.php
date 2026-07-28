<?php
/** Standalone regression coverage for venue-scoped Roadie identities. */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'EXTRACHILL_ROADIE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_ROADIE_AGENT_SLUG', 'roadie' );
define( 'EXTRACHILL_ROADIE_AGENT_NAME', 'Roadie' );

eval( 'namespace AgentsAPI\\Core\\Identity; class WP_Agent_Identity_Scope { public string $agent_slug; public int $owner_user_id; public string $instance_key; public function __construct( string $slug, int $owner, string $key ) { $this->agent_slug = $slug; $this->owner_user_id = $owner; $this->instance_key = $key; } } class WP_Agent_Materialized_Identity { public int $id; public WP_Agent_Identity_Scope $scope; public function __construct( int $id, WP_Agent_Identity_Scope $scope ) { $this->id = $id; $this->scope = $scope; } }' );
eval( 'namespace AgentsAPI\\AI; class WP_Agent_Execution_Principal { public const REQUEST_CONTEXT_CHAT = "chat"; public int $acting_user_id; public string $effective_agent_id; public function __construct( int $user, string $agent ) { $this->acting_user_id = $user; $this->effective_agent_id = $agent; } public static function user_session( int $user, string $agent ) { return new self( $user, $agent ); } }' );
eval( 'namespace DataMachine\\Core\\Database\\Agents; class Agents { public static array $rows = array(); public function get_agent( int $id ) { return self::$rows[$id] ?? null; } }' );

$GLOBALS['_venue_actions']       = array();
$GLOBALS['_venue_filters']       = array();
$GLOBALS['_venue_user']          = 7;
$GLOBALS['_venue_voices']        = array();
$GLOBALS['_venue_cross_calls']   = array();
$GLOBALS['_venue_materialized']  = array();
$GLOBALS['_venue_next_agent_id'] = 100;
$GLOBALS['_venue_community']     = array( 'public_voice' => null );
$GLOBALS['_venue_principals']    = array();

function add_action( string $hook, callable $callback ): void { $GLOBALS['_venue_actions'][ $hook ][] = $callback; }
function add_filter( string $hook, callable $callback, int $priority = 10 ): void { $GLOBALS['_venue_filters'][ $hook ][ $priority ][] = $callback; }
function remove_filter( string $hook, callable $callback, int $priority = 10 ): void {
	foreach ( $GLOBALS['_venue_filters'][ $hook ][ $priority ] ?? array() as $index => $registered ) {
		if ( $registered === $callback ) { unset( $GLOBALS['_venue_filters'][ $hook ][ $priority ][ $index ] ); }
	}
}
function apply_filters( string $hook, $value, ...$args ) {
	$priorities = $GLOBALS['_venue_filters'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value, ...$args ); } }
	return $value;
}
function __( $text ): string { return (string) $text; }
function get_current_user_id(): int { return (int) $GLOBALS['_venue_user']; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_title( $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ?? '' ), '-' ); }
function sanitize_key( $value ): string { return sanitize_title( $value ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ): string { return sanitize_text_field( $value ); }
function esc_url_raw( $value ): string { return (string) $value; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function user_can( $user, string $capability ): bool { return (int) $user === 7 && in_array( $capability, array( 'access_roadie', 'manage_options' ), true ); }
function get_current_network_id(): int { return 1; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/' ); }
function get_bloginfo( string $key ): string { unset( $key ); return 'Extra Chill'; }
function home_url(): string { return 'https://extrachill.com'; }
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); }

class WP_REST_Request {
	public function get_param( string $key ) { unset( $key ); return null; }
	public function get_header( string $key ): string { unset( $key ); return ''; }
}

class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function wp_register_agent( string $slug, array $args ) { $GLOBALS['_venue_registered_agent'] = compact( 'slug', 'args' ); return (object) array(); }
function wp_register_ability( string $slug, array $args ) { $GLOBALS['_venue_registered_ability'] = compact( 'slug', 'args' ); return (object) array(); }
function wp_has_ability_category(): bool { return false; }
function wp_register_ability_category( string $slug, array $args ): void { $GLOBALS['_venue_category'] = compact( 'slug', 'args' ); }

function wp_materialize_agent_identity( string $slug, $store, array $args ) {
	unset( $store );
	$key = $slug . ':' . $args['owner_user_id'] . ':' . $args['instance_key'];
	if ( ! isset( $GLOBALS['_venue_materialized'][ $key ] ) ) {
		$id = $GLOBALS['_venue_next_agent_id']++;
		$scope = new AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( $slug, (int) $args['owner_user_id'], (string) $args['instance_key'] );
		$GLOBALS['_venue_materialized'][ $key ] = new AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity( $id, $scope );
		DataMachine\Core\Database\Agents\Agents::$rows[ $id ] = array(
			'agent_id' => $id, 'agent_slug' => $slug, 'owner_id' => $args['owner_user_id'], 'instance_key' => $args['instance_key'],
		);
	}
	return $GLOBALS['_venue_materialized'][ $key ];
}

function ec_cross_site_rest_request( string $site, string $method, string $path, array $args = array() ) {
	$GLOBALS['_venue_cross_calls'][] = compact( 'site', 'method', 'path', 'args' );
	if ( 'events' === $site ) { return $GLOBALS['_venue_voices']; }
	$GLOBALS['_venue_principals'][] = apply_filters( 'agents_api_execution_principal', null );
	return $GLOBALS['_venue_community'];
}

function venue_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

require_once __DIR__ . '/_stub-base-tool.php';
require_once dirname( __DIR__ ) . '/inc/venue-agent-instances.php';
require_once dirname( __DIR__ ) . '/inc/tools/caller.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-community.php';
require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';

extrachill_roadie_register_venue_agent();
venue_assert( 'roadie-venue' === $GLOBALS['_venue_registered_agent']['slug'], 'Reusable venue definition must register once.' );
venue_assert( false === $GLOBALS['_venue_registered_agent']['args']['meta']['datamachine_default_materialization'], 'Definition must opt out of default Data Machine materialization.' );
venue_assert( ! isset( $GLOBALS['_venue_registered_agent']['args']['owner_resolver'] ), 'Definition-only registration must not rely on an invalid zero owner.' );

$voices = static fn( array $ids ): array => array( 'voices' => array_map( static fn( int $id ): array => array(
	'reference' => 'venue:' . $id, 'term_id' => $id, 'name' => 'Venue ' . $id, 'slug' => 'venue-' . $id,
	'url' => 'https://events.example/venue-' . $id, 'description' => '',
), $ids ) );
$GLOBALS['_venue_voices'] = $voices( array( 55, 92 ) );
$first  = extrachill_roadie_select_venue_agent( array( 'venue_term_id' => 55 ) );
$repeat = extrachill_roadie_select_venue_agent( array( 'venue_term_id' => 55 ) );
$second = extrachill_roadie_select_venue_agent( array( 'venue_term_id' => 92 ) );
venue_assert( $first['agent_id'] === $repeat['agent_id'], 'Materialization must be stable and reused.' );
venue_assert( $first['agent_id'] !== $second['agent_id'], 'Two venues sharing one definition need distinct identities.' );
venue_assert( 'venue:55' === $first['instance_key'] && 'venue:92' === $second['instance_key'], 'Exact canonical venue scope must persist.' );
venue_assert( array() === $GLOBALS['_venue_cross_calls'][0]['args'], 'Events projection must receive no body or user override.' );

$GLOBALS['_venue_user'] = 8;
$owner_b = extrachill_roadie_select_venue_agent( array( 'venue_term_id' => 55 ) );
venue_assert( $owner_b['agent_id'] !== $first['agent_id'], 'Different owners need distinct materialized identities.' );
venue_assert( is_wp_error( extrachill_roadie_resolve_venue_agent_scope( $first['agent_id'], 8 ) ), 'A principal/owner mismatch must fail closed.' );

$GLOBALS['_venue_user'] = 7;
$scope = extrachill_roadie_resolve_venue_agent_scope( $first['agent_id'], 7 );
venue_assert( is_array( $scope ) && 55 === $scope['venue_term_id'], 'Venue must derive from persisted identity scope.' );
venue_assert( extrachill_roadie_venue_agent_workspace( $first['agent_id'] ) !== extrachill_roadie_venue_agent_workspace( $second['agent_id'] ), 'Materialized instances need separate transcript/workspace scope.' );
$request = new WP_REST_Request();
$chat_a  = extrachill_roadie_frontend_chat_input( array(), $request, (string) $first['agent_id'], array() );
$chat_b  = extrachill_roadie_frontend_chat_input( array(), $request, (string) $second['agent_id'], array() );
venue_assert( $chat_a['workspace'] !== $chat_b['workspace'], 'Chat workspaces must be instance-separated.' );
venue_assert( $chat_a['session_owner'] !== $chat_b['session_owner'], 'Transcript owners must be instance-separated.' );
venue_assert( (string) $first['agent_id'] === $chat_a['principal']['effective_agent_id'] && 7 === $chat_a['principal']['acting_user_id'], 'Chat principal must preserve effective agent and acting human.' );
$history_a = extrachill_roadie_frontend_chat_ability_input( array(), 'agents/list-conversation-sessions', $request, (string) $first['agent_id'], array() );
$history_b = extrachill_roadie_frontend_chat_ability_input( array(), 'agents/list-conversation-sessions', $request, (string) $second['agent_id'], array() );
venue_assert( $history_a['workspace'] !== $history_b['workspace'], 'History lifecycle must retain materialized workspace isolation.' );

$singleton_id = 5;
DataMachine\Core\Database\Agents\Agents::$rows[ $singleton_id ] = array( 'agent_id' => 5, 'agent_slug' => 'roadie', 'owner_id' => 7, 'instance_key' => 'default' );
venue_assert( null === extrachill_roadie_resolve_venue_agent_scope( $singleton_id, 7 ), 'Singleton Roadie must retain its legacy path.' );

$tool = new ECRoadie_ManageCommunity();
$GLOBALS['_venue_cross_calls'] = array();
$GLOBALS['_venue_community'] = array(
	'topic_id' => 501,
	'author_id' => 7,
	'public_voice' => array( 'reference' => 'venue:55', 'accountable_user_id' => 7, 'automated' => true ),
);
$created = $tool->handle_tool_call( array(
	'action' => 'create_topic', 'calling_user_id' => 7, 'effective_agent_id' => $first['agent_id'],
	'forum_id' => 10, 'title' => 'Venue update', 'content' => 'Tonight.', 'public_voice' => 'venue:92',
) );
$community_call = $GLOBALS['_venue_cross_calls'][1];
venue_assert( true === $created['success'], 'Authorized venue Community write must succeed.' );
venue_assert( '/wp-abilities/v1/abilities/extrachill/community-create-topic/run' === $community_call['path'], 'Community writes must use the standard REST ability route.' );
venue_assert( 7 === $community_call['args']['user_id'] && ! isset( $community_call['args']['body']['input']['user_id'] ), 'Trusted human must remain the caller and author.' );
venue_assert( 'venue:55' === $community_call['args']['body']['input']['public_voice'], 'Model input must not cross the identity-bound venue scope.' );
venue_assert( 'venue:55' === $created['data']['public_voice']['reference'], 'Nullable Community public_voice envelope must be consumed.' );
$principal = $GLOBALS['_venue_principals'][0];
venue_assert( 7 === $principal->acting_user_id && (string) $first['agent_id'] === $principal->effective_agent_id, 'Community must see human/effective-agent separation for disclosure.' );

// Both users manage venue 55, but neither venue instance may write as the other owner.
$GLOBALS['_venue_cross_calls'] = array();
$admin_override = $tool->handle_tool_call( array(
	'action' => 'create_topic', 'calling_user_id' => 7, 'effective_agent_id' => $first['agent_id'], 'user_id' => 8,
	'forum_id' => 10, 'title' => 'Spoofed venue update', 'content' => 'Denied.',
) );
venue_assert( false === $admin_override['success'] && 1 === count( $GLOBALS['_venue_cross_calls'] ) && 'events' === $GLOBALS['_venue_cross_calls'][0]['site'], 'Venue mode must reject an administrator cross-user override before Community dispatch.' );
$GLOBALS['_venue_user'] = 8;
$GLOBALS['_venue_cross_calls'] = array();
$manager_override = $tool->handle_tool_call( array(
	'action' => 'create_reply', 'calling_user_id' => 8, 'effective_agent_id' => $owner_b['agent_id'], 'user_id' => 7,
	'topic_id' => 501, 'content' => 'Denied.',
) );
venue_assert( false === $manager_override['success'] && 1 === count( $GLOBALS['_venue_cross_calls'] ) && 'events' === $GLOBALS['_venue_cross_calls'][0]['site'], 'A second valid venue manager must not target the first manager as author.' );
$GLOBALS['_venue_user'] = 7;

$GLOBALS['_venue_voices'] = $voices( array() );
$GLOBALS['_venue_cross_calls'] = array();
$revoked = $tool->handle_tool_call( array( 'action' => 'list_forums', 'calling_user_id' => 7, 'effective_agent_id' => $first['agent_id'] ) );
venue_assert( false === $revoked['success'] && 1 === count( $GLOBALS['_venue_cross_calls'] ), 'Revoked membership must deny before the venue operation.' );

$GLOBALS['_venue_voices'] = new WP_Error( 'events_down', 'Events unavailable.' );
$GLOBALS['_venue_cross_calls'] = array();
$events_failure = extrachill_roadie_select_venue_agent( array( 'venue_term_id' => 55 ) );
venue_assert( is_wp_error( $events_failure ) && 1 === count( $GLOBALS['_venue_cross_calls'] ), 'Transient Events failures must fail closed without materialization.' );

$GLOBALS['_venue_voices'] = $voices( array( 55 ) );
$GLOBALS['_venue_community'] = new WP_Error( 'community_down', 'Community unavailable.' );
$GLOBALS['_venue_cross_calls'] = array();
$community_failure = $tool->handle_tool_call( array(
	'action' => 'create_reply', 'calling_user_id' => 7, 'effective_agent_id' => $first['agent_id'], 'topic_id' => 501, 'content' => 'Update.',
) );
venue_assert( false === $community_failure['success'], 'Transient Community failures must propagate as tool failures.' );

$GLOBALS['_venue_community'] = array( 'topic_id' => 502, 'author_id' => 7, 'public_voice' => null );
$GLOBALS['_venue_cross_calls'] = array();
$singleton = $tool->handle_tool_call( array(
	'action' => 'create_topic', 'calling_user_id' => 7, 'effective_agent_id' => $singleton_id, 'forum_id' => 10, 'title' => 'Human', 'content' => 'Post.',
) );
venue_assert( true === $singleton['success'] && ! isset( $GLOBALS['_venue_cross_calls'][0]['args']['body']['input']['public_voice'] ), 'Singleton Roadie writes must remain unvoiced and compatible.' );

$GLOBALS['_venue_community'] = array( 'topic_id' => 503, 'author_id' => 8, 'public_voice' => null );
$GLOBALS['_venue_cross_calls'] = array();
$legacy_override = $tool->handle_tool_call( array(
	'action' => 'create_topic', 'calling_user_id' => 7, 'effective_agent_id' => $singleton_id, 'user_id' => 8,
	'forum_id' => 10, 'title' => 'Admin post', 'content' => 'Legacy override.',
) );
venue_assert( true === $legacy_override['success'] && 8 === $GLOBALS['_venue_cross_calls'][0]['args']['user_id'], 'Singleton Roadie must preserve its legacy administrator override.' );

echo "Roadie venue agent instances smoke passed.\n";
