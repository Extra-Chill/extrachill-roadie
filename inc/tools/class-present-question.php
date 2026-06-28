<?php
/**
 * Present Question Tool
 *
 * Presentational chat tool that lets the agent surface a multiple-choice
 * question to the user as an interactive card. Instead of asking an
 * open-ended question and parsing a free-text reply, the agent calls
 * `present_question` with a question string and a small set of choices;
 * the frontend chat renders each choice as a clickable button. Clicking a
 * choice sends its `message` back to the agent as a normal user turn, so
 * the round-trip is automatic and the agent gets a clean, unambiguous answer.
 *
 * This is a pure presentational tool — it performs no cross-site calls and
 * needs no capability gate beyond being offered in the chat surface. It simply
 * echoes the question and choices back under a `result` key so the chat
 * package's tool-name-agnostic QuestionCard renderer (keyed on the
 * `present_question` tool name) can render them.
 *
 * Result contract consumed by the renderer:
 *   array(
 *     'result' => array(
 *       'question' => '...',
 *       'choices'  => array(
 *         array( 'label' => '...', 'message' => '...', 'description' => '...' ),
 *         ...
 *       ),
 *     ),
 *   )
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_PresentQuestion extends BaseTool {

	protected string $tool_slug = 'present_question';

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'public' )
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
			'description' => 'Present the user with a single multiple-choice question for a genuine branching pick — a discrete one-of-N decision (yes/no, choose one of a few options) that moves the conversation forward. The tool renders the question and every choice as clickable buttons; that rendered card IS your turn for this question (it already shows the question and all options, so the card is the message — there is nothing to add as separate text). Each choice carries a short button label and the message sent back as the user\'s reply when clicked, so a click continues the conversation automatically; the chat input also stays live, so the user can always type a free-form answer instead. Best for a true fork where a small set of options is the natural way to ask. For action-oriented intents, prefer the action tool: when the user wants to file or track something (a GitHub issue, feature request, or bug report), `file_feature_request` is the forward path — it files the issue and surfaces any repo/dedupe choices it needs on its own, so you reach for it directly rather than asking a preliminary question here.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'question', 'choices' ),
				'properties' => array(
					'question'       => array(
						'type'        => 'string',
						'description' => 'The question to present to the user. A single, clear prompt.',
					),
					'choices'        => array(
						'type'        => 'array',
						'description' => 'The available choices, in display order. Keep it to a handful of clear, mutually-exclusive options.',
						'items'       => array(
							'type'       => 'object',
							'required'   => array( 'label', 'message' ),
							'properties' => array(
								'label'       => array(
									'type'        => 'string',
									'description' => 'Short button text shown to the user (e.g. "Yes, proceed").',
								),
								'message'     => array(
									'type'        => 'string',
									'description' => 'The message sent back as the user\'s reply when this choice is clicked. Phrase it as the user speaking (e.g. "Yes, go ahead and update my bio.").',
								),
								'description' => array(
									'type'        => 'string',
									'description' => 'Optional longer explanation of what this choice means, shown alongside the button.',
								),
							),
						),
					),
					'allow_freeform' => array(
						'type'        => 'boolean',
						'description' => 'Optional. When true, signals the UI may also offer a free-text answer alongside the choices.',
					),
				),
			),
		);
	}

	/**
	 * Tool callback.
	 *
	 * Validates the question and choices, then echoes them back under a
	 * `result` key for the QuestionCard renderer. No side effects.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param array<string,mixed> $tool_def   Resolved tool definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		$question = trim( (string) ( $parameters['question'] ?? '' ) );
		if ( '' === $question ) {
			return $this->buildErrorResponse(
				'question is required and must be a non-empty string.',
				$this->tool_slug
			);
		}

		$raw_choices = $parameters['choices'] ?? null;
		if ( ! is_array( $raw_choices ) || array() === $raw_choices ) {
			return $this->buildErrorResponse(
				'choices is required and must be a non-empty array of {label, message, description?} objects.',
				$this->tool_slug
			);
		}

		$choices = array();
		foreach ( $raw_choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$label = trim( (string) ( $choice['label'] ?? '' ) );
			if ( '' === $label ) {
				return $this->buildErrorResponse(
					'Each choice requires a non-empty label.',
					$this->tool_slug
				);
			}

			// Fall back to the label when no explicit reply message is given,
			// so a click always sends something meaningful back.
			$message = trim( (string) ( $choice['message'] ?? '' ) );
			if ( '' === $message ) {
				$message = $label;
			}

			$entry = array(
				'label'   => $label,
				'message' => $message,
			);

			$description = trim( (string) ( $choice['description'] ?? '' ) );
			if ( '' !== $description ) {
				$entry['description'] = $description;
			}

			$choices[] = $entry;
		}

		if ( array() === $choices ) {
			return $this->buildErrorResponse(
				'No valid choices provided. Each choice needs at least a label.',
				$this->tool_slug
			);
		}

		$result = array(
			'question' => $question,
			'choices'  => $choices,
		);

		if ( isset( $parameters['allow_freeform'] ) ) {
			$result['allow_freeform'] = (bool) $parameters['allow_freeform'];
		}

		return array(
			'result' => $result,
		);
	}
}
