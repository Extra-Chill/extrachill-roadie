<?php
/**
 * Inspect Page Tool (read-only, rendered DOM)
 *
 * Chat tool that lets Roadie READ THE RENDERED DOM of the current page — what
 * the user actually sees — so it can ground UI/layout feedback in the assembled
 * markup, not just the URL + title. This is the DOM-read counterpart to the
 * source-read inspect_code tool (#56): source tells you WHERE to change a thing,
 * DOM tells you WHAT the user sees and how separate plugins compose into one
 * page (extrachill-roadie#58, option 2 of #54).
 *
 * THE GROUNDING LOOP this completes:
 *   inspect_page  — "what / where on the screen" (rendered DOM, adjacency,
 *                   visual dominance, cross-plugin composition)
 *      ->
 *   inspect_code  — "which plugin / file emits it" (source jailed to the
 *                   subsite's owning components)
 *      ->
 *   grounded issue (file_feature_request) or propose_code_change.
 *
 * INPUT:
 *   - url      — optional. Defaults to the current page from client context
 *                (page_url, bound via client_context_bindings — the same key
 *                inc/frontend-chat.php populates from the widget). An explicit
 *                url may be passed but is constrained to the SAME multisite
 *                network (see SECURITY).
 *   - selector — optional CSS-ish hint to scope the returned subtree
 *                ("#main", ".calendar", "main"). Defaults to the page's main
 *                content region (<main>, [role=main], #main, .site-main, then
 *                <body>) so the model gets the relevant area, not chrome.
 *   - max_nodes/max_depth — optional caps on the structured output size.
 *
 * OUTPUT: a STRUCTURED, TOKEN-ECONOMICAL view of the relevant subtree — tag +
 * id + classes + trimmed text + nesting — NOT a raw full-page HTML dump.
 * script/style/svg/noscript/template are stripped. This is enough to reason
 * about sibling/container relationships and visual dominance without blowing up
 * a chat turn.
 *
 * SECURITY MODEL (the whole point — never leak a page the caller can't see):
 *   - Read-only, full stop. No write action exists on this tool.
 *   - Capability: TEAM TIER (access_roadie) — identical gate to inspect_code,
 *     via extrachill_roadie_user_tier(). A public visitor gets a clean
 *     permission error.
 *   - SAME-NETWORK ONLY: the resolved URL's host MUST belong to this WordPress
 *     multisite network (verified against the network's registered site hosts).
 *     An off-network URL is rejected — this tool is not a generic web fetcher
 *     and cannot be turned into an SSRF primitive against arbitrary hosts.
 *   - CALLER'S VIEW: the fetch forwards the calling request's auth cookies so
 *     the page renders exactly as the (already team-gated) caller would see it —
 *     no more, no less. Because the caller is team-or-above AND the host is
 *     on-network AND their own session drives the render, the tool can never
 *     return a page the caller couldn't load themselves in a browser.
 *   - Dependency-free parsing: PHP's bundled DOMDocument. No external HTML
 *     library, no shell-out, no headless browser.
 *
 * Mechanism choice (documented for the PR): an internal authenticated HTTP GET
 * (wp_remote_get) forwarding the caller's cookies, rather than a bare
 * server-side render. wp_remote_get over the resolved on-network URL with the
 * caller's cookies reproduces the caller's authenticated view (role-gated nav,
 * logged-in chrome) faithfully and without reimplementing the full template
 * stack; constraining the host to the network + re-enforcing the team gate is
 * what keeps it safe. A cookie-less internal render would silently drop the
 * caller's session and could show MORE or LESS than they see — exactly the leak
 * this tool must avoid.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

/**
 * Read-only rendered-DOM inspector chat tool for the current Extra Chill page.
 *
 * @see ECRoadie_InspectCode The source-read sibling this completes the loop with.
 */
class ECRoadie_InspectPage extends BaseTool {

	/**
	 * Tool slug as registered with Data Machine's tool system.
	 *
	 * @var string
	 */
	protected string $tool_slug = 'inspect_page';

