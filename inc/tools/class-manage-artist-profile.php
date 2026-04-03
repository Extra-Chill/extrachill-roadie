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
	 *
	 * Artist IDs are stored in user meta (network-wide), but we need to
	 * read post data from the artist blog via switch_to_blog (safe for
	 * data reads — only abilities fail cross-site).
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

		// Read post data from the artist blog (switch_to_blog is safe for reads).
		$artists     = array();
		$artist_blog = $this->get_blog_id( 'artist' );

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

		return $this->rest_request( 'GET', '/artists/' . $artist_id );
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

		$result = $this->rest_request( 'POST', '/artists', array(
			'body' => $body,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Artist profile created successfully.';
		}

		return $result;
	}

	/**
	 * Update an existing artist profile.
	 */
	private function handle_update( array $parameters ): array {
		$artist_id = $this->resolve_artist_id( $parameters );

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

		$result = $this->rest_request( 'PUT', '/artists/' . $artist_id, array(
			'body' => $body,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Artist profile updated successfully.';
		}

		return $result;
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
		// Read post data from the artist blog (switch_to_blog is safe for reads).
		$artists     = array();
		$artist_blog = $this->get_blog_id( 'artist' );

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
			'tool_name'  => 'manage_artist_profile',
			'data'       => array(
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

}
