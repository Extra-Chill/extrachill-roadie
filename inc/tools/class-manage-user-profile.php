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
			'description'        => 'Manage a user\'s Extra Chill profile. Defaults to the calling user. Admins can target another user by passing user_id. Can get profile details, update bio/title/local scene and its visibility, or replace profile links. Profile links are different from artist link pages — these are the links shown on the user\'s community profile.',
			'parameters'         => array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'description' => 'Action: "get" (view profile), "update" (update bio, title, local scene, or local scene visibility), "update_links" (replace profile links)',
					),
					'user_id'      => array(
						'type'        => 'integer',
						'description' => 'Target user ID. Optional. Defaults to the calling user. Admin-only override — non-admins targeting another user get a permission error.',
					),
					'calling_user_id' => array( 'type' => 'integer' ),
					'custom_title' => array(
						'type'        => 'string',
						'description' => 'Custom profile title (e.g. "Music Producer", "Concert Photographer"). Used in "update".',
					),
					'bio'          => array(
						'type'        => 'string',
						'description' => 'User bio/description. HTML is allowed. Used in "update".',
					),
					'local_scene' => array(
						'type'        => 'string',
						'description' => 'Canonical Events location slug for the user\'s Local Scene. Pass an empty string to clear it. Used in "update".',
					),
					'local_scene_visibility' => array(
						'type'        => 'string',
						'enum'        => array( 'public', 'private' ),
						'description' => 'Whether the Local Scene appears on the user\'s public profile. Used in "update".',
					),
					'local_city' => array(
						'type'        => 'string',
						'description' => 'Compatibility alias for local_scene. Values are resolved as canonical Events location slugs; this does not update artist local_city.',
					),
					'links'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'Array of profile links for "update_links". Each: {type_key, url, custom_label?}. Valid type_key values: website, facebook, instagram, twitter, youtube, tiktok, spotify, soundcloud, bandcamp, github, other. Full replacement — all existing links are replaced.',
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
		$profile = $this->rest_request( 'GET', '/users/me/profile', array(
			'user_id' => $acting_user_id,
		) );
		if ( ! ( $profile['success'] ?? false ) ) {
			return $profile;
		}

		$settings = $this->execute_user_ability( 'extrachill/get-user-settings', array(), $acting_user_id );
		if ( is_wp_error( $settings ) ) {
			return $this->buildErrorResponse( $settings->get_error_message(), 'manage_user_profile' );
		}

		$visibility = $settings['local_scene_visibility'] ?? 'private';
		$profile['data']['local_scene_visibility'] = $visibility;
		if ( 'public' !== $visibility ) {
			$profile['data']['local_scene'] = null;
			$profile['data']['local_city']  = '';
		}

		return $profile;
	}

	/**
	 * Update the acting user's profile fields.
	 */
	private function handle_update( array $parameters, int $acting_user_id ): array {
		$body = array();

		$fields = array( 'custom_title', 'bio' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $parameters ) ) {
				$body[ $field ] = $parameters[ $field ];
			}
		}

		$settings = array();
		if ( array_key_exists( 'local_scene', $parameters ) ) {
			$settings['local_scene'] = $parameters['local_scene'];
		} elseif ( array_key_exists( 'local_city', $parameters ) ) {
			$settings['local_scene'] = $parameters['local_city'];
		}
		if ( array_key_exists( 'local_scene_visibility', $parameters ) ) {
			$settings['local_scene_visibility'] = $parameters['local_scene_visibility'];
		}

		if ( empty( $body ) && empty( $settings ) ) {
			return $this->buildErrorResponse(
				'At least one field is required: custom_title, bio, local_scene, or local_scene_visibility.',
				'manage_user_profile'
			);
		}

		if ( ! empty( $body ) ) {
			$result = $this->rest_request( 'POST', '/users/me/profile', array(
				'body'    => $body,
				'user_id' => $acting_user_id,
			) );
			if ( ! ( $result['success'] ?? false ) ) {
				return $result;
			}
		}

		if ( ! empty( $settings ) ) {
			$ability_result = $this->execute_user_ability( 'extrachill/update-user-settings', $settings, $acting_user_id );
			if ( is_wp_error( $ability_result ) ) {
				return $this->buildErrorResponse( $ability_result->get_error_message(), 'manage_user_profile' );
			}
		}

		return array(
			'success'   => true,
			'message'   => 'Profile updated successfully.',
			'tool_name' => 'manage_user_profile',
		);
	}

	/** Execute a self-only Users Ability as the selected acting user. */
	private function execute_user_ability( string $name, array $input, int $acting_user_id ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'ability_api_unavailable', 'WordPress Abilities API is unavailable.' );
		}

		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', sprintf( 'Required ability %s is unavailable.', $name ) );
		}

		$original_user_id = get_current_user_id();
		try {
			wp_set_current_user( $acting_user_id );
			return $ability->execute( $input );
		} finally {
			wp_set_current_user( $original_user_id );
		}
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
