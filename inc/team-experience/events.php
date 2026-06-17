<?php
/**
 * Roadie Team Experience Analytics Events
 *
 * Emit helper + hook wiring for Roadie usage analytics events.
 *
 * SHARED EVENT CONTRACT (Extra-Chill/extrachill-users#127)
 * --------------------------------------------------------
 * The team-experience instrumentation spans three plugins
 * (extrachill-users, extrachill-studio, extrachill-roadie). The event
 * NAMES and payload shape are a shared contract defined once and reused
 * verbatim at every emit site, so the team-cohort rollup in
 * extrachill-users (`extrachill/get-team-experience-stats`) can join the
 * events against the `extra_chill_team` role on `user_id`.
 *
 * Event types emitted by extrachill-roadie:
 *   - roadie_session_started  (first turn of a roadie frontend-chat session)
 *   - roadie_tool_invoked     (a roadie platform tool executes)
 *
 * Payload convention: every event carries a `user_id` key in event_data
 * identifying the SUBJECT (the user driving the chat). Roadie passes it
 * explicitly because the analytics table records the WP current user,
 * which in agent/runtime contexts can differ from the calling user.
 *
 * All emits route through the existing `extrachill/track-analytics-event`
 * ability — never write the analytics table directly.
 *
 * @package ExtraChillRoadie\TeamExperience
 * @since   0.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit a Roadie team-experience analytics event via the canonical ability.
 *
 * No-op (returns 0) when the analytics ability is unavailable.
 *
 * @param string $event_type Event type identifier from the shared contract.
 * @param int    $user_id    Subject user ID (the calling user). 0 when none.
 * @param array  $extra      Optional additional payload keys merged into event_data.
 * @return int Event ID on success, 0 on failure / when unavailable.
 */
function extrachill_roadie_emit_team_experience_event( string $event_type, int $user_id, array $extra = array() ): int {
	if ( '' === $event_type ) {
		return 0;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return 0;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return 0;
	}

	$event_data = array_merge( array( 'user_id' => $user_id ), $extra );

	$result = $ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => $event_data,
		)
	);

	return is_int( $result ) ? $result : 0;
}

/**
 * The full set of Roadie tool slugs.
 *
 * Roadie's write-capable platform tools (the managed set) plus the
 * conversational `present_question` tool. Used to scope the global Data
 * Machine tool-audit hook down to Roadie's own tools, so we never count a
 * tool invocation from some other Data Machine agent as a Roadie event.
 *
 * @since 0.13.0
 * @return string[]
 */
function extrachill_roadie_all_tool_slugs(): array {
	$slugs = function_exists( 'extrachill_roadie_managed_tool_slugs' )
		? extrachill_roadie_managed_tool_slugs()
		: array();

	$slugs[] = 'present_question';

	return array_values( array_unique( $slugs ) );
}

/**
 * Emit roadie_session_started on the first turn of a roadie chat session.
 *
 * Hooks the roadie-scoped frontend-chat input filter (which only fires for
 * the roadie agent). The Frontend Agent Chat REST handler sends an empty
 * `session_id` on the first turn of a conversation and a populated one on
 * every continuation, so an empty session_id is the reliable "new session"
 * signal. Pass-through filter — returns $chat_input unchanged.
 *
 * @since 0.13.0
 *
 * @param mixed  $chat_input Canonical agents/chat input.
 * @param mixed  $request    REST request.
 * @param string $agent_slug Selected agent slug.
 * @param array  $config     Frontend chat configuration.
 * @return mixed The unmodified $chat_input.
 */
function extrachill_roadie_emit_session_started( $chat_input, $request, string $agent_slug, array $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $config is required by the 4-arg frontend_agent_chat input filter signature.
	if ( ! is_array( $chat_input ) ) {
		return $chat_input;
	}

	if ( EXTRACHILL_ROADIE_AGENT_SLUG !== sanitize_title( $agent_slug ) ) {
		return $chat_input;
	}

	$session_id = isset( $chat_input['session_id'] ) ? (string) $chat_input['session_id'] : '';
	if ( '' !== $session_id ) {
		// Continuation turn — the session already started.
		return $chat_input;
	}

	$user_id = (int) get_current_user_id();

	extrachill_roadie_emit_team_experience_event( 'roadie_session_started', $user_id );

	return $chat_input;
}
add_filter( 'frontend_agent_chat_chat_input', 'extrachill_roadie_emit_session_started', 20, 4 );
add_filter( 'frontend_agent_chat_queue_input', 'extrachill_roadie_emit_session_started', 20, 4 );

/**
 * Emit roadie_tool_invoked when a Roadie platform tool executes.
 *
 * Hooks Data Machine's generic `datamachine_tool_execution_audit` action —
 * the canonical per-tool-execution seam — and scopes it to Roadie's own
 * tools so other agents' tool calls are ignored. The acting user id is read
 * from the audit context's principal summary.
 *
 * @since 0.13.0
 *
 * @param array $audit_context Safe audit context from the tool executor.
 * @return void
 */
function extrachill_roadie_emit_tool_invoked( $audit_context ): void {
	if ( ! is_array( $audit_context ) ) {
		return;
	}

	$tool_name = isset( $audit_context['tool_name'] ) ? (string) $audit_context['tool_name'] : '';
	if ( '' === $tool_name ) {
		return;
	}

	if ( ! in_array( $tool_name, extrachill_roadie_all_tool_slugs(), true ) ) {
		return;
	}

	$principal = isset( $audit_context['principal_context'] ) && is_array( $audit_context['principal_context'] )
		? $audit_context['principal_context']
		: array();
	$user_id   = isset( $principal['acting_user_id'] ) ? (int) $principal['acting_user_id'] : 0;

	$extra = array( 'tool' => $tool_name );
	if ( isset( $audit_context['result_status'] ) ) {
		$extra['result_status'] = (string) $audit_context['result_status'];
	}

	extrachill_roadie_emit_team_experience_event( 'roadie_tool_invoked', $user_id, $extra );
}
add_action( 'datamachine_tool_execution_audit', 'extrachill_roadie_emit_tool_invoked', 10, 1 );
