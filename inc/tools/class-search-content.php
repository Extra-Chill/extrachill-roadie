<?php
/**
 * Search Content Tool (read-only, public)
 *
 * Chat tool that lets Roadie SEARCH EXTRA CHILL'S PUBLISHED CATALOG — the
 * 2,800+ articles, artist coverage, song-meaning pieces, music history, and
 * Grateful Dead/Garcia writing that actually live on the network — so the model
 * answers editorial/music questions FROM THE SITE, with sources, instead of
 * from its own (unreliable) memory.
 *
 * THE GAP THIS CLOSES: asked for a Jerry Garcia quote, Roadie returned a Mick
 * Jagger lyric and misattributed it — a hallucination, because it had no tool
 * to look up what Extra Chill has published. This tool gives it eyes on the
 * real catalog and renders the results as CITATIONS in the chat UI, so every
 * music/editorial claim can be grounded in (and linked to) a real article.
 *
 * THE TWO PRIMITIVES THIS BRIDGES (it builds NO new infrastructure):
 *   1. extrachill/multisite-search — the network-wide search ability already
 *      registered by extrachill-search. It returns published results with full
 *      permalinks, titles, excerpts, site names, dates, and relevance scores.
 *      This tool calls it via wp_get_ability(...)->execute([...]); it does not
 *      reimplement search.
 *   2. The chat citation UI — @extrachill/chat already renders a message's
 *      citations, and frontend-agent-chat already normalizes a tool/turn's
 *      `metadata.citations` onto the assistant message (see
 *      frontend_agent_chat_normalize_citation_metadata). So a tool that returns
 *      citations in the canonical agents-api shape gets rendered for free.
 *
 * HOW CITATIONS FLOW (the canonical channel, not a bespoke one):
 *   - The tool returns top-level `metadata.citations` — the key agents-api's
 *     WP_Agent_Citation_Metadata canonicalizes and the frontend chat REST layer
 *     reads (frontend_agent_chat_find_citation_values looks for `citations` /
 *     `sources`). Each citation carries source.url (permalink), source.title
 *     (post title), source.label (site name), and a snippet — exactly the shape
 *     getMessageCitations() needs to render a card.
 *   - The same results are ALSO returned in the tool's `result` data (titles +
 *     permalinks + excerpts) so the model can read them, reason over them, and
 *     cite the articles inline as a belt-and-suspenders fallback even on any
 *     surface that doesn't auto-render the citation metadata.
 *
 * ACCESS: PUBLIC. The catalog is public; a logged-out visitor asking "what does
 * Extra Chill say about X" benefits from grounded, sourced answers just as much
 * as a team member. There is no write path and nothing user-scoped here — it
 * only ever reads `publish`-status content the whole web can already see.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

/**
 * Read-only, public catalog-search chat tool that grounds Roadie's editorial /
 * music answers in Extra Chill's published content and renders the sources as
 * chat citations.
 */
class ECRoadie_SearchContent extends BaseTool {

	/**
	 * Tool slug as registered with Data Machine's tool system.
	 *
	 * @var string
	 */
	protected string $tool_slug = 'search_content';

	/**
	 * The network-search ability this tool wraps. Single source of search
	 * truth — registered by extrachill-search, called here, never rebuilt.
	 *
	 * @var string
	 */
	protected const SEARCH_ABILITY = 'extrachill/multisite-search';

	/**
	 * Default and maximum number of results returned. Kept tight so a single
	 * lookup grounds an answer without flooding the chat turn with citations.
	 *
	 * @var int
	 */
	protected const DEFAULT_LIMIT = 6;
	protected const MAX_LIMIT     = 12;

	/**
	 * Per-result snippet length cap (characters) for the citation snippet.
	 *
	 * @var int
	 */
	protected const MAX_SNIPPET = 280;

