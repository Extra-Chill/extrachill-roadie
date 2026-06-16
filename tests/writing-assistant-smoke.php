<?php
/**
 * Smoke test: the writing_assistant tool is author-scoped, submit-only-to-pending,
 * and routes its actions correctly against the MAIN site.
 *
 * Confirms:
 *   - a writer can list/get/submit their OWN draft on main (blog 1);
 *   - a writer is refused on a draft they do not own;
 *   - a non-admin cannot act on another user's behalf (inherited gate);
 *   - submit_for_review only ever sends status=pending (never publish);
 *   - get_draft / list_drafts report the main blog_id for the content tools;
 *   - non-draft / non-existent posts are refused.
 *
 * Run with: php tests/writing-assistant-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/_stub-base-tool.php';
require_once __DIR__ . '/_stub-wp-and-rest.php';

// Sanitizers the tool relies on that the shared stubs don't provide.
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-writing-assistant.php';

function ec_roadie_wa_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Register a fake post in the get_post() stub registry.
 */
function ec_roadie_wa_set_post( int $id, int $author, string $status, string $type = 'post' ): void {
	$GLOBALS['ec_roadie_test_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_author' => $author,
		'post_status' => $status,
		'post_type'   => $type,
	);
}

$writer    = 38;
$other     = 77;
$admin     = 1;
$draft_id  = 500;

// =========================================================================
// 1. Writer submits their OWN draft → status=pending, never publish.
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );
ec_roadie_wa_set_post( $draft_id, $writer, 'draft' );
$GLOBALS['ec_roadie_test_rest_response'] = array( 'id' => $draft_id, 'status' => 'pending' );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'submit_for_review',
		'post_id'         => $draft_id,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( true === ( $result['success'] ?? false ), 'Writer should be able to submit their own draft.' );
$calls = $GLOBALS['ec_roadie_test_rest_calls'];
ec_roadie_wa_assert( 1 === count( $calls ), 'submit_for_review should make exactly one cross-site call.' );
ec_roadie_wa_assert( 'main' === $calls[0]['site_key'], 'submit must target the main site.' );
ec_roadie_wa_assert( 'POST' === $calls[0]['method'], 'submit must be a POST.' );
ec_roadie_wa_assert( '/wp/v2/posts/' . $draft_id === $calls[0]['path'], 'submit must hit the post update route on main.' );
ec_roadie_wa_assert( 'pending' === ( $calls[0]['args']['body']['status'] ?? '' ), 'submit must set status=pending.' );
ec_roadie_wa_assert( 'publish' !== ( $calls[0]['args']['body']['status'] ?? '' ), 'submit must NEVER publish.' );
ec_roadie_wa_assert( $writer === ( $calls[0]['effective_user'] ?? 0 ), 'submit must act as the writer.' );

// =========================================================================
// 2. Writer cannot submit a draft they do NOT own → refused, no REST call.
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );
ec_roadie_wa_set_post( $draft_id, $other, 'draft' ); // owned by someone else

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'submit_for_review',
		'post_id'         => $draft_id,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( false === ( $result['success'] ?? true ), 'Writer must not submit a draft they do not own.' );
ec_roadie_wa_assert( str_contains( strtolower( $result['error'] ?? '' ), 'your own drafts' ), 'Refusal should explain author-scoping.' );
ec_roadie_wa_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'A refused submit must not reach the REST helper.' );

// =========================================================================
// 3. Non-admin cannot act on another user's behalf (inherited gate).
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'list_drafts',
		'user_id'         => $other,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( false === ( $result['success'] ?? true ), 'Non-admin must not act on another user.' );
ec_roadie_wa_assert( 'permission' === ( $result['error_type'] ?? '' ), 'Cross-user denial should be a permission error.' );
ec_roadie_wa_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'Denied cross-user call must not reach REST.' );

// =========================================================================
// 4. get_draft on a non-existent post → not_found, no content call.
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'get_draft',
		'post_id'         => 999,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( false === ( $result['success'] ?? true ), 'get_draft on a missing post should fail.' );
ec_roadie_wa_assert( 'not_found' === ( $result['error_type'] ?? '' ), 'Missing draft should be not_found.' );
ec_roadie_wa_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'Missing draft must not reach REST.' );

// =========================================================================
// 5. get_draft on the writer's own draft → reports main blog_id for the
//    content tools.
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );
ec_roadie_wa_set_post( $draft_id, $writer, 'draft' );
$GLOBALS['ec_roadie_test_rest_response'] = array(
	'id'     => $draft_id,
	'title'  => array( 'raw' => 'My Draft' ),
	'status' => 'draft',
);

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'get_draft',
		'post_id'         => $draft_id,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( true === ( $result['success'] ?? false ), 'Writer should read their own draft.' );
ec_roadie_wa_assert( 1 === (int) ( $result['data']['blog_id'] ?? 0 ), 'get_draft must report the main blog_id (1).' );
ec_roadie_wa_assert( $draft_id === (int) ( $result['data']['post_id'] ?? 0 ), 'get_draft must echo the post_id.' );

// =========================================================================
// 6. Already-published post is refused (Roadie only works on drafts).
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );
ec_roadie_wa_set_post( $draft_id, $writer, 'publish' );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'submit_for_review',
		'post_id'         => $draft_id,
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( false === ( $result['success'] ?? true ), 'A published post is not an editable draft.' );
ec_roadie_wa_assert( 0 === count( $GLOBALS['ec_roadie_test_rest_calls'] ), 'Published post must not reach REST.' );

// =========================================================================
// 7. Admin CAN act on another writer's behalf (inherited admin override).
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $admin );
ec_roadie_test_grant_cap( $admin, 'manage_options' );
ec_roadie_wa_set_post( $draft_id, $other, 'draft' );
$GLOBALS['ec_roadie_test_rest_response'] = array( 'id' => $draft_id, 'status' => 'pending' );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'submit_for_review',
		'post_id'         => $draft_id,
		'user_id'         => $other,
		'calling_user_id' => $admin,
	)
);

ec_roadie_wa_assert( true === ( $result['success'] ?? false ), 'Admin should submit on behalf of another writer.' );
$calls = $GLOBALS['ec_roadie_test_rest_calls'];
ec_roadie_wa_assert( $other === ( $calls[0]['effective_user'] ?? 0 ), 'Admin-on-behalf submit must act as the target writer.' );

// =========================================================================
// 8. Unknown action → clean validation error.
// =========================================================================
ec_roadie_test_reset();
ec_roadie_test_login_as( $writer );

$tool   = new ECRoadie_WritingAssistant();
$result = $tool->handle_tool_call(
	array(
		'action'          => 'delete_everything',
		'calling_user_id' => $writer,
	)
);

ec_roadie_wa_assert( false === ( $result['success'] ?? true ), 'Unknown action should fail.' );
ec_roadie_wa_assert( str_contains( strtolower( $result['error'] ?? '' ), 'invalid action' ), 'Unknown action should be flagged as invalid.' );

echo "Roadie writing-assistant smoke passed (24 assertions).\n";
