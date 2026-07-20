<?php
/**
 * Writing Assistant Tool
 *
 * Lets an Extra Chill team writer work on their own blog draft through Roadie
 * chat: discover the draft, read it, and submit it for editorial review — all
 * targeting MAIN extrachill.com (blog 1), where Studio drafts are born and
 * where editors look for the review queue (extrachill-studio#75, #90).
 *
 * This tool is deliberately small. The heavy lifting — proposing block-level
 * edits as inline accept/reject diff cards — is handled by Data Machine's
 * generic content tools (`get_post_blocks` / `edit_post_blocks` /
 * `replace_post_blocks` / `insert_content`), which Roadie already exposes in
 * `chat` mode. As of data-machine#2669 those tools accept an optional
 * `blog_id`, so the agent edits the draft on main by passing the main site's
 * blog id. The agent-mode guidance (inc/agent-mode/register.php) tells the
 * model to do exactly that.
 *
 * What this tool adds on top:
 *   - `list_drafts`      — the writer's OWN drafts on main (author-scoped).
 *   - `get_draft`        — a single draft's title/status/excerpt on main, plus
 *                          the blog_id to hand to the content tools.
 *   - `submit_for_review`— transition a draft → `pending` on main. Never
 *                          publishes (the team role lacks `publish_posts`, and
 *                          this tool only ever sends `pending`).
 *
 * Cross-site execution reuses the existing `ec_cross_site_rest_request('main', …)`
 * primitive (via ECRoadie_PlatformTool::rest_request) — the same primitive the
 * Studio compose proxy routes (#90) use under the hood. No parallel cross-site
 * mechanism is introduced. Author-scoping is enforced through the calling-user
 * contract from ECRoadie_PlatformTool plus an explicit author check on each
 * draft, so a writer can only touch their own drafts (admins may target
 * another user via the inherited act-on-behalf-of gate).
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRoadie_WritingAssistant extends ECRoadie_PlatformTool {

	protected string $site_key  = 'main';
	protected string $tool_slug = 'writing_assistant';

	public function __construct() {
		$this->registerTool(
			'writing_assistant',
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
			array( 'access_level' => 'author' )
		);
	}

	/**
	 * Tool definition surfaced to the AI.
	 *
	 * @return array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Work on the calling writer\'s own blog draft on the main Extra Chill site. Use "list_drafts" to find their drafts, "get_draft" to read one (returns the blog_id to pass to get_post_blocks/edit_post_blocks when proposing edits), and "submit_for_review" to send a finished draft to the editors (draft → pending). Drafts live on the MAIN site; the actual editing is done with the content tools (get_post_blocks, edit_post_blocks) using the blog_id this tool reports. Never publishes — editorial approval is a human step in wp-admin. Defaults to the calling user; admins may target another writer with user_id.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array(
						'type'        => 'string',
						'description' => 'Action to perform: "list_drafts" (list the user\'s own drafts on main), "get_draft" (read one draft + its blog_id), "submit_for_review" (transition a draft to pending for editorial review).',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Draft post ID on the main site. Required for "get_draft" and "submit_for_review". Omit for "list_drafts".',
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'Target writer\'s user ID. Optional. Defaults to the calling user. Admin-only override.',
					),
				),
				'required'   => array( 'action' ),
			),
		);
	}

	/**
	 * Handle the chat tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		$acting_user_id = $this->resolve_acting_user_id( $parameters );

		$denied = $this->assert_acting_user_allowed( $acting_user_id, $parameters );
		if ( null !== $denied ) {
			return $denied;
		}

		$action = isset( $parameters['action'] ) ? sanitize_key( (string) $parameters['action'] ) : '';

		switch ( $action ) {
			case 'list_drafts':
				return $this->handle_list_drafts( $acting_user_id );
			case 'get_draft':
				return $this->handle_get_draft( $parameters, $acting_user_id );
			case 'submit_for_review':
				return $this->handle_submit_for_review( $parameters, $acting_user_id );
			default:
				return $this->buildErrorResponse(
					'Invalid action "' . $action . '". Use: list_drafts, get_draft, submit_for_review.',
					'writing_assistant'
				);
		}
	}

	/**
	 * List the acting writer's own drafts on the main site.
	 *
	 * Author-scoped: the `author` query param restricts results to the writer's
	 * own posts even when the caller (an admin acting on their behalf) could see
	 * others'. Mirrors extrachill-studio#90's draft picker.
	 *
	 * @param int $acting_user_id Writer whose drafts to list.
	 * @return array
	 */
	private function handle_list_drafts( int $acting_user_id ): array {
		$result = $this->rest_request(
			'GET',
			'/wp/v2/posts',
			array(
				'query'   => array(
					'status'   => 'draft',
					'author'   => $acting_user_id,
					'per_page' => 20,
					'orderby'  => 'modified',
					'order'    => 'desc',
					'context'  => 'edit',
					'_fields'  => 'id,title,status,modified,link',
				),
				'user_id' => $acting_user_id,
			)
		);

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$posts  = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$drafts = array();
		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$drafts[] = array(
				'post_id'  => (int) ( $post['id'] ?? 0 ),
				'title'    => (string) ( $post['title']['rendered'] ?? ( $post['title'] ?? '' ) ),
				'status'   => (string) ( $post['status'] ?? '' ),
				'modified' => (string) ( $post['modified'] ?? '' ),
			);
		}

		if ( empty( $drafts ) ) {
			return $this->buildDiagnosticErrorResponse(
				'You do not have any drafts in progress on the main site yet.',
				'not_found',
				'writing_assistant',
				array( 'user_id' => $acting_user_id ),
				array(
					'action'    => 'Start a draft in Studio',
					'message'   => 'Create a draft in the Studio compose editor first, then come back to edit or submit it.',
					'tool_hint' => 'writing_assistant',
				)
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'blog_id' => $this->main_blog_id(),
				'drafts'  => $drafts,
				'count'   => count( $drafts ),
			),
			'tool_name' => 'writing_assistant',
		);
	}

	/**
	 * Read a single draft on the main site.
	 *
	 * Returns the draft's metadata plus the main `blog_id` so the agent can pass
	 * it to `get_post_blocks` / `edit_post_blocks` to read and propose edits to
	 * the block content. Author-scoped: the writer can only read their own
	 * drafts (admins acting on behalf are gated by the inherited check).
	 *
	 * @param array $parameters     Tool parameters.
	 * @param int   $acting_user_id Writer the draft must belong to.
	 * @return array
	 */
	private function handle_get_draft( array $parameters, int $acting_user_id ): array {
		$post_id = isset( $parameters['post_id'] ) ? absint( $parameters['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return $this->buildErrorResponse(
				'A post_id is required to read a draft. Use "list_drafts" to find one.',
				'writing_assistant'
			);
		}

		$ownership = $this->assert_draft_owned_by( $post_id, $acting_user_id );
		if ( null !== $ownership ) {
			return $ownership;
		}

		$result = $this->rest_request(
			'GET',
			'/wp/v2/posts/' . $post_id,
			array(
				'query'   => array(
					'context' => 'edit',
					'_fields' => 'id,title,status,excerpt,modified,link',
				),
				'user_id' => $acting_user_id,
			)
		);

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$post = is_array( $result['data'] ?? null ) ? $result['data'] : array();

		return array(
			'success'   => true,
			'data'      => array(
				'blog_id'  => $this->main_blog_id(),
				'post_id'  => $post_id,
				'title'    => (string) ( $post['title']['raw'] ?? ( $post['title']['rendered'] ?? '' ) ),
				'status'   => (string) ( $post['status'] ?? '' ),
				'modified' => (string) ( $post['modified'] ?? '' ),
				'hint'     => 'To read or edit the block content, call get_post_blocks / edit_post_blocks with this post_id AND blog_id. Propose edits as a preview (preview=true) so the writer can accept or reject them.',
			),
			'tool_name' => 'writing_assistant',
		);
	}

	/**
	 * Submit a draft for editorial review on the main site.
	 *
	 * Transitions the post draft → `pending` via the same cross-site primitive
	 * the Studio compose proxy uses (extrachill-studio#90). Never sends
	 * `publish`; the `extra_chill_team` role lacks `publish_posts`, and even if
	 * it didn't, this tool only ever requests `pending`. Editorial approval is a
	 * deliberate human step in wp-admin.
	 *
	 * @param array $parameters     Tool parameters.
	 * @param int   $acting_user_id Writer the draft must belong to.
	 * @return array
	 */
	private function handle_submit_for_review( array $parameters, int $acting_user_id ): array {
		$post_id = isset( $parameters['post_id'] ) ? absint( $parameters['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return $this->buildErrorResponse(
				'A post_id is required to submit a draft. Use "list_drafts" to find one.',
				'writing_assistant'
			);
		}

		$ownership = $this->assert_draft_owned_by( $post_id, $acting_user_id );
		if ( null !== $ownership ) {
			return $ownership;
		}

		$result = $this->rest_request(
			'POST',
			'/wp/v2/posts/' . $post_id,
			array(
				'body'    => array( 'status' => 'pending' ),
				'user_id' => $acting_user_id,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$result['message'] = 'Draft submitted for review. It is now in the editorial queue on extrachill.com as a pending post — an editor will review and publish it.';
		}

		return $result;
	}

	/**
	 * Assert that a draft belongs to the acting writer and is still a draft.
	 *
	 * Reads the post on main (switch_to_blog is safe for data reads) and checks
	 * authorship + status. Returns null when the writer owns the draft, or a
	 * standardized error response otherwise. Admins acting on behalf of the
	 * writer have already passed assert_acting_user_allowed(), so the author
	 * comparison here is against the *resolved* acting user — the writer whose
	 * draft is being touched — which is the correct scope in both cases.
	 *
	 * @param int $post_id        Draft post ID on main.
	 * @param int $acting_user_id Expected author.
	 * @return array|null Error response when denied/not-found, null when allowed.
	 */
	private function assert_draft_owned_by( int $post_id, int $acting_user_id ): ?array {
		$main_blog_id = $this->main_blog_id();
		if ( $main_blog_id <= 0 ) {
			return $this->buildErrorResponse(
				'Could not resolve the main site. Ensure extrachill-multisite is active.',
				'writing_assistant'
			);
		}

		switch_to_blog( $main_blog_id );
		$post = get_post( $post_id );
		restore_current_blog();

		if ( ! $post || 'post' !== $post->post_type ) {
			return $this->buildDiagnosticErrorResponse(
				sprintf( 'No draft #%d found on the main site.', $post_id ),
				'not_found',
				'writing_assistant',
				array( 'post_id' => $post_id ),
				array(
					'action'    => 'List your drafts',
					'message'   => 'Use action "list_drafts" to see the drafts you can work on.',
					'tool_hint' => 'writing_assistant',
				)
			);
		}

		if ( (int) $post->post_author !== $acting_user_id ) {
			return $this->buildErrorResponse(
				sprintf( 'Draft #%d does not belong to this writer. You can only work on your own drafts.', $post_id ),
				'writing_assistant'
			);
		}

		if ( 'draft' !== $post->post_status && 'pending' !== $post->post_status ) {
			return $this->buildErrorResponse(
				sprintf( 'Post #%d is "%s", not an editable draft. Roadie only works on drafts awaiting submission.', $post_id, $post->post_status ),
				'writing_assistant'
			);
		}

		return null;
	}

	/**
	 * Resolve the main site's blog ID.
	 *
	 * @return int Main blog ID, or 0 when unresolved.
	 */
	private function main_blog_id(): int {
		$blog_id = $this->get_blog_id( 'main' );
		return $blog_id ? (int) $blog_id : 0;
	}
}
