<?php
/**
 * Manage Link Page Tool
 *
 * Chat tool for managing artist link pages — links, social links, styles, and settings.
 * Uses the cross-site REST helper from ECRoadie_PlatformTool to route
 * requests to the artist site via internal HTTP.
 *
 * Convenience actions (add_link, remove_link) handle fetch-modify-save internally
 * so the AI doesn't need to orchestrate multi-step operations.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 * @since 0.8.0 Calling-user identity propagation: all actions act on behalf
 *              of the calling user (or an explicit user_id when admins
 *              override).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_ManageLinkPage extends ECRoadie_PlatformTool {

	protected string $site_key  = 'artist';
	protected string $tool_slug = 'manage_link_page';

	public function __construct() {
		$this->registerTool(
			'manage_link_page',
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
			array( 'access_level' => 'authenticated' )
		);
	}

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
			'description'        => 'Manage an artist\'s link page on Extra Chill. Defaults to the calling user. Admins can target another user by passing user_id. Can get the full link page, add/remove individual links, replace all links, update social links, change visual styles (colors, fonts, button shapes), and update settings (redirects, tracking pixels, subscribe mode). The artist_id is auto-resolved if the user has only one artist.',
			'parameters'         => array(
				'type'       => 'object',
				'properties' => array(
					'action'              => array(
						'type'        => 'string',
						'description' => 'Action: "get" (view link page), "add_link" (add a single link), "remove_link" (remove a link by URL or ID), "save_links" (replace all link sections), "save_socials" (replace social links), "save_styles" (update CSS variables), "save_settings" (update settings like redirects, tracking, subscribe mode)',
					),
					'user_id'             => array(
						'type'        => 'integer',
						'description' => 'Target user ID for auto-resolving artist_id. Optional. Defaults to the calling user. Admin-only override.',
					),
					'calling_user_id'     => array( 'type' => 'integer' ),
					'artist_id'           => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID. Auto-resolved if user has one artist.',
					),
					'url'                 => array(
						'type'        => 'string',
						'description' => 'Link URL. Used in "add_link" (required) and "remove_link" (to identify by URL).',
					),
					'text'                => array(
						'type'        => 'string',
						'description' => 'Link display text. Used in "add_link".',
					),
					'section'             => array(
						'type'        => 'string',
						'description' => 'Section title to add the link to. Used in "add_link". Defaults to the first section.',
					),
					'link_id'             => array(
						'type'        => 'string',
						'description' => 'Link ID to remove. Used in "remove_link" as alternative to URL.',
					),
					'links'               => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'Full array of link sections for "save_links". Each section: {section_title, links: [{link_text, link_url, expires_at?}]}.',
					),
					'socials'             => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'Array of social links for "save_socials". Each: {type, url}. Types: apple_music, bandcamp, bluesky, facebook, github, instagram, patreon, pinterest, soundcloud, spotify, substack, tiktok, twitch, twitter_x, venmo, website, youtube, custom.',
					),
					'css_vars'            => array(
						'type'        => 'object',
						'description' => 'CSS variables for "save_styles". Keys must start with "--link-page-". Examples: --link-page-button-bg-color, --link-page-text-color, --link-page-background-color, --link-page-button-radius, --link-page-title-font-family, --link-page-profile-img-shape (circle/square/rectangle).',
					),
					'settings'            => array(
						'type'        => 'object',
						'description' => 'Settings for "save_settings". Keys: link_expiration_enabled (bool), redirect_enabled (bool), redirect_target_url (string), youtube_embed_enabled (bool), meta_pixel_id, google_tag_id, google_tag_manager_id, subscribe_display_mode (icon_modal/inline_form/disabled), subscribe_description, social_icons_position (above/below), profile_image_shape (circle/square/rectangle).',
					),
					'background_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID for background image. Pass 0 to remove. Used in "save_settings".',
					),
					'profile_image_id'    => array(
						'type'        => 'integer',
						'description' => 'Attachment ID for profile image. Pass 0 to remove. Used in "save_settings".',
					),
				),
				'required'   => array( 'action', 'calling_user_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$acting_user_id = $this->resolve_acting_user_id( $parameters );

		$denied = $this->assert_acting_user_allowed( $acting_user_id, $parameters );
		if ( null !== $denied ) {
			return $denied;
		}

		$action = $parameters['action'] ?? '';

		switch ( $action ) {
			case 'get':
				return $this->handle_get( $parameters, $acting_user_id );
			case 'add_link':
				return $this->handle_add_link( $parameters, $acting_user_id );
			case 'remove_link':
				return $this->handle_remove_link( $parameters, $acting_user_id );
			case 'save_links':
				return $this->handle_save_links( $parameters, $acting_user_id );
			case 'save_socials':
				return $this->handle_save_socials( $parameters, $acting_user_id );
			case 'save_styles':
				return $this->handle_save_styles( $parameters, $acting_user_id );
			case 'save_settings':
				return $this->handle_save_settings( $parameters, $acting_user_id );
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
	private function handle_get( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		return $this->link_page_result(
			$this->execute_cross_site_ability( 'extrachill/get-link-page-data', array( 'artist_id' => $artist_id ), $acting_user_id, true ),
			$artist_id
		);
	}

	/**
	 * Add a single link to the link page.
	 *
	 * Fetches current links, appends the new one, and saves. This convenience
	 * action avoids making the AI do the fetch-modify-save dance.
	 */
	private function handle_add_link( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
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
		$current = $this->link_page_result(
			$this->execute_cross_site_ability( 'extrachill/get-link-page-data', array( 'artist_id' => $artist_id ), $acting_user_id, true ),
			$artist_id
		);

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

		return $this->save_link_page( 'extrachill/save-link-page-links', array( 'artist_id' => $artist_id, 'links' => $sections ), $acting_user_id, $artist_id );
	}

	/**
	 * Remove a link from the link page by URL or link ID.
	 */
	private function handle_remove_link( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
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
		$current = $this->link_page_result(
			$this->execute_cross_site_ability( 'extrachill/get-link-page-data', array( 'artist_id' => $artist_id ), $acting_user_id, true ),
			$artist_id
		);

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

		return $this->save_link_page( 'extrachill/save-link-page-links', array( 'artist_id' => $artist_id, 'links' => $sections ), $acting_user_id, $artist_id );
	}

	/**
	 * Replace all link sections.
	 */
	private function handle_save_links( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$links = $parameters['links'] ?? null;
		if ( ! is_array( $links ) ) {
			return $this->buildErrorResponse( 'links array is required.', 'manage_link_page' );
		}

		return $this->save_link_page( 'extrachill/save-link-page-links', array( 'artist_id' => $artist_id, 'links' => $links ), $acting_user_id, $artist_id );
	}

	/**
	 * Replace social links.
	 */
	private function handle_save_socials( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$socials = $parameters['socials'] ?? null;
		if ( ! is_array( $socials ) ) {
			return $this->buildErrorResponse( 'socials array is required.', 'manage_link_page' );
		}

		$result = $this->execute_cross_site_ability(
			'extrachill/save-social-links',
			array( 'artist_id' => $artist_id, 'social_links' => $socials ),
			$acting_user_id
		);
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $this->tool_slug );
		}
		if ( ! is_array( $result ) || ! isset( $result['social_links'] ) || ! is_array( $result['social_links'] ) ) {
			return $this->buildErrorResponse( 'The link-page owner returned an invalid social-links response.', $this->tool_slug );
		}

		return array( 'success' => true, 'data' => $result, 'tool_name' => $this->tool_slug );
	}

	/**
	 * Update CSS variables (merge with existing).
	 */
	private function handle_save_styles( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
		if ( is_array( $artist_id ) ) {
			return $artist_id;
		}

		$css_vars = $parameters['css_vars'] ?? null;
		if ( empty( $css_vars ) || ! is_array( $css_vars ) ) {
			return $this->buildErrorResponse( 'css_vars object is required.', 'manage_link_page' );
		}

		return $this->save_link_page( 'extrachill/save-link-page-styles', array( 'artist_id' => $artist_id, 'css_vars' => $css_vars ), $acting_user_id, $artist_id );
	}

	/**
	 * Update link page settings.
	 */
	private function handle_save_settings( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );
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

		$body['artist_id'] = $artist_id;
		return $this->save_link_page( 'extrachill/save-link-page-settings', $body, $acting_user_id, $artist_id );
	}

	/**
	 * Resolve the artist ID from parameters or the canonical owner response.
	 *
	 * @param array $parameters     Tool parameters.
	 * @param int   $acting_user_id User to auto-detect artists for when artist_id is absent.
	 * @return int|array<string,mixed> Artist ID on success, or error/disambiguation response array.
	 */
	private function resolve_artist_id( array $parameters, int $acting_user_id ) {
		if ( ! empty( $parameters['artist_id'] ) ) {
			return (int) $parameters['artist_id'];
		}

		$user_id = $acting_user_id;
		$artists = $this->get_user_artists( $user_id );
		if ( is_array( $artists ) && isset( $artists['success'] ) ) {
			return $artists;
		}

		if ( empty( $artists ) ) {
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

		if ( count( $artists ) === 1 ) {
			return (int) $artists[0]['id'];
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
	 * Resolve and validate the Users-owned membership response.
	 *
	 * @return array<int,array<string,mixed>>|array<string,mixed>
	 */
	private function get_user_artists( int $user_id ): array {
		$result = $this->execute_local_ability( 'extrachill/get-user-artists', array( 'user_id' => $user_id ), $user_id );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $this->tool_slug );
		}
		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse( 'The artist membership owner returned an invalid response.', $this->tool_slug );
		}
		foreach ( $result as $artist ) {
			if ( ! is_array( $artist ) || (int) ( $artist['id'] ?? 0 ) <= 0 || ! is_string( $artist['name'] ?? null ) || ! is_string( $artist['slug'] ?? null ) || ! array_key_exists( 'profile_image_url', $artist ) || ( null !== $artist['profile_image_url'] && ! is_string( $artist['profile_image_url'] ) ) ) {
				return $this->buildErrorResponse( 'The artist membership owner returned an invalid response.', $this->tool_slug );
			}
		}
		return array_values( $result );
	}

	/** Execute and validate a link-page ability that returns full page data. */
	private function save_link_page( string $ability_name, array $input, int $user_id, int $artist_id ): array {
		return $this->link_page_result( $this->execute_cross_site_ability( $ability_name, $input, $user_id ), $artist_id );
	}

	/** Validate an Artist-owned full link-page response before reporting success. */
	private function link_page_result( $result, int $artist_id ): array {
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $this->tool_slug );
		}

		$valid = is_array( $result )
			&& (int) ( $result['artist_id'] ?? 0 ) === $artist_id
			&& (int) ( $result['link_page_id'] ?? 0 ) > 0
			&& is_array( $result['links'] ?? null )
			&& is_array( $result['css_vars'] ?? null )
			&& is_array( $result['socials'] ?? null )
			&& is_array( $result['settings'] ?? null );
		if ( ! $valid ) {
			return $this->buildErrorResponse( 'The link-page owner returned an invalid response.', $this->tool_slug );
		}

		return array( 'success' => true, 'data' => $result, 'tool_name' => $this->tool_slug );
	}

}
