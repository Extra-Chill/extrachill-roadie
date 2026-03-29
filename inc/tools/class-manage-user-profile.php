<?php
/**
 * Manage User Profile Tool
 *
 * Chat tool for reading and updating the current user's profile — bio,
 * custom title, city, and profile links. Wraps extrachill-users abilities.
 *
 * @package ExtraChillAgentKit\Tools
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECAgentKit_ManageUserProfile extends BaseTool {

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

		return match ( $action ) {
			'get'          => $this->handle_get(),
			'update'       => $this->handle_update( $parameters ),
			'update_links' => $this->handle_update_links( $parameters ),
			default        => $this->buildErrorResponse(
				'Invalid action "' . $action . '". Use: get, update, update_links.',
				'manage_user_profile'
			),
		};
	}

	/**
	 * Get the current user's profile.
	 */
	private function handle_get(): array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		$ability = wp_get_ability( 'extrachill/get-user-profile' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'User profile system is not available.',
				'manage_user_profile'
			);
		}

		$result = $ability->execute( array( 'user_id' => $user_id ) );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_user_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_user_profile',
		);
	}

	/**
	 * Update the current user's profile fields.
	 */
	private function handle_update( array $parameters ): array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		$input = array( 'user_id' => $user_id );

		$fields = array( 'custom_title', 'bio', 'local_city' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $parameters ) ) {
				$input[ $field ] = $parameters[ $field ];
			}
		}

		if ( count( $input ) <= 1 ) {
			return $this->buildErrorResponse(
				'At least one field is required: custom_title, bio, or local_city.',
				'manage_user_profile'
			);
		}

		$ability = wp_get_ability( 'extrachill/update-user-profile' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'User profile system is not available.',
				'manage_user_profile'
			);
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_user_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'message'   => 'Profile updated successfully.',
			'tool_name' => 'manage_user_profile',
		);
	}

	/**
	 * Replace the current user's profile links.
	 */
	private function handle_update_links( array $parameters ): array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $this->buildErrorResponse( 'You must be logged in.', 'manage_user_profile' );
		}

		$links = $parameters['links'] ?? null;

		if ( ! is_array( $links ) ) {
			return $this->buildErrorResponse( 'links array is required.', 'manage_user_profile' );
		}

		$ability = wp_get_ability( 'extrachill/update-user-links' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'User profile system is not available.',
				'manage_user_profile'
			);
		}

		$result = $ability->execute( array(
			'user_id' => $user_id,
			'links'   => $links,
		) );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_user_profile'
			);
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to update profile links.' ),
				'manage_user_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'message'   => 'Profile links updated successfully.',
			'tool_name' => 'manage_user_profile',
		);
	}
}
