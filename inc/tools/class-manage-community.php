<?php
/**
 * Manage Community Tool
 *
 * Chat tool for interacting with the Extra Chill community forums.
 * Browse forums, create topics, post replies, and manage notifications.
 * Wraps extrachill-community abilities with cross-site execution.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_ManageCommunity extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'manage_community',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Interact with the Extra Chill community forums. Browse forums, list and read topics, create new topics, post replies, and manage notifications. All actions run on community.extrachill.com.',
			'parameters'  => array(
				'action'          => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: "list_forums" (browse available forums), "list_topics" (list topics in a forum or all), "get_topic" (read a topic with replies), "create_topic" (post a new topic), "create_reply" (reply to a topic), "get_notifications" (check notifications), "mark_notifications_read" (mark all as read)',
				),
				'forum_id'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Forum ID. Required for "create_topic". Optional filter for "list_topics".',
				),
				'topic_id'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Topic ID. Required for "get_topic", "create_reply".',
				),
				'title'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Topic title. Required for "create_topic".',
				),
				'content'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post content (HTML allowed). Required for "create_topic" and "create_reply".',
				),
				'reply_to'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Parent reply ID for threaded replies. Used in "create_reply".',
				),
				'page'            => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Page number for paginated results. Default: 1.',
				),
				'per_page'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Results per page (max 100). Default: 20 for topics, 30 for replies.',
				),
				'include_replies' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Include replies when getting a topic. Default: true.',
				),
				'unread'          => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Only return unread notifications. Used in "get_notifications".',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		switch ( $action ) {
			case 'list_forums':
				return $this->handle_list_forums();
			case 'list_topics':
				return $this->handle_list_topics( $parameters );
			case 'get_topic':
				return $this->handle_get_topic( $parameters );
			case 'create_topic':
				return $this->handle_create_topic( $parameters );
			case 'create_reply':
				return $this->handle_create_reply( $parameters );
			case 'get_notifications':
				return $this->handle_get_notifications( $parameters );
			case 'mark_notifications_read':
				return $this->handle_mark_notifications_read();
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: list_forums, list_topics, get_topic, create_topic, create_reply, get_notifications, mark_notifications_read.',
					'manage_community'
				);
		}
	}

	/**
	 * List all available forums.
	 */
	private function handle_list_forums(): array {
		return $this->execute_on_community_blog( 'extrachill/community-list-forums', array() );
	}

	/**
	 * List topics with optional forum filter and pagination.
	 */
	private function handle_list_topics( array $parameters ): array {
		$input = array();

		if ( ! empty( $parameters['forum_id'] ) ) {
			$input['forum_id'] = (int) $parameters['forum_id'];
		}
		if ( ! empty( $parameters['page'] ) ) {
			$input['page'] = (int) $parameters['page'];
		}
		if ( ! empty( $parameters['per_page'] ) ) {
			$input['per_page'] = (int) $parameters['per_page'];
		}

		return $this->execute_on_community_blog( 'extrachill/community-list-topics', $input );
	}

	/**
	 * Get a single topic with its replies.
	 */
	private function handle_get_topic( array $parameters ): array {
		$topic_id = $parameters['topic_id'] ?? null;

		if ( empty( $topic_id ) ) {
			return $this->buildErrorResponse( 'topic_id is required.', 'manage_community' );
		}

		$input = array( 'topic_id' => (int) $topic_id );

		if ( array_key_exists( 'include_replies', $parameters ) ) {
			$input['include_replies'] = (bool) $parameters['include_replies'];
		}
		if ( ! empty( $parameters['page'] ) ) {
			$input['replies_page'] = (int) $parameters['page'];
		}
		if ( ! empty( $parameters['per_page'] ) ) {
			$input['replies_per_page'] = (int) $parameters['per_page'];
		}

		return $this->execute_on_community_blog( 'extrachill/community-get-topic', $input );
	}

	/**
	 * Create a new forum topic.
	 */
	private function handle_create_topic( array $parameters ): array {
		$forum_id = $parameters['forum_id'] ?? null;
		$title    = $parameters['title'] ?? '';
		$content  = $parameters['content'] ?? '';

		if ( empty( $forum_id ) ) {
			// Provide helpful guidance about available forums.
			$forums = $this->execute_on_community_blog( 'extrachill/community-list-forums', array() );
			$guidance = '';
			if ( ( $forums['success'] ?? false ) && ! empty( $forums['data']['forums'] ) ) {
				$names = array_map(
					function ( $f ) {
						return $f['title'] . ' (ID: ' . $f['forum_id'] . ')';
					},
					$forums['data']['forums']
				);
				$guidance = ' Available forums: ' . implode( ', ', $names ) . '.';
			}

			return $this->buildDiagnosticErrorResponse(
				'forum_id is required to create a topic.' . $guidance,
				'validation',
				'manage_community',
				array(),
				array(
					'action'    => 'Ask the user which forum to post in',
					'message'   => 'List forums first or ask the user which forum they want to post in.',
					'tool_hint' => 'manage_community',
				)
			);
		}

		if ( empty( $title ) ) {
			return $this->buildErrorResponse( 'title is required to create a topic.', 'manage_community' );
		}
		if ( empty( $content ) ) {
			return $this->buildErrorResponse( 'content is required to create a topic.', 'manage_community' );
		}

		$input = array(
			'forum_id' => (int) $forum_id,
			'title'    => $title,
			'content'  => $content,
			'user_id'  => get_current_user_id(),
		);

		$result = $this->execute_on_community_blog( 'extrachill/community-create-topic', $input );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Topic created successfully.';
		}

		return $result;
	}

	/**
	 * Post a reply to a topic.
	 */
	private function handle_create_reply( array $parameters ): array {
		$topic_id = $parameters['topic_id'] ?? null;
		$content  = $parameters['content'] ?? '';

		if ( empty( $topic_id ) ) {
			return $this->buildErrorResponse( 'topic_id is required to post a reply.', 'manage_community' );
		}
		if ( empty( $content ) ) {
			return $this->buildErrorResponse( 'content is required to post a reply.', 'manage_community' );
		}

		$input = array(
			'topic_id' => (int) $topic_id,
			'content'  => $content,
			'user_id'  => get_current_user_id(),
		);

		if ( ! empty( $parameters['reply_to'] ) ) {
			$input['reply_to'] = (int) $parameters['reply_to'];
		}

		$result = $this->execute_on_community_blog( 'extrachill/community-create-reply', $input );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Reply posted successfully.';
		}

		return $result;
	}

	/**
	 * Get the current user's notifications.
	 */
	private function handle_get_notifications( array $parameters ): array {
		$input = array(
			'user_id' => get_current_user_id(),
		);

		if ( isset( $parameters['unread'] ) ) {
			$input['unread'] = (bool) $parameters['unread'];
		}

		return $this->execute_on_community_blog( 'extrachill/community-get-notifications', $input );
	}

	/**
	 * Mark all notifications as read.
	 */
	private function handle_mark_notifications_read(): array {
		$input = array(
			'user_id' => get_current_user_id(),
		);

		$result = $this->execute_on_community_blog( 'extrachill/community-mark-notifications-read', $input );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'All notifications marked as read.';
		}

		return $result;
	}

	/**
	 * Execute an ability on the community blog with automatic blog switching.
	 *
	 * @param string $ability_slug Ability slug.
	 * @param array  $input        Ability input.
	 * @return array Tool response.
	 */
	private function execute_on_community_blog( string $ability_slug, array $input ): array {
		$community_blog = $this->get_community_blog_id();

		if ( $community_blog ) {
			switch_to_blog( $community_blog );
		}

		$ability = wp_get_ability( $ability_slug );
		$result  = null;

		if ( $ability ) {
			$result = $ability->execute( $input );
		}

		if ( $community_blog ) {
			restore_current_blog();
		}

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Community forums are not available on this network.',
				'manage_community'
			);
		}

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'manage_community'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_community',
		);
	}

	/**
	 * Get the blog ID for the community site.
	 *
	 * @return int|null Blog ID or null if multisite helper unavailable.
	 */
	private function get_community_blog_id(): ?int {
		if ( function_exists( 'ec_get_blog_id' ) ) {
			return ec_get_blog_id( 'community' );
		}
		return null;
	}
}