	/**
	 * Register the tool with Data Machine's tool system.
	 *
	 * Public access: the published catalog is public, so logged-out visitors
	 * get grounded, sourced answers too. No client-context binding is needed —
	 * the query comes from the model's reasoning, not the current page.
	 */
	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
			array( 'access_level' => 'public' )
		);
	}

	/**
	 * Tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Search what Extra Chill has actually PUBLISHED — its 2,800+ articles, artist coverage, song-meaning and music-history pieces, festival and show coverage, and the deep Grateful Dead / Jerry Garcia catalog — across the whole multisite network. Use this to GROUND any answer about Extra Chill\'s coverage, an artist, a song\'s meaning, music history, or a quote in the REAL articles, with sources, instead of answering music/editorial questions from memory. This is the catalog-read counterpart to inspect_page/inspect_code: those ground UI feedback in the rendered page and source; this grounds music/editorial answers in the published content. The results come back as CITATIONS (article title, full permalink, site, and an excerpt) that render as clickable source cards in the chat, AND in the tool data so you can read them and link to the articles inline. When a user asks "what does Extra Chill say about X," "did you cover Y," "find me your piece on Z," or any factual music/quote question this site might have written about, search_content FIRST, then answer from the matching articles and cite them. This tool is for CATALOG questions — what Extra Chill published. It is NOT for questions about yourself, your name, your lineage, or the platform\'s own story (e.g. "who are you," "who is Big Steve to you," "what is Extra Chill"): answer those directly from your own identity, and only reach for search_content if the user also wants to see what the site published on the topic. Search for ONE topic at a time for best relevance; pass post_types to narrow (e.g. just "post") and limit to control how many sources come back. This is strictly READ-ONLY and only ever returns published content.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'query' ),
				'properties' => array(
					'query'      => array(
						'type'        => 'string',
						'description' => 'The search query — an artist, song, topic, person, quote, or phrase to find in Extra Chill\'s published catalog. Search for one topic at a time; a focused query ("Jerry Garcia Ripple meaning", "Charleston Pour House") grounds far better than a broad one.',
					),
					'post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional list of post types to restrict the search to (e.g. ["post"] for articles only). Omit to search all searchable content across the network.',
					),
					'limit'      => array(
						'type'        => 'integer',
						'description' => 'Optional. How many results/citations to return (default 6, max 12). Lower it when you only need the single best source; raise it to survey what has been written on a topic.',
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

		$query = trim( (string) ( $parameters['query'] ?? '' ) );
		if ( '' === $query ) {
			return $this->buildErrorResponse(
				'query is required and must be a non-empty string. Pass the artist, song, topic, person, or phrase to look up in the Extra Chill catalog.',
				$this->tool_slug
			);
		}

		// Confirm the network-search ability is present. Degrade gracefully —
		// a clean error like the other tools — if extrachill-search is not
		// active on this install.
		$ability = $this->resolve_search_ability();
		if ( ! is_object( $ability ) ) {
			return $ability; // Error response.
		}

		$limit = $this->clamp_limit( $parameters['limit'] ?? 0 );

		$input = array(
			'search_term'  => $query,
			'limit'        => $limit,
			'post_status'  => array( 'publish' ),
			'return_count' => false,
		);

		$post_types = $this->sanitize_post_types( $parameters['post_types'] ?? null );
		if ( array() !== $post_types ) {
			$input['post_type'] = $post_types;
		}

		/**
		 * Filter the input passed to the multisite-search ability from the
		 * search_content tool. Lets an operator constrain or expand the search
		 * surface without touching tool code.
		 *
		 * @since 0.16.0
		 *
		 * @param array  $input      Ability input.
		 * @param array  $parameters Original tool parameters.
		 * @param string $query      The sanitized query string.
		 */
		$input = (array) apply_filters( 'extrachill_roadie_search_content_input', $input, $parameters, $query );

		$results = $ability->execute( $input );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $results ) ) {
			return $this->buildErrorResponse(
				'Catalog search failed: ' . $results->get_error_message(),
				$this->tool_slug
			);
		}

		// The ability returns a results array (return_count=false). Some
		// adapters may wrap it as { results, total }; handle both shapes.
		$rows = $this->extract_rows( $results );

		$citations = array();
		$items     = array();
		$index     = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$permalink = trim( (string) ( $row['permalink'] ?? '' ) );
			$title     = trim( (string) ( $row['post_title'] ?? '' ) );

			// A citation needs at minimum a URL or a title to be renderable.
			if ( '' === $permalink && '' === $title ) {
				continue;
			}

			++$index;

			$site_name = trim( (string) ( $row['site_name'] ?? '' ) );
			$snippet   = $this->build_snippet( $row );

			// Canonical agents-api citation shape: source.{url,title,label}
			// plus a top-level snippet. This is exactly what
			// frontend_agent_chat_normalize_citation() maps and
			// getMessageCitations() renders.
			$citation = array(
				'index'  => $index,
				'source' => array_filter(
					array(
						'url'   => $permalink,
						'title' => $title,
						'label' => $site_name,
					),
					static function ( $value ): bool {
						return '' !== (string) $value;
					}
				),
			);
			if ( '' !== $permalink ) {
				$citation['url'] = $permalink;
			}
			if ( '' !== $snippet ) {
				$citation['snippet'] = $snippet;
			}

			$citations[] = $citation;

			// The same record, in the tool data, so the model can read and
			// link to the articles inline as a fallback to the citation card.
			$items[] = array(
				'index'     => $index,
				'title'     => $title,
				'url'       => $permalink,
				'site'      => $site_name,
				'date'      => trim( (string) ( $row['post_date'] ?? '' ) ),
				'post_type' => trim( (string) ( $row['post_type'] ?? '' ) ),
				'excerpt'   => $snippet,
			);
		}

		$data = array(
			'query'        => $query,
			'count'        => count( $items ),
			'source_count' => count( $citations ),
			'results'      => $items,
			'next_step'    => array() === $items
				? 'This query matched no published Extra Chill articles, so the CATALOG has no coverage to cite for it — scope any "what Extra Chill published / covered / said" claim to that fact and offer a different phrasing. This result bounds the published catalog ONLY; it says nothing about who you are or what you know from your own identity. If the question was really about yourself, your lineage, or the platform (e.g. "who is Big Steve to you"), answer it straight from your own identity — an empty catalog search is not a reason to disclaim knowledge you already hold.'
				: 'Answer from these published articles and cite them — the sources render as citation cards, and you can also link the titles inline. Ground every music/editorial claim (a quote, a fact, what Extra Chill said) in one of these results, keeping each claim within what they actually support.',
		);

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => $data,
			// The canonical citation channel: top-level metadata.citations is
			// what agents-api canonicalizes and the chat REST layer surfaces
			// onto the assistant message for rendering.
			'metadata'  => array(
				'citations'    => $citations,
				'source_count' => count( $citations ),
			),
		);
	}

	/**
	 * Resolve the multisite-search ability object, or a clean error response
	 * when the Abilities API or the ability itself is unavailable.
	 *
	 * @return object|array Ability object, or error response array.
	 */
	protected function resolve_search_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse(
				'The WordPress Abilities API is not available on this site, so the catalog cannot be searched.',
				$this->tool_slug
			);
		}

		$ability = wp_get_ability( self::SEARCH_ABILITY );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			return $this->buildErrorResponse(
				sprintf(
					'The "%s" search ability is not registered. Ensure the ExtraChill Search plugin is active to search the catalog.',
					self::SEARCH_ABILITY
				),
				$this->tool_slug
			);
		}

		return $ability;
	}

	/**
	 * Normalize the ability output into a flat list of result rows.
	 *
	 * extrachill/multisite-search returns a results array when
	 * return_count=false; a { results, total } object when true. We always
	 * request false, but handle the wrapped shape defensively.
	 *
	 * @param mixed $results Raw ability output.
	 * @return array<int,mixed>
	 */
	protected function extract_rows( $results ): array {
		if ( ! is_array( $results ) ) {
			return array();
		}

		if ( isset( $results['results'] ) && is_array( $results['results'] ) ) {
			return array_values( $results['results'] );
		}

		return array_values( $results );
	}

	/**
	 * Build a trimmed citation snippet from a result row, preferring the
	 * excerpt and falling back to a slice of the content.
	 *
	 * @param array<string,mixed> $row Result row.
	 * @return string
	 */
	protected function build_snippet( array $row ): string {
		$excerpt = trim( (string) ( $row['post_excerpt'] ?? '' ) );
		if ( '' === $excerpt ) {
			$excerpt = trim( (string) ( $row['post_content'] ?? '' ) );
		}

		if ( '' === $excerpt ) {
			return '';
		}

		// Strip tags/shortcodes and collapse whitespace so the snippet is
		// clean prose, not raw block markup.
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$excerpt = wp_strip_all_tags( $excerpt );
		} else {
			$excerpt = strip_tags( $excerpt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$excerpt = strip_shortcodes( $excerpt );
		}

		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) ?? $excerpt );

		if ( strlen( $excerpt ) > self::MAX_SNIPPET ) {
			$excerpt = rtrim( substr( $excerpt, 0, self::MAX_SNIPPET ) ) . '…';
		}

		return $excerpt;
	}

	/**
	 * Sanitize the optional post_types parameter into a clean string list.
	 *
	 * @param mixed $post_types Raw parameter value.
	 * @return string[]
	 */
	protected function sanitize_post_types( $post_types ): array {
		if ( is_string( $post_types ) && '' !== trim( $post_types ) ) {
			$post_types = array( $post_types );
		}

		if ( ! is_array( $post_types ) ) {
			return array();
		}

		$clean = array();
		foreach ( $post_types as $type ) {
			if ( ! is_scalar( $type ) ) {
				continue;
			}
			$type = trim( (string) $type );
			if ( '' !== $type ) {
				$clean[ $type ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * Clamp the result limit into [1, MAX_LIMIT], applying the default when the
	 * supplied value is absent or non-positive.
	 *
	 * @param mixed $value Supplied limit.
	 * @return int
	 */
	protected function clamp_limit( $value ): int {
		$limit = (int) $value;
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}
		return max( 1, min( $limit, self::MAX_LIMIT ) );
	}
}
