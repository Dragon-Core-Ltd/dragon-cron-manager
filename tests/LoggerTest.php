<?php
/**
 * Logger: clearing the log must report a failed TRUNCATE.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase {

	protected function setUp(): void {
		dragoncronmanager_test_reset();
	}

	public function test_clear_logs_returns_true_when_truncate_succeeds(): void {
		$this->assertTrue( ( new Logger() )->clear_logs() );
		$this->assertStringContainsString( 'TRUNCATE TABLE wp_dcm_log', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_clear_logs_returns_false_when_truncate_fails(): void {
		$GLOBALS['wpdb']->query_result = false;

		$this->assertFalse( ( new Logger() )->clear_logs() );
	}
}
