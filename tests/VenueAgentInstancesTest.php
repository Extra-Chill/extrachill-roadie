<?php
/** PHPUnit bridge for the standalone venue-agent contract regression. */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	final class RoadieVenueAgentInstancesTest extends WP_UnitTestCase {
		public function test_standalone_contract(): void {
			$output = array();
			$status = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/venue-agent-instances-smoke.php' ) . ' 2>&1', $output, $status );

			$this->assertSame( 0, $status, implode( "\n", $output ) );
			$this->assertStringContainsString( 'Roadie venue agent instances smoke passed.', implode( "\n", $output ) );
		}
	}
} else {
	require __DIR__ . '/venue-agent-instances-smoke.php';
}
