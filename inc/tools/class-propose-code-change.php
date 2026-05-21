<?php
/**
 * Propose Code Change Tool
 *
 * Chat tool that dispatches a WP Codebox sandbox to make a bounded code
 * change against the current subsite's stack and open a PR.
 *
 * Flow:
 *   1. Resolve the current subsite context (theme + subsite-specific plugins).
 *   2. Build a recipe (mounts) from the slug → repo map.
 *   3. Invoke `wp-codebox/run-agent-task` with the recipe, the user's
 *      task description, `preview_hold_seconds=900`, and the env var name
 *      for `GITHUB_TOKEN`.
 *   4. Surface the artifact bundle metadata + preview URL back to chat.
 *
 * Per the WP Codebox `secret_env` contract, only the env var NAME is passed
 * in the ability input. The actual token value must be in the parent PHP
 * process environment (typically set via `wp-config.php` `putenv()`).
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_ProposeCodeChange extends BaseTool {

	protected string $tool_slug = 'propose_code_change';

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
			'description' => 'When the user describes a code change they want made to this site (a typo fix, copy change, a small feature, etc.), use this tool to dispatch a sandboxed coding agent that implements the change in an isolated WordPress Playground, captures an artifact bundle, and opens a pull request on GitHub for human review. The user does not need shell access or git knowledge. Only call this when the user explicitly asks for a code change. The PR URL surfaces in the response.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'task_description' ),
				'properties' => array(
					'task_description' => array(
						'type'        => 'string',
						'description' => 'Natural-language description of the code change to make. Include enough context that a coding agent could implement it without further questions — what to change, where (file/component/page if known), and the desired outcome.',
					),
				),
			),
		);
	}

	/**
	 * Tool callback.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Resolved tool definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		// 1. Capability check.
		if ( ! current_user_can( EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ) ) {
			return $this->buildErrorResponse(
				'You do not have permission to propose code changes. Ask an administrator to grant the "extrachill_propose_code" capability.',
				$this->tool_slug
			);
		}

		$task_description = trim( (string) ( $parameters['task_description'] ?? '' ) );
		if ( '' === $task_description ) {
			return $this->buildErrorResponse(
				'task_description is required. Describe the change you want made.',
				$this->tool_slug
			);
		}

		// 2. Verify the wp-codebox ability is available.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse(
				'The WordPress Abilities API is not available on this site. The contribute-code flow requires it.',
				$this->tool_slug
			);
		}

		$ability = wp_get_ability( 'wp-codebox/run-agent-task' );
		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'The `wp-codebox/run-agent-task` ability is not registered. Install and activate the wp-codebox plugin on this network.',
				$this->tool_slug
			);
		}

		// 3. Verify the platform bot token env var is set in the parent process.
		if ( ! extrachill_roadie_github_token_is_present() ) {
			$env_name    = extrachill_roadie_github_token_env_name();
			$option_name = extrachill_roadie_github_token_option_name();
			return $this->buildDiagnosticErrorResponse(
				sprintf(
					'GitHub token is not configured. The env var "%s" must be set in the WordPress PHP process environment (typically via `putenv()` in `wp-config.php` or a `.env` file). The env var name itself can be overridden via the network option "%s" or the `extrachill_roadie_github_token_env_name` filter.',
					$env_name,
					$option_name
				),
				'configuration',
				$this->tool_slug,
				array(),
				array(
					'action'    => 'Configure GitHub token',
					'message'   => sprintf( 'Set %s in wp-config.php with putenv(). See docs/contribute-code.md for the setup walkthrough.', $env_name ),
					'tool_hint' => $this->tool_slug,
				)
			);
		}

		// 4. Detect subsite context + build recipe.
		$context = extrachill_roadie_detect_subsite_context();
		$recipe  = extrachill_roadie_build_recipe( $context, extrachill_roadie_default_repo_map() );

		if ( empty( $recipe['mounts'] ) ) {
			return $this->buildErrorResponse(
				'No editable mounts could be built for this subsite. Check the slug-to-repo map (`extrachill_roadie_repo_map` filter) covers this site\'s theme and plugins.',
				$this->tool_slug
			);
		}

		// 5. Build the goal text the sandboxed agent sees. Include subsite
		// context so the agent knows which subsite triggered the change.
		$goal = $this->build_sandbox_goal( $task_description, $context, $recipe );

		// 6. Invoke the ability.
		$preview_hold = (int) apply_filters( 'extrachill_roadie_preview_hold_seconds', 900 );
		$input        = array(
			'goal'                 => $goal,
			'mounts'               => $recipe['mounts'],
			'secret_env'           => array( extrachill_roadie_github_token_env_name() ),
			'preview_hold_seconds' => max( 0, min( 3600, $preview_hold ) ),
			'expected_artifacts'   => array( 'patch', 'review', 'preview' ),
			'context'              => array(
				'consumer'         => 'extrachill-roadie',
				'subsite'          => array(
					'blog_id'  => (int) ( $context['blog_id'] ?? 0 ),
					'site_url' => (string) ( $context['site_url'] ?? '' ),
				),
				'editable_targets' => $recipe['editable_targets'],
				'unmapped_plugins' => $recipe['unmapped_active_plugins'],
				'proposer_user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
		);

		/**
		 * Filter the input passed to `wp-codebox/run-agent-task`.
		 *
		 * @since 0.7.0
		 *
		 * @param array $input   Ability input.
		 * @param array $context Subsite context.
		 * @param array $recipe  Built recipe.
		 */
		$input = (array) apply_filters( 'extrachill_roadie_propose_code_input', $input, $context, $recipe );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				'Sandbox dispatch failed: ' . $result->get_error_message(),
				$this->tool_slug
			);
		}

		if ( ! is_array( $result ) ) {
			return $this->buildErrorResponse(
				'Unexpected response from wp-codebox/run-agent-task.',
				$this->tool_slug
			);
		}

		return array(
			'success'   => (bool) ( $result['success'] ?? false ),
			'tool_name' => $this->tool_slug,
			'data'      => $this->shape_chat_response( $result, $context, $recipe ),
		);
	}

	/**
	 * Compose the sandbox goal string.
	 *
	 * Prepends a short framing so the sandboxed agent knows which subsite
	 * the change originated from and which mounts are editable.
	 *
	 * @param string $task_description User's natural-language request.
	 * @param array  $context          Subsite context.
	 * @param array  $recipe           Built recipe.
	 * @return string
	 */
	protected function build_sandbox_goal( string $task_description, array $context, array $recipe ): string {
		$lines   = array();
		$lines[] = sprintf(
			'Contribution proposed via roadie chat on %s (blog %d).',
			(string) ( $context['site_url'] ?? 'unknown site' ),
			(int) ( $context['blog_id'] ?? 0 )
		);

		$editable = array_keys( (array) ( $recipe['editable_targets'] ?? array() ) );
		if ( ! empty( $editable ) ) {
			$lines[] = 'Editable components mounted readwrite: ' . implode( ', ', $editable ) . '.';
		}

		$agent_stack = array_keys( (array) ( $recipe['agent_stack_targets'] ?? array() ) );
		if ( ! empty( $agent_stack ) ) {
			$lines[] = 'Agent stack mounted readonly (reference only, do not edit): ' . implode( ', ', $agent_stack ) . '.';
		}

		$lines[] = '';
		$lines[] = 'Task from the proposer:';
		$lines[] = $task_description;
		$lines[] = '';
		$lines[] = 'When the change is implemented, commit with conventional-commit style messages, push the branch, and open a pull request against the appropriate repo (use the `metadata.repo` of the mount you edited). Never edit readonly mounts. Never hand-bump version strings or CHANGELOG.md (homeboy owns both).';

		return implode( "\n", $lines );
	}

	/**
	 * Shape the wp-codebox response into a chat-friendly payload.
	 *
	 * Picks summary, changed files, preview URL, and PR URL out of the
	 * artifact bundle metadata. Whatever the sandbox put in `run` or
	 * `artifacts` is surfaced verbatim under `raw` for debugging.
	 *
	 * @param array $result  Ability result.
	 * @param array $context Subsite context.
	 * @param array $recipe  Recipe.
	 * @return array<string,mixed>
	 */
	protected function shape_chat_response( array $result, array $context, array $recipe ): array {
		$run         = (array) ( $result['run'] ?? array() );
		$artifacts   = $result['artifacts'] ?? '';
		$paths       = (array) ( $result['paths'] ?? array() );

		// Try a few well-known keys the sandbox might publish.
		$preview_url = (string) ( $run['preview_url'] ?? $run['preview']['url'] ?? '' );
		$pr_url      = (string) ( $run['pr_url'] ?? $run['pull_request']['url'] ?? '' );
		$summary     = (string) ( $run['summary'] ?? $run['review']['summary'] ?? '' );
		$changed     = (array) ( $run['changed_files'] ?? $run['review']['changed_files'] ?? array() );

		return array(
			'summary'       => $summary,
			'preview_url'   => $preview_url,
			'pr_url'        => $pr_url,
			'changed_files' => $changed,
			'artifact'      => array(
				'id'             => (string) ( $run['artifact_id'] ?? '' ),
				'artifacts_path' => is_string( $artifacts ) ? $artifacts : '',
				'paths'          => $paths,
			),
			'subsite'       => array(
				'blog_id'  => (int) ( $context['blog_id'] ?? 0 ),
				'site_url' => (string) ( $context['site_url'] ?? '' ),
			),
			'editable_targets' => $recipe['editable_targets'],
			'raw'           => $result,
		);
	}
}
