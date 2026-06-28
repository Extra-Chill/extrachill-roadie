<?php
/**
 * Smoke tests for the read-only, public search_content chat tool.
 *
 * Covers:
 *   - Tool registers via the datamachine_tools filter for chat mode with
 *     PUBLIC access_level (a logged-out visitor benefits — the catalog is
 *     public), and exposes no client-context binding.
 *   - Tool definition is read-only: params are exactly {query, post_types,
 *     limit}; there is no action/write param.
 *   - It wraps the extrachill/multisite-search ability (does not reimplement
 *     search): the ability is called with the query, publish status, and a
 *     clamped limit, plus optional post_type.
 *   - CITATIONS: each result maps to a canonical agents-api citation
 *     (source.url = permalink, source.title = post_title, source.label =
 *     site_name, snippet from the excerpt) AND the result is mirrored in the
 *     tool `data` so the model can link inline. Citations ride on top-level
 *     `metadata.citations` — the channel the chat REST layer surfaces onto the
 *     assistant message.
 *   - Snippet building strips tags/shortcodes, collapses whitespace, and caps
 *     length.
 *   - Graceful degradation: when the multisite-search ability is absent the
 *     tool returns a clean error, not a fatal.
 *   - Empty query is rejected; a zero-result search returns success with an
 *     honest "no coverage — do not fabricate" next_step and no citations.
 *
 * Run: php tests/search-content-tool.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- BaseTool stub (same shape as the other tool smoke tests) --------------
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
		}'
	);
}

// --- Minimal WP shims the tool touches -------------------------------------
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			unset( $code );
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string ): string {
		return trim( strip_tags( $string ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( string $content ): string {
		return preg_replace( '/\[[^\]]*\]/', '', $content ) ?? $content;
	}
}

// --- Controllable fake search ability --------------------------------------
$GLOBALS['extrachill_roadie_test_state']['search_ability']      = null;
$GLOBALS['extrachill_roadie_test_state']['last_search_input']   = null;

/**
 * A stand-in ability object whose execute() returns a canned payload (or a
 * WP_Error) and records the input it was called with.
 */
class Roadie_Fake_Search_Ability {
	/** @var mixed */
	private $payload;
	public function __construct( $payload ) {
		$this->payload = $payload;
	}
	public function execute( $input ) {
		$GLOBALS['extrachill_roadie_test_state']['last_search_input'] = $input;
		return $this->payload;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		if ( 'extrachill/multisite-search' !== $name ) {
			return null;
		}
		return $GLOBALS['extrachill_roadie_test_state']['search_ability'];
	}
}

require_once dirname( __DIR__ ) . '/inc/tools/class-search-content.php';

$GLOBALS['extrachill_roadie_test_state']['registered_tools'] = array();

$tool = new ECRoadie_SearchContent();

// =====================================================================
// Registration + definition (read-only, public)
// =====================================================================

roadie_test_assert(
	isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools']['search_content'] ),
	'search_content must register via registerTool()'
);

$reg = $GLOBALS['extrachill_roadie_test_state']['registered_tools']['search_content'];
roadie_test_assert( in_array( 'chat', $reg['modes'], true ), 'tool must register for chat mode' );
roadie_test_assert(
	'public' === ( $reg['meta']['access_level'] ?? '' ),
	'tool must be PUBLIC access_level — the catalog is public, visitors benefit'
);
roadie_test_assert(
	empty( $reg['meta']['client_context_bindings'] ),
	'search_content needs no client-context binding — the query comes from reasoning, not the page'
);

$definition = $tool->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $definition['method'], 'tool dispatch method' );

$props = array_keys( $definition['parameters']['properties'] ?? array() );
sort( $props );
roadie_test_assert(
	$props === array( 'limit', 'post_types', 'query' ),
	'params are exactly the read-only set (query, post_types, limit). Got: ' . implode( ',', $props )
);
roadie_test_assert(
	! isset( $definition['parameters']['properties']['action'] ),
	'search_content has no action param — it is read-only, not a multi-action tool'
);
roadie_test_assert(
	in_array( 'query', $definition['parameters']['required'] ?? array(), true ),
	'query is required'
);

// =====================================================================
// Empty query is rejected
// =====================================================================

$err = $tool->handle_tool_call( array( 'query' => '   ' ) );
roadie_test_assert( false === ( $err['success'] ?? null ), 'empty query rejected' );

// =====================================================================
// Graceful degradation when the search ability is absent
// =====================================================================

$GLOBALS['extrachill_roadie_test_state']['search_ability'] = null;
$missing = $tool->handle_tool_call( array( 'query' => 'Jerry Garcia' ) );
roadie_test_assert( false === ( $missing['success'] ?? null ), 'absent ability degrades to a clean error' );
roadie_test_assert(
	false !== strpos( (string) ( $missing['error'] ?? '' ), 'multisite-search' ),
	'error names the missing search ability'
);

// =====================================================================
// Happy path: results -> citations + data + metadata.citations
// =====================================================================

$GLOBALS['extrachill_roadie_test_state']['search_ability'] = new Roadie_Fake_Search_Ability(
	array(
		array(
			'ID'           => 101,
			'post_title'   => 'What "Ripple" Means: Jerry Garcia and the Grateful Dead',
			'post_excerpt' => '<p>A look at the [caption]lyrics[/caption] and the   story behind the song.</p>',
			'post_content' => 'Full content here.',
			'post_date'    => '2021-03-01 10:00:00',
			'post_type'    => 'post',
			'permalink'    => 'https://extrachill.com/ripple-meaning',
			'site_name'    => 'Extra Chill',
		),
		array(
			'ID'           => 102,
			'post_title'   => 'Grateful Dead at the Pour House',
			'post_excerpt' => '',
			'post_content' => 'A night of Dead covers in Charleston.',
			'post_date'    => '2022-06-01 10:00:00',
			'post_type'    => 'post',
			'permalink'    => 'https://extrachill.com/dead-pour-house',
			'site_name'    => 'Extra Chill',
		),
	)
);

