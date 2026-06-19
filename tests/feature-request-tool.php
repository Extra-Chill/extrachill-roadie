<?php
/**
 * Smoke tests for the file_feature_request chat tool.
 *
 * Covers:
 *   - Tool registers via the datamachine_tools filter (chat mode, authenticated).
 *   - Tool definition surfaces the three documented actions.
 *   - Capability gate: users without extrachill_propose_code are blocked.
 *   - Repo validation: rejects repos outside the EC slug-to-repo registry.
 *   - Action validation: unknown actions are rejected with a clear error.
 *   - file_issue: requires title + body, auto-applies default labels,
 *     appends attribution footer, returns issue_url + issue_number.
 *   - list_recent_issues: filters PRs out of the normalized list, respects
 *     state defaulting, clamps per_page.
 *   - comment_on_issue: requires positive issue_number + non-empty body,
 *     returns comment_url.
 *
 * Run: php tests/feature-request-tool.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Test-local stubs ---------------------------------------------------

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		$pool = $GLOBALS['extrachill_roadie_test_state']['users'] ?? array();
		return $pool[ $user_id ] ?? null;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof ECRoadie_TestWPError;
	}
}

if ( ! class_exists( 'ECRoadie_TestWPError' ) ) {
	final class ECRoadie_TestWPError {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
	}
}

if ( ! class_exists( 'ECRoadie_TestAbility' ) ) {
	final class ECRoadie_TestAbility {
		/**
		 * @param callable(array):mixed $executor
		 */
		public function __construct( private string $name, private $executor ) {}

		public function execute( array $input ) {
			$GLOBALS['extrachill_roadie_test_state']['ability_calls'][] = array(
				'name'  => $this->name,
				'input' => $input,
			);
			return ( $this->executor )( $input );
		}
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		$registry = $GLOBALS['extrachill_roadie_test_state']['abilities'] ?? array();
		return $registry[ $name ] ?? null;
	}
}

// BaseTool stub: same shape used by contribute-code-tool-registration.php.
if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {
				$GLOBALS["extrachill_roadie_test_state"]["registered_tools"][ $toolName ] = array(
					"definition" => $toolDefinition,
					"modes"      => $modes,
					"meta"       => $meta,
				);
			}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "tool_name" => $tool_name );
			}
			protected function buildDiagnosticErrorResponse( string $error, string $type, string $tool_name, array $a = array(), array $b = array() ): array {
				return array( "success" => false, "error" => $error, "type" => $type, "tool_name" => $tool_name );
			}
		}'
	);
}

$GLOBALS['extrachill_roadie_test_state']['registered_tools']  = array();
$GLOBALS['extrachill_roadie_test_state']['ability_calls']     = array();
$GLOBALS['extrachill_roadie_test_state']['abilities']         = array();
$GLOBALS['extrachill_roadie_test_state']['users']             = array();
$GLOBALS['extrachill_roadie_test_state']['current_user_id']   = 0;

require_once dirname( __DIR__ ) . '/inc/tools/class-file-feature-request.php';

$tool = new ECRoadie_FileFeatureRequest();

// --- Registration -------------------------------------------------------

roadie_test_assert(
	isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools']['file_feature_request'] ),
	'file_feature_request must register via registerTool()'
);

$reg = $GLOBALS['extrachill_roadie_test_state']['registered_tools']['file_feature_request'];
roadie_test_assert( in_array( 'chat', $reg['modes'], true ), 'tool must register for chat mode' );
roadie_test_assert(
	'authenticated' === ( $reg['meta']['access_level'] ?? '' ),
	'tool must require authenticated access_level'
);

$definition = $tool->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $definition['method'], 'tool dispatch method' );
$required = $definition['parameters']['required'] ?? array();
roadie_test_assert( in_array( 'action', $required, true ), 'definition requires action' );
roadie_test_assert( ! in_array( 'repo', $required, true ), 'definition makes repo optional (auto-inferred from context)' );

$action_enum = $definition['parameters']['properties']['action']['enum'] ?? array();
roadie_test_assert( in_array( 'file_issue', $action_enum, true ), 'action enum includes file_issue' );
roadie_test_assert( in_array( 'list_recent_issues', $action_enum, true ), 'action enum includes list_recent_issues' );
roadie_test_assert( in_array( 'comment_on_issue', $action_enum, true ), 'action enum includes comment_on_issue' );

// --- Capability gate ----------------------------------------------------

