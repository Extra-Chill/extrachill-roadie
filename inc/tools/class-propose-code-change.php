<?php
/**
 * Propose Code Change Tool
 *
 * Chat tool that dispatches a WP Codebox sandbox to make a bounded code
 * change against the current subsite's stack. The sandbox produces an
 * artifact bundle (patch.diff + review.json + preview URL). It does NOT
 * push code or open a PR — that's the apply_code_change tool's job, gated
 * on explicit human approval in chat.
 *
 * Flow:
 *   1. Capability check (extrachill_propose_code).
 *   2. Detect subsite context.
 *   3. Build recipe (mounts from /var/lib/datamachine/workspace/<repo>).
 *   4. Invoke wp-codebox/run-agent-task with:
 *       - the recipe mounts
 *       - inherit.connectors=['openai'] (resolves provider/model/secret_env
 *         via our wp_codebox_resolve_inheritance filter)
 *       - preview_hold_seconds=900
 *   5. Return artifact_id + preview_url + summary + changed_files to chat
 *      so the user can review and explicitly approve.
 *
 * No GitHub token, no git operations, no PR. That all lives in
 * apply_code_change.
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Support\GitHubCredentialResolver;

class ECRoadie_ProposeCodeChange extends BaseTool {

	protected string $tool_slug = 'propose_code_change';

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'roadie' ),
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
			'description' => 'When the user describes a code change they want made to this site (a typo fix, copy change, a small feature, etc.), use this tool to dispatch a sandboxed coding agent that implements the change in an isolated WordPress Playground. The sandbox produces a reviewable patch artifact and a live preview URL — it does NOT push code or open a pull request. After this tool returns, surface the preview URL and summary to the user and ask them to approve. When the user approves, call apply_code_change with the returned artifact_id to commit the change and open a PR.',
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

		// Fail fast if GitHub credentials are not configured. There is no
		// point dispatching an expensive sandbox run when the eventual
		// apply-back step cannot push or open a PR.
		if ( ! $this->resolver_is_configured() ) {
			return $this->buildErrorResponse(
				'GitHub credentials are not configured. The apply-back step resolves a token per repo via the Data Machine credential profile system. Verify with `wp --allow-root --path=/var/www/extrachill.com datamachine-code github status`, and configure via the `datamachine/update-settings` ability with `github_credential_profiles` + `github_default_profile_id`.',
				$this->tool_slug
			);
		}

		// 3. Detect subsite context + build recipe.
		$context = extrachill_roadie_detect_subsite_context();
		$recipe  = extrachill_roadie_build_recipe( $context, extrachill_roadie_default_repo_map() );

		if ( empty( $recipe['mounts'] ) ) {
			$missing = $recipe['missing_clones'] ?? array();
			$detail  = '';
			if ( ! empty( $missing ) ) {
				$detail = ' Missing workspace clones for: ' . implode( ', ', $missing ) . '. Run `wp datamachine-code workspace clone <repo>` for each.';
			}
			return $this->buildErrorResponse(
				'No editable mounts could be built for this subsite.' . $detail . ' Check the slug-to-repo map (`extrachill_roadie_repo_map` filter) covers this site\'s theme and plugins.',
				$this->tool_slug
			);
		}

		// 4. Build the sandbox goal.
		$goal = $this->build_sandbox_goal( $task_description, $context, $recipe );

		// 5. Invoke the ability.
		$preview_hold = (int) apply_filters( 'extrachill_roadie_preview_hold_seconds', 900 );
		$connectors   = (array) apply_filters( 'extrachill_roadie_inherit_connector_names', array( 'openai' ) );

		$input = array(
			'goal'                 => $goal,
			'mounts'               => $recipe['mounts'],
			'inherit'              => array(
				// Connector names resolved by our wp_codebox_resolve_inheritance
				// filter. The filter populates provider/model/secretEnv and
				// putenvs credential values on the host PHP process.
				'connectors' => array_values( array_filter( array_map( 'strval', $connectors ) ) ),
			),
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
	 * The sandbox agent has no other context than what we put here, so be
	 * explicit about: which subsite triggered the change, which mounts are
	 * editable, the user's task, and the hard constraints (don't push, don't
	 * touch CHANGELOG/versions, conventional commits).
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

		$lines[] = '';
		$lines[] = 'Task from the proposer:';
		$lines[] = $task_description;
		$lines[] = '';
		$lines[] = 'Implement the change against the editable mounts only. Do not push code, do not open a pull request — those happen on the host after a human approves your patch. Never edit CHANGELOG.md, never hand-bump version strings (homeboy owns both). Use conventional commit message styling in any commit messages you author (fix:, feat:, refactor:, etc.) even though the actual commit will be made by the apply-back tool.';

		return implode( "\n", $lines );
	}

	/**
	 * Shape the wp-codebox response into a chat-friendly payload.
	 *
	 * Returns artifact_id, preview_url, summary, changed_files, and the list
	 * of mounts that actually had changes — enough for chat to render a
	 * "ready for review" message with an explicit approve step that hands
	 * artifact_id to apply_code_change.
	 *
	 * @param array $result  Ability result.
	 * @param array $context Subsite context.
	 * @param array $recipe  Recipe.
	 * @return array<string,mixed>
	 */
	protected function shape_chat_response( array $result, array $context, array $recipe ): array {
		$run         = (array) ( $result['run'] ?? array() );
		$artifacts   = $result['artifacts'] ?? '';

		// wp-codebox publishes preview URL under run.artifacts.preview.url
		// per its README; we also accept a few fallback shapes.
		$preview_url = (string) (
			$run['artifacts']['preview']['url']
				?? $run['preview']['url']
				?? $run['preview_url']
				?? ''
		);

		$artifact_id = (string) (
			$run['artifact']['id']
				?? $run['artifact_id']
				?? $run['manifest']['id']
				?? ''
		);

		$review      = (array) ( $run['artifact']['review'] ?? $run['review'] ?? array() );
		$summary     = (string) ( $review['summary'] ?? $run['summary'] ?? '' );
		$changed     = (array) ( $review['changed_files']
			?? $run['changed_files']
			?? $run['artifact']['changed_files']
			?? array() );

		return array(
			'status'           => 'pending-approval',
			'artifact_id'      => $artifact_id,
			'preview_url'      => $preview_url,
			'summary'          => $summary,
			'changed_files'    => $changed,
			'subsite'          => array(
				'blog_id'  => (int) ( $context['blog_id'] ?? 0 ),
				'site_url' => (string) ( $context['site_url'] ?? '' ),
			),
			'editable_targets' => $recipe['editable_targets'],
			'next_step'        => sprintf(
				'Review the changes at the preview URL. If they look good, call apply_code_change with artifact_id="%s" to commit and open a pull request. To discard, call wp-codebox/discard-artifact.',
				$artifact_id
			),
			'artifact'         => array(
				'id'             => $artifact_id,
				'artifacts_path' => is_string( $artifacts ) ? $artifacts : '',
			),
			'raw'              => $result,
		);
	}

	/**
	 * Test seam: ask the resolver whether GitHub auth is configured at all.
	 *
	 * @return bool
	 */
	protected function resolver_is_configured(): bool {
		return GitHubCredentialResolver::isConfigured();
	}
}
