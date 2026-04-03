<?php
/**
 * EC Platform Tool Base
 *
 * Base class for Extra Chill platform chat tools that need cross-site REST
 * execution. Provides a shared rest_request() method so individual tools
 * don't duplicate the ec_cross_site_rest_request() wrapping logic.
 *
 * Each subclass declares its $site_key and $tool_slug, and calls
 * $this->rest_request() for all cross-site operations.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

abstract class ECRoadie_PlatformTool extends BaseTool {

	/**
	 * Logical site key for cross-site routing (e.g. 'community', 'artist', 'events').
	 *
	 * @var string
	 */
	protected string $site_key = '';

	/**
	 * Tool slug used in error responses (e.g. 'manage_community').
	 *
	 * @var string
	 */
	protected string $tool_slug = '';

	/**
	 * Make a REST API request to this tool's target site.
	 *
	 * Routes through ec_cross_site_rest_request() which makes an internal
	 * HTTP call to the correct subsite via localhost. The route affinity
	 * middleware ensures the request reaches the right WordPress instance
	 * with the correct plugins and abilities loaded.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE).
	 * @param string $path   REST path without namespace (e.g. '/community/topics').
	 * @param array  $args   Optional. Request arguments:
	 *                       - 'body'    => array  Request body for POST/PUT.
	 *                       - 'query'   => array  Query parameters for GET.
	 *                       - 'headers' => array  Additional headers.
	 * @return array Tool response array with success/data or error.
	 */
	protected function rest_request( string $method, string $path, array $args = array() ): array {
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return $this->buildErrorResponse(
				'Cross-site REST helper not available. Ensure extrachill-multisite is active.',
				$this->tool_slug
			);
		}

		$result = ec_cross_site_rest_request( $this->site_key, $method, $path, $args );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				$this->tool_slug
			);
		}

		// Check for error responses from the API.
		if ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ) {
			$error_msg = $result['message'] ?? $result['error'] ?? 'Operation failed.';
			return $this->buildErrorResponse( $error_msg, $this->tool_slug );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => $this->tool_slug,
		);
	}

	/**
	 * Get the blog ID for a site by key.
	 *
	 * Convenience wrapper for ec_get_blog_id() used by tools that still
	 * need switch_to_blog() for safe data reads (e.g. get_post()).
	 *
	 * @param string $key Site key (e.g. 'artist', 'community').
	 * @return int|null Blog ID or null if unavailable.
	 */
	protected function get_blog_id( string $key ): ?int {
		if ( function_exists( 'ec_get_blog_id' ) ) {
			return ec_get_blog_id( $key );
		}
		return null;
	}
}
