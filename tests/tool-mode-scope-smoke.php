<?php
/**
 * Regression coverage for every Roadie-owned tool registration.
 *
 * Run with: php tests/tool-mode-scope-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

declare(strict_types=1);

$tool_files = array(
	'class-manage-artist-profile.php' => 'manage_artist_profile',
	'class-manage-link-page.php'      => 'manage_link_page',
	'class-manage-user-profile.php'   => 'manage_user_profile',
	'class-manage-community.php'      => 'manage_community',
	'class-writing-assistant.php'     => 'writing_assistant',
	'class-propose-code-change.php'   => 'propose_code_change',
	'class-apply-code-change.php'     => 'apply_code_change',
	'class-file-feature-request.php'  => 'file_feature_request',
	'class-inspect-code.php'          => 'inspect_code',
	'class-inspect-page.php'          => 'inspect_page',
	'class-present-question.php'      => 'present_question',
	'class-search-content.php'        => 'search_content',
);

$registrations = array();
foreach ( $tool_files as $file => $slug ) {
	$source = file_get_contents( dirname( __DIR__ ) . '/inc/tools/' . $file );
	if ( false === $source ) {
		throw new RuntimeException( "Unable to read {$file}." );
	}

	$matched = preg_match(
		"/registerTool\\(.*?array\\(\\s*'([^']+)'\\s*\\),\\s*array\\(\\s*'access_level'/s",
		$source,
		$match
	);
	if ( 1 !== $matched ) {
		throw new RuntimeException( "Unable to audit registration in {$file}." );
	}

	$registrations[ $slug ] = array( $match[1] );
}

if ( count( $tool_files ) !== count( $registrations ) ) {
	throw new RuntimeException( 'Every Roadie tool must have one unique registration.' );
}

foreach ( $registrations as $slug => $modes ) {
	if ( array( 'roadie' ) !== $modes ) {
		throw new RuntimeException( "{$slug} must register only for roadie mode." );
	}
}

$resolve_for_modes = static function ( array $active_modes ) use ( $registrations ): array {
	return array_filter(
		$registrations,
		static fn( array $tool_modes ): bool => array() !== array_intersect( $active_modes, $tool_modes )
	);
};

if ( array() !== $resolve_for_modes( array( 'chat' ) ) ) {
	throw new RuntimeException( 'Generic chat mode must not receive Roadie-owned tools.' );
}

if ( count( $registrations ) !== count( $resolve_for_modes( array( 'chat', 'roadie' ) ) ) ) {
	throw new RuntimeException( 'Roadie mode should receive every Roadie-owned tool.' );
}

echo 'Roadie tool mode scope smoke passed (' . ( count( $registrations ) + 3 ) . " assertions).\n";