$GLOBALS['extrachill_roadie_test_state']['user_caps'] = array();
$blocked = $tool->handle_tool_call( array( 'action' => 'file_issue', 'repo' => 'Extra-Chill/extrachill-roadie', 'title' => 't', 'body' => 'b' ) );
roadie_test_assert( false === $blocked['success'], 'caller without cap must be blocked' );
roadie_test_assert(
	false !== strpos( $blocked['error'] ?? '', 'permission' ),
	'capability error must mention permission'
);

// Grant the cap for the rest of the suite.
$GLOBALS['extrachill_roadie_test_state']['user_caps'][ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] = true;

// --- Action + repo validation ------------------------------------------

$bad_action = $tool->handle_tool_call( array( 'action' => 'delete_universe', 'repo' => 'Extra-Chill/extrachill-roadie' ) );
roadie_test_assert( false === $bad_action['success'], 'unknown action must be rejected' );

// Missing repo + inference fails (unknown blog: no subsite-specific plugins,
// theme slug not in the registry) must fall back to requiring an explicit repo.
$GLOBALS['extrachill_roadie_test_state']['theme'] = (function () {
	$theme             = new ECRoadie_TestTheme();
	$theme->stylesheet = 'some-unregistered-theme';
	return $theme;
})();
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array();
$missing_repo = $tool->handle_tool_call( array( 'action' => 'file_issue', 'repo' => '', 'title' => 't', 'body' => 'b' ) );
roadie_test_assert( false === $missing_repo['success'], 'missing repo with failed inference must be rejected' );
roadie_test_assert(
	false !== strpos( $missing_repo['error'] ?? '', 'repo is required' ),
	'failed-inference fallback surfaces the standard repo-required error'
);
// Restore the default registered theme for the rest of the suite.
$GLOBALS['extrachill_roadie_test_state']['theme'] = null;

$bad_repo = $tool->handle_tool_call( array( 'action' => 'file_issue', 'repo' => 'evil-org/private-secrets', 'title' => 't', 'body' => 'b' ) );
roadie_test_assert( false === $bad_repo['success'], 'unregistered repo must be rejected' );
roadie_test_assert(
	false !== strpos( $bad_repo['error'] ?? '', 'registry' ),
	'unregistered-repo error must mention the registry'
);

// --- file_issue: missing-ability error path ----------------------------

$missing_ability = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => 't',
	'body'   => 'b',
) );
roadie_test_assert( false === $missing_ability['success'], 'missing ability must surface error' );
roadie_test_assert(
	false !== strpos( $missing_ability['error'] ?? '', 'datamachine/create-github-issue' ),
	'missing-ability error names the missing ability'
);

// --- Register stub abilities for the success-path tests ----------------

$GLOBALS['extrachill_roadie_test_state']['abilities'] = array(
	'datamachine/create-github-issue' => new ECRoadie_TestAbility(
		'datamachine/create-github-issue',
		static function ( array $input ): array {
			return array(
				'number'   => 42,
				'html_url' => 'https://github.com/' . $input['repo'] . '/issues/42',
				'title'    => $input['title'],
				'labels'   => array_map(
					static fn( $label ) => array( 'name' => $label ),
					(array) ( $input['labels'] ?? array() )
				),
			);
		}
	),
	'datamachine/list-github-issues'  => new ECRoadie_TestAbility(
		'datamachine/list-github-issues',
		static function ( array $input ): array {
			return array(
				array(
					'number'     => 11,
					'title'      => 'Existing idea',
					'state'      => 'open',
					'html_url'   => 'https://github.com/' . $input['repo'] . '/issues/11',
					'labels'     => array( array( 'name' => 'feature-request' ), array( 'name' => 'roadie-submitted' ) ),
					'updated_at' => '2026-05-01T00:00:00Z',
				),
				array(
					'number'       => 12,
					'title'        => 'A pull request',
					'state'        => 'open',
					'html_url'     => 'https://github.com/' . $input['repo'] . '/pull/12',
					'pull_request' => array( 'url' => 'https://api.github.com/...' ),
				),
			);
		}
	),
	'datamachine/comment-github-issue' => new ECRoadie_TestAbility(
		'datamachine/comment-github-issue',
		static function ( array $input ): array {
			return array(
				'id'       => 7777,
				'html_url' => sprintf(
					'https://github.com/%s/issues/%d#issuecomment-7777',
					$input['repo'],
					$input['issue_number']
				),
				'body'     => $input['body'],
			);
		}
	),
);

// --- file_issue: requires title + body ---------------------------------

