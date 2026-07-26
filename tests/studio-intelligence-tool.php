<?php
/**
 * Contract coverage for the private Studio Intelligence Roadie adapter.
 *
 * Run with: php tests/studio-intelligence-tool.php
 *
 * @package ExtraChillRoadie\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/contribute-code-bootstrap.php';

if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $name, $definition, array $modes = array(), array $meta = array() ): void {
				$GLOBALS["roadie_intelligence_registrations"][ $name ] = array( "definition" => $definition, "modes" => $modes, "meta" => $meta );
			}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "tool_name" => $tool_name );
			}
		}'
	);
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( string $key ): ?int {
		return 'studio' === $key ? 12 : null;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test shim.
	}
}

if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
	function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) {
		$loopback = apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args );
		$GLOBALS['roadie_intelligence_calls'][] = compact( 'site_key', 'method', 'path', 'args', 'loopback' );

		$responder = $GLOBALS['roadie_intelligence_responder'] ?? null;
		return is_callable( $responder ) ? $responder( $site_key, $method, $path, $args ) : new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.' );
	}
}

require_once dirname( __DIR__ ) . '/inc/tools/caller.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-studio-intelligence.php';

$GLOBALS['roadie_intelligence_registrations'] = array();
$GLOBALS['roadie_intelligence_calls']         = array();
$GLOBALS['extrachill_roadie_test_state']['current_blog'] = 7;
$GLOBALS['extrachill_roadie_test_state']['caps_by_user']  = array(
	102 => array( 'access_roadie' => true, 'intelligence_read' => true ),
	103 => array( 'access_roadie' => true ),
	104 => array(),
);

$tool = new ECRoadie_StudioIntelligence();

$registration = $GLOBALS['roadie_intelligence_registrations']['studio_intelligence'] ?? array();
roadie_test_assert( array( 'roadie' ) === ( $registration['modes'] ?? null ), 'Studio Intelligence must register only for Roadie mode.' );
roadie_test_assert( 'authenticated' === ( $registration['meta']['access_level'] ?? '' ), 'Studio Intelligence must link authenticated access metadata.' );
roadie_test_assert( ! isset( $registration['meta']['ability'] ), 'Off-Studio discovery must not require a locally registered Intelligence ability.' );

$definition = $tool->getToolDefinition();
$binding    = $definition['parameter_bindings']['calling_user_id'] ?? array();
roadie_test_assert( 'caller_context' === ( $binding['source'] ?? '' ) && true === ( $binding['authoritative'] ?? false ), 'Caller binding must be authoritative trusted context.' );
roadie_test_assert( array( 'search', 'read' ) === ( $definition['parameters']['properties']['action']['enum'] ?? array() ), 'Only search and read verbs may be exposed.' );
$definition_text = strtolower( json_encode( $definition ) ?: '' );
foreach ( array( 'create', 'update', 'delete', 'import', 'config', 'provision', 'memory_write' ) as $write_verb ) {
	roadie_test_assert( false === in_array( $write_verb, $definition['parameters']['properties']['action']['enum'] ?? array(), true ), 'Write verb must not register: ' . $write_verb );
}
roadie_test_assert( false !== strpos( $definition_text, 'strictly read-only' ), 'Definition must state its read-only contract.' );

roadie_test_assert(
	true === ECRoadie_StudioIntelligence::use_http_loopback( false, 'studio', 'POST', '/intelligence/v1/search', array() ),
	'Non-Studio origin must use target-bootstrap loopback.'
);
$GLOBALS['extrachill_roadie_test_state']['current_blog'] = 12;
roadie_test_assert(
	false === ECRoadie_StudioIntelligence::use_http_loopback( false, 'studio', 'POST', '/intelligence/v1/read', array() ),
	'Studio origin should use in-process target routes.'
);
$GLOBALS['extrachill_roadie_test_state']['current_blog'] = 7;
roadie_test_assert(
	false === ECRoadie_StudioIntelligence::use_http_loopback( false, 'main', 'POST', '/intelligence/v1/read', array() ),
	'Unrelated routes must retain the transport default.'
);

$anonymous = $tool->handle_tool_call( array( 'action' => 'search', 'query' => 'roadie', 'calling_user_id' => 0 ) );
roadie_test_assert( false === ( $anonymous['success'] ?? true ) && array() === $GLOBALS['roadie_intelligence_calls'], 'Anonymous caller must fail before target dispatch.' );

$subscriber = $tool->handle_tool_call( array( 'action' => 'search', 'query' => 'roadie', 'calling_user_id' => 104 ) );
roadie_test_assert( false === ( $subscriber['success'] ?? true ) && array() === $GLOBALS['roadie_intelligence_calls'], 'Subscriber must fail before target dispatch.' );

$malformed = $tool->handle_tool_call( array( 'action' => 'read', 'ref' => array( 'wiki:bad' ), 'calling_user_id' => 102 ) );
roadie_test_assert( false === ( $malformed['success'] ?? true ) && array() === $GLOBALS['roadie_intelligence_calls'], 'Malformed ref must fail before dispatch.' );

$GLOBALS['roadie_intelligence_responder'] = static function ( string $site_key, string $method, string $path, array $args ) {
	unset( $site_key, $method );
	$user_id = (int) ( $args['user_id'] ?? 0 );
	if ( ! user_can( $user_id, 'intelligence_read' ) ) {
		return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.' );
	}

	if ( '/intelligence/v1/search' === $path ) {
		return array(
			'success' => true,
			'query'   => (string) ( $args['body']['query'] ?? '' ),
			'results' => array(
				array(
					'source_type'  => 'wiki',
					'source_label' => 'Studio Wiki',
					'id'           => 'roadie-architecture',
					'read_ref'     => 'wiki:roadie-architecture',
					'title'        => 'Roadie architecture',
					'snippet'      => 'Roadie provides the network chat integration.',
					'permalink'    => 'https://studio.extrachill.com/knowledge/wiki/roadie-architecture/',
					'access'       => array( 'source_visibility' => 'restricted', 'provenance' => array( 'studio-wiki' ) ),
				),
			),
			'stats'   => array( 'wiki' => 1 ),
		);
	}

	return array(
		'success'     => true,
		'ref'         => (string) ( $args['body']['ref'] ?? '' ),
		'resolved'    => array(
			'source'       => 'wiki',
			'source_label' => 'Studio Wiki',
			'id'           => 'roadie-architecture',
			'url'          => 'https://studio.extrachill.com/knowledge/wiki/roadie-architecture/',
			'tool'         => 'intelligence/wiki-read',
			'via'          => 'local',
		),
		'title'       => 'Roadie architecture',
		'body'        => '<p>Roadie reads the private Studio corpus through the canonical transport.</p>',
		'body_format' => 'html',
		'access'      => array( 'source_visibility' => 'restricted', 'provenance' => array( 'studio-wiki' ) ),
		'metadata'    => array( 'root' => 'platform' ),
	);
};

$search = $tool->handle_tool_call( array( 'action' => 'search', 'query' => 'Roadie architecture', 'limit' => 99, 'calling_user_id' => 102 ) );
roadie_test_assert( true === ( $search['success'] ?? false ), 'Team search must succeed.' );
$search_call = $GLOBALS['roadie_intelligence_calls'][0] ?? array();
roadie_test_assert( 'studio' === ( $search_call['site_key'] ?? '' ) && '/intelligence/v1/search' === ( $search_call['path'] ?? '' ), 'Search must route to Studio Intelligence.' );
roadie_test_assert( 102 === ( $search_call['args']['user_id'] ?? 0 ), 'Authoritative caller must propagate to target auth.' );
roadie_test_assert( 20 === ( $search_call['args']['body']['limit'] ?? 0 ), 'Search limit must be bounded.' );
roadie_test_assert( true === ( $search_call['loopback'] ?? false ), 'Non-Studio search must use authenticated loopback.' );
roadie_test_assert( 'wiki:roadie-architecture' === ( $search['data']['results'][0]['read_ref'] ?? '' ), 'Search must preserve the canonical read ref.' );
roadie_test_assert( array( 'studio-wiki' ) === ( $search['data']['results'][0]['access']['provenance'] ?? null ), 'Search must preserve provenance.' );
$search_citation = $search['metadata']['citations'][0] ?? array();
roadie_test_assert( 'wiki:roadie-architecture' === ( $search_citation['ref'] ?? '' ), 'Search citation must retain its read ref.' );
roadie_test_assert( 'https://studio.extrachill.com/knowledge/wiki/roadie-architecture/' === ( $search_citation['source']['url'] ?? '' ), 'Search citation must retain its URL.' );

$read = $tool->handle_tool_call( array( 'action' => 'read', 'ref' => 'wiki:roadie-architecture', 'calling_user_id' => 102 ) );
roadie_test_assert( true === ( $read['success'] ?? false ), 'Team read must succeed.' );
$read_call = $GLOBALS['roadie_intelligence_calls'][1] ?? array();
roadie_test_assert( '/intelligence/v1/read' === ( $read_call['path'] ?? '' ) && 102 === ( $read_call['args']['user_id'] ?? 0 ), 'Read must route to Studio as the authoritative caller.' );
roadie_test_assert( 'wiki:roadie-architecture' === ( $read['data']['ref'] ?? '' ), 'Read must preserve its ref.' );
roadie_test_assert( 'restricted' === ( $read['data']['access']['source_visibility'] ?? '' ), 'Read must preserve access provenance.' );
roadie_test_assert( false !== strpos( (string) ( $read['metadata']['citations'][0]['snippet'] ?? '' ), 'canonical transport' ), 'Hydrated body must ground its citation.' );

$target_denied = $tool->handle_tool_call( array( 'action' => 'search', 'query' => 'private', 'calling_user_id' => 103 ) );
roadie_test_assert( false === ( $target_denied['success'] ?? true ), 'Team caller without Studio intelligence_read must be denied by the target.' );
roadie_test_assert( false !== strpos( (string) ( $target_denied['error'] ?? '' ), 'Studio Intelligence is unavailable' ), 'Target denial must return a clean diagnostic.' );

$GLOBALS['roadie_intelligence_responder'] = static fn() => new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.' );
$missing = $tool->handle_tool_call( array( 'action' => 'search', 'query' => 'anything', 'calling_user_id' => 102 ) );
roadie_test_assert( false === ( $missing['success'] ?? true ), 'Missing Intelligence plugin/routes must fail cleanly.' );
roadie_test_assert( '/intelligence/v1/search' === ( $missing['diagnostic']['route'] ?? '' ), 'Unavailable diagnostic must identify the missing target route.' );

$GLOBALS['roadie_intelligence_responder'] = static fn() => array( 'success' => false, 'error' => 'Provider response failed.' );
$failed = $tool->handle_tool_call( array( 'action' => 'read', 'ref' => 'wiki:roadie-architecture', 'calling_user_id' => 102 ) );
roadie_test_assert( false === ( $failed['success'] ?? true ) && false !== strpos( (string) ( $failed['error'] ?? '' ), 'Provider response failed' ), 'Target response errors must propagate cleanly.' );

echo "Roadie Studio Intelligence tool smoke passed.\n";
