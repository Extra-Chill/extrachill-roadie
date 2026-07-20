<?php
/**
 * Smoke tests for Roadie frontend chat branding.
 *
 * Run with: php tests/frontend-chat-branding-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['extrachill_roadie_test_filters'] = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['extrachill_roadie_test_filters'][ $hook ][] = $callback;
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['extrachill_roadie_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function sanitize_title( $value ): string {
	$value = strtolower( (string) $value );
	$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
	return trim( (string) $value, '-' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ): string {
		unset( $domain );
		return (string) $text;
	}
}

// Toggleable login state for confirming the greeting does not infer access.
$GLOBALS['extrachill_roadie_test_logged_in'] = false;
function is_user_logged_in(): bool {
	return (bool) ( $GLOBALS['extrachill_roadie_test_logged_in'] ?? false );
}

function roadie_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/permissions.php';
require_once dirname( __DIR__ ) . '/inc/frontend-chat.php';

$roadie_config = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'roadie' ) );
roadie_smoke_assert( 'Roadie' === ( $roadie_config['fab_label'] ?? '' ), 'Roadie config should brand the frontend chat launcher.' );

$default_config = apply_filters( 'frontend_agent_chat_config', array() );
roadie_smoke_assert( 'Roadie' === ( $default_config['fab_label'] ?? '' ), 'Empty config should default to Roadie branding when Roadie owns the consumer plugin.' );

$other_config = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'other-agent' ) );
roadie_smoke_assert( ! isset( $other_config['fab_label'] ), 'Other explicitly configured agents should keep their launcher branding.' );

// Branding must not infer entitlement from login state. Canonical grant and
// additive team access are enforced by the dedicated visibility filters.
$GLOBALS['extrachill_roadie_test_logged_in'] = false;
$logged_out = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'roadie' ) );
roadie_smoke_assert( ! empty( $logged_out['fab_greeting'] ), 'Roadie should provide a launcher greeting.' );

$GLOBALS['extrachill_roadie_test_logged_in'] = true;
$logged_in = apply_filters( 'frontend_agent_chat_config', array( 'agent_slug' => 'roadie' ) );
roadie_smoke_assert( $logged_in['fab_greeting'] === $logged_out['fab_greeting'], 'Greeting should not replace canonical entitlement with login-state policy.' );

// Page-awareness guidance: the directive-outputs filter should append a
// system_text guidance block ONLY when page_url is present in client_context,
// and must reference the page url and the inspect_page tool.
$base_output = array(
	array(
		'type'    => 'system_text',
		'content' => "# Current Client Context\n\n- page url: https://events.extrachill.com/",
	),
);

$with_page = apply_filters(
	'datamachine_client_context_directive_outputs',
	$base_output,
	array(
		'page_url'   => 'https://events.extrachill.com/',
		'page_title' => 'Events Calendar',
	)
);
roadie_smoke_assert( count( $with_page ) === count( $base_output ) + 1, 'Page-awareness guidance should append one output when page_url is present.' );
$page_guidance = (string) ( $with_page[ count( $with_page ) - 1 ]['content'] ?? '' );
roadie_smoke_assert( false !== strpos( $page_guidance, 'https://events.extrachill.com/' ), 'Page-awareness guidance should embed the current page url.' );
roadie_smoke_assert( false !== strpos( $page_guidance, 'Events Calendar' ), 'Page-awareness guidance should embed the current page title when present.' );
roadie_smoke_assert( false !== strpos( $page_guidance, 'inspect_page' ), 'Page-awareness guidance should point at the inspect_page tool.' );
roadie_smoke_assert( false !== strpos( $page_guidance, 'authoritative' ), 'Page-awareness guidance should assert the page is authoritative.' );

// Guardrail: no page_url means no guidance appended.
$no_page = apply_filters(
	'datamachine_client_context_directive_outputs',
	$base_output,
	array( 'site' => 'Extra Chill' )
);
roadie_smoke_assert( count( $no_page ) === count( $base_output ), 'Page-awareness guidance must NOT append when page_url is absent.' );

// Empty page_url string is treated as absent.
$empty_page = apply_filters(
	'datamachine_client_context_directive_outputs',
	$base_output,
	array( 'page_url' => '' )
);
roadie_smoke_assert( count( $empty_page ) === count( $base_output ), 'Page-awareness guidance must NOT append when page_url is empty.' );

echo "Roadie frontend chat branding smoke passed (11 assertions).\n";
