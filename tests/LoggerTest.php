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

	public function test_constructing_the_logger_schedules_nothing_before_init(): void {
		$GLOBALS['dragoncronmanager_test_cron']    = array();
		$GLOBALS['dragoncronmanager_test_actions'] = array();

		$logger = new Logger();

		$this->assertFalse( wp_next_scheduled( 'dragoncronmanager_cleanup_logs' ) );
		$this->assertContains( array( $logger, 'ensure_scheduled' ), $GLOBALS['dragoncronmanager_test_actions']['init'] ?? array() );
	}

	public function test_ensure_scheduled_schedules_cleanup_once(): void {
		$GLOBALS['dragoncronmanager_test_cron'] = array();

		$logger = new Logger();
		$logger->ensure_scheduled();
		$logger->ensure_scheduled();

		$this->assertNotFalse( wp_next_scheduled( 'dragoncronmanager_cleanup_logs' ) );
		$count = 0;
		foreach ( $GLOBALS['dragoncronmanager_test_cron'] as $hooks ) {
			$count += count( $hooks['dragoncronmanager_cleanup_logs'] ?? array() );
		}
		$this->assertSame( 1, $count );
	}

	public function test_cleanup_cutoff_is_in_the_same_site_time_as_start_time(): void {
		// start_time is written with current_time( 'mysql' ): site-local wall
		// clock. The cutoff must be too, not the database server's NOW().
		$GLOBALS['dragoncronmanager_test_timezone'] = 'Pacific/Auckland';
		update_option( 'dragoncronmanager_log_retention_days', 7 );

		( new Logger() )->cleanup_old_logs();

		$sql = (string) end( $GLOBALS['wpdb']->queries );
		$this->assertStringNotContainsString( 'NOW()', $sql );
		$this->assertMatchesRegularExpression( "/start_time < '(\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d)'/", $sql );
		preg_match( "/start_time < '([^']+)'/", $sql, $m );

		$expected = ( new DateTime( 'now', new DateTimeZone( 'Pacific/Auckland' ) ) )->modify( '-7 days' );
		$actual   = new DateTime( $m[1], new DateTimeZone( 'Pacific/Auckland' ) );
		$this->assertLessThanOrEqual( 5, abs( $expected->getTimestamp() - $actual->getTimestamp() ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unusable_retention' )]
	public function test_unusable_retention_keeps_at_least_a_day_of_logs( $retention ): void {
		update_option( 'dragoncronmanager_log_retention_days', $retention );

		( new Logger() )->cleanup_old_logs();

		preg_match( "/start_time < '([^']+)'/", (string) end( $GLOBALS['wpdb']->queries ), $m );
		$cutoff = new DateTime( $m[1], new DateTimeZone( 'UTC' ) );
		$now    = new DateTime( current_time( 'mysql' ), new DateTimeZone( 'UTC' ) );
		$this->assertGreaterThanOrEqual( DAY_IN_SECONDS - 5, $now->getTimestamp() - $cutoff->getTimestamp() );
	}

	public static function unusable_retention(): array {
		return array(
			'zero'        => array( 0 ),
			'negative'    => array( -3 ),
			'non-numeric' => array( 'forever' ),
		);
	}
}
