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

require_once __DIR__ . '/caller.php';

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
			array( 'roadie' ),
			array(
				'access_level'            => 'authenticated',
				// Bind the per-turn client-context page_url into the `page_url`
				// parameter slot when the model doesn't pass one. inc/frontend-
				// chat.php populates client_context['page_url'] from the widget;
				// the runtime merges it here (caller-supplied value still wins,
				// per WP_Agent_Tool_Parameters::buildParameters) so repo
				// inference can prefer the subsite the user actually had open.
				// Mirrors the exact mechanism inspect_page uses (#58).
				'client_context_bindings' => array( 'page_url' => 'page_url' ),
			)
		);
	}

	/**
	 * Tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'                   => self::class,
			'method'                  => 'handle_tool_call',
			'client_context_bindings' => array( 'page_url' => 'page_url' ),
			'description'             => 'When the user wants to track something on GitHub — a feature request, a bug report, or any "open an issue on github" / "file an issue" / "report a bug" ask — use this tool to file or look up GitHub issues against the appropriate Extra Chill repo. This is ALWAYS the right tool for filing GitHub issues; never use create_taxonomy_term (which makes a category/tag term, not a GitHub issue) for issue/bug-report requests. Three actions are supported: action="file_issue" creates a new issue (requires title, body; repo is optional and auto-inferred from the current subsite when omitted); action="list_recent_issues" finds existing open issues to dedupe against (optional repo/labels/state); action="comment_on_issue" adds a comment to an existing issue (requires issue_number, body; repo optional). When the user is chatting from the subsite that owns the code, leave repo unset and it will be inferred from page context — do not interrogate the user for the repo. Before filing new issues, prefer calling list_recent_issues first with a few keywords from the proposed title to surface duplicates and ask the user whether to comment on an existing thread instead. Use propose_code_change instead when the user wants the change implemented, not just tracked.',
			'parameters'              => array(
				'type'       => 'object',
				'required'   => array( 'action' ),
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'enum'        => array( 'file_issue', 'list_recent_issues', 'comment_on_issue' ),
						'description' => 'Which sub-action to run.',
					),
					'repo'         => array(
						'type'        => 'string',
						'description' => 'GitHub repo in owner/name form (e.g. Extra-Chill/extrachill-roadie). Optional: when omitted, it is auto-inferred from the current subsite via the slug-to-repo registry. Must be present in the registry; cross-org repos are rejected.',
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
					'page_url'     => array(
						'type'        => 'string',
						'description' => 'Optional. The page the user is currently viewing. Normally supplied automatically from chat client context (you do not need to set it); when present it disambiguates which subsite — and therefore which repo — an omitted repo should be inferred against. Pass it explicitly only if you know the user is referring to a different on-network page than the one they are on.',
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
		if ( ! extrachill_roadie_acting_caller_can( $parameters, $cap ) ) {
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

		// 3. Resolve repo: prefer the explicit param, otherwise infer it from
		// the current subsite's context so a team member chatting from
		// e.g. events.extrachill.com doesn't have to name the repo.
		$repo            = trim( (string) ( $parameters['repo'] ?? '' ) );
		$repo_inferred   = false;
		$repo_candidates = array();
		if ( '' === $repo ) {
			// The page the user actually had open (bound from client context
			// via client_context_bindings) is the disambiguating signal for
			// repo inference on the multi-repo main site. Caller-supplied repo
			// still wins (handled by the '' === $repo guard above).
			//
			// Inference picks the single best repo from the FULL candidate set
			// (plugins first, then the active-theme fallback) so theme-only
			// subsites still resolve. The DISAMBIGUATION decision, however, is
			// driven by the PLUGIN-only candidate set: real ambiguity is more
			// than one editable plugin owning the concern, not a plugin shadowed
			// by the shared theme fallback.
			$page_url        = trim( (string) ( $parameters['page_url'] ?? '' ) );
			$inferred        = $this->infer_repo_from_context( $page_url );
			$repo_candidates = $this->plugin_candidate_repos_from_context( $page_url );
			if ( '' !== $inferred ) {
				$repo          = $inferred;
				$repo_inferred = true;
			}
		}

		// 4. Validate repo against the slug-to-repo registry. Inference failure
		// falls through to here, which returns the standard "repo is required"
		// error so the model knows to supply one explicitly.
		$check = $this->validate_repo( $repo );
		if ( true !== $check ) {
			return $check;
		}

		// 5. Verify abilities API + the specific ability we need.
		$ability_check = $this->require_ability( $this->ability_for_action( $action ) );
		if ( true !== $ability_check ) {
			return $ability_check;
		}

		switch ( $action ) {
			case 'file_issue':
				return $this->handle_file_issue( $parameters, $repo, $repo_inferred, $repo_candidates );
			case 'list_recent_issues':
				return $this->handle_list_recent_issues( $parameters, $repo, $repo_inferred );
			case 'comment_on_issue':
				return $this->handle_comment_on_issue( $parameters, $repo, $repo_inferred );
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
	 * Infer the single best target repo from the current subsite's context.
	 *
	 * Thin wrapper over `candidate_repos_from_context()`: returns the first
	 * (highest-priority) candidate, preserving the original single-repo
	 * contract for the file-issue routing path. Returns an empty string when
	 * no subsite slug maps to a registered repo, which lets the caller fall
	 * back to requiring an explicit `repo`.
	 *
	 * @since 0.11.0
	 * @since 0.15.0 Accepts $page_url to prefer the viewed subsite.
	 *
	 * @param string $page_url Optional front-end URL the user was viewing.
	 * @return string `owner/name` repo, or empty string when inference fails.
	 */
	protected function infer_repo_from_context( string $page_url = '' ): string {
		$candidates = $this->candidate_repos_from_context( $page_url );
		return $candidates[0] ?? '';
	}

	/**
	 * Resolve EVERY registered repo the current subsite maps to, in priority
	 * order, driven entirely by the slug-to-repo registry.
	 *
	 * The events subsite is the motivating case: it runs BOTH
	 * `extrachill-events` (venue/discovery/roundup) AND `data-machine-events`
	 * (the front-end Calendar + EventsMap blocks) — so a single inferred repo
	 * cannot represent "this site's code." This method surfaces all mapped
	 * candidates so the caller can present them for disambiguation, while the
	 * read-across-all-components grounding lives in inspect_code (which reads
	 * every subsite-mapped component for the same reason).
	 *
	 * LAYER PURITY (RULES.md): selection is registry-driven. We never name a
	 * plugin in code (no `if ( 'data-machine-events' === $slug )`). The order
	 * comes from (a) the subsite-context detector's plugin order, then (b) the
	 * active theme as a final fallback. Adding a plugin to the map + un-
	 * excluding it from subsite-context detection is sufficient to make it a
	 * candidate — no inference-code change required.
	 *
	 * BLOG RESOLUTION (page-url repo inference): the blog whose context we detect
	 * is preferentially the one that owns the page the user actually had open
	 * (page_url), NOT just the blog the chat request happens to execute on.
	 * The chat widget POSTs to a single REST endpoint, so a team member viewing
	 * events.extrachill.com while the turn runs on blog 1 would otherwise get
	 * blog-1 candidates — the exact ambiguity that forced Roadie to ASK which
	 * repo. Resolving page_url → blog id (pure network topology, see
	 * extrachill_roadie_blog_id_from_page_url) disambiguates without naming a
	 * single plugin. Falls back to the executing blog when no page_url is
	 * supplied or it doesn't resolve to a network site.
	 *
	 * @since 0.13.0
	 * @since 0.15.0 Accepts $page_url to prefer the viewed subsite.
	 *
	 * @param string $page_url Optional front-end URL the user was viewing.
	 * @return string[] Ordered, de-duplicated `owner/name` repos. Empty when
	 *                  no subsite slug maps to a registered repo.
	 */
	protected function candidate_repos_from_context( string $page_url = '' ): array {
		$candidates = $this->plugin_candidate_repos_from_context( $page_url );

		// Fall back to the active theme slug for sites whose distinguishing
		// surface is the theme rather than a plugin. The theme is a FALLBACK,
		// not a peer of the subsite plugins — see plugin_candidate_repos_from_context()
		// for why disambiguation is gated on plugin candidates only.
		if ( ! function_exists( 'extrachill_roadie_detect_subsite_context' )
			|| ! function_exists( 'extrachill_roadie_repo_for_slug' ) ) {
			return $candidates;
		}

		$context    = extrachill_roadie_detect_subsite_context( $this->resolve_context_blog_id( $page_url ) );
		$theme_slug = (string) ( $context['theme']['slug'] ?? '' );
		$theme_repo = extrachill_roadie_repo_for_slug( $theme_slug );
		if ( '' !== $theme_repo && ! in_array( $theme_repo, $candidates, true ) ) {
			$candidates[] = $theme_repo;
		}

		return $candidates;
	}

	/**
	 * Resolve only the PLUGIN-derived candidate repos for the current subsite,
	 * in detector order — excluding the active-theme fallback.
	 *
	 * This is the set that governs genuine multi-component AMBIGUITY. Almost
	 * every subsite also maps an active theme (e.g. the shared `extrachill`
	 * theme), so counting the theme as a peer would make nearly every inferred
	 * file_issue look "ambiguous" and trip disambiguation spuriously. Real
	 * ambiguity is "this subsite runs more than one editable PLUGIN that could
	 * own the concern" (the events site: extrachill-events + data-machine-events).
	 *
	 * Honours the same page_url → blog resolution as candidate_repos_from_context()
	 * so the plugin candidate set reflects the subsite the user had open, not
	 * just the blog the chat turn executes on.
	 *
	 * LAYER PURITY (RULES.md): registry-driven; no plugin name appears here.
	 *
	 * @since 0.14.0
	 * @since 0.15.0 Accepts $page_url to prefer the viewed subsite.
	 *
	 * @param string $page_url Optional front-end URL the user was viewing.
	 * @return string[] Ordered, de-duplicated `owner/name` repos from active
	 *                  subsite plugins. Empty when none map to a registered repo.
	 */
	protected function plugin_candidate_repos_from_context( string $page_url = '' ): array {
		if ( ! function_exists( 'extrachill_roadie_detect_subsite_context' )
			|| ! function_exists( 'extrachill_roadie_repo_for_slug' ) ) {
			return array();
		}

		$context = extrachill_roadie_detect_subsite_context( $this->resolve_context_blog_id( $page_url ) );

		$candidates = array();

		// Every subsite-specific active plugin that maps to a repo, in the
		// detector's order. The detector already excludes network-wide
		// platform boilerplate and agent infra, so the survivors are the
		// genuinely subsite-owning, editable plugins (e.g. on the events site:
		// extrachill-events AND data-machine-events).
		foreach ( (array) ( $context['plugins'] ?? array() ) as $plugin ) {
			$slug = (string) ( $plugin['slug'] ?? '' );
			$repo = extrachill_roadie_repo_for_slug( $slug );
			if ( '' !== $repo && ! in_array( $repo, $candidates, true ) ) {
				$candidates[] = $repo;
			}
		}

		return $candidates;
	}

	/**
	 * Resolve the blog id whose subsite context should drive repo inference.
	 *
	 * Prefers the subsite that owns the page the user actually had open
	 * (page_url → blog id, pure network topology), falling back to the blog the
	 * chat turn is executing on. The chat widget POSTs to a single REST
	 * endpoint, so a team member viewing events.extrachill.com while the turn
	 * runs on blog 1 would otherwise get blog-1 candidates — the exact
	 * ambiguity that forced Roadie to ASK which repo.
	 *
	 * @since 0.15.0
	 *
	 * @param string $page_url Optional front-end URL the user was viewing.
	 * @return int|null Blog id to detect, or null to let the detector default.
	 */
	protected function resolve_context_blog_id( string $page_url = '' ): ?int {
		$blog_id = 0;

		if ( '' !== $page_url && function_exists( 'extrachill_roadie_blog_id_from_page_url' ) ) {
			$blog_id = (int) extrachill_roadie_blog_id_from_page_url( $page_url );
		}

		if ( $blog_id <= 0 ) {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		}

		return $blog_id > 0 ? $blog_id : null;
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
	 * @param array<string,mixed> $parameters      Tool parameters.
	 * @param string              $repo            Validated repo.
	 * @param bool                $repo_inferred   Whether $repo was inferred from context.
	 * @param string[]            $repo_candidates All registered repos the subsite maps to (multi-plugin disambiguation).
	 * @return array<string,mixed>
	 */
	protected function handle_file_issue( array $parameters, string $repo, bool $repo_inferred = false, array $repo_candidates = array() ): array {
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

		// Multi-plugin disambiguation, BEFORE filing. When the repo was
		// inferred (not explicitly supplied) and the subsite maps to more than
		// one editable repo, we can't know which component owns the concern.
		// Rather than file against a guess and then emit a prose "you may have
		// picked wrong" hint (the old post-file behaviour), surface the repo
		// choice as structured choices the QuestionCard renders. The user picks
		// the owning repo (or types a free-text answer), and the next turn
		// re-calls file_issue with an explicit `repo`, which skips this branch
		// entirely (explicit repo => not inferred).
		//
		// LAYER PURITY (RULES.md): candidates come from the registry-driven
		// candidate_repos_from_context(); no plugin name is hardcoded here.
		if ( $repo_inferred && count( $repo_candidates ) > 1 ) {
			return $this->repo_disambiguation_choices( $parameters, $repo_candidates );
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

		$data = array(
			'action'        => 'file_issue',
			'repo'          => $repo,
			'repo_inferred' => $repo_inferred,
			'issue_number'  => $issue_number,
			'issue_url'     => $issue_url,
			'labels'        => $labels,
			'next_step'     => 'If this issue describes a code change rather than just an idea to track, offer to call propose_code_change next so a sandbox can implement it.',
			'raw'           => $result,
		);

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => $data,
		);
	}

	/**
	 * Build a structured repo-disambiguation choice payload.
	 *
	 * Returned BEFORE filing when the repo was inferred on a multi-plugin
	 * subsite. The result carries `question` + `choices` under the same `data`
	 * key the rest of this tool uses, so the chat package's QuestionCard
	 * renderer (parseQuestionPayloadFromToolGroup, which unwraps `result.data`)
	 * picks it up deterministically — no model decision to call
	 * present_question required.
	 *
	 * Each choice's `message` is phrased as the USER speaking: it becomes the
	 * next turn when clicked, instructing the agent to re-file against the
	 * chosen repo (now explicit, so the disambiguation branch is skipped). The
	 * chat input always stays live (allow_freeform defaults true), so the user
	 * can type "none of those" or describe the surface instead.
	 *
	 * LAYER PURITY (RULES.md): the candidate set is the registry-driven
	 * $repo_candidates; no plugin name is named in this method.
	 *
	 * @since 0.14.0
	 *
	 * @param array<string,mixed> $parameters      Original tool parameters (title carried through for the reply message).
	 * @param string[]            $repo_candidates Registry-ordered candidate repos for this subsite.
	 * @return array<string,mixed>
	 */
	protected function repo_disambiguation_choices( array $parameters, array $repo_candidates ): array {
		$title = trim( (string) ( $parameters['title'] ?? '' ) );

		$choices = array();
		foreach ( $repo_candidates as $candidate ) {
			$candidate = (string) $candidate;
			if ( '' === $candidate ) {
				continue;
			}
			// Use just the repo name (after the owner/) for the button label
			// to keep it short; the full owner/name goes in the reply message
			// so the next file_issue call gets an unambiguous explicit repo.
			$short = $candidate;
			$slash = strrpos( $candidate, '/' );
			if ( false !== $slash ) {
				$short = substr( $candidate, $slash + 1 );
			}

			$choices[] = array(
				'label'   => $short,
				'message' => sprintf( 'File it against %s.', $candidate ),
			);
		}

		$question = '' !== $title
			? sprintf( 'This site maps to more than one component. Which repo should "%s" be filed against?', $title )
			: 'This site maps to more than one component. Which repo should this issue be filed against?';

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'          => 'file_issue',
				'status'          => 'awaiting_repo_choice',
				'repo_candidates' => $repo_candidates,
				'question'        => $question,
				'choices'         => $choices,
				'next_step'       => 'The issue was NOT filed yet — this subsite owns more than one editable component. Ask the user which repo owns this concern (the choices above render as clickable buttons), or, if you can ground it yourself, call inspect_code to read the relevant page source and confirm the owning component, then re-call file_issue with an explicit repo.',
			),
		);
	}

	/**
	 * list_recent_issues handler.
	 *
	 * @param array<string,mixed> $parameters    Tool parameters.
	 * @param string              $repo          Validated repo.
	 * @param bool                $repo_inferred Whether $repo was inferred from context.
	 * @return array<string,mixed>
	 */
	protected function handle_list_recent_issues( array $parameters, string $repo, bool $repo_inferred = false ): array {
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

		$data = array(
			'action'        => 'list_recent_issues',
			'repo'          => $repo,
			'repo_inferred' => $repo_inferred,
			'state'         => $state,
			'keywords'      => $keywords,
			'count'         => count( $issues ),
			'issues'        => $issues,
			'next_step'     => 'Compare each issue title against the user\'s intent (and any keywords echoed above). If any look like the same idea, propose commenting on that existing issue with comment_on_issue. Otherwise proceed to file_issue.',
		);

		// When there are candidate duplicates, ALSO surface them as structured
		// choices so the QuestionCard renders deterministically — one
		// "comment on #N" choice per existing issue plus a "file a new issue
		// instead" escape. The raw `issues` array above is kept intact because
		// the model still reasons over it; the structured choices are purely
		// additive. The chat input stays live (allow_freeform defaults true),
		// so the user can type a free-form answer instead of clicking.
		$dedupe_choices = $this->dedupe_choices( $repo, $issues );
		if ( array() !== $dedupe_choices ) {
			$data['question'] = '' !== $keywords
				? sprintf( 'I found some existing issues that might already cover "%s". Want me to add to one of them, or file a new issue?', $keywords )
				: 'I found some existing issues that might already cover this. Want me to add to one of them, or file a new issue?';
			$data['choices']  = $dedupe_choices;
		}

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => $data,
		);
	}

	/**
	 * Build structured dedupe choices from a normalized issue list.
	 *
	 * One choice per existing issue ("Comment on #N: <title>") plus a trailing
	 * "File a new issue instead" escape. Each choice's `message` is phrased as
	 * the USER speaking so it becomes the next turn when clicked: clicking a
	 * "comment" choice steers the agent toward comment_on_issue against that
	 * number; clicking the escape steers it toward file_issue. The chat input
	 * remains the universal override (allow_freeform defaults true).
	 *
	 * Returns an empty array when there are no candidate issues, so the caller
	 * only attaches the question/choices when a real bounded set exists.
	 *
	 * @since 0.14.0
	 *
	 * @param string                         $repo   Validated repo (for an unambiguous reply message).
	 * @param array<int,array<string,mixed>> $issues Normalized issue list.
	 * @return array<int,array<string,string>>
	 */
	protected function dedupe_choices( string $repo, array $issues ): array {
		if ( array() === $issues ) {
			return array();
		}

		$choices = array();
		foreach ( $issues as $issue ) {
			$number = (int) ( $issue['number'] ?? 0 );
			if ( $number <= 0 ) {
				continue;
			}
			$title = trim( (string) ( $issue['title'] ?? '' ) );

			$label = '' !== $title
				? sprintf( 'Comment on #%d: %s', $number, $title )
				: sprintf( 'Comment on #%d', $number );

			$message = '' !== $title
				? sprintf( "That's the same as #%d (\"%s\") in %s — add my note as a comment there instead of filing a new issue.", $number, $title, $repo )
				: sprintf( "That's the same as #%d in %s — add my note as a comment there instead of filing a new issue.", $number, $repo );

			$choices[] = array(
				'label'   => $label,
				'message' => $message,
			);
		}

		if ( array() === $choices ) {
			return array();
		}

		// Trailing escape: none of the existing issues match — file fresh.
		$choices[] = array(
			'label'   => 'File a new issue instead',
			'message' => 'None of those match — go ahead and file a new issue.',
		);

		return $choices;
	}

	/**
	 * comment_on_issue handler.
	 *
	 * @param array<string,mixed> $parameters    Tool parameters.
	 * @param string              $repo          Validated repo.
	 * @param bool                $repo_inferred Whether $repo was inferred from context.
	 * @return array<string,mixed>
	 */
	protected function handle_comment_on_issue( array $parameters, string $repo, bool $repo_inferred = false ): array {
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
				'action'        => 'comment_on_issue',
				'repo'          => $repo,
				'repo_inferred' => $repo_inferred,
				'issue_number'  => $issue_number,
				'comment_url'   => $comment_url,
				'raw'           => $result,
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
		$user_id = extrachill_roadie_resolve_acting_caller( $parameters );

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