$no_title = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => '',
	'body'   => 'body',
) );
roadie_test_assert( false === $no_title['success'], 'file_issue without title fails' );

$no_body = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => 't',
	'body'   => '',
) );
roadie_test_assert( false === $no_body['success'], 'file_issue without body fails' );

// --- file_issue: success path + attribution + default labels -----------

$GLOBALS['extrachill_roadie_test_state']['current_user_id'] = 7;
$GLOBALS['extrachill_roadie_test_state']['users'][7] = (object) array(
	'user_login'   => 'chubes',
	'display_name' => 'Chris Huber',
);
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();

$filed = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => 'FAB needs a brighter color',
	'body'   => 'Make the floating action button pop more so users notice it on first paint.',
	'labels' => array( 'ui-polish', 'roadie-submitted' /* duplicate; should dedupe */ ),
) );
roadie_test_assert( true === $filed['success'], 'file_issue success path returns success' );
roadie_test_assert( 42 === ( $filed['data']['issue_number'] ?? 0 ), 'file_issue returns issue_number from ability' );
roadie_test_assert(
	'https://github.com/Extra-Chill/extrachill-roadie/issues/42' === ( $filed['data']['issue_url'] ?? '' ),
	'file_issue returns issue_url from html_url'
);

$call = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert( 'datamachine/create-github-issue' === $call['name'], 'file_issue calls create-github-issue ability' );
roadie_test_assert(
	in_array( 'roadie-submitted', $call['input']['labels'], true ),
	'file_issue auto-applies roadie-submitted label'
);
roadie_test_assert(
	in_array( 'feature-request', $call['input']['labels'], true ),
	'file_issue auto-applies feature-request label'
);
roadie_test_assert(
	in_array( 'ui-polish', $call['input']['labels'], true ),
	'file_issue preserves caller-supplied labels'
);
$label_counts = array_count_values( $call['input']['labels'] );
roadie_test_assert(
	1 === ( $label_counts['roadie-submitted'] ?? 0 ),
	'file_issue dedupes duplicate labels'
);

roadie_test_assert(
	false !== strpos( $call['input']['body'], 'Filed via Roadie chat' ),
	'file_issue body carries attribution footer'
);
roadie_test_assert(
	false !== strpos( $call['input']['body'], 'chubes' ),
	'file_issue body attribution includes WP login'
);
roadie_test_assert(
	false !== strpos( $call['input']['body'], '#7' ),
	'file_issue body attribution includes WP user id'
);
roadie_test_assert(
	false !== strpos( $call['input']['body'], 'extrachill.test' ),
	'file_issue body attribution includes subsite URL'
);

// --- file_issue: repo auto-inferred from subsite context ---------------
// Simulate a team member chatting from events.extrachill.com (blog 7) with
// extrachill-events active. Omitting repo should infer Extra-Chill/extrachill-events
// from the active subsite plugin via the slug-to-repo registry.
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 7;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array( 'extrachill-events/extrachill-events.php' );
$GLOBALS['extrachill_roadie_test_state']['ability_calls']  = array();

$inferred = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'title'  => 'Calendar load-more frozen on June 9',
	'body'   => 'The events calendar load-more button stops responding at June 9.',
) );
roadie_test_assert( true === $inferred['success'], 'file_issue succeeds with repo inferred from subsite context' );
roadie_test_assert(
	'Extra-Chill/extrachill-events' === ( $inferred['data']['repo'] ?? '' ),
	'repo auto-infers to Extra-Chill/extrachill-events from the active subsite plugin'
);
roadie_test_assert(
	true === ( $inferred['data']['repo_inferred'] ?? false ),
	'success payload flags repo_inferred so the model confirms once instead of asking repeatedly'
);
$inferred_call = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert(
	'Extra-Chill/extrachill-events' === ( $inferred_call['input']['repo'] ?? '' ),
	'inferred repo is forwarded to the create-github-issue ability'
);

// Explicit repo still wins over inference, and is flagged as not inferred.
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();
$explicit = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => 'Explicit repo wins',
	'body'   => 'Caller named the repo directly.',
) );
roadie_test_assert(
	'Extra-Chill/extrachill-roadie' === ( $explicit['data']['repo'] ?? '' ),
	'explicit repo param overrides subsite inference'
);
roadie_test_assert(
	false === ( $explicit['data']['repo_inferred'] ?? true ),
	'explicit repo is not flagged as inferred'
);