$res = $tool->handle_tool_call(
	array(
		'query'      => 'Jerry Garcia Ripple meaning',
		'post_types' => array( 'post', 'post', '' ),
		'limit'      => 50, // over the cap — must clamp.
	)
);

roadie_test_assert( true === ( $res['success'] ?? null ), 'happy-path search succeeds' );
roadie_test_assert( 'search_content' === ( $res['tool_name'] ?? '' ), 'tool_name echoed' );

// Ability input: query, publish status, clamped limit, deduped post_type.
$input = $GLOBALS['extrachill_roadie_test_state']['last_search_input'];
roadie_test_assert( 'Jerry Garcia Ripple meaning' === ( $input['search_term'] ?? '' ), 'query passed as search_term' );
roadie_test_assert( array( 'publish' ) === ( $input['post_status'] ?? null ), 'search restricted to published content' );
roadie_test_assert( 12 === ( $input['limit'] ?? 0 ), 'limit clamped to MAX_LIMIT (12)' );
roadie_test_assert( array( 'post' ) === ( $input['post_type'] ?? null ), 'post_types sanitized + deduped to ["post"]' );

// metadata.citations — the canonical channel.
$citations = $res['metadata']['citations'] ?? null;
roadie_test_assert( is_array( $citations ) && 2 === count( $citations ), 'two citations on metadata.citations' );
roadie_test_assert( 2 === ( $res['metadata']['source_count'] ?? 0 ), 'metadata.source_count matches' );

$c0 = $citations[0];
roadie_test_assert( 1 === ( $c0['index'] ?? 0 ), 'citation carries a 1-based index' );
roadie_test_assert( 'https://extrachill.com/ripple-meaning' === ( $c0['source']['url'] ?? '' ), 'source.url = permalink' );
roadie_test_assert(
	'What "Ripple" Means: Jerry Garcia and the Grateful Dead' === ( $c0['source']['title'] ?? '' ),
	'source.title = post_title'
);
roadie_test_assert( 'Extra Chill' === ( $c0['source']['label'] ?? '' ), 'source.label = site_name' );
roadie_test_assert( 'https://extrachill.com/ripple-meaning' === ( $c0['url'] ?? '' ), 'top-level url = permalink (renderable)' );

// Snippet: tags + shortcodes stripped, whitespace collapsed.
$snippet = (string) ( $c0['snippet'] ?? '' );
roadie_test_assert( false === strpos( $snippet, '<' ), 'snippet has no HTML tags' );
roadie_test_assert( false === strpos( $snippet, '[caption]' ), 'snippet has no shortcodes' );
roadie_test_assert( false === strpos( $snippet, '   ' ), 'snippet whitespace collapsed' );

// Second result had empty excerpt -> snippet falls back to content.
roadie_test_assert(
	false !== strpos( (string) ( $citations[1]['snippet'] ?? '' ), 'Charleston' ),
	'empty excerpt falls back to a content snippet'
);

// Data mirror so the model can read + link inline.
roadie_test_assert( 2 === ( $res['data']['count'] ?? 0 ), 'data.count = 2' );
roadie_test_assert( 'Jerry Garcia Ripple meaning' === ( $res['data']['query'] ?? '' ), 'data echoes the query' );
$first_item = $res['data']['results'][0] ?? array();
roadie_test_assert(
	'https://extrachill.com/ripple-meaning' === ( $first_item['url'] ?? '' ),
	'data result carries the permalink for inline linking'
);
roadie_test_assert(
	false !== strpos( (string) ( $res['data']['next_step'] ?? '' ), 'cite' ),
	'next_step steers the model to cite the articles'
);

// =====================================================================
// WP_Error from the ability degrades cleanly
// =====================================================================

$GLOBALS['extrachill_roadie_test_state']['search_ability'] = new Roadie_Fake_Search_Ability(
	new WP_Error( 'boom', 'search exploded' )
);
$wp_err = $tool->handle_tool_call( array( 'query' => 'anything' ) );
roadie_test_assert( false === ( $wp_err['success'] ?? null ), 'ability WP_Error degrades to a clean error' );

// =====================================================================
// Zero results: honest, no fabricated coverage, no citations
// =====================================================================

$GLOBALS['extrachill_roadie_test_state']['search_ability'] = new Roadie_Fake_Search_Ability( array() );
$empty = $tool->handle_tool_call( array( 'query' => 'no such artist 9xz' ) );
roadie_test_assert( true === ( $empty['success'] ?? null ), 'zero-result search still succeeds' );
roadie_test_assert( 0 === ( $empty['data']['count'] ?? -1 ), 'zero results reported as count 0' );
roadie_test_assert( array() === ( $empty['metadata']['citations'] ?? null ), 'no citations on a zero-result search' );
roadie_test_assert(
	false !== stripos( (string) ( $empty['data']['next_step'] ?? '' ), 'do not fabricate' ),
	'zero-result next_step forbids fabricating coverage/quotes'
);

echo 'Roadie search_content tool smoke passed.' . PHP_EOL;
