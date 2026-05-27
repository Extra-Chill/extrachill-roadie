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
	 * Routes through ec_cross_site_rest_request() which dispatches in-process
	 * via switch_to_blog() + rest_do_request() (or HTTP loopback when the
	 * `ec_cross_site_use_http_loopback` filter is true for the route). The
	 * helper handles wp_set_current_user() in a try/finally when a `user_id`
	 * is supplied in $args — so authentication switches cleanly without the
	 * caller having to manage user-context juggling.
	 *
	 * @since 0.5.0
	 * @since 0.8.0 Accepts `user_id` in $args for calling-user propagation.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE).
	 * @param string $path   REST path without namespace (e.g. '/community/topics').
	 * @param array  $args   Optional. Request arguments:
	 *                       - 'body'    => array  Request body for POST/PUT.
	 *                       - 'query'   => array  Query parameters for GET.
	 *                       - 'headers' => array  Additional headers.
	 *                       - 'user_id' => int    Authenticate as this user for the request.
	 *                                             Defaults to current user when omitted.
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

	/**
	 * Read the calling user ID from the merged tool parameters.
	 *
	 * Data Machine's ToolParameters::buildParameters() merges the loop payload
	 * INTO the tool's $parameters array before calling handle_tool_call(). The
	 * loop payload sets `calling_user_id` from the chat orchestrator's loop
	 * context — so every tool sees it as $parameters['calling_user_id'].
	 *
	 * Returns 0 when absent, non-numeric, or non-positive — matching the
	 * datamachine_get_calling_user_id() contract from data-machine core.
	 *
	 * @since 0.8.0
	 *
	 * @param array $parameters Tool parameters (post payload-merge).
	 * @return int Non-negative user ID. 0 means "no human caller".
	 */
	protected function get_calling_user_id( array $parameters ): int {
		// Prefer the canonical core helper when available so we stay in sync
		// with any future contract changes.
		if ( function_exists( 'datamachine_get_calling_user_id' ) ) {
			return datamachine_get_calling_user_id( $parameters );
		}

		$raw = $parameters['calling_user_id'] ?? 0;
		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		$user_id = (int) $raw;
		return $user_id > 0 ? $user_id : 0;
	}

	/**
	 * Resolve the user ID this invocation should act on behalf of.
	 *
	 * Priority order:
	 *   1. Explicit `user_id` input from the AI (override).
	 *   2. `calling_user_id` from the loop payload (human caller in chat).
	 *   3. `get_current_user_id()` as last resort (REST/CLI without explicit
	 *      calling-user context).
	 *
	 * Returns 0 only when no user is resolvable — caller must handle that
	 * case explicitly (typically a "you must be logged in" error response).
	 *
	 * @since 0.8.0
	 *
	 * @param array $parameters Tool parameters.
	 * @return int Resolved acting user ID, or 0 when none is available.
	 */
	protected function resolve_acting_user_id( array $parameters ): int {
		if ( isset( $parameters['user_id'] ) && is_numeric( $parameters['user_id'] ) ) {
			$explicit = (int) $parameters['user_id'];
			if ( $explicit > 0 ) {
				return $explicit;
			}
		}

		$calling = $this->get_calling_user_id( $parameters );
		if ( $calling > 0 ) {
			return $calling;
		}

		$current = (int) get_current_user_id();
		return $current > 0 ? $current : 0;
	}

	/**
	 * Assert that the current actor is allowed to act on behalf of the given user.
	 *
	 * Rules:
	 *   - Acting on yourself is always allowed.
	 *   - Acting on another user requires `manage_options` on the caller.
	 *   - Acting with no resolvable user is denied.
	 *
	 * Returns null when allowed, or a standardized permission-denied response
	 * when forbidden. Callers should `return` the response immediately when
	 * non-null.
	 *
	 * @since 0.8.0
	 *
	 * @param int   $acting_user_id The user the tool would act as.
	 * @param array $parameters     Tool parameters (used to detect explicit user_id override).
	 * @return array|null Error response when denied, null when allowed.
	 */
	protected function assert_acting_user_allowed( int $acting_user_id, array $parameters ): ?array {
		if ( $acting_user_id <= 0 ) {
			return $this->buildErrorResponse(
				'No user context available for this action. Sign in or provide an explicit user_id.',
				$this->tool_slug
			);
		}

		$calling = $this->get_calling_user_id( $parameters );

		// Effective caller: the human on whose behalf the agent is acting in
		// this invocation. Fall back to the current WP user when there is no
		// chat caller (REST/CLI contexts).
		$caller = $calling > 0 ? $calling : (int) get_current_user_id();

		// Acting as yourself, or no caller at all (system task), is allowed.
		// System tasks (calling_user_id = 0, current_user = 0) reach internal
		// abilities directly and have their own permission gates.
		if ( $caller <= 0 || $caller === $acting_user_id ) {
			return null;
		}

		// Different user — admin-only.
		if ( user_can( $caller, 'manage_options' ) ) {
			return null;
		}

		return $this->buildErrorResponse(
			sprintf(
				'Permission denied: only administrators can act on behalf of another user (caller #%d → target #%d).',
				$caller,
				$acting_user_id
			),
			$this->tool_slug
		);
	}
}