	/**
	 * Hard ceiling on bytes of HTML fetched and parsed. Larger responses are
	 * truncated before parsing so one page can't blow up a chat turn.
	 *
	 * @var int
	 */
	protected const MAX_HTML_BYTES = 2097152; // 2 MB.

	/**
	 * Default and maximum number of element nodes emitted in the structured
	 * subtree. The cap keeps the response token-economical.
	 *
	 * @var int
	 */
	protected const DEFAULT_MAX_NODES = 400;
	protected const MAX_MAX_NODES     = 1500;

	/**
	 * Default and maximum nesting depth of the emitted subtree.
	 *
	 * @var int
	 */
	protected const DEFAULT_MAX_DEPTH = 12;
	protected const MAX_MAX_DEPTH     = 30;

	/**
	 * Per-node trimmed-text cap so one verbose paragraph can't bloat output.
	 *
	 * @var int
	 */
	protected const MAX_NODE_TEXT = 200;

	/**
	 * HTTP fetch timeout in seconds.
	 *
	 * @var int
	 */
	protected const FETCH_TIMEOUT = 10;

	/**
	 * Element tags stripped entirely from the structured view (and their
	 * subtrees) — non-visual, non-layout noise.
	 *
	 * @var array<int,string>
	 */
	protected const STRIP_TAGS = array( 'script', 'style', 'svg', 'noscript', 'template', 'link', 'meta' );

