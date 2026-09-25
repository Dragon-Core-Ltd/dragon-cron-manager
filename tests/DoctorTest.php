<?php
/**
 * Doctor: log start times are site-local and must convert to real timestamps.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Doctor;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase {

	protected function setUp(): void {
		dragoncronmanager_test_reset();
	}

	public function test_log_time_in_a_utc_site_is_read_as_utc(): void {
		$this->assertSame( gmmktime( 12, 0, 0, 9, 24, 2026 ), Doctor::log_timestamp( '2026-09-24 12:00:00' ) );
	}

	public function test_log_time_in_an_offset_site_is_read_in_the_site_timezone(): void {
		$GLOBALS['dragoncronmanager_test_timezone'] = 'Europe/Berlin';
		// 12:00 in Berlin (CEST, UTC+2) is 10:00 UTC.
		$this->assertSame( gmmktime( 10, 0, 0, 9, 24, 2026 ), Doctor::log_timestamp( '2026-09-24 12:00:00' ) );
	}

	public function test_unparseable_log_time_is_unknown(): void {
		$this->assertSame( 0, Doctor::log_timestamp( 'not a date' ) );
	}

	public function test_starved_queue_verdict_does_not_claim_it_ran_the_queue(): void {
		$findings = Doctor::verdict(
			array(
				'disable_wp_cron' => false,
				'max_overdue'     => 2 * HOUR_IN_SECONDS,
				'lock_age'        => 0,
				'loopback'        => array( 'ok' => true ),
				'site_url'        => 'https://example.test/wp-cron.php',
			)
		);

		$this->assertCount( 1, $findings );
		$this->assertStringNotContainsString( 'kicked', $findings[0]['fix'] );
		$this->assertStringContainsString( 'reach', $findings[0]['detail'] . ' ' . $findings[0]['fix'] );
	}
}