// --- #57: data-machine-events is in the registry and file-able ----------
// The events Calendar + EventsMap UI lives in data-machine-events, which was
// previously absent from the repo map (and excluded from subsite-context), so
// Roadie could neither file against nor read it. It must now resolve.
roadie_test_assert(
	'Extra-Chill/data-machine-events' === extrachill_roadie_repo_for_slug( 'data-machine-events' ),
	'data-machine-events must resolve from the slug-to-repo registry (#57)'
);
roadie_test_assert(
	'plugin' === ( extrachill_roadie_default_repo_map()['data-machine-events']['kind'] ?? '' ),
	'data-machine-events is a front-end-rendering subsite plugin (kind=plugin), not agent-stack infra (#57)'
);
// data-machine-socials is in the registry too (file-able) but as platform-plugin
// because it renders no front-end surface.
roadie_test_assert(
	'Extra-Chill/data-machine-socials' === extrachill_roadie_repo_for_slug( 'data-machine-socials' ),
	'data-machine-socials must resolve from the registry so it is file-able (#57)'
);
roadie_test_assert(
	'platform-plugin' === ( extrachill_roadie_default_repo_map()['data-machine-socials']['kind'] ?? '' ),
	'data-machine-socials is backend platform infra (kind=platform-plugin), not a subsite editable surface (#57)'
);

// --- #57: multi-plugin events subsite surfaces disambiguation candidates -
// The events site runs BOTH extrachill-events and data-machine-events. Filing
// with an inferred repo must surface every mapped candidate plus a grounding
// hint so the model can confirm it filed against the right plugin (and reach
// for inspect_code to read the actual page source).
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 7;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array(
	'extrachill-events/extrachill-events.php',
	'data-machine-events/data-machine-events.php',
);
$GLOBALS['extrachill_roadie_test_state']['ability_calls']  = array();

$multi = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'title'  => 'The calendar map takes too much vertical space',
	'body'   => 'On the events calendar the map block dominates the viewport above the listings.',
) );
roadie_test_assert( true === $multi['success'], 'file_issue succeeds on the multi-plugin events subsite (#57)' );
roadie_test_assert(
	true === ( $multi['data']['repo_inferred'] ?? false ),
	'multi-plugin events subsite still flags repo_inferred (#57)'
);
$multi_candidates = $multi['data']['repo_candidates'] ?? array();
roadie_test_assert(
	in_array( 'Extra-Chill/extrachill-events', $multi_candidates, true ),
	'multi-plugin candidates include extrachill-events (#57)'
);
roadie_test_assert(
	in_array( 'Extra-Chill/data-machine-events', $multi_candidates, true ),
	'multi-plugin candidates include data-machine-events — the calendar/map owner (#57)'
);
// Plugins rank ahead of the theme fallback: the two event plugins lead, the
// active theme (also registered) trails as the final fallback candidate.
roadie_test_assert(
	array( 'Extra-Chill/extrachill-events', 'Extra-Chill/data-machine-events' )
		=== array_slice( $multi_candidates, 0, 2 ),
	'mapped subsite plugins lead the candidate list, in detector order (#57). Got: ' . implode( ',', $multi_candidates )
);
roadie_test_assert(
	false !== strpos( $multi['data']['disambiguation'] ?? '', 'inspect_code' ),
	'disambiguation hint points the model at inspect_code to ground the owning component (#57)'
);

// --- page_url drives repo inference, not just the executing blog --------
// The motivating bug (qrisg): a team member chatting from the main site
// (blog 1, multi-repo, inherently ambiguous) about a specific subsite's page
// was forced to name the repo because inference only looked at the executing
// blog. page_url (the page the user actually had open) is the disambiguator.
//
// Set up a network site table so page_url → blog id resolves like core's
// get_blog_id_from_url(), and give each blog its own active plugins so the
// detector reads the VIEWED subsite's surface, not the executing one's.
$GLOBALS['extrachill_roadie_test_state']['network_sites'] = array(
	'extrachill.test|/'        => 1, // main site
	'events.extrachill.test|/' => 7, // events subsite
	'shop.extrachill.test|/'   => 3, // shop subsite
);
$GLOBALS['extrachill_roadie_test_state']['active_plugins_by_blog'] = array(
	1 => array(),                                            // main site: no registered subsite plugin
	7 => array( 'extrachill-events/extrachill-events.php' ), // events subsite
	3 => array( 'extrachill-shop/extrachill-shop.php' ),     // shop subsite
);

