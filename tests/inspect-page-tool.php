<?php
/**
 * Smoke tests for the read-only inspect_page (rendered DOM) chat tool.
 *
 * Covers:
 *   - Tool registers via the datamachine_tools filter (roadie mode, authenticated)
 *     and declares the page_url client-context binding.
 *   - Tool definition surfaces NO write action and the read-only param set.
 *   - Capability gate: callers below team tier (no access_roadie) are blocked;
 *     a team member WITH access_roadie but WITHOUT propose-code is allowed.
 *   - SECURITY — the load-bearing on-network host check:
 *       * an off-network URL is REJECTED (no leak / no SSRF),
 *       * an on-network URL is accepted and inspected.
 *   - Structured output: returns a STRUCTURED subtree (tags/classes/text/
 *     nesting), NOT a raw HTML dump; strips script/style/svg.
 *   - selector scoping returns the targeted region; an unmatched selector
 *     errors instead of silently returning the whole page.
 *   - node/depth caps bound the output.
 *
 * The HTTP fetch is short-circuited with the extrachill_roadie_inspect_page_html
 * filter so the parser/structuring logic is exercised deterministically without
 * a live request — the security gate (team + on-network host) still runs in
 * full before the HTML is ever parsed.
 *
 * Run: php tests/inspect-page-tool.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Roadie permission constants/resolver the tool gates on ----------------
require_once dirname( __DIR__ ) . '/inc/permissions.php';

// --- BaseTool stub (same shape as inspect-code-tool.php) -------------------
if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {
				$GLOBALS["extrachill_roadie_test_state"]["registered_tools"][ $toolName ] = array(
					"definition" => $toolDefinition,
					"modes"      => $modes,
					"meta"       => $meta,
				);
			}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "error_type" => "validation", "tool_name" => $tool_name );
			}
			protected function buildDiagnosticErrorResponse( string $error, string $type, string $tool_name, array $a = array(), array $b = array() ): array {
				return array( "success" => false, "error" => $error, "error_type" => $type, "tool_name" => $tool_name );
			}
		}'
	);
}

// user_can() is needed by extrachill_roadie_user_tier(). Drive it off test state.
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $cap ): bool {
		$caps = $GLOBALS['extrachill_roadie_test_state']['caps_by_user'][ (int) $user_id ] ?? array();
		return ! empty( $caps[ $cap ] );
	}
}

// wp_parse_url() — wrap PHP's parse_url for the PHP_URL_* component form.
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

$GLOBALS['extrachill_roadie_test_state']['registered_tools'] = array();
$GLOBALS['extrachill_roadie_test_state']['caps_by_user']     = array();
$GLOBALS['extrachill_roadie_test_state']['current_user_id']  = 0;
$GLOBALS['extrachill_roadie_test_state']['site_url']         = 'https://events.extrachill.com';

require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-page.php';

class ECRoadie_TestInspectPage extends ECRoadie_InspectPage {
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$parameters['calling_user_id'] = get_current_user_id();
		return parent::handle_tool_call( $parameters, $tool_def );
	}
}

$tool = new ECRoadie_TestInspectPage();

// Constrain the network host allow-list to a known fixture set.
add_filter(
	'extrachill_roadie_inspect_page_network_hosts',
	function ( $hosts ) {
		return array( 'events.extrachill.com', 'extrachill.com', 'community.extrachill.com' );
	}
);

// Helper: become a given user with a given cap set.
$set_user = function ( int $user_id, array $caps ) {
	$GLOBALS['extrachill_roadie_test_state']['current_user_id']          = $user_id;
	$GLOBALS['extrachill_roadie_test_state']['caps_by_user'][ $user_id ] = $caps;
};

// Inject deterministic page HTML so the fetch is skipped. The HTML mirrors the
// motivating bug: a scope nav (tonight/this weekend) rendered as a sibling of a
// search filter bar, with a big calendar map — plus script/style/svg noise that
// MUST be stripped, and an out-of-band "secret" marker that must never appear.
$set_page_html = function ( string $html ) {
	roadie_test_reset_inspect_page_html();
	add_filter(
		'extrachill_roadie_inspect_page_html',
		function ( $pre, $url ) use ( $html ) {
			unset( $pre, $url );
			return $html;
		},
		10,
		2
	);
};

// The bootstrap apply_filters runs ALL callbacks for a hook in registration
// order, last value wins — so re-registering replaces effectively. Provide a
// reset that clears just the html hook.
if ( ! function_exists( 'roadie_test_reset_inspect_page_html' ) ) {
	function roadie_test_reset_inspect_page_html(): void {
		unset( $GLOBALS['extrachill_roadie_test_state']['filters']['extrachill_roadie_inspect_page_html'] );
	}
}

$page_html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
	<title>Events</title>
	<style>.x{color:red}</style>
	<script>var leak = "SECRET_SCRIPT_TOKEN";</script>
</head>
<body>
	<header id="masthead" class="site-header">Extra Chill</header>
	<main id="main" class="site-main">
		<nav class="scope-nav">
			<a class="scope-link" href="#tonight">Tonight</a>
			<a class="scope-link" href="#weekend">This Weekend</a>
		</nav>
		<div class="filter-bar">
			<input class="search-input" />
			<button class="search-go">Search</button>
		</div>
		<section class="calendar">
			<div class="event-map">MAP</div>
			<svg><circle /></svg>
			<noscript>SECRET_NOSCRIPT_TOKEN</noscript>
		</section>
	</main>
	<footer class="site-footer">Footer</footer>
</body>
</html>
HTML;

// =====================================================================
// Registration + definition
// =====================================================================

roadie_test_assert(
	isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools']['inspect_page'] ),
	'inspect_page must register via registerTool()'
);

$reg = $GLOBALS['extrachill_roadie_test_state']['registered_tools']['inspect_page'];
roadie_test_assert( array( 'roadie' ) === $reg['modes'], 'tool must register only for roadie mode' );
roadie_test_assert(
	'authenticated' === ( $reg['meta']['access_level'] ?? '' ),
	'tool must require authenticated access_level'
);
roadie_test_assert(
	( $reg['meta']['client_context_bindings']['url'] ?? '' ) === 'page_url',
	'tool must bind client-context page_url into the url parameter'
);

$definition = $tool->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $definition['method'], 'tool dispatch method' );
roadie_test_assert(
	( $definition['client_context_bindings']['url'] ?? '' ) === 'page_url',
	'definition advertises the page_url binding for the runtime merge'
);
$props = array_keys( $definition['parameters']['properties'] ?? array() );
sort( $props );
roadie_test_assert(
	$props === array( 'calling_user_id', 'max_depth', 'max_nodes', 'selector', 'url' ),
	'params are the read-only set plus the executor-controlled caller. Got: ' . implode( ',', $props )
);
// No write/action enum — this tool is read-only by construction.
roadie_test_assert(
	! isset( $definition['parameters']['properties']['action'] ),
	'inspect_page has no action param — it is read-only, not a multi-action tool'
);

// =====================================================================
// Capability gate — below team tier is blocked
// =====================================================================

// A logged-in user WITHOUT access_roadie (has propose-code only) must be
// blocked — proves the tool is NOT gated on propose-code.
$set_user( 5, array( 'extrachill_propose_code' => true ) );
$set_page_html( $page_html );
$blocked = $tool->handle_tool_call( array( 'url' => 'https://events.extrachill.com/calendar/' ) );
roadie_test_assert( false === $blocked['success'], 'non-team caller must be blocked even with propose-code cap' );
roadie_test_assert(
	false !== stripos( $blocked['error'] ?? '', 'team access' ) || false !== stripos( $blocked['error'] ?? '', 'access_roadie' ),
	'capability error must mention team access'
);

// A team member WITH access_roadie but WITHOUT propose-code MUST be allowed.
$set_user( 7, array( 'access_roadie' => true ) );

// =====================================================================
// SECURITY — on-network host check (load-bearing)
// =====================================================================

$set_page_html( $page_html );
$offnet = $tool->handle_tool_call( array( 'url' => 'https://evil.example.com/phish/' ) );
roadie_test_assert( false === $offnet['success'], 'off-network URL must be REJECTED (no arbitrary-host fetch)' );
roadie_test_assert(
	false !== stripos( $offnet['error'] ?? '', 'not on the Extra Chill' ) || false !== stripos( $offnet['error'] ?? '', 'network' ),
	'off-network rejection surfaces a clear error'
);

// A missing URL (no client-context page_url bound) errors cleanly.
$no_url = $tool->handle_tool_call( array() );
roadie_test_assert( false === $no_url['success'], 'missing URL must error cleanly' );

// =====================================================================
// Structured output (NOT a raw dump) + strip script/style/svg/noscript
// =====================================================================

$set_page_html( $page_html );
$res = $tool->handle_tool_call( array( 'url' => 'https://events.extrachill.com/calendar/' ) );
roadie_test_assert( true === $res['success'], 'team member with access_roadie can inspect an on-network page' );
roadie_test_assert( isset( $res['data']['dom'] ) && is_array( $res['data']['dom'] ), 'result carries a structured dom tree (array), not raw html' );
roadie_test_assert( ! isset( $res['data']['html'] ), 'result does NOT carry a raw html dump' );

$dom = $res['data']['dom'];
roadie_test_assert( 'main' === ( $dom['tag'] ?? '' ), 'default scope is the <main> content area. Got tag: ' . ( $dom['tag'] ?? 'NONE' ) );

// Flatten the structured tree to assert on tags/classes/text without coupling
// to exact nesting indices.
$flatten = function ( array $node, callable $self ): array {
	$out = array( $node );
	foreach ( $node['children'] ?? array() as $child ) {
		$out = array_merge( $out, $self( $child, $self ) );
	}
	return $out;
};
$nodes = $flatten( $dom, $flatten );

$tags    = array();
$classes = array();
$texts   = array();
foreach ( $nodes as $n ) {
	$tags[] = $n['tag'] ?? '';
	foreach ( $n['classes'] ?? array() as $c ) {
		$classes[] = $c;
	}
	if ( isset( $n['text'] ) ) {
		$texts[] = $n['text'];
	}
}

// Structural truth the model needs: scope-nav and filter-bar are siblings under
// main (the motivating-bug adjacency), and the calendar/map is present.
roadie_test_assert( in_array( 'nav', $tags, true ), 'structured view includes the scope <nav>' );
roadie_test_assert( in_array( 'scope-nav', $classes, true ), 'structured view carries the scope-nav class' );
roadie_test_assert( in_array( 'filter-bar', $classes, true ), 'structured view carries the filter-bar class' );
roadie_test_assert( in_array( 'event-map', $classes, true ), 'structured view carries the event-map class' );

// scope-nav and filter-bar must be DIRECT children of main (adjacency proof).
$main_child_classes = array();
foreach ( $dom['children'] ?? array() as $child ) {
	foreach ( $child['classes'] ?? array() as $c ) {
		$main_child_classes[] = $c;
	}
}
roadie_test_assert(
	in_array( 'scope-nav', $main_child_classes, true ) && in_array( 'filter-bar', $main_child_classes, true ),
	'scope-nav and filter-bar render as adjacent siblings under main (the cross-plugin adjacency the DOM read exists to reveal)'
);

// Trimmed text is captured (labels), not a full dump.
roadie_test_assert( in_array( 'Tonight', $texts, true ), 'direct text of links is captured ("Tonight")' );
roadie_test_assert( in_array( 'This Weekend', $texts, true ), 'direct text of links is captured ("This Weekend")' );

// script/style/svg/noscript content MUST be stripped — no secret tokens leak.
$json = wp_json_encode( $res );
roadie_test_assert( false === strpos( $json, 'SECRET_SCRIPT_TOKEN' ), 'script content must be stripped from the structured view' );
roadie_test_assert( false === strpos( $json, 'SECRET_NOSCRIPT_TOKEN' ), 'noscript content must be stripped from the structured view' );
roadie_test_assert( ! in_array( 'svg', $tags, true ), 'svg elements must be stripped' );
roadie_test_assert( ! in_array( 'script', $tags, true ), 'script elements must be stripped' );
roadie_test_assert( ! in_array( 'style', $tags, true ), 'style elements must be stripped' );

// Default scope excludes chrome (header/footer live outside <main>).
roadie_test_assert( ! in_array( 'site-footer', $classes, true ), 'default main-content scope excludes the footer chrome' );

// =====================================================================
// selector scoping
// =====================================================================

$set_page_html( $page_html );
$scoped = $tool->handle_tool_call( array(
	'url'      => 'https://events.extrachill.com/calendar/',
	'selector' => '.calendar',
) );
roadie_test_assert( true === $scoped['success'], 'selector-scoped inspect succeeds' );
roadie_test_assert(
	in_array( 'calendar', $scoped['data']['dom']['classes'] ?? array(), true ),
	'selector ".calendar" scopes the root to the calendar section'
);
$scoped_nodes = $flatten( $scoped['data']['dom'], $flatten );
$scoped_classes = array();
foreach ( $scoped_nodes as $n ) {
	foreach ( $n['classes'] ?? array() as $c ) {
		$scoped_classes[] = $c;
	}
}
roadie_test_assert( in_array( 'event-map', $scoped_classes, true ), 'scoped subtree includes the map inside the calendar' );
roadie_test_assert( ! in_array( 'scope-nav', $scoped_classes, true ), 'scoped subtree excludes siblings outside the selector' );

// Unmatched selector errors rather than silently returning the whole page.
$set_page_html( $page_html );
$nomatch = $tool->handle_tool_call( array(
	'url'      => 'https://events.extrachill.com/calendar/',
	'selector' => '.does-not-exist',
) );
roadie_test_assert( false === $nomatch['success'], 'unmatched selector must error, not return the whole page' );

// =====================================================================
// node cap bounds output
// =====================================================================

$set_page_html( $page_html );
$capped = $tool->handle_tool_call( array(
	'url'       => 'https://events.extrachill.com/calendar/',
	'max_nodes' => 2,
) );
roadie_test_assert( true === $capped['success'], 'node-capped inspect succeeds' );
roadie_test_assert( ( $capped['data']['node_count'] ?? 0 ) <= 2, 'node count respects the max_nodes cap' );
roadie_test_assert( true === ( $capped['data']['truncated'] ?? false ), 'hitting the node cap flags truncation' );

echo "inspect-page tool smoke passed.\n";
