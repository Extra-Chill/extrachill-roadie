<?php
/**
 * Manage Link Page Tool
 *
 * Chat tool for managing artist link pages — links, social links, styles, and settings.
 * Uses ec_cross_site_rest_request() to route through the REST API on the artist site.
 *
 * Convenience actions (add_link, remove_link) handle fetch-modify-save internally
 * so the AI doesn't need to orchestrate multi-step operations.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_ManageLinkPage extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'manage_link_page',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Manage an artist\'s link page on Extra Chill. Can get the full link page, add/remove individual links, replace all links, update social links, change visual styles (colors, fonts, button shapes), and update settings (redirects, tracking pixels, subscribe mode). The artist_id is auto-resolved if the user has only one artist.',
			'parameters'  => array(
				'action'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "get" (view link page), "add_link" (add a single link), "remove_link" (remove a link by URL or ID), "save_links" (replace all link sections), "save_socials" (replace social links), "save_styles" (update CSS variables), "save_settings" (update settings like redirects, tracking, subscribe mode)',
				),
				'artist_id'  => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Artist profile ID. Auto-resolved if user has one artist.',
				),
				'url'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Link URL. Used in "add_link" (required) and "remove_link" (to identify by URL).',
				),
				'text'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Link display text. Used in "add_link".',
				),
				'section'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Section title to add the link to. Used in "add_link". Defaults to the first section.',
				),
				'link_id'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Link ID to remove. Used in "remove_link" as alternative to URL.',
				),
				'links'      => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Full array of link sections for "save_links". Each section: {section_title, links: [{link_text, link_url, expires_at?}]}.',
				),
				'socials'    => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of social links for "save_socials". Each: {type, url}. Types: apple_music, bandcamp, bluesky, facebook, github, instagram, patreon, pinterest, soundcloud, spotify, substack, tiktok, twitch, twitter_x, venmo, website, youtube, custom.',
				),
				'css_vars'   => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'CSS variables for "save_styles". Keys must start with "--link-page-". Examples: --link-page-button-bg-color, --link-page-text-color, --link-page-background-color, --link-page-button-radius, --link-page-title-font-family, --link-page-profile-img-shape (circle/square/rectangle).',
				),
				'settings'   => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Settings for "save_settings". Keys: link_expiration_enabled (bool), redirect_enabled (bool), redirect_target_url (string), youtube_embed_enabled (bool), meta_pixel_id, google_tag_id, google_tag_manager_id, subscribe_display_mode (icon_modal/inline_form/disabled), subscribe_description, social_icons_position (above/below), profile_image_shape (circle/square/rectangle).',
				),
				'background_image_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Attachment ID for background image. Pass 0 to remove. Used in "save_settings".',
				),
				'profile_image_id'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Attachment ID for profile image. Pass 0 to remove. Used in "save_settings".',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		switch ( $action ) {
			case 'get':
				return $this->handle_get( $parameters );
			case 'add_link':
				return $this->handle_add_link( $parameters );
			case 'remove_link':
				return $this->handle_remove_link( $parameters );
			case 'save_links':
				return $this->handle_save_links( $parameters );
			case 'save_socials':
				return $this->handle_save_socials( $parameters );
			case 'save_styles':
				return $this->handle_save_styles( $parameters );
			case 'save_settings':
				return $this->handle_save_settings( $parameters );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: get, add_link, remove_link, save_links, save_socials, save_styles, save_settings.',
					'manage_link_page'
				);
		}
	}

	/**
	 * Get the full link page data.
	 */
	private function handle_get( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		return $this->rest_request( 'GET', '/artists/' . $artist_id . '/links' );
	}

	/**
	 * Add a single link to the link page.
	 *
	 * Fetches current links, appends the new one, and saves. This convenience
	 * action avoids making the AI do the fetch-modify-save dance.
	 */
	private function handle_add_link( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$url  = $parameters['url'] ?? '';
		$text = $parameters['text'] ?? '';

		if ( empty( $url ) ) {
			return $this->buildErrorResponse( 'URL is required to add a link.', 'manage_link_page' );
		}
		if ( empty( $text ) ) {
			return $this->buildErrorResponse( 'Link text is required to add a link.', 'manage_link_page' );
		}

		$section_title = $parameters['section'] ?? '';

		// Fetch current link page data to get existing links.
		$current = $this->rest_request( 'GET', '/artists/' . $artist_id . '/links' );

		if ( ! ( $current['success'] ?? false ) ) {
			return $current;
		}

		$sections = $current['data']['links'] ?? array();

		// Find or create the target section.
		$target_index = null;
		if ( ! empty( $section_title ) ) {
			foreach ( $sections as $i => $section ) {
				if ( strcasecmp( $section['section_title'] ?? '', $section_title ) === 0 ) {
					$target_index = $i;
					break;
				}
			}
			// Create new section if not found.
			if ( null === $target_index ) {
				$sections[]   = array(
					'section_title' => $section_title,
					'links'         => array(),
				);
				$target_index = count( $sections ) - 1;
			}
		} else {
			// Default to first section, or create one.
			if ( empty( $sections ) ) {
				$sections[] = array(
					'section_title' => '',
					'links'         => array(),
				);
			}
			$target_index = 0;
		}

		// Append the new link.
		$sections[ $target_index ]['links'][] = array(
			'link_text' => $text,
			'link_url'  => $url,
		);

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => array( 'links' => $sections ),
		) );
	}

	/**
	 * Remove a link from the link page by URL or link ID.
	 */
	private function handle_remove_link( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$target_url = $parameters['url'] ?? '';
		$target_id  = $parameters['link_id'] ?? '';

		if ( empty( $target_url ) && empty( $target_id ) ) {
			return $this->buildErrorResponse(
				'Either url or link_id is required to remove a link.',
				'manage_link_page'
			);
		}

		// Fetch current links.
		$current = $this->rest_request( 'GET', '/artists/' . $artist_id . '/links' );

		if ( ! ( $current['success'] ?? false ) ) {
			return $current;
		}

		$sections = $current['data']['links'] ?? array();
		$removed  = false;

		foreach ( $sections as $si => $section ) {
			$links = $section['links'] ?? array();
			foreach ( $links as $li => $link ) {
				$match_url = ! empty( $target_url ) && ( $link['link_url'] ?? '' ) === $target_url;
				$match_id  = ! empty( $target_id ) && ( $link['id'] ?? '' ) === $target_id;

				if ( $match_url || $match_id ) {
					array_splice( $sections[ $si ]['links'], $li, 1 );
					$removed = true;
					break 2;
				}
			}
		}

		if ( ! $removed ) {
			return $this->buildErrorResponse(
				'Link not found on the link page.',
				'manage_link_page'
			);
		}

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => array( 'links' => $sections ),
		) );
	}

	/**
	 * Replace all link sections.
	 */
	private function handle_save_links( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$links = $parameters['links'] ?? null;
		if ( ! is_array( $links ) ) {
			return $this->buildErrorResponse( 'links array is required.', 'manage_link_page' );
		}

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => array( 'links' => $links ),
		) );
	}

	/**
	 * Replace social links.
	 */
	private function handle_save_socials( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$socials = $parameters['socials'] ?? null;
		if ( ! is_array( $socials ) ) {
			return $this->buildErrorResponse( 'socials array is required.', 'manage_link_page' );
		}

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => array( 'socials' => $socials ),
		) );
	}

	/**
	 * Update CSS variables (merge with existing).
	 */
	private function handle_save_styles( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$css_vars = $parameters['css_vars'] ?? null;
		if ( empty( $css_vars ) || ! is_array( $css_vars ) ) {
			return $this->buildErrorResponse( 'css_vars object is required.', 'manage_link_page' );
		}

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => array( 'css_vars' => $css_vars ),
		) );
	}

	/**
	 * Update link page settings.
	 */
	private function handle_save_settings( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$body = array();

		if ( isset( $parameters['settings'] ) && is_array( $parameters['settings'] ) ) {
			$body['settings'] = $parameters['settings'];
		}
		if ( array_key_exists( 'background_image_id', $parameters ) ) {
			$body['background_image_id'] = (int) $parameters['background_image_id'];
		}
		if ( array_key_exists( 'profile_image_id', $parameters ) ) {
			$body['profile_image_id'] = (int) $parameters['profile_image_id'];
		}

		if ( empty( $body ) ) {
			return $this->buildErrorResponse(
				'At least one of settings, background_image_id, or profile_image_id is required.',
				'manage_link_page'
			);
		}

		return $this->rest_request( 'PUT', '/artists/' . $artist_id . '/links', array(
			'body' => $body,
		) );
	}

	/**
	 * Resolve the artist ID from parameters or auto-detect from user meta.
	 *
	 * @param array $parameters Tool parameters.
	 * @return int|array<string,mixed> Artist ID on success, or error/disambiguation response array.
	 */
	private function resolve_artist_id( array $parameters ) {
		if ( ! empty( $parameters['artist_id'] ) ) {
			return (int) $parameters['artist_id'];
		}

		$user_id    = get_current_user_id();
		$artist_ids = $this->get_user_artist_ids( $user_id );

		if ( empty( $artist_ids ) ) {
			return $this->buildDiagnosticErrorResponse(
				'No artist profile found for your account.',
				'not_found',
				'manage_link_page',
				array( 'user_id' => $user_id ),
				array(
					'action'    => 'Create an artist profile first',
					'message'   => 'Use the manage_artist_profile tool with action "create" to set up your artist profile.',
					'tool_hint' => 'manage_artist_profile',
				)
			);
		}

		if ( count( $artist_ids ) === 1 ) {
			return (int) $artist_ids[0];
		}

		// Multiple artists — need disambiguation.
		// Read post data from the artist blog (switch_to_blog is safe for reads).
		$artists    = array();
		$artist_blog = $this->get_artist_blog_id();

		if ( $artist_blog ) {
			switch_to_blog( $artist_blog );
		}

		foreach ( $artist_ids as $aid ) {
			$post = get_post( (int) $aid );
			if ( $post && 'artist_profile' === $post->post_type ) {
				$artists[] = array(
					'id'   => (int) $post->ID,
					'name' => $post->post_title,
				);
			}
		}

		if ( $artist_blog ) {
			restore_current_blog();
		}

		return array(
			'success'    => false,
			'error'      => 'You manage multiple artist profiles. Please specify which one.',
			'error_type' => 'validation',
			'tool_name'  => 'manage_link_page',
			'data'       => array(
				'artists'     => $artists,
				'instruction' => 'Ask the user which artist they want to manage, then re-call with artist_id.',
			),
		);
	}

	/**
	 * Make a REST API request via the cross-site helper.
	 *
	 * Always routes to the artist site via ec_cross_site_rest_request().
	 *
	 * @param string $method HTTP method.
	 * @param string $path   REST path (e.g. '/artists/123/links').
	 * @param array  $args   Optional request args (query, body, headers).
	 * @return array Tool response array.
	 */
	private function rest_request( string $method, string $path, array $args = array() ): array {
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return $this->buildErrorResponse(
				'Cross-site REST helper not available. Ensure extrachill-multisite is active.',
				'manage_link_page'
			);
		}

		$result = ec_cross_site_rest_request( 'artist', $method, $path, $args );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_link_page'
			);
		}

		// Some abilities return a raw data array without 'success' key.
		$is_error = is_array( $result ) && isset( $result['success'] ) && false === $result['success'];

		if ( $is_error ) {
			$error_msg = $result['message'] ?? $result['error'] ?? 'Operation failed.';
			return $this->buildErrorResponse( $error_msg, 'manage_link_page' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_link_page',
		);
	}

	/**
	 * Get the user's artist profile IDs from user meta.
	 */
	private function get_user_artist_ids( int $user_id ): array {
		$ids = get_user_meta( $user_id, '_artist_profile_ids', true );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Get the blog ID for the artist site.
	 */
	private function get_artist_blog_id(): ?int {
		if ( function_exists( 'ec_get_blog_id' ) ) {
			return ec_get_blog_id( 'artist' );
		}
		return null;
	}
}
