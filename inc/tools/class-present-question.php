<?php
/**
 * Present Question Tool
 *
 * Presentational chat tool that lets the agent surface a multiple-choice
 * question to the user as an interactive card. The agent calls
 * `present_question` with a question string and a small set of choices;
 * the frontend chat renders each choice as a clickable button. Clicking a
 * choice sends its `message` back to the agent as a normal user turn, so
 * the round-trip is automatic and the agent gets a clean, unambiguous answer.
 *
 * The chat input is ALWAYS available — it is the permanent escape hatch for
 * any answer that isn't one of the choices. There is no "freeform" knob the
 * agent can toggle: a chat box that's always present is not a mode. The tool
 * deliberately exposes no such parameter so the model can never (and need
 * never) reason about whether free text is "allowed."
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
			'description' => 'Present the user with a multiple-choice question rendered as clickable buttons. Use this whenever you need the user to pick from a small, well-defined set of options (a branching choice) instead of asking an open-ended question. Each choice has a short button label and the message that will be sent back as the user\'s reply when they click it. Clicking a choice continues the conversation automatically. Prefer this over free-text questions whenever the answer is genuinely one of a few discrete options.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'question', 'choices' ),
				'properties' => array(
					'question' => array(
						'type'        => 'string',
						'description' => 'The question to present to the user. A single, clear prompt.',
					),
					'choices'  => array(
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

		return array(
			'result' => $result,
		);
	}
}