// Chat turn EXECUTES on the main site (blog 1) but the user had the EVENTS
// calendar open. Inference must follow page_url → events repo, not blog 1.
$GLOBALS['extrachill_roadie_test_state']['current_blog']  = 1;
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();

$page_inferred = $tool->handle_tool_call( array(
	'action'   => 'file_issue',
	'title'    => 'Calendar filter bar wraps awkwardly on mobile',
	'body'     => 'On the events calendar the tonight/this-weekend buttons wrap below the search box on narrow screens.',
	'page_url' => 'https://events.extrachill.test/calendar/',
) );
roadie_test_assert( true === $page_inferred['success'], 'file_issue succeeds with repo inferred from page_url' );
roadie_test_assert(
	'Extra-Chill/extrachill-events' === ( $page_inferred['data']['repo'] ?? '' ),
	'repo infers from the VIEWED subsite (page_url=events) even though the turn executes on blog 1. Got: ' . ( $page_inferred['data']['repo'] ?? '(none)' )
);
roadie_test_assert(
	true === ( $page_inferred['data']['repo_inferred'] ?? false ),
	'page_url-inferred repo is flagged repo_inferred'
);
$page_call = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert(
	'Extra-Chill/extrachill-events' === ( $page_call['input']['repo'] ?? '' ),
	'page_url-inferred repo is forwarded to the create-github-issue ability'
);

// A page_url for a DIFFERENT subsite resolves to THAT subsite's repo — proving
// the signal is the page, not a fixed default.
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();
$shop_inferred = $tool->handle_tool_call( array(
	'action'   => 'file_issue',
	'title'    => 'Cart total mis-renders',
	'body'     => 'The shop cart total shows the wrong currency symbol.',
	'page_url' => 'https://shop.extrachill.test/cart/',
) );
roadie_test_assert(
	'Extra-Chill/extrachill-shop' === ( $shop_inferred['data']['repo'] ?? '' ),
	'a shop page_url infers the shop repo — inference tracks the page, not a default. Got: ' . ( $shop_inferred['data']['repo'] ?? '(none)' )
);

// Explicit repo still wins over page_url inference.
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();
$page_explicit = $tool->handle_tool_call( array(
	'action'   => 'file_issue',
	'repo'     => 'Extra-Chill/extrachill-roadie',
	'title'    => 'Explicit beats page_url',
	'body'     => 'Caller named the repo even though they were on the events page.',
	'page_url' => 'https://events.extrachill.test/calendar/',
) );
roadie_test_assert(
	'Extra-Chill/extrachill-roadie' === ( $page_explicit['data']['repo'] ?? '' ),
	'explicit repo param overrides page_url inference'
);
roadie_test_assert(
	false === ( $page_explicit['data']['repo_inferred'] ?? true ),
	'explicit repo (with page_url present) is not flagged inferred'
);

// An OFF-NETWORK page_url does not resolve to a blog, so inference falls back
// to the executing blog (blog 1). Blog 1 has no registered subsite plugin but
// its active theme (extrachill) IS in the registry, so inference resolves to
// the theme repo — proving the fallback path runs against the executing blog,
// not the unresolved page_url.
$GLOBALS['extrachill_roadie_test_state']['ability_calls'] = array();
$offnet = $tool->handle_tool_call( array(
	'action'   => 'file_issue',
	'title'    => 'Off-network page',
	'body'     => 'page_url is some external site.',
	'page_url' => 'https://example.com/some/page/',
) );
roadie_test_assert(
	'Extra-Chill/extrachill' === ( $offnet['data']['repo'] ?? '' ),
	'off-network page_url falls back to the executing blog (blog 1 → theme repo), not the unresolved external URL. Got: ' . ( $offnet['data']['repo'] ?? '(none)' )
);

// The tool advertises the page_url client-context binding so the runtime can
// merge client_context['page_url'] into the param automatically (same
// mechanism inspect_page uses).
$page_def = $tool->getToolDefinition();
roadie_test_assert(
	( $page_def['client_context_bindings']['page_url'] ?? '' ) === 'page_url',
	'definition advertises the page_url client-context binding'
);
roadie_test_assert(
	( $reg['meta']['client_context_bindings']['page_url'] ?? '' ) === 'page_url',
	'registration meta advertises the page_url client-context binding for the runtime merge'
);
roadie_test_assert(
	isset( $page_def['parameters']['properties']['page_url'] ),
	'definition exposes a page_url parameter slot for the binding'
);
roadie_test_assert(
	! in_array( 'page_url', $page_def['parameters']['required'] ?? array(), true ),
	'page_url is optional (normally supplied from client context, not the model)'
);

