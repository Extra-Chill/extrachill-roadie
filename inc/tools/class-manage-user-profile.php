<?php
/**
 * Manage User Profile Tool
 *
 * Chat tool for reading and updating the current user's profile — bio,
 * custom title, city, and profile links. Routes requests through the
 * REST API for architectural consistency with other platform tools.
 *
 * User profile routes have no site affinity (extrachill-users is
 * network-activated), so requests work from any site. We use 'main'
 * as the site_key to ensure the API plugin is loaded.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 * @since 0.5.0 Refactored to use ECRoadie_PlatformTool + REST.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_ManageUserProfile extends ECRoadie_PlatformTool {

	protected string $site_key  = 'main';
	protected string $tool_slug = 'manage_user_profile';

	public function __construct() {
		$this->registerTool(
			'manage_user_profile',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Manage the current user\'s Extra Chill profile. Can get profile details, update bio/title/city, or replace profile links. Profile links are different from artist link pages — these are the links shown on the user\'s community profile.',
			'parameters'  => array(
				'action'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "get" (view profile), "update" (update bio, title, or city), "update_links" (replace profile links)',
				),
				'custom_title' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Custom profile title (e.g. "Music Producer", "Concert Photographer"). Used in "update".',
				),
				'bio'          => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'User bio/description. HTML is allowed. Used in "update".',
				),
				'local_city'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'User\'s city (e.g. "Austin, TX"). Used in "update".',
				),
				'links'        => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of profile links for "update_links". Each: {type_key, url, custom_label?}. Valid type_key values: website, facebook, instagram, twitter, youtube, tiktok, spotify, soundcloud, bandcamp, github, other. Full replacement — all existing links are replaced.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		switch ( $action ) {
			case 'get':
				return $this->handle_get();
			case 'update':
				return $this->handle_update( $parameters );
			case 'update_links':
				return $this->handle_update_links( $parameters );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: get, update, update_links.',
					'manage_user_profile'
				);
		}
	}

	/**
	 * Get the current user's profile.
	 */
	private function handle_get(): array {
		if ( ! get_current_user_id() ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		return $this->rest_request( 'GET', '/users/me/profile' );
	}

	/**
	 * Update the current user's profile fields.
	 */
	private function handle_update( array $parameters ): array {
		if ( ! get_current_user_id() ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		$body = array();

		$fields = array( 'custom_title', 'bio', 'local_city' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $parameters ) ) {
				$body[ $field ] = $parameters[ $field ];
			}
		}

		if ( empty( $body ) ) {
			return $this->buildErrorResponse(
				'At least one field is required: custom_title, bio, or local_city.',
				'manage_user_profile'
			);
		}

		$result = $this->rest_request( 'POST', '/users/me/profile', array(
			'body' => $body,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Profile updated successfully.';
		}

		return $result;
	}

	/**
	 * Replace the current user's profile links.
	 */
	private function handle_update_links( array $parameters ): array {
		if ( ! get_current_user_id() ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		$links = $parameters['links'] ?? null;

		if ( ! is_array( $links ) ) {
			return $this->buildErrorResponse( 'links array is required.', 'manage_user_profile' );
		}

		$result = $this->rest_request( 'POST', '/users/me/links', array(
			'body' => array( 'links' => $links ),
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Profile links updated successfully.';
		}

		return $result;
	}
}
