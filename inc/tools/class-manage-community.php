<?php
/**
 * Manage Community Tool
 *
 * Chat tool for interacting with the Extra Chill community forums.
 * Browse forums, create topics, post replies, and manage notifications.
 *
 * Uses the cross-site REST helper from ECRoadie_PlatformTool to route
 * requests to the community site. The API route affinity middleware
 * handles forwarding automatically when called from any site.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 * @since 0.8.0 Calling-user identity propagation: topics, replies, and
 *              notifications are attributed to the calling user (or an
 *              explicit user_id when admins override).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_ManageCommunity extends ECRoadie_PlatformTool {

	protected string $site_key  = 'community';
	protected string $tool_slug = 'manage_community';

	public function __construct() {
		$this->registerTool(
			'manage_community',
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
			'description'        => 'Interact with the Extra Chill community forums. Browse forums, list and read topics, create new topics, post replies, and manage notifications. Forum posts and replies are attributed to the calling user by default; admins may override via user_id. All actions run on community.extrachill.com.',
			'parameters'         => array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'description' => 'Action: "list_forums" (browse available forums), "list_topics" (list topics in a forum or all), "get_topic" (read a topic with replies), "create_topic" (post a new topic), "create_reply" (reply to a topic), "get_notifications" (check notifications), "mark_notifications_read" (mark all as read)',
					),
					'user_id'         => array(
						'type'        => 'integer',
						'description' => 'Target user ID for write actions (create_topic, create_reply) and notification reads. Optional. Defaults to the calling user. Admin-only override.',
					),
					'calling_user_id' => array( 'type' => 'integer' ),
					'forum_id'        => array(
						'type'        => 'integer',
						'description' => 'Forum ID. Required for "create_topic". Optional filter for "list_topics".',
					),
					'topic_id'        => array(
						'type'        => 'integer',
						'description' => 'Topic ID. Required for "get_topic", "create_reply".',
					),
					'title'           => array(
						'type'        => 'string',
						'description' => 'Topic title. Required for "create_topic".',
					),
					'content'         => array(
						'type'        => 'string',
						'description' => 'Post content (HTML allowed). Required for "create_topic" and "create_reply".',
					),
					'reply_to'        => array(
						'type'        => 'integer',
						'description' => 'Parent reply ID for threaded replies. Used in "create_reply".',
					),
					'page'            => array(
						'type'        => 'integer',
						'description' => 'Page number for paginated results. Default: 1.',
					),
					'per_page'        => array(
						'type'        => 'integer',
						'description' => 'Results per page (max 100). Default: 20 for topics, 30 for replies.',
					),
					'include_replies' => array(
						'type'        => 'boolean',
						'description' => 'Include replies when getting a topic. Default: true.',
					),
					'unread'          => array(
						'type'        => 'boolean',
						'description' => 'Only return unread notifications. Used in "get_notifications".',
					),
				),
				'required'   => array( 'action' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		// Read-only actions that don't need a user context.
		$public_actions = array( 'list_forums', 'list_topics', 'get_topic' );

		if ( in_array( $action, $public_actions, true ) ) {
			switch ( $action ) {
				case 'list_forums':
					return $this->handle_list_forums();
				case 'list_topics':
					return $this->handle_list_topics( $parameters );
				case 'get_topic':
					return $this->handle_get_topic( $parameters );
			}
		}

		// User-scoped actions: resolve and authorize.
		$acting_user_id = $this->resolve_acting_user_id( $parameters );

		$denied = $this->assert_acting_user_allowed( $acting_user_id, $parameters );
		if ( null !== $denied ) {
			return $denied;
		}

		switch ( $action ) {
			case 'create_topic':
				return $this->handle_create_topic( $parameters, $acting_user_id );
			case 'create_reply':
				return $this->handle_create_reply( $parameters, $acting_user_id );
			case 'get_notifications':
				return $this->handle_get_notifications( $parameters, $acting_user_id );
			case 'mark_notifications_read':
				return $this->handle_mark_notifications_read( $acting_user_id );
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
		return $this->rest_request( 'GET', '/community/forums' );
	}

	/**
	 * List topics with optional forum filter and pagination.
	 */
	private function handle_list_topics( array $parameters ): array {
		$query = array();

		if ( ! empty( $parameters['forum_id'] ) ) {
			$query['forum_id'] = (int) $parameters['forum_id'];
		}
		if ( ! empty( $parameters['page'] ) ) {
			$query['page'] = (int) $parameters['page'];
		}
		if ( ! empty( $parameters['per_page'] ) ) {
			$query['per_page'] = (int) $parameters['per_page'];
		}

		return $this->rest_request( 'GET', '/community/topics', array(
			'query' => $query,
		) );
	}

	/**
	 * Get a single topic with its replies.
	 */
	private function handle_get_topic( array $parameters ): array {
		$topic_id = $parameters['topic_id'] ?? null;

		if ( empty( $topic_id ) ) {
			return $this->buildErrorResponse( 'topic_id is required.', 'manage_community' );
		}

		$query = array();

		if ( array_key_exists( 'include_replies', $parameters ) ) {
			$query['include_replies'] = (bool) $parameters['include_replies'];
		}
		if ( ! empty( $parameters['page'] ) ) {
			$query['replies_page'] = (int) $parameters['page'];
		}
		if ( ! empty( $parameters['per_page'] ) ) {
			$query['replies_per_page'] = (int) $parameters['per_page'];
		}

		return $this->rest_request( 'GET', '/community/topics/' . (int) $topic_id, array(
			'query' => $query,
		) );
	}

	/**
	 * Create a new forum topic.
	 */
	private function handle_create_topic( array $parameters, int $acting_user_id ): array {
		$forum_id = $parameters['forum_id'] ?? null;
		$title    = $parameters['title'] ?? '';
		$content  = $parameters['content'] ?? '';

		if ( empty( $forum_id ) ) {
			// Provide helpful guidance about available forums.
			$forums   = $this->handle_list_forums();
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

		$result = $this->rest_request( 'POST', '/community/topics', array(
			'body'    => array(
				'forum_id' => (int) $forum_id,
				'title'    => $title,
				'content'  => $content,
			),
			'user_id' => $acting_user_id,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Topic created successfully.';
		}

		return $result;
	}

	/**
	 * Post a reply to a topic.
	 */
	private function handle_create_reply( array $parameters, int $acting_user_id ): array {
		$topic_id = $parameters['topic_id'] ?? null;
		$content  = $parameters['content'] ?? '';

		if ( empty( $topic_id ) ) {
			return $this->buildErrorResponse( 'topic_id is required to post a reply.', 'manage_community' );
		}
		if ( empty( $content ) ) {
			return $this->buildErrorResponse( 'content is required to post a reply.', 'manage_community' );
		}

		$body = array(
			'topic_id' => (int) $topic_id,
			'content'  => $content,
		);

		if ( ! empty( $parameters['reply_to'] ) ) {
			$body['reply_to'] = (int) $parameters['reply_to'];
		}

		$result = $this->rest_request( 'POST', '/community/replies', array(
			'body'    => $body,
			'user_id' => $acting_user_id,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'Reply posted successfully.';
		}

		return $result;
	}

	/**
	 * Get the acting user's notifications.
	 */
	private function handle_get_notifications( array $parameters, int $acting_user_id ): array {
		$query = array();

		if ( isset( $parameters['unread'] ) ) {
			$query['unread'] = (bool) $parameters['unread'];
		}

		return $this->rest_request( 'GET', '/community/notifications', array(
			'query'   => $query,
			'user_id' => $acting_user_id,
		) );
	}

	/**
	 * Mark all notifications as read for the acting user.
	 */
	private function handle_mark_notifications_read( int $acting_user_id ): array {
		$result = $this->rest_request( 'POST', '/community/notifications/mark-read', array(
			'user_id' => $acting_user_id,
		) );

		if ( $result['success'] ?? false ) {
			$result['message'] = 'All notifications marked as read.';
		}

		return $result;
	}

}