	/**
	 * Register the tool with Data Machine's tool system.
	 */
	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
			array(
				'access_level'            => 'authenticated',
				// Bind the per-turn client-context page_url into the `url`
				// parameter slot when the model doesn't pass one explicitly.
				// inc/frontend-chat.php populates client_context['page_url']
				// from the widget; the runtime merges it here so "inspect this
				// page" works with no argument. Caller-supplied url still wins.
				'client_context_bindings' => array( 'url' => 'page_url' ),
			)
		);
	}

	/**
	 * Tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'                   => self::class,
			'method'                  => 'handle_tool_call',
			'client_context_bindings' => array( 'url' => 'page_url' ),
			'description'             => 'Read-only inspector for the RENDERED DOM of the current page — what the user actually sees. Use this to GROUND UI/layout feedback before describing or filing it: when a user says "the calendar map is too big" or "the tonight/this weekend buttons should be grouped with the search controls," call inspect_page to read the assembled markup and see how elements actually nest and sit next to each other, instead of guessing layout from the URL and title. This returns a STRUCTURED, token-economical view of the relevant subtree (element tags + ids + classes + trimmed text + nesting) — NOT a raw HTML dump — so you can reason about sibling/container relationships and which area dominates the page. It is the DOM half of grounding; pair it with inspect_code (the SOURCE half): inspect_page shows WHAT is on screen and how plugins compose into one page, then inspect_code shows WHICH plugin/file emits a given element. This tool is strictly READ-ONLY and can only read pages on the Extra Chill multisite network, rendered in YOUR (the calling team member\'s) own logged-in view — it cannot fetch arbitrary external sites and cannot show a page you could not load yourself. By default it reads the current page (from client context) and scopes to the main content area; pass url to read a specific on-network page, or selector to focus a region (e.g. ".calendar", "#main").',
			'parameters'              => array(
				'type'       => 'object',
				'required'   => array(),
				'properties' => array(
					'url'       => array(
						'type'        => 'string',
						'description' => 'Optional. The page to inspect. Defaults to the page the user is currently viewing (from client context). Must be a URL on the Extra Chill multisite network (extrachill.com or a *.extrachill.com subsite); off-network URLs are rejected.',
					),
					'selector'  => array(
						'type'        => 'string',
						'description' => 'Optional CSS-style hint to scope the returned subtree. Supports a single tag ("main", "header"), an id ("#main"), a class (".calendar", ".filter-bar"), or "tag.class". Omit to default to the page\'s main content region (main / [role=main] / #main / .site-main, then body). Use this to focus on the area the user mentioned.',
					),
					'max_nodes' => array(
						'type'        => 'integer',
						'description' => 'Optional cap on the number of element nodes returned (defaults to 400, max 1500). Lower it to get a coarse overview of a large page first.',
					),
					'max_depth' => array(
						'type'        => 'integer',
						'description' => 'Optional cap on how deeply nested the returned subtree goes (defaults to 12, max 30).',
					),
				),
			),
		);
	}

	/**
	 * Tool callback.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param array<string,mixed> $tool_def   Resolved tool definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		// 1. Capability check — TEAM TIER (access_roadie), identical to
		// inspect_code. Reading the rendered page to ground feedback is a team
		// action; a public visitor has no team access and gets a clean error.
		$cap_check = $this->check_team_capability();
		if ( true !== $cap_check ) {
			return $cap_check;
		}

		// 2. Resolve the target URL: explicit param, else the bound page_url
		// from client context.
		$url = trim( (string) ( $parameters['url'] ?? '' ) );
		if ( '' === $url ) {
			return $this->buildErrorResponse(
				'No page URL is available to inspect. The current page URL normally arrives from the chat client context; pass an explicit url (on the Extra Chill network) if none is present.',
				$this->tool_slug
			);
		}

		// 3. SECURITY: constrain to the multisite network. This is what stops
		// the tool being an arbitrary-host fetcher / SSRF primitive.
		$safe_url = $this->resolve_on_network_url( $url );
		if ( ! is_string( $safe_url ) ) {
			return $safe_url; // Error response.
		}

		// 4. Fetch the rendered HTML in the caller's authenticated view.
		$html = $this->fetch_rendered_html( $safe_url );
		if ( ! is_string( $html ) ) {
			return $html; // Error response.
		}

		// 5. Parse + emit a structured, token-economical subtree.
		$selector  = trim( (string) ( $parameters['selector'] ?? '' ) );
		$max_nodes = $this->clamp_int( $parameters['max_nodes'] ?? 0, self::DEFAULT_MAX_NODES, 1, self::MAX_MAX_NODES );
		$max_depth = $this->clamp_int( $parameters['max_depth'] ?? 0, self::DEFAULT_MAX_DEPTH, 1, self::MAX_MAX_DEPTH );

		return $this->build_structured_view( $html, $safe_url, $selector, $max_nodes, $max_depth );
	}

	/**
	 * Gate on team tier via the access_roadie capability.
	 *
	 * Mirrors ECRoadie_InspectCode::check_team_capability() exactly — reuses
	 * extrachill_roadie_user_tier() so the tier boundary lives in one place,
	 * with a direct cap-check fallback. Either path requires team-or-above.
	 *
	 * @return true|array True when allowed, error response otherwise.
	 */
	protected function check_team_capability() {
		$allowed = false;

		if ( function_exists( 'extrachill_roadie_user_tier' ) ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$tier    = extrachill_roadie_user_tier( $user_id );
			$allowed = in_array(
				$tier,
				array( EXTRACHILL_ROADIE_TIER_TEAM, EXTRACHILL_ROADIE_TIER_ADMIN ),
				true
			);
		} else {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap granted by the extra_chill_team role (extrachill-users#45).
			$allowed = function_exists( 'current_user_can' ) && current_user_can( 'access_roadie' );
		}

		if ( ! $allowed ) {
			return $this->buildErrorResponse(
				'You do not have permission to inspect the rendered page. This requires Extra Chill team access (the "access_roadie" capability). Ask an administrator to add you to the team role.',
				$this->tool_slug
			);
		}

		return true;
	}

	/**
	 * Validate that $url points at a host on THIS multisite network, and return
	 * the canonical URL to fetch. THIS IS THE LOAD-BEARING SAFETY CHECK — it is
	 * what keeps inspect_page from being an arbitrary-host fetcher.
	 *
	 * Algorithm:
	 *   1. Parse the URL; require http/https and a host.
	 *   2. Compare the host (case-insensitive) against the set of registered
	 *      network site hosts. Accept only an exact host match.
	 *
	 * The network host set comes from get_sites() (each blog's home host) plus
	 * the current site's home host as a backstop. A test seam filter lets the
	 * smoke suite inject hosts without booting multisite.
	 *
	 * @param string $url Caller-supplied or context-bound URL.
	 * @return string|array Canonical URL string, or error response.
	 */
	protected function resolve_on_network_url( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $this->buildErrorResponse(
				sprintf( 'Could not parse a host from the URL "%s".', $url ),
				$this->tool_slug
			);
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $this->buildErrorResponse(
				'Only http/https URLs can be inspected.',
				$this->tool_slug
			);
		}

		$host          = strtolower( (string) $parts['host'] );
		$network_hosts = $this->network_hosts();

		if ( ! in_array( $host, $network_hosts, true ) ) {
			return $this->buildErrorResponse(
				sprintf(
					'The URL "%s" is not on the Extra Chill multisite network, so it cannot be inspected. inspect_page only reads pages on this network (extrachill.com and its subsites), never arbitrary external sites.',
					$url
				),
				$this->tool_slug
			);
		}

		return $url;
	}

	/**
	 * The set of lowercased hosts that belong to this multisite network.
	 *
	 * @return array<int,string>
	 */
	protected function network_hosts(): array {
		$hosts = array();

		if ( function_exists( 'get_sites' ) ) {
			$sites = get_sites( array( 'number' => 0 ) );
			foreach ( (array) $sites as $site ) {
				$domain = is_object( $site ) ? (string) ( $site->domain ?? '' ) : '';
				if ( '' !== $domain ) {
					$hosts[] = strtolower( $domain );
				}
			}
		}

		// Backstop: the current site's home host.
		if ( function_exists( 'home_url' ) ) {
			$home_host = wp_parse_url( (string) home_url(), PHP_URL_HOST );
			if ( is_string( $home_host ) && '' !== $home_host ) {
				$hosts[] = strtolower( $home_host );
			}
		}

		/**
		 * Filter the lowercased host allow-list inspect_page will fetch from.
		 *
		 * Defaults to the registered multisite network hosts. This filter
		 * exists for tests and for surgical environment adjustments; it must
		 * only ever describe hosts ON this network — never widen the tool into
		 * an arbitrary-host fetcher.
		 *
		 * @since 0.14.0
		 *
		 * @param array<int,string> $hosts Lowercased network hosts.
		 */
		$hosts = (array) apply_filters( 'extrachill_roadie_inspect_page_network_hosts', $hosts );

		$clean = array();
		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host ) {
				$clean[ $host ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * Fetch the rendered HTML of an on-network URL in the calling team
	 * member's authenticated view.
	 *
	 * The caller's auth cookies (from the originating request) are forwarded so
	 * the page renders exactly as that user would see it — role-gated nav,
	 * logged-in chrome, the works. Combined with the team gate + on-network
	 * host check, this guarantees the tool never returns a page the caller
	 * couldn't load themselves.
	 *
	 * A test seam filter (extrachill_roadie_inspect_page_html) lets the smoke
	 * suite inject HTML so the parsing/structuring logic is exercised without a
	 * live HTTP round-trip.
	 *
	 * @param string $url On-network, validated URL.
	 * @return string|array HTML string, or error response.
	 */
	protected function fetch_rendered_html( string $url ) {
		/**
		 * Short-circuit the HTTP fetch with caller-supplied HTML.
		 *
		 * Returns null by default (perform the real fetch). A non-null string
		 * is treated as the fetched HTML and the network request is skipped.
		 * Used by the smoke suite to drive the parser deterministically.
		 *
		 * @since 0.14.0
		 *
		 * @param string|null $pre Pre-fetched HTML, or null to fetch.
		 * @param string      $url The on-network URL being fetched.
		 */
		$pre = apply_filters( 'extrachill_roadie_inspect_page_html', null, $url );
		if ( is_string( $pre ) ) {
			return $this->cap_html( $pre );
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->buildErrorResponse(
				'The HTTP client is unavailable in this context, so the page cannot be fetched.',
				$this->tool_slug
			);
		}

		$args = array(
			'timeout'     => self::FETCH_TIMEOUT,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => array(),
		);

		$cookie_header = $this->caller_cookie_header();
		if ( '' !== $cookie_header ) {
			$args['headers']['Cookie'] = $cookie_header;
		}

		$response = wp_remote_get( $url, $args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $this->buildErrorResponse(
				sprintf( 'Could not fetch the page "%s": %s', $url, $response->get_error_message() ),
				$this->tool_slug
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return $this->buildErrorResponse(
				sprintf( 'Fetching "%s" returned HTTP %d, so there is no rendered page to inspect.', $url, $code ),
				$this->tool_slug
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return $this->buildErrorResponse(
				sprintf( 'The page "%s" returned an empty body.', $url ),
				$this->tool_slug
			);
		}

		return $this->cap_html( $body );
	}

	/**
	 * Build the Cookie header that reproduces the caller's authenticated view
	 * by forwarding the auth cookies present on the originating request.
	 *
	 * Only forwards cookies; never invents or elevates a session. If the caller
	 * had no auth cookie (e.g. token-auth context), the fetch is anonymous —
	 * which still cannot exceed what an anonymous viewer sees, so it cannot
	 * leak.
	 *
	 * @return string Cookie header value, or '' when none.
	 */
	protected function caller_cookie_header(): string {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return '';
		}

		$pairs = array();
		foreach ( $_COOKIE as $name => $value ) {
			$name = (string) $name;
			// Forward only WordPress auth/session cookies — not unrelated
			// third-party cookies — so the render reflects the caller's login
			// without leaking anything else.
			if ( 0 !== strpos( $name, 'wordpress_' ) && 0 !== strpos( $name, 'wp-' ) && 0 !== strpos( $name, 'wp_' ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$pairs[] = $name . '=' . rawurlencode( (string) $value );
		}

		return implode( '; ', $pairs );
	}

	/**
	 * Hard-cap the HTML byte length before parsing.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	protected function cap_html( string $html ): string {
		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			return substr( $html, 0, self::MAX_HTML_BYTES );
		}
		return $html;
	}

	/**
	 * Parse HTML and emit a structured, token-economical subtree.
	 *
	 * @param string $html      Rendered HTML.
	 * @param string $url       The inspected URL (echoed back).
	 * @param string $selector  Optional scope hint.
	 * @param int    $max_nodes Node cap.
	 * @param int    $max_depth Depth cap.
	 * @return array<string,mixed>
	 */
	protected function build_structured_view( string $html, string $url, string $selector, int $max_nodes, int $max_depth ): array {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->buildErrorResponse(
				'The DOM parser (DOMDocument) is unavailable in this environment, so the page cannot be structured.',
				$this->tool_slug
			);
		}

		$doc = new \DOMDocument();

		// Suppress libxml warnings on real-world (imperfect) HTML; we only need
		// a best-effort tree. Force UTF-8 handling.
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->buildErrorResponse(
				'The page HTML could not be parsed into a DOM tree.',
				$this->tool_slug
			);
		}

		$root = $this->select_root( $doc, $selector );
		if ( null === $root ) {
			return $this->buildErrorResponse(
				sprintf( 'No element matched the selector "%s" on this page. Omit selector to inspect the main content area, or try a broader hint.', $selector ),
				$this->tool_slug
			);
		}

		$state = array(
			'count'     => 0,
			'max_nodes' => $max_nodes,
			'truncated' => false,
		);

		$tree = $this->node_to_struct( $root, $max_depth, 0, $state );

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'url'        => $url,
				'scope'      => '' !== $selector ? $selector : 'main-content',
				'node_count' => $state['count'],
				'max_nodes'  => $max_nodes,
				'max_depth'  => $max_depth,
				'truncated'  => $state['truncated'],
				'dom'        => $tree,
				'next_step'  => 'This is the rendered DOM the user sees. To find which plugin/file emits an element here, use inspect_code (grep a class or text you see above, then read_file the template it points at). Ground every layout claim in this tree or that source — do not assert positions you have not read.',
			),
		);
	}

	/**
	 * Resolve the root element to structure from, honoring an optional
	 * selector and otherwise defaulting to the main content region.
	 *
	 * Supported selector shapes (single, simple): "tag", "#id", ".class",
	 * "tag.class". Anything more complex falls back to the main region.
	 *
	 * @param \DOMDocument $doc      Parsed document.
	 * @param string       $selector Optional scope hint.
	 * @return \DOMElement|null
	 */
	protected function select_root( \DOMDocument $doc, string $selector ) {
		if ( '' !== $selector ) {
			$match = $this->match_selector( $doc, $selector );
			if ( null !== $match ) {
				return $match;
			}
			// Unmatched selector: signal "no match" to the caller rather than
			// silently returning the whole page.
			return null;
		}

		// Default: the main content region, in preference order.
		foreach ( array( 'main' ) as $tag ) {
			$el = $this->first_by_tag( $doc, $tag );
			if ( null !== $el ) {
				return $el;
			}
		}

		$role_main = $this->first_by_attr( $doc, 'role', 'main' );
		if ( null !== $role_main ) {
			return $role_main;
		}

		foreach ( array( 'main', 'site-main', 'content', 'primary' ) as $id ) {
			$el = $this->first_by_id( $doc, $id );
			if ( null !== $el ) {
				return $el;
			}
		}

		foreach ( array( 'site-main', 'content', 'main' ) as $class ) {
			$el = $this->first_by_class( $doc, $class );
			if ( null !== $el ) {
				return $el;
			}
		}

		// Last resort: <body>.
		$body = $this->first_by_tag( $doc, 'body' );
		if ( null !== $body ) {
			return $body;
		}

		return $doc->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument core API property.
	}

	/**
	 * Match a single simple selector ("tag", "#id", ".class", "tag.class").
	 *
	 * @param \DOMDocument $doc      Parsed document.
	 * @param string       $selector Selector hint.
	 * @return \DOMElement|null
	 */
	protected function match_selector( \DOMDocument $doc, string $selector ) {
		$selector = trim( $selector );

		if ( 0 === strpos( $selector, '#' ) ) {
			return $this->first_by_id( $doc, substr( $selector, 1 ) );
		}

		if ( 0 === strpos( $selector, '.' ) ) {
			return $this->first_by_class( $doc, substr( $selector, 1 ) );
		}

		// "tag.class"
		if ( false !== strpos( $selector, '.' ) ) {
			list( $tag, $class ) = explode( '.', $selector, 2 );
			return $this->first_by_tag_class( $doc, $tag, $class );
		}

		// Bare tag.
		return $this->first_by_tag( $doc, $selector );
	}

	/**
	 * First element with the given tag name.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @param string       $tag Tag name.
	 * @return \DOMElement|null
	 */
	protected function first_by_tag( \DOMDocument $doc, string $tag ) {
		$list = $doc->getElementsByTagName( $tag );
		$item = $list->item( 0 );
		return ( $item instanceof \DOMElement ) ? $item : null;
	}

	/**
	 * First element with the given id.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @param string       $id  Element id.
	 * @return \DOMElement|null
	 */
	protected function first_by_id( \DOMDocument $doc, string $id ) {
		$all = $doc->getElementsByTagName( '*' );
		foreach ( $all as $el ) {
			if ( $el instanceof \DOMElement && $el->getAttribute( 'id' ) === $id ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * First element carrying the given class token.
	 *
	 * @param \DOMDocument $doc         Parsed document.
	 * @param string       $class_token Class token (no leading dot).
	 * @return \DOMElement|null
	 */
	protected function first_by_class( \DOMDocument $doc, string $class_token ) {
		$all = $doc->getElementsByTagName( '*' );
		foreach ( $all as $el ) {
			if ( $el instanceof \DOMElement && $this->has_class( $el, $class_token ) ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * First element matching both tag and class.
	 *
	 * @param \DOMDocument $doc         Parsed document.
	 * @param string       $tag         Tag name.
	 * @param string       $class_token Class token.
	 * @return \DOMElement|null
	 */
	protected function first_by_tag_class( \DOMDocument $doc, string $tag, string $class_token ) {
		$list = $doc->getElementsByTagName( $tag );
		foreach ( $list as $el ) {
			if ( $el instanceof \DOMElement && $this->has_class( $el, $class_token ) ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * First element whose attribute equals a value.
	 *
	 * @param \DOMDocument $doc   Parsed document.
	 * @param string       $attr  Attribute name.
	 * @param string       $value Attribute value.
	 * @return \DOMElement|null
	 */
	protected function first_by_attr( \DOMDocument $doc, string $attr, string $value ) {
		$all = $doc->getElementsByTagName( '*' );
		foreach ( $all as $el ) {
			if ( $el instanceof \DOMElement && $el->getAttribute( $attr ) === $value ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * Whether an element carries a class token.
	 *
	 * @param \DOMElement $el          Element.
	 * @param string      $class_token Class token.
	 * @return bool
	 */
	protected function has_class( \DOMElement $el, string $class_token ): bool {
		$classes = preg_split( '/\s+/', (string) $el->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $classes ) && in_array( $class_token, $classes, true );
	}

	/**
	 * Convert a DOM element into the structured, token-economical node shape:
	 *   { tag, id?, classes?, text?, children?, truncated? }
	 *
	 * Stripped tags (script/style/svg/...) are skipped entirely. Direct text of
	 * a node is captured (trimmed + capped) so the model sees labels/copy
	 * without a full text dump. Depth and node-count caps bound the output.
	 *
	 * @param \DOMElement $el    Element to convert.
	 * @param int         $max_depth Remaining depth budget.
	 * @param int         $depth     Current depth.
	 * @param array       $state     Shared counter/state (by reference).
	 * @return array<string,mixed>
	 */
	protected function node_to_struct( \DOMElement $el, int $max_depth, int $depth, array &$state ): array {
		++$state['count'];

		$node = array( 'tag' => strtolower( $el->nodeName ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.

		$id = trim( (string) $el->getAttribute( 'id' ) );
		if ( '' !== $id ) {
			$node['id'] = $id;
		}

		$classes = preg_split( '/\s+/', (string) $el->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $classes ) && array() !== $classes ) {
			$node['classes'] = array_values( $classes );
		}

		$text = $this->direct_text( $el );
		if ( '' !== $text ) {
			$node['text'] = $text;
		}

		// Depth guard.
		if ( $depth >= $max_depth ) {
			if ( $el->hasChildNodes() ) {
				$node['truncated'] = 'depth';
			}
			return $node;
		}

		$children = array();
		foreach ( $el->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.
			if ( ! ( $child instanceof \DOMElement ) ) {
				continue;
			}

			$tag = strtolower( $child->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.
			if ( in_array( $tag, self::STRIP_TAGS, true ) ) {
				continue;
			}

			if ( $state['count'] >= $state['max_nodes'] ) {
				$state['truncated'] = true;
				$node['truncated']  = 'nodes';
				break;
			}

			$children[] = $this->node_to_struct( $child, $max_depth, $depth + 1, $state );
		}

		if ( array() !== $children ) {
			$node['children'] = $children;
		}

		return $node;
	}

	/**
	 * Extract the DIRECT text of an element (its own text nodes, not
	 * descendants'), trimmed and length-capped.
	 *
	 * @param \DOMElement $el Element.
	 * @return string
	 */
	protected function direct_text( \DOMElement $el ): string {
		$parts = array();
		foreach ( $el->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.
			if ( XML_TEXT_NODE === $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.
				$value = trim( (string) $child->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode core API property.
				if ( '' !== $value ) {
					$parts[] = $value;
				}
			}
		}

		$text = trim( implode( ' ', $parts ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = is_string( $text ) ? $text : '';

		if ( strlen( $text ) > self::MAX_NODE_TEXT ) {
			$text = substr( $text, 0, self::MAX_NODE_TEXT ) . '…';
		}

		return $text;
	}

	/**
	 * Clamp an integer parameter into [min,max], applying a default when the
	 * supplied value is non-positive/absent.
	 *
	 * @param mixed $value    Supplied value.
	 * @param int   $fallback Default when absent/non-positive.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @return int
	 */
	protected function clamp_int( $value, int $fallback, int $min, int $max ): int {
		$int = (int) $value;
		if ( $int <= 0 ) {
			$int = $fallback;
		}
		return max( $min, min( $int, $max ) );
	}
}
