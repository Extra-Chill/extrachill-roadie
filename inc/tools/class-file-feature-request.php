<?php
/**
 * File Feature Request Tool
 *
 * Chat tool that lets team members file GitHub issues against the appropriate
 * Extra Chill repo from inside a Roadie chat. Companion to propose_code_change
 * (PR #13): different intent, lighter touch.
 *
 * Use cases this tool covers:
 *   - "I have an idea for the FAB color" -> file_issue on extrachill-roadie.
 *   - "Did we already file something about this?" -> list_recent_issues.
 *   - "Add a note to that existing issue" -> comment_on_issue.
 *
 * Repo inference reuses `inc/contribute-code/repo-map.php` from PR #13 so the
 * registry stays single-source. Capability gate reuses `extrachill_propose_code`
 * for the same reason — every team member who can propose code should also be
 * able to file ideas, and we don't want a parallel cap to manage.
 *
 * All three actions delegate to existing Data Machine abilities:
 *   - file_issue        -> datamachine/create-github-issue
 *   - list_recent_issues -> datamachine/list-github-issues
 *   - comment_on_issue  -> datamachine/comment-github-issue
 *
 * The tool's job is the chat-facing contract (capability check, repo allowlist
 * against the EC repo map, auto-labels, author attribution) — not the GitHub
 * API itself.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_FileFeatureRequest extends BaseTool {

	protected string $tool_slug = 'file_feature_request';

	/**
	 * Default labels applied to every Roadie-filed issue, before any
	 * action-specific labels the caller passes in.
	 *
	 * Override via the `extrachill_roadie_feature_request_default_labels`
	 * filter.
	 *
	 * @var string[]
	 */
	protected const DEFAULT_LABELS = array( 'roadie-submitted', 'feature-request' );

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	/**
	 * Tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'When the user describes an idea, feature request, or bug observation that should be tracked (rather than implemented right now), use this tool to file or look up GitHub issues against the appropriate Extra Chill repo. Three actions are supported: action="file_issue" creates a new issue (requires repo, title, body); action="list_recent_issues" finds existing open issues to dedupe against (requires repo, optional labels/state); action="comment_on_issue" adds a comment to an existing issue (requires repo, issue_number, body). Before filing new issues, prefer calling list_recent_issues first with a few keywords from the proposed title to surface duplicates and ask the user whether to comment on an existing thread instead. Use propose_code_change instead when the user wants the change implemented, not just tracked.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'action', 'repo' ),
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'enum'        => array( 'file_issue', 'list_recent_issues', 'comment_on_issue' ),
						'description' => 'Which sub-action to run.',
					),
					'repo'         => array(
						'type'        => 'string',
						'description' => 'GitHub repo in owner/name form (e.g. Extra-Chill/extrachill-roadie). Must be present in the slug-to-repo registry; cross-org repos are rejected.',
					),
					'title'        => array(
						'type'        => 'string',
						'description' => 'Issue title. Required for action=file_issue. A concise, action-oriented summary.',
					),
					'body'         => array(
						'type'        => 'string',
						'description' => 'Issue or comment body in GitHub Markdown. Required for file_issue and comment_on_issue. Include enough context that the issue is actionable without the original chat.',
					),
					'issue_number' => array(
						'type'        => 'integer',
						'description' => 'Existing issue number. Required for action=comment_on_issue.',
					),
					'labels'       => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional extra labels to attach to a new issue (only used with file_issue). The default labels "roadie-submitted" and "feature-request" are always applied.',
					),
					'state'        => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed', 'all' ),
						'description' => 'Issue state for list_recent_issues. Defaults to "open".',
					),
					'keywords'     => array(
						'type'        => 'string',
						'description' => 'Free-form keywords for the agent loop to use when judging dedupe overlap with list_recent_issues results. Echoed back in the response to keep the model anchored on the original phrasing; not passed to the GitHub API.',
					),
					'per_page'     => array(
						'type'        => 'integer',
						'description' => 'How many recent issues to fetch with list_recent_issues. Defaults to 20, max 100.',
					),
				),
			),
		);
	}

	/**
	 * Tool callback.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param array<string,mixed> $tool_def   Resolved tool definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		// 1. Capability check — reuse the propose-code cap. Every team member
		// who can propose a code change should also be able to file an idea;
		// keeping these on one cap avoids a parallel grant flow for operators.
		$cap = defined( 'EXTRACHILL_ROADIE_PROPOSE_CODE_CAP' ) ? EXTRACHILL_ROADIE_PROPOSE_CODE_CAP : 'extrachill_propose_code';
		if ( ! current_user_can( $cap ) ) {
			return $this->buildErrorResponse(
				sprintf(
					'You do not have permission to file feature requests. Ask an administrator to grant the "%s" capability.',
					$cap
				),
				$this->tool_slug
			);
		}

		// 2. Validate action.
		$action = trim( (string) ( $parameters['action'] ?? '' ) );
		if ( ! in_array( $action, array( 'file_issue', 'list_recent_issues', 'comment_on_issue' ), true ) ) {
			return $this->buildErrorResponse(
				'action is required and must be one of: file_issue, list_recent_issues, comment_on_issue.',
				$this->tool_slug
			);
		}

		// 3. Validate repo against the slug-to-repo registry.
		$repo  = trim( (string) ( $parameters['repo'] ?? '' ) );
		$check = $this->validate_repo( $repo );
		if ( true !== $check ) {
			return $check;
		}

		// 4. Verify abilities API + the specific ability we need.
		$ability_check = $this->require_ability( $this->ability_for_action( $action ) );
		if ( true !== $ability_check ) {
			return $ability_check;
		}

		switch ( $action ) {
			case 'file_issue':
				return $this->handle_file_issue( $parameters, $repo );
			case 'list_recent_issues':
				return $this->handle_list_recent_issues( $parameters, $repo );
			case 'comment_on_issue':
				return $this->handle_comment_on_issue( $parameters, $repo );
		}

		// Unreachable — action is validated above. Defensive default.
		return $this->buildErrorResponse(
			'Unhandled action: ' . $action,
			$this->tool_slug
		);
	}

	/**
	 * Map an action to its backing Data Machine ability name.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	protected function ability_for_action( string $action ): string {
		switch ( $action ) {
			case 'file_issue':
				return 'datamachine/create-github-issue';
			case 'list_recent_issues':
				return 'datamachine/list-github-issues';
			case 'comment_on_issue':
				return 'datamachine/comment-github-issue';
		}
		return '';
	}

	/**
	 * Validate the requested repo against the EC slug-to-repo registry.
	 *
	 * Cross-org repos and arbitrary repos not in the registry are rejected
	 * here so the GitHub PAT can never be turned into a write surface against
	 * repos the operator never intended to expose.
	 *
	 * @param string $repo Repo in owner/name form.
	 * @return true|array True on success, error response array on failure.
	 */
	protected function validate_repo( string $repo ) {
		if ( '' === $repo ) {
			return $this->buildErrorResponse(
				'repo is required in owner/name form (e.g. Extra-Chill/extrachill-roadie).',
				$this->tool_slug
			);
		}

		if ( ! function_exists( 'extrachill_roadie_default_repo_map' ) ) {
			return $this->buildErrorResponse(
				'The slug-to-repo registry is not loaded. Ensure inc/contribute-code/repo-map.php is required.',
				$this->tool_slug
			);
		}

		$allowed_repos = $this->allowed_repos();
		if ( ! in_array( $repo, $allowed_repos, true ) ) {
			return $this->buildErrorResponse(
				sprintf(
					'Repo "%s" is not in the Extra Chill repo registry. Allowed repos: %s. To add a new repo, extend the `extrachill_roadie_repo_map` filter.',
					$repo,
					implode( ', ', $allowed_repos )
				),
				$this->tool_slug
			);
		}

		return true;
	}

	/**
	 * Flat list of allowed `owner/name` repos derived from the registry.
	 *
	 * Includes agent-stack entries because they are still legitimately
	 * issue-trackable; the registry is the operator's allowlist, not a
	 * write-vs-read distinction.
	 *
	 * @return string[]
	 */
	protected function allowed_repos(): array {
		$repos = array();
		foreach ( extrachill_roadie_default_repo_map() as $entry ) {
			$repo = (string) ( $entry['repo'] ?? '' );
			if ( '' !== $repo ) {
				$repos[] = $repo;
			}
		}
		/**
		 * Filter the flat allowlist of `owner/name` repos accepted by the
		 * file_feature_request tool. Defaults to every repo in the EC
		 * slug-to-repo registry.
		 *
		 * @since 0.8.0
		 *
		 * @param string[] $repos Allowed repos.
		 */
		$repos = (array) apply_filters( 'extrachill_roadie_feature_request_allowed_repos', array_values( array_unique( $repos ) ) );

		return array_values( array_map( 'strval', $repos ) );
	}

	/**
	 * Confirm an ability is loaded and callable.
	 *
	 * @param string $ability_name Fully qualified ability name.
	 * @return true|array True on success, error response on failure.
	 */
	protected function require_ability( string $ability_name ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse(
				'The WordPress Abilities API is not available on this site.',
				$this->tool_slug
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return $this->buildErrorResponse(
				sprintf(
					'The "%s" ability is not registered. Install/activate the Data Machine plugin that provides it.',
					$ability_name
				),
				$this->tool_slug
			);
		}

		return true;
	}

	/**
	 * file_issue handler.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param string              $repo       Validated repo.
	 * @return array<string,mixed>
	 */
	protected function handle_file_issue( array $parameters, string $repo ): array {
		$title = trim( (string) ( $parameters['title'] ?? '' ) );
		$body  = (string) ( $parameters['body'] ?? '' );

		if ( '' === $title ) {
			return $this->buildErrorResponse(
				'title is required for action=file_issue.',
				$this->tool_slug
			);
		}

		if ( '' === trim( $body ) ) {
			return $this->buildErrorResponse(
				'body is required for action=file_issue. Include enough context that the issue is actionable without the original chat.',
				$this->tool_slug
			);
		}

		// Merge caller-supplied labels with the defaults. Defaults always win
		// on dedupe so we never lose `roadie-submitted` / `feature-request`.
		$caller_labels  = array_values(
			array_filter(
				array_map( 'strval', (array) ( $parameters['labels'] ?? array() ) ),
				static function ( string $label ): bool {
					return '' !== trim( $label );
				}
			)
		);
		$default_labels = (array) apply_filters(
			'extrachill_roadie_feature_request_default_labels',
			self::DEFAULT_LABELS
		);
		$labels         = array_values(
			array_unique(
				array_merge(
					array_map( 'strval', $default_labels ),
					$caller_labels
				)
			)
		);

		$augmented_body = $this->augment_body_with_attribution( $body, $parameters );

		$input = array(
			'repo'   => $repo,
			'title'  => $title,
			'body'   => $augmented_body,
			'labels' => $labels,
		);

		/**
		 * Filter the input passed to `datamachine/create-github-issue`.
		 *
		 * @since 0.8.0
		 *
		 * @param array  $input      Ability input.
		 * @param array  $parameters Original tool parameters.
		 * @param string $repo       Validated repo.
		 */
		$input = (array) apply_filters( 'extrachill_roadie_file_issue_input', $input, $parameters, $repo );

		$result = wp_get_ability( 'datamachine/create-github-issue' )->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				'GitHub issue creation failed: ' . $result->get_error_message(),
				$this->tool_slug
			);
		}

		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse(
				'Unexpected response shape from datamachine/create-github-issue.',
				$this->tool_slug
			);
		}

		$issue_url    = (string) ( $result['html_url'] ?? $result['url'] ?? '' );
		$issue_number = isset( $result['number'] ) ? (int) $result['number'] : 0;

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'       => 'file_issue',
				'repo'         => $repo,
				'issue_number' => $issue_number,
				'issue_url'    => $issue_url,
				'labels'       => $labels,
				'next_step'    => 'If this issue describes a code change rather than just an idea to track, offer to call propose_code_change next so a sandbox can implement it.',
				'raw'          => $result,
			),
		);
	}

	/**
	 * list_recent_issues handler.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param string              $repo       Validated repo.
	 * @return array<string,mixed>
	 */
	protected function handle_list_recent_issues( array $parameters, string $repo ): array {
		$state    = (string) ( $parameters['state'] ?? 'open' );
		if ( ! in_array( $state, array( 'open', 'closed', 'all' ), true ) ) {
			$state = 'open';
		}

		$per_page = (int) ( $parameters['per_page'] ?? 20 );
		$per_page = max( 1, min( 100, $per_page ) );

		$input = array(
			'repo'     => $repo,
			'state'    => $state,
			'per_page' => $per_page,
		);

		/**
		 * Filter the input passed to `datamachine/list-github-issues`.
		 *
		 * @since 0.8.0
		 *
		 * @param array  $input      Ability input.
		 * @param array  $parameters Original tool parameters.
		 * @param string $repo       Validated repo.
		 */
		$input = (array) apply_filters( 'extrachill_roadie_list_issues_input', $input, $parameters, $repo );

		$result = wp_get_ability( 'datamachine/list-github-issues' )->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				'GitHub issue list failed: ' . $result->get_error_message(),
				$this->tool_slug
			);
		}

		// Normalize the issue list down to the fields the model needs for
		// dedupe judgment without dragging the entire GitHub API payload
		// through the chat context window.
		$issues_raw = is_array( $result ) ? $result : array();
		$issues     = array();
		foreach ( $issues_raw as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			// GitHub returns PRs in the issues endpoint too; PRs carry
			// `pull_request`. Skip them so dedupe only compares issue-to-issue.
			if ( isset( $issue['pull_request'] ) ) {
				continue;
			}
			$issues[] = array(
				'number'     => (int) ( $issue['number'] ?? 0 ),
				'title'      => (string) ( $issue['title'] ?? '' ),
				'state'      => (string) ( $issue['state'] ?? '' ),
				'url'        => (string) ( $issue['html_url'] ?? '' ),
				'labels'     => $this->normalize_labels( $issue['labels'] ?? array() ),
				'updated_at' => (string) ( $issue['updated_at'] ?? '' ),
			);
		}

		$keywords = trim( (string) ( $parameters['keywords'] ?? '' ) );

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'    => 'list_recent_issues',
				'repo'      => $repo,
				'state'     => $state,
				'keywords'  => $keywords,
				'count'     => count( $issues ),
				'issues'    => $issues,
				'next_step' => 'Compare each issue title against the user\'s intent (and any keywords echoed above). If any look like the same idea, propose commenting on that existing issue with comment_on_issue. Otherwise proceed to file_issue.',
			),
		);
	}

	/**
	 * comment_on_issue handler.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param string              $repo       Validated repo.
	 * @return array<string,mixed>
	 */
	protected function handle_comment_on_issue( array $parameters, string $repo ): array {
		$issue_number = (int) ( $parameters['issue_number'] ?? 0 );
		if ( $issue_number <= 0 ) {
			return $this->buildErrorResponse(
				'issue_number is required for action=comment_on_issue and must be a positive integer.',
				$this->tool_slug
			);
		}

		$body = (string) ( $parameters['body'] ?? '' );
		if ( '' === trim( $body ) ) {
			return $this->buildErrorResponse(
				'body is required for action=comment_on_issue.',
				$this->tool_slug
			);
		}

		$augmented_body = $this->augment_body_with_attribution( $body, $parameters );

		$input = array(
			'repo'         => $repo,
			'issue_number' => $issue_number,
			'body'         => $augmented_body,
		);

		/**
		 * Filter the input passed to `datamachine/comment-github-issue`.
		 *
		 * @since 0.8.0
		 *
		 * @param array  $input      Ability input.
		 * @param array  $parameters Original tool parameters.
		 * @param string $repo       Validated repo.
		 */
		$input = (array) apply_filters( 'extrachill_roadie_comment_issue_input', $input, $parameters, $repo );

		$result = wp_get_ability( 'datamachine/comment-github-issue' )->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				'GitHub issue comment failed: ' . $result->get_error_message(),
				$this->tool_slug
			);
		}

		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse(
				'Unexpected response shape from datamachine/comment-github-issue.',
				$this->tool_slug
			);
		}

		$comment_url = (string) ( $result['html_url'] ?? $result['url'] ?? '' );

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'       => 'comment_on_issue',
				'repo'         => $repo,
				'issue_number' => $issue_number,
				'comment_url'  => $comment_url,
				'raw'          => $result,
			),
		);
	}

	/**
	 * Add a "Filed via Roadie chat by <user>" footer to issue/comment bodies.
	 *
	 * Priority order for proposer identity (post #8):
	 *   1. `$parameters['calling_user_id']` — the human on whose behalf the
	 *      agent loop is running (set by `ChatOrchestrator` and propagated
	 *      through `ToolParameters::buildParameters` per the
	 *      `datamachine_get_calling_user_id()` contract).
	 *   2. `get_current_user_id()` — fallback when the tool is invoked
	 *      outside an agent loop (CLI, smoke test, direct REST).
	 *
	 * @param string $body       Original body.
	 * @param array  $parameters Tool parameters (may carry `calling_user_id`).
	 * @return string Augmented body.
	 */
	protected function augment_body_with_attribution( string $body, array $parameters = array() ): string {
		$user_id = 0;

		if ( function_exists( 'datamachine_get_calling_user_id' ) ) {
			$user_id = (int) datamachine_get_calling_user_id( $parameters );
		} elseif ( isset( $parameters['calling_user_id'] ) ) {
			$user_id = (int) $parameters['calling_user_id'];
		}

		if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
		}

		$username = '';
		$display  = '';

		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$username = (string) ( $user->user_login ?? '' );
				$display  = (string) ( $user->display_name ?? '' );
			}
		}

		$site_url = function_exists( 'home_url' ) ? (string) home_url() : '';

		$attribution_lines   = array( '', '---', '' );
		$attribution_lines[] = '_Filed via Roadie chat._';

		if ( '' !== $username ) {
			$attribution_lines[] = sprintf(
				'_Proposer: %s%s (WP user #%d)._',
				$display !== '' ? $display . ' ' : '',
				$username !== '' ? '(`' . $username . '`)' : '',
				$user_id
			);
		} elseif ( $user_id > 0 ) {
			$attribution_lines[] = sprintf( '_Proposer: WP user #%d._', $user_id );
		}

		if ( '' !== $site_url ) {
			$attribution_lines[] = sprintf( '_Subsite: %s._', $site_url );
		}

		/**
		 * Filter the attribution footer lines appended to filed issue and
		 * comment bodies. Return an empty array to suppress the footer
		 * entirely.
		 *
		 * @since 0.8.0
		 *
		 * @param string[] $attribution_lines Footer lines (already prefixed with separator).
		 * @param int      $user_id           Current WP user id.
		 * @param string   $username          Current WP user login.
		 */
		$attribution_lines = (array) apply_filters(
			'extrachill_roadie_feature_request_attribution_lines',
			$attribution_lines,
			$user_id,
			$username
		);

		if ( empty( $attribution_lines ) ) {
			return $body;
		}

		return rtrim( $body ) . "\n" . implode( "\n", array_map( 'strval', $attribution_lines ) );
	}

	/**
	 * Normalize GitHub issue labels to a flat string list.
	 *
	 * GitHub returns labels as an array of objects (`['name' => '...']`) when
	 * fetched via the issues endpoint; some adapters flatten them already.
	 * Handle both shapes.
	 *
	 * @param mixed $labels Raw labels value.
	 * @return string[]
	 */
	protected function normalize_labels( $labels ): array {
		if ( ! is_array( $labels ) ) {
			return array();
		}
		$out = array();
		foreach ( $labels as $label ) {
			if ( is_string( $label ) ) {
				$out[] = $label;
				continue;
			}
			if ( is_array( $label ) && isset( $label['name'] ) ) {
				$out[] = (string) $label['name'];
				continue;
			}
			if ( is_object( $label ) && isset( $label->name ) ) {
				$out[] = (string) $label->name;
			}
		}
		return array_values( array_filter( $out, static fn( $l ) => '' !== $l ) );
	}
}
