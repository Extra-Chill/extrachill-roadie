<?php
/**
 * Integration coverage for authoritative Roadie caller bindings.
 *
 * Run with:
 * ROADIE_DATA_MACHINE_DIR=/path/to/data-machine php tests/tool-executor-caller-binding-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/contribute-code-bootstrap.php';
require_once __DIR__ . '/_stub-github-credential-resolver.php';

$data_machine_dir = getenv( 'ROADIE_DATA_MACHINE_DIR' );
if ( ! is_string( $data_machine_dir ) || '' === $data_machine_dir ) {
	echo "Roadie ToolExecutor caller-binding smoke skipped (set ROADIE_DATA_MACHINE_DIR).\n";
	exit( 0 );
}

$autoload = rtrim( $data_machine_dir, '/' ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "ROADIE_DATA_MACHINE_DIR must reference a Composer-installed Data Machine checkout.\n" );
	exit( 1 );
}
require_once $autoload;

if ( ! method_exists( AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::class, 'modelParameterSchema' ) ) {
	fwrite( STDERR, "Data Machine must vendor Agents API a4765649 or newer.\n" );
	exit( 1 );
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $title ) ?? '' );
	}
}

if ( ! class_exists( 'DataMachine\\Engine\\AI\\Actions\\ActionPolicyResolver', false ) ) {
	eval(
		'namespace DataMachine\Engine\AI\Actions;
		class ActionPolicyResolver {
			public const MODE_CHAT = "chat";
			public function resolveForTool( array $context ): string { return "direct"; }
		}'
	);
}

if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool', false ) ) {
	eval(
		'namespace DataMachine\Engine\AI\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "tool_name" => $tool_name );
			}
		}'
	);
}

if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\Execution\\ToolExecutionCore', false ) ) {
	eval(
		'namespace DataMachine\Engine\AI\Tools\Execution;
		class ToolExecutionCore implements \AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
			public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
				return array( "success" => true, "data" => $tool_call["parameters"] ?? array(), "tool_name" => $tool_call["tool_name"] ?? "" );
			}
		}'
	);
}

if ( ! class_exists( 'DataMachine\\Core\\WordPress\\PostTracking', false ) ) {
	eval(
		'namespace DataMachine\Core\WordPress;
		class PostTracking {
			public static function extractPostId( array $result ): int { return 0; }
			public static function store( int $post_id, array $tool_def, int $job_id ): void {}
		}'
	);
}

if ( ! class_exists( 'DataMachine\\Core\\Workspace\\WordPressWorkspaceScope', false ) ) {
	eval(
		'namespace DataMachine\Core\Workspace;
		class WordPressWorkspaceScope {
			public static function current() { return null; }
		}'
	);
}

require_once dirname( __DIR__ ) . '/inc/tools/caller.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-ec-platform-tool.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-artist-profile.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-link-page.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-user-profile.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-manage-community.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-writing-assistant.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-propose-code-change.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-apply-code-change.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-file-feature-request.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-code.php';
require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-page.php';
require_once rtrim( $data_machine_dir, '/' ) . '/inc/Engine/AI/Tools/ToolExecutor.php';

$tools = array(
	'manage_artist_profile' => ( new ECRoadie_ManageArtistProfile() )->getToolDefinition(),
	'manage_link_page'      => ( new ECRoadie_ManageLinkPage() )->getToolDefinition(),
	'manage_user_profile'   => ( new ECRoadie_ManageUserProfile() )->getToolDefinition(),
	'manage_community'      => ( new ECRoadie_ManageCommunity() )->getToolDefinition(),
	'writing_assistant'     => ( new ECRoadie_WritingAssistant() )->getToolDefinition(),
	'propose_code_change'   => ( new ECRoadie_ProposeCodeChange() )->getToolDefinition(),
	'apply_code_change'     => ( new ECRoadie_ApplyCodeChange() )->getToolDefinition(),
	'file_feature_request'  => ( new ECRoadie_FileFeatureRequest() )->getToolDefinition(),
	'inspect_code'          => ( new ECRoadie_InspectCode() )->getToolDefinition(),
	'inspect_page'          => ( new ECRoadie_InspectPage() )->getToolDefinition(),
);

foreach ( $tools as $tool_name => $definition ) {
	$binding = $definition['parameter_bindings']['calling_user_id'] ?? array();
	roadie_test_assert( 'caller_context' === ( $binding['source'] ?? '' ), $tool_name . ' must bind from caller_context.' );
	roadie_test_assert( 'calling_user_id' === ( $binding['path'] ?? '' ), $tool_name . ' must bind the canonical caller key.' );
	roadie_test_assert( true === ( $binding['authoritative'] ?? false ), $tool_name . ' caller binding must be authoritative.' );

	$model_schema = AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters::modelParameterSchema( $definition );
	roadie_test_assert( ! array_key_exists( 'calling_user_id', $model_schema['properties'] ?? array() ), $tool_name . ' must hide calling_user_id from the model schema.' );
	roadie_test_assert( ! in_array( 'calling_user_id', $model_schema['required'] ?? array(), true ), $tool_name . ' must hide calling_user_id from model requirements.' );
}

$provider_request = new AgentsAPI\AI\WP_Agent_Provider_Turn_Request(
	array( array( 'role' => 'user', 'content' => 'Inspect caller-bound tools.' ) ),
	$tools
);
foreach ( $provider_request->toolDeclarations() as $tool_name => $definition ) {
	$provider_schema = $definition['parameters'] ?? array();
	roadie_test_assert( ! array_key_exists( 'calling_user_id', $provider_schema['properties'] ?? array() ), $tool_name . ' provider declaration must not expose calling_user_id.' );
}

$tool_name  = 'propose_code_change';
$definition = array( $tool_name => $tools[ $tool_name ] );
$execute    = static function ( array $model_parameters, array $payload, array $client_context = array() ) use ( $tool_name, $definition ): array {
	return DataMachine\Engine\AI\Tools\ToolExecutor::executeTool(
		$tool_name,
		$model_parameters,
		$definition,
		$payload,
		'chat',
		0,
		$client_context
	);
};

$injected = $execute( array( 'task_description' => 'Inspect.' ), array( 'calling_user_id' => 102 ) );
roadie_test_assert( true === ( $injected['success'] ?? false ), 'ToolExecutor must inject an authoritative caller.' );
roadie_test_assert( 102 === ( $injected['audit_context']['parameters_redacted']['calling_user_id'] ?? null ), 'Injected caller must reach execution.' );

$tampered = $execute(
	array( 'task_description' => 'Inspect.', 'calling_user_id' => 777 ),
	array( 'calling_user_id' => 102 )
);
roadie_test_assert( 102 === ( $tampered['audit_context']['parameters_redacted']['calling_user_id'] ?? null ), 'Model caller override must lose to trusted caller context.' );

$zero = $execute(
	array( 'task_description' => 'Inspect.', 'calling_user_id' => 777 ),
	array( 'calling_user_id' => 0 )
);
roadie_test_assert( 0 === ( $zero['audit_context']['parameters_redacted']['calling_user_id'] ?? null ), 'Explicit authoritative zero must survive preparation.' );

$absent = $execute(
	array( 'task_description' => 'Inspect.', 'calling_user_id' => 777 ),
	array(),
	array( 'calling_user_id' => 999 )
);
roadie_test_assert( false === ( $absent['success'] ?? true ), 'Missing trusted caller context must fail closed.' );
roadie_test_assert( array( 'calling_user_id' ) === ( $absent['metadata']['missing_parameters'] ?? array() ), 'Missing caller must be reported after rejecting model and client_context substitutes.' );

$nested_client_context = $execute(
	array( 'task_description' => 'Inspect.', 'calling_user_id' => 777 ),
	array( 'client_context' => array( 'calling_user_id' => 888 ) ),
	array( 'calling_user_id' => 999 )
);
roadie_test_assert( false === ( $nested_client_context['success'] ?? true ), 'Payload and frontend client_context must not contaminate trusted caller_context.' );

echo "Roadie ToolExecutor caller-binding smoke passed.\n";
