<?php
/**
 * Manage Artist Profile Tool
 *
 * Chat tool for creating, reading, and updating artist profiles.
 * Uses the cross-site REST helper from ECRoadie_PlatformTool to route
 * requests to the artist site, where abilities are properly loaded.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 * @since 0.8.0 Calling-user identity propagation: list/get/create/update act
 *              on behalf of the calling user (or an explicit user_id when
 *              admins override).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_ManageArtistProfile extends ECRoadie_PlatformTool {

	protected string $site_key  = 'artist';
	protected string $tool_slug = 'manage_artist_profile';

	public function __construct() {
		$this->registerTool(
			'manage_artist_profile',
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
			'description'        => 'Manage artist profiles on the Extra Chill platform. Defaults to the calling user. Admins can target another user by passing user_id. Can list a user\'s artists, get artist details, create a new artist profile, or update an existing one (name, bio, genre, city, images). If the user has only one artist, it is auto-selected.',
			'parameters'         => array(
				'type'       => 'object',
				'properties' => array(
					'action'           => array(
						'type'        => 'string',
						'description' => 'Action to perform: "list" (list user\'s artists), "get" (get artist details), "create" (create new artist), "update" (update existing artist)',
					),
					'user_id'          => array(
						'type'        => 'integer',
						'description' => 'Target user ID for list/create/auto-resolve. Optional. Defaults to the calling user. Admin-only override.',
					),
					'calling_user_id'  => array( 'type' => 'integer' ),
					'artist_id'        => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID. Required for "get" and "update". Omit for "list" and "create". If the user has only one artist, this is auto-resolved.',
					),
					'name'             => array(
						'type'        => 'string',
						'description' => 'Artist/band name. Required for "create", optional for "update".',
					),
					'bio'              => array(
						'type'        => 'string',
						'description' => 'Artist bio/description. HTML is allowed. Used in "create" and "update".',
					),
					'genre'            => array(
						'type'        => 'string',
						'description' => 'Music genre (e.g. "Indie Rock", "Hip Hop"). Used in "create" and "update".',
					),
					'local_city'       => array(
						'type'        => 'string',
						'description' => 'City/scene the artist is based in (e.g. "Austin, TX"). Used in "create" and "update".',
					),
					'profile_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID for profile image. Pass 0 to remove. Used in "update".',
					),
					'header_image_id'  => array(
						'type'        => 'integer',
						'description' => 'Attachment ID for header image. Pass 0 to remove. Used in "update".',
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
			case 'list':
				return $this->handle_list( $acting_user_id );
			case 'get':
				return $this->handle_get( $parameters, $acting_user_id );
			case 'create':
				return $this->handle_create( $parameters, $acting_user_id );
			case 'update':
				return $this->handle_update( $parameters, $acting_user_id );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: list, get, create, update.',
					'manage_artist_profile'
				);
		}
	}

	/**
	 * List the acting user's artist profiles.
	 *
	 * Membership and artist summaries come from the Users-owned ability.
	 */
	private function handle_list( int $acting_user_id ): array {
		$user_id = $acting_user_id;
		$artists = $this->get_user_artists( $user_id );
		if ( is_array( $artists ) && isset( $artists['success'] ) ) {
			return $artists;
		}

		if ( empty( $artists ) ) {
			return $this->buildDiagnosticErrorResponse(
				'You do not have any artist profiles yet.',
				'not_found',
				'manage_artist_profile',
				array( 'user_id' => $user_id ),
				array(
					'action'    => 'Request artist access or create a profile',
					'message'   => 'Use action "create" to create a new artist profile, or ask an admin to grant you artist access.',
					'tool_hint' => 'manage_artist_profile',
				)
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'user_id' => $user_id,
				'artists' => $artists,
				'count'   => count( $artists ),
			),
			'tool_name' => 'manage_artist_profile',
		);
	}

	/**
	 * Get artist profile details.
	 */
	private function handle_get( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );

		if ( is_array( $artist_id ) ) {
			return $artist_id; // Error or disambiguation response.
		}

		$result = $this->execute_cross_site_ability( 'extrachill/get-artist-data', array( 'artist_id' => $artist_id ), $acting_user_id, true );
		return $this->artist_result( $result, $artist_id );
	}

	/**
	 * Create a new artist profile.
	 */
	private function handle_create( array $parameters, int $acting_user_id ): array {
		$name = $parameters['name'] ?? '';

		if ( empty( $name ) ) {
			return $this->buildErrorResponse(
				'Artist name is required to create a profile.',
				'manage_artist_profile'
			);
		}

		$body = array( 'name' => $name );

		if ( ! empty( $parameters['bio'] ) ) {
			$body['bio'] = $parameters['bio'];
		}
		if ( ! empty( $parameters['genre'] ) ) {
			$body['genre'] = $parameters['genre'];
		}
		if ( ! empty( $parameters['local_city'] ) ) {
			$body['local_city'] = $parameters['local_city'];
		}

		$result = $this->artist_result( $this->execute_cross_site_ability( 'extrachill/create-artist', $body, $acting_user_id ) );
		if ( $result['success'] ?? false ) {
			$result['message'] = 'Artist profile created successfully.';
		}
		return $result;
	}

	/**
	 * Update an existing artist profile.
	 */
	private function handle_update( array $parameters, int $acting_user_id ): array {
		$artist_id = $this->resolve_artist_id( $parameters, $acting_user_id );

		if ( is_array( $artist_id ) ) {
			return $artist_id; // Error or disambiguation response.
		}

		$body = array();

		// Only include fields that were actually provided.
		$fields = array( 'name', 'bio', 'genre', 'local_city', 'profile_image_id', 'header_image_id' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $parameters ) ) {
				$body[ $field ] = $parameters[ $field ];
			}
		}

		if ( empty( $body ) ) {
			return $this->buildErrorResponse(
				'At least one field to update is required.',
				'manage_artist_profile'
			);
		}

		$body['artist_id'] = $artist_id;
		$result            = $this->artist_result( $this->execute_cross_site_ability( 'extrachill/update-artist', $body, $acting_user_id ), $artist_id );
		if ( $result['success'] ?? false ) {
			$result['message'] = 'Artist profile updated successfully.';
		}
		return $result;
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
				'manage_artist_profile',
				array( 'user_id' => $user_id ),
				array(
					'action'    => 'Create an artist profile first',
					'message'   => 'Use action "create" with a name to set up your artist profile.',
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
			'tool_name'  => 'manage_artist_profile',
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

	/** Validate an Artist-owned profile response before reporting success. */
	private function artist_result( $result, int $expected_id = 0 ): array {
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $this->tool_slug );
		}

		$id = is_array( $result ) ? (int) ( $result['id'] ?? 0 ) : 0;
		if ( $id <= 0 || ( $expected_id > 0 && $id !== $expected_id ) || ! is_string( $result['name'] ?? null ) || ! is_string( $result['slug'] ?? null ) ) {
			return $this->buildErrorResponse( 'The artist owner returned an invalid response.', $this->tool_slug );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => $this->tool_slug,
		);
	}

}
