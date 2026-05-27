<?php
/**
 * Manage User Profile Tool
 *
 * Chat tool for reading and updating a user's profile — bio, custom title,
 * city, and profile links. Routes requests through the REST API for
 * architectural consistency with other platform tools.
 *
 * User profile routes have no site affinity (extrachill-users is
 * network-activated), so requests work from any site. We use 'main'
 * as the site_key to ensure the API plugin is loaded.
 *
 * Identity model: the tool acts on behalf of `calling_user_id` by default
 * (the chat caller). An admin agent can target another user by passing
 * `user_id` explicitly; non-admins attempting that get a clean permission
 * denial.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 * @since 0.5.0 Refactored to use ECRoadie_PlatformTool + REST.
 * @since 0.8.0 Calling-user identity propagation via user_id + calling_user_id.
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
			'description' => 'Manage a user\'s Extra Chill profile. Defaults to the calling user. Admins can target another user by passing user_id. Can get profile details, update bio/title/city, or replace profile links. Profile links are different from artist link pages — these are the links shown on the user\'s community profile.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'description' => 'Action: "get" (view profile), "update" (update bio, title, or city), "update_links" (replace profile links)',
					),
					'user_id'      => array(
						'type'        => 'integer',
						'description' => 'Target user ID. Optional. Defaults to the calling user. Admin-only override — non-admins targeting another user get a permission error.',
					),
					'custom_title' => array(
						'type'        => 'string',
						'description' => 'Custom profile title (e.g. "Music Producer", "Concert Photographer"). Used in "update".',
					),
					'bio'          => array(
						'type'        => 'string',
						'description' => 'User bio/description. HTML is allowed. Used in "update".',
					),
					'local_city'   => array(
						'type'        => 'string',
						'description' => 'User\'s city (e.g. "Austin, TX"). Used in "update".',
					),
					'links'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'Array of profile links for "update_links". Each: {type_key, url, custom_label?}. Valid type_key values: website, facebook, instagram, twitter, youtube, tiktok, spotify, soundcloud, bandcamp, github, other. Full replacement — all existing links are replaced.',
					),
				),
				'required'   => array( 'action' ),
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
				return $this->handle_get( $acting_user_id );
			case 'update':
				return $this->handle_update( $parameters, $acting_user_id );
			case 'update_links':
				return $this->handle_update_links( $parameters, $acting_user_id );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: get, update, update_links.',
					'manage_user_profile'
				);
		}
	}

	/**
	 * Get the acting user's profile.
	 *
	 * The REST endpoint (`/users/me/profile`) reads the authenticated user
	 * via get_current_user_id(); ec_cross_site_rest_request() switches the
	 * user for the duration of the call when `user_id` is supplied.
	 */
	private function handle_get( int $acting_user_id ): array {
		return $this->rest_request( 'GET', '/users/me/profile', array(
			'user_id' => $acting_user_id,
		) );
	}

	/**
	 * Update the acting user's profile fields.
	 */
	private function handle_update( array $parameters, int $acting_user_id ): array {
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
			'body'    => $body,
			'user_id' => $acting_user_id,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Profile updated successfully.';
		}

		return $result;
	}

	/**
	 * Replace the acting user's profile links.
	 */
	private function handle_update_links( array $parameters, int $acting_user_id ): array {
		$links = $parameters['links'] ?? null;

		if ( ! is_array( $links ) ) {
			return $this->buildErrorResponse( 'links array is required.', 'manage_user_profile' );
		}

		$result = $this->rest_request( 'POST', '/users/me/links', array(
			'body'    => array( 'links' => $links ),
			'user_id' => $acting_user_id,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Profile links updated successfully.';
		}

		return $result;
	}
}
