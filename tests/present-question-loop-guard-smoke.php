<?php
/**
 * Smoke test: present_question loop guidance is positively framed.
 *
 * Regression guard for the production failure where Roadie called
 * `present_question` repeatedly for a single "open an issue" intent and never
 * called `file_feature_request` (chat sessions 8b6eb0f8 and e98fabab, user
 * qrisg / Chris Gardner, 2026-06-19 / 2026-06-28).
 *
 * The fix is STRUCTURE + POSITIVE FRAMING, not prohibitions. Chris's directive:
 * do not steer the model with negative "do NOT / MUST / NEVER" constraints — a
 * model that ignored the soft "file it" rule ignores a louder "don't" the same
 * way. So this test asserts the *opposite* of the first attempt:
 *
 *   1. The roadie mode directive frames filing as the AFFIRMATIVE goal-state
 *      (a filed issue + URL is the deliverable; file then refine via comment),
 *      and describes `present_question` by what it IS for (a genuine one-off
 *      branching pick) — NOT by what it must not do.
 *   2. The directive carries NO negative-prohibition scaffolding for this
 *      behavior ("CRITICAL USAGE LIMITS", "you MUST call", "NEVER use",
 *      "present_question is NOT a filing tool", "you are never allowed").
 *   3. The `present_question` tool description states the card contract
 *      descriptively (the rendered card is the turn) and points filing intent
 *      at `file_feature_request` as the forward path — again, positively.
 *
 * The durable structural guardrail (gating present_question after N calls per
 * filing intent) is blocked on data-machine#2813 — the per-turn tool-visibility
 * seam isn't given conversation history. Until that lands, positive framing is
 * the mitigation, and this test locks the framing in.
 *
 * Run with: php tests/present-question-loop-guard-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/_stub-agent-mode-registry.php';
require_once __DIR__ . '/_stub-base-tool.php';

$GLOBALS['ec_roadie_test_actions']          = array();
$GLOBALS['ec_roadie_test_filters']          = array();
$GLOBALS['ec_roadie_test_registered_tools'] = array();

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ec_roadie_test_actions'][ $hook ][] = $callback;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ec_roadie_test_filters'][ $hook ][] = $callback;
}

function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['ec_roadie_test_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ec_roadie_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function __( $text, $domain = null ): string {
	unset( $domain );
	return (string) $text;
}

// Grant the team capability so the team-tier guidance (which carries the filing
// section + the present_question posture bullet) is the branch under test.
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability, ...$args ): bool {
		unset( $user, $args );
		return 'access_roadie' === $capability;
	}
}

function ec_roadie_loop_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/permissions.php';
require_once dirname( __DIR__ ) . '/inc/agent-mode/register.php';

do_action( 'datamachine_agent_modes' );

// --- 1. Mode directive (team tier): filing is the AFFIRMATIVE goal ---------

$guidance = apply_filters( 'datamachine_agent_mode_roadie', '', array( 'calling_user_id' => 38 ) );
$lower    = strtolower( $guidance );

// Filing is framed as the goal-state / deliverable, not a boundary.
ec_roadie_loop_assert(
	str_contains( $lower, 'goal' ) && str_contains( $lower, 'filed' ),
	'Filing guidance must frame a filed issue as the goal-state.'
);
ec_roadie_loop_assert(
	str_contains( $lower, 'file_feature_request' ) && str_contains( $lower, 'forward path' ),
	'Filing guidance must name file_feature_request as the forward path.'
);
// File first, refine via comment afterward — the positive shape that replaces
// "do not loop present_question".
ec_roadie_loop_assert(
	str_contains( $lower, 'comment_on_issue' ) && str_contains( $lower, 'refine' ),
	'Filing guidance must point refinement to comment_on_issue after the issue exists.'
);
// The tool surfaces its own repo/dedupe choices (so the model does not author a
// preliminary present_question card before filing).
ec_roadie_loop_assert(
	( str_contains( $lower, 'repo' ) || str_contains( $lower, 'dedupe' ) )
		&& str_contains( $lower, 'choices' )
		&& str_contains( $lower, 'file_feature_request' ),
	'Filing guidance must note file_feature_request surfaces its own repo/dedupe choices.'
);

// present_question is described by what it IS for (a genuine one-off pick).
ec_roadie_loop_assert(
	str_contains( $lower, 'one-off' ) || str_contains( $lower, 'genuine' ),
	'Guidance must describe present_question as a genuine one-off branching pick (positive framing).'
);

// --- 2. NO negative-prohibition scaffolding for this behavior --------------

$banned_phrases = array(
	'critical usage limits',
	'you must call',
	'never use `present_question`',
	'present_question is not a filing tool',
	'present_question` is not a filing tool',
	'never allowed to ask a third',
	'do not loop',
	'do not call this repeatedly',
);
foreach ( $banned_phrases as $phrase ) {
	ec_roadie_loop_assert(
		! str_contains( $lower, $phrase ),
		sprintf( 'Guidance must not steer with the negative prohibition "%s" (use positive framing instead).', $phrase )
	);
}

// The card contract is stated as a FACT (the card is the turn), not as a ban on
// restating prose.
ec_roadie_loop_assert(
	str_contains( $lower, 'card is the message' ) || str_contains( $lower, 'card is your message' ) || str_contains( $lower, 'whole turn' ),
	'Guidance must state the card contract descriptively (the rendered card is the turn).'
);

// --- 3. present_question tool description: descriptive + forward path -------

require_once dirname( __DIR__ ) . '/inc/tools/class-present-question.php';

$tool       = new ECRoadie_PresentQuestion();
$registered = $GLOBALS['ec_roadie_test_registered_tools']['present_question'] ?? null;
ec_roadie_loop_assert( is_array( $registered ), 'present_question tool should register itself.' );

$definition = ( $registered['definition'] )();
$desc       = (string) ( $definition['description'] ?? '' );
$desc_lower = strtolower( $desc );

// Descriptive card contract — the rendered card is the turn (not "do not restate").
ec_roadie_loop_assert(
	str_contains( $desc_lower, 'card is the message' ) || str_contains( $desc_lower, 'card is your turn' ) || str_contains( $desc_lower, 'is your turn' ),
	'present_question description must state the card-is-the-turn contract descriptively.'
);
// Points filing intent at file_feature_request as the forward path (positive).
ec_roadie_loop_assert(
	str_contains( $desc_lower, 'file_feature_request' ) && str_contains( $desc_lower, 'forward path' ),
	'present_question description must point filing intent to file_feature_request as the forward path.'
);
// No negative scaffolding on the tool either.
ec_roadie_loop_assert(
	! str_contains( $desc_lower, 'critical usage limits' ) && ! str_contains( $desc_lower, 'do not call this repeatedly' ),
	'present_question description must not use negative "CRITICAL USAGE LIMITS / do not call repeatedly" framing.'
);

// The tool still functions — emitting it returns the question + choices intact.
$result = $tool->handle_tool_call(
	array(
		'question' => 'Pick one',
		'choices'  => array(
			array( 'label' => 'A', 'message' => 'Do A.' ),
			array( 'label' => 'B', 'message' => 'Do B.' ),
		),
	)
);
ec_roadie_loop_assert(
	isset( $result['result']['question'] ) && 'Pick one' === $result['result']['question'],
	'present_question must still echo the question under result.question.'
);
ec_roadie_loop_assert(
	isset( $result['result']['choices'] ) && 2 === count( $result['result']['choices'] ),
	'present_question must still echo both choices under result.choices.'
);

echo "present_question positive-framing smoke passed (16 assertions).\n";
