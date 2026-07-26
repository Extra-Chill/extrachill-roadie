<?php
/**
 * Read-only Roadie adapter for the private Studio Intelligence corpus.
 *
 * @package ExtraChillRoadie\Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_StudioIntelligence extends ECRoadie_PlatformTool {

	protected string $site_key  = 'studio';
	protected string $tool_slug = 'extrachill_intelligence';

	private const SEARCH_ROUTE = '/intelligence/v1/search';
	private const READ_ROUTE   = '/intelligence/v1/read';
	private const DEFAULT_LIMIT = 8;
	private const MAX_LIMIT     = 20;

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
			array( 'access_level' => 'authenticated' )
		);

		// Intelligence is site-activated on Studio, so off-site requests need a
		// target bootstrap rather than switch_to_blog() alone.
		add_filter( 'ec_cross_site_use_http_loopback', array( self::class, 'use_http_loopback' ), 10, 5 );
	}

	/**
	 * Force the existing authenticated loopback transport for Studio-local routes.
	 */
	public static function use_http_loopback( bool $use_http, string $site_key, string $method, string $path, array $args ): bool {
		unset( $method, $args );

		if ( $use_http || 'studio' !== $site_key || ! in_array( $path, array( self::SEARCH_ROUTE, self::READ_ROUTE ), true ) ) {
			return $use_http;
		}

		$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'studio' ) : 0;
		return $studio_blog_id <= 0 || ! function_exists( 'get_current_blog_id' ) || $studio_blog_id !== (int) get_current_blog_id();
	}

	/**
	 * Return the model-facing read-only tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'              => self::class,
			'method'             => 'handle_tool_call',
			'parameter_bindings' => array(
				'calling_user_id' => array(
					'source'        => 'caller_context',
					'path'          => 'calling_user_id',
					'authoritative' => true,
				),
			),
			'description'        => 'Search and read Extra Chill\'s private Studio Intelligence corpus. This is the authoritative internal knowledge source for platform, editorial, operational, and institutional questions. Use action="search" first with a focused query, then action="read" with a returned read_ref to hydrate the selected source before answering. Results retain source URLs, refs, provenance, and citation metadata. This tool is strictly READ-ONLY and cannot create, update, import, configure, provision, or write memory.',
			'parameters'         => array(
				'type'       => 'object',
				'required'   => array( 'action', 'calling_user_id' ),
				'properties' => array(
					'calling_user_id' => array( 'type' => 'integer' ),
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'search', 'read' ),
						'description' => 'Use search to discover sources, or read to hydrate one returned ref.',
					),
					'query'           => array(
						'type'        => 'string',
						'description' => 'Focused corpus query. Required for action=search.',
					),
					'ref'             => array(
						'type'        => 'string',
						'description' => 'Exact read_ref returned by search. Required for action=read.',
					),
					'limit'           => array(
						'type'        => 'integer',
						'description' => 'Maximum search results (default 8, max 20).',
					),
				),
			),
		);
	}

	/**
	 * Execute search or single-ref hydration as the authoritative caller.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param array<string,mixed> $tool_def   Resolved definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		$caller_id = extrachill_roadie_resolve_acting_caller( $parameters );
		if ( $caller_id <= 0 || ! extrachill_roadie_acting_caller_can( $parameters, 'access_roadie' ) ) {
			return $this->buildErrorResponse(
				'Permission denied: Studio Intelligence is available only to authenticated Extra Chill team members.',
				$this->tool_slug
			);
		}

		$action = trim( (string) ( $parameters['action'] ?? '' ) );
		if ( 'search' === $action ) {
			return $this->search( $parameters, $caller_id );
		}
		if ( 'read' === $action ) {
			return $this->read( $parameters, $caller_id );
		}

		return $this->buildErrorResponse( 'action is required and must be one of: search, read.', $this->tool_slug );
	}

	/**
	 * Search the canonical Studio corpus.
	 */
	private function search( array $parameters, int $caller_id ): array {
		$query = trim( (string) ( $parameters['query'] ?? '' ) );
		if ( '' === $query ) {
			return $this->buildErrorResponse( 'query is required for action=search.', $this->tool_slug );
		}

		$limit = isset( $parameters['limit'] ) ? (int) $parameters['limit'] : self::DEFAULT_LIMIT;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$result = $this->request_intelligence(
			'POST',
			self::SEARCH_ROUTE,
			array( 'query' => $query, 'limit' => $limit ),
			$caller_id
		);
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$payload   = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$citations = $this->search_citations( (array) ( $payload['results'] ?? array() ) );

		return $this->success_response( $payload, $citations );
	}

	/**
	 * Hydrate one exact ref returned by search.
	 */
	private function read( array $parameters, int $caller_id ): array {
		$raw_ref = $parameters['ref'] ?? '';
		$ref     = is_string( $raw_ref ) ? trim( $raw_ref ) : '';
		if ( '' === $ref || strlen( $ref ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $ref ) ) {
			return $this->buildErrorResponse( 'ref is required for action=read and must be a valid ref returned by search.', $this->tool_slug );
		}

		$result = $this->request_intelligence( 'POST', self::READ_ROUTE, array( 'ref' => $ref ), $caller_id );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$payload   = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$citations = ! empty( $payload['success'] ) ? $this->read_citations( $payload ) : array();

		return $this->success_response( $payload, $citations );
	}

	/**
	 * Dispatch through Roadie's existing cross-site REST helper.
	 */
	private function request_intelligence( string $method, string $route, array $body, int $caller_id ): array {
		$result = $this->rest_request(
			$method,
			$route,
			array(
				'body'    => $body,
				'user_id' => $caller_id,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return $result;
		}

		$result['error']      = 'Studio Intelligence is unavailable: ' . (string) ( $result['error'] ?? 'the target route did not return a usable response.' );
		$result['diagnostic'] = array(
			'target' => 'studio',
			'route'  => $route,
		);
		return $result;
	}

	/**
	 * Preserve the Intelligence payload while publishing canonical citations.
	 */
	private function success_response( array $payload, array $citations ): array {
		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => $payload,
			'metadata'  => array(
				'citations'    => $citations,
				'source_count' => count( $citations ),
			),
		);
	}

	/**
	 * Build citation cards without dropping refs or access provenance.
	 */
	private function search_citations( array $rows ): array {
		$citations = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$citation = $this->citation(
				(string) ( $row['title'] ?? '' ),
				(string) ( $row['permalink'] ?? '' ),
				(string) ( $row['source_label'] ?? $row['source_type'] ?? '' ),
				(string) ( $row['snippet'] ?? '' ),
				(string) ( $row['read_ref'] ?? '' ),
				is_array( $row['access'] ?? null ) ? $row['access'] : array()
			);
			if ( array() !== $citation ) {
				$citations[] = $citation;
			}
		}

		return $this->index_citations( $citations );
	}

	/**
	 * Build a citation for a hydrated read envelope.
	 */
	private function read_citations( array $payload ): array {
		$resolved = is_array( $payload['resolved'] ?? null ) ? $payload['resolved'] : array();
		$citation = $this->citation(
			(string) ( $payload['title'] ?? '' ),
			(string) ( $resolved['url'] ?? '' ),
			(string) ( $resolved['source_label'] ?? $resolved['source'] ?? '' ),
			$this->snippet( (string) ( $payload['body'] ?? '' ) ),
			(string) ( $payload['ref'] ?? '' ),
			is_array( $payload['access'] ?? null ) ? $payload['access'] : array()
		);

		return array() === $citation ? array() : $this->index_citations( array( $citation ) );
	}

	/**
	 * Build one canonical agents-api citation plus Intelligence provenance.
	 */
	private function citation( string $title, string $url, string $label, string $snippet, string $ref, array $access ): array {
		$title = trim( $title );
		$url   = trim( $url );
		if ( '' === $title && '' === $url ) {
			return array();
		}

		$citation = array(
			'source' => array_filter(
				array(
					'url'   => $url,
					'title' => $title,
					'label' => trim( $label ),
				)
			),
			'ref'    => $ref,
			'access' => $access,
		);
		if ( '' !== $url ) {
			$citation['url'] = $url;
		}
		if ( '' !== trim( $snippet ) ) {
			$citation['snippet'] = $this->snippet( $snippet );
		}

		return $citation;
	}

	/**
	 * Add stable one-based citation indexes.
	 */
	private function index_citations( array $citations ): array {
		foreach ( $citations as $index => &$citation ) {
			$citation['index'] = $index + 1;
		}
		unset( $citation );
		return $citations;
	}

	/**
	 * Bound hydrated body text used in citation cards.
	 */
	private function snippet( string $text ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return strlen( $text ) > 280 ? substr( $text, 0, 279 ) . '...' : $text;
	}
}
