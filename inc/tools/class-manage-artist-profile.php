<?php
/**
 * Manage Artist Profile Tool
 *
 * Chat tool for creating, reading, and updating artist profiles.
 * Wraps extrachill-artist-platform abilities with cross-site execution.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_ManageArtistProfile extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'manage_artist_profile',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Manage artist profiles on the Extra Chill platform. Can list the current user\'s artists, get artist details, create a new artist profile, or update an existing one (name, bio, genre, city, images). If the user has only one artist, it is auto-selected.',
			'parameters'  => array(
				'action'           => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "list" (list user\'s artists), "get" (get artist details), "create" (create new artist), "update" (update existing artist)',
				),
				'artist_id'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Artist profile ID. Required for "get" and "update". Omit for "list" and "create". If the user has only one artist, this is auto-resolved.',
				),
				'name'             => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Artist/band name. Required for "create", optional for "update".',
				),
				'bio'              => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Artist bio/description. HTML is allowed. Used in "create" and "update".',
				),
				'genre'            => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Music genre (e.g. "Indie Rock", "Hip Hop"). Used in "create" and "update".',
				),
				'local_city'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'City/scene the artist is based in (e.g. "Austin, TX"). Used in "create" and "update".',
				),
				'profile_image_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Attachment ID for profile image. Pass 0 to remove. Used in "update".',
				),
				'header_image_id'  => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Attachment ID for header image. Pass 0 to remove. Used in "update".',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		switch ( $action ) {
			case 'list':
				return $this->handle_list();
			case 'get':
				return $this->handle_get( $parameters );
			case 'create':
				return $this->handle_create( $parameters );
			case 'update':
				return $this->handle_update( $parameters );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: list, get, create, update.',
					'manage_artist_profile'
				);
		}
	}

	/**
	 * List the current user's artist profiles.
	 */
	private function handle_list(): array {
		$user_id    = get_current_user_id();
		$artist_ids = $this->get_user_artist_ids( $user_id );

		if ( empty( $artist_ids ) ) {
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

		$artists    = array();
		$artist_blog = $this->get_artist_blog_id();

		if ( $artist_blog ) {
			switch_to_blog( $artist_blog );
		}

		foreach ( $artist_ids as $artist_id ) {
			$post = get_post( (int) $artist_id );
			if ( $post && 'artist_profile' === $post->post_type ) {
				$artists[] = array(
					'id'   => (int) $post->ID,
					'name' => $post->post_title,
					'slug' => $post->post_name,
				);
			}
		}

		if ( $artist_blog ) {
			restore_current_blog();
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
	private function handle_get( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );

		if ( is_array( $artist_id ) ) {
			return $artist_id; // Error or disambiguation response.
		}

		$artist_blog = $this->get_artist_blog_id();
		if ( $artist_blog ) {
			switch_to_blog( $artist_blog );
		}

		$ability = wp_get_ability( 'extrachill/get-artist-data' );
		$result  = null;

		if ( $ability ) {
			$result = $ability->execute( array( 'artist_id' => $artist_id ) );
		}

		if ( $artist_blog ) {
			restore_current_blog();
		}

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Artist platform is not available on this network.',
				'manage_artist_profile'
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_artist_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_artist_profile',
		);
	}

	/**
	 * Create a new artist profile.
	 */
	private function handle_create( array $parameters ): array {
		$name = $parameters['name'] ?? '';

		if ( empty( $name ) ) {
			return $this->buildErrorResponse(
				'Artist name is required to create a profile.',
				'manage_artist_profile'
			);
		}

		$user_id = get_current_user_id();
		$input   = array(
			'name'    => $name,
			'user_id' => $user_id,
		);

		if ( ! empty( $parameters['bio'] ) ) {
			$input['bio'] = $parameters['bio'];
		}
		if ( ! empty( $parameters['genre'] ) ) {
			$input['genre'] = $parameters['genre'];
		}
		if ( ! empty( $parameters['local_city'] ) ) {
			$input['local_city'] = $parameters['local_city'];
		}

		$artist_blog = $this->get_artist_blog_id();
		if ( $artist_blog ) {
			switch_to_blog( $artist_blog );
		}

		$ability = wp_get_ability( 'extrachill/create-artist' );
		$result  = null;

		if ( $ability ) {
			$result = $ability->execute( $input );
		}

		if ( $artist_blog ) {
			restore_current_blog();
		}

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Artist platform is not available on this network.',
				'manage_artist_profile'
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_artist_profile'
			);
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to create artist profile.' ),
				'manage_artist_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'message'   => 'Artist profile created successfully.',
			'tool_name' => 'manage_artist_profile',
		);
	}

	/**
	 * Update an existing artist profile.
	 */
	private function handle_update( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );

		if ( is_array( $artist_id ) ) {
			return $artist_id; // Error or disambiguation response.
		}

		// Check the user can manage this artist.
		if ( function_exists( 'ec_can_manage_artist' ) ) {
			if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
				return $this->buildErrorResponse(
					'You do not have permission to manage this artist profile.',
					'manage_artist_profile'
				);
			}
		}

		$input = array( 'artist_id' => $artist_id );

		// Only include fields that were actually provided.
		$fields = array( 'name', 'bio', 'genre', 'local_city', 'profile_image_id', 'header_image_id' );
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $parameters ) ) {
				$input[ $field ] = $parameters[ $field ];
			}
		}

		$artist_blog = $this->get_artist_blog_id();
		if ( $artist_blog ) {
			switch_to_blog( $artist_blog );
		}

		$ability = wp_get_ability( 'extrachill/update-artist' );
		$result  = null;

		if ( $ability ) {
			$result = $ability->execute( $input );
		}

		if ( $artist_blog ) {
			restore_current_blog();
		}

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Artist platform is not available on this network.',
				'manage_artist_profile'
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_artist_profile'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'message'   => 'Artist profile updated successfully.',
			'tool_name' => 'manage_artist_profile',
		);
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
				'manage_artist_profile',
				array( 'user_id' => $user_id ),
				array(
					'action'    => 'Create an artist profile first',
					'message'   => 'Use action "create" with a name to set up your artist profile.',
					'tool_hint' => 'manage_artist_profile',
				)
			);
		}

		if ( count( $artist_ids ) === 1 ) {
			return (int) $artist_ids[0];
		}

		// Multiple artists — need disambiguation.
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
			'success'   => false,
			'error'     => 'You manage multiple artist profiles. Please specify which one.',
			'error_type' => 'validation',
			'tool_name' => 'manage_artist_profile',
			'data'      => array(
				'artists'     => $artists,
				'instruction' => 'Ask the user which artist they want to manage, then re-call with artist_id.',
			),
		);
	}

	/**
	 * Get the user's artist profile IDs from user meta.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Array of artist profile IDs.
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
	 *
	 * @return int|null Blog ID or null if multisite helper unavailable.
	 */
	private function get_artist_blog_id(): ?int {
		if ( function_exists( 'ec_get_blog_id' ) ) {
			return ec_get_blog_id( 'artist' );
		}
		return null;
	}
}