// Tear down the per-blog/network test seams so later assertions use the flat
// defaults.
unset(
	$GLOBALS['extrachill_roadie_test_state']['network_sites'],
	$GLOBALS['extrachill_roadie_test_state']['active_plugins_by_blog']
);

// Restore the default blog/plugins for any later assertions.
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 1;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array();

// --- list_recent_issues: PR filtering, normalization, defaults ---------

$listed = $tool->handle_tool_call( array(
	'action' => 'list_recent_issues',
	'repo'   => 'Extra-Chill/extrachill-roadie',
) );
roadie_test_assert( true === $listed['success'], 'list_recent_issues success path' );
roadie_test_assert( 1 === ( $listed['data']['count'] ?? -1 ), 'list_recent_issues filters PRs out of normalized list' );
$issues = $listed['data']['issues'] ?? array();
roadie_test_assert( 1 === count( $issues ), 'normalized issue list has exactly one entry' );
roadie_test_assert( 11 === ( $issues[0]['number'] ?? 0 ), 'normalized issue carries the right number' );
roadie_test_assert(
	in_array( 'feature-request', $issues[0]['labels'] ?? array(), true ),
	'normalized issue label array contains feature-request'
);

$last = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert( 'open' === ( $last['input']['state'] ?? '' ), 'list_recent_issues defaults state to open' );
roadie_test_assert( 20 === ( $last['input']['per_page'] ?? 0 ), 'list_recent_issues defaults per_page to 20' );

// per_page clamping
$tool->handle_tool_call( array(
	'action'   => 'list_recent_issues',
	'repo'     => 'Extra-Chill/extrachill-roadie',
	'per_page' => 999,
) );
$last_clamp = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert( 100 === ( $last_clamp['input']['per_page'] ?? 0 ), 'list_recent_issues clamps per_page to 100' );

// invalid state falls back to open
$tool->handle_tool_call( array(
	'action' => 'list_recent_issues',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'state'  => 'invalid',
) );
$last_state = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert( 'open' === ( $last_state['input']['state'] ?? '' ), 'list_recent_issues falls back to open on bad state' );

// --- comment_on_issue: validation + success ----------------------------

$no_num = $tool->handle_tool_call( array(
	'action' => 'comment_on_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'body'   => 'lgtm',
) );
roadie_test_assert( false === $no_num['success'], 'comment_on_issue without issue_number fails' );

$no_body = $tool->handle_tool_call( array(
	'action'       => 'comment_on_issue',
	'repo'         => 'Extra-Chill/extrachill-roadie',
	'issue_number' => 42,
	'body'         => '',
) );
roadie_test_assert( false === $no_body['success'], 'comment_on_issue without body fails' );

$commented = $tool->handle_tool_call( array(
	'action'       => 'comment_on_issue',
	'repo'         => 'Extra-Chill/extrachill-roadie',
	'issue_number' => 42,
	'body'         => 'Following up — same idea here.',
) );
roadie_test_assert( true === $commented['success'], 'comment_on_issue success' );
roadie_test_assert(
	false !== strpos( $commented['data']['comment_url'] ?? '', 'issuecomment-7777' ),
	'comment_on_issue surfaces the comment URL'
);

$last_comment = end( $GLOBALS['extrachill_roadie_test_state']['ability_calls'] );
roadie_test_assert(
	false !== strpos( $last_comment['input']['body'], 'Filed via Roadie chat' ),
	'comment_on_issue body carries attribution footer'
);

// --- WP_Error propagation ---------------------------------------------

$GLOBALS['extrachill_roadie_test_state']['abilities']['datamachine/create-github-issue'] = new ECRoadie_TestAbility(
	'datamachine/create-github-issue',
	static fn( array $i ) => new ECRoadie_TestWPError( 'gh_error', 'GitHub API exploded.' )
);

$errored = $tool->handle_tool_call( array(
	'action' => 'file_issue',
	'repo'   => 'Extra-Chill/extrachill-roadie',
	'title'  => 'x',
	'body'   => 'y',
) );
roadie_test_assert( false === $errored['success'], 'WP_Error from ability surfaces as failure' );
roadie_test_assert(
	false !== strpos( $errored['error'] ?? '', 'GitHub API exploded' ),
	'WP_Error message is forwarded to caller'
);

echo "feature-request tool smoke passed.\n";
