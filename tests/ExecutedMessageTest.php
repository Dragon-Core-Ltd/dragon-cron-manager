<?php
/**
 * The message after running an event by hand has a plural form.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Cron;
use PHPUnit\Framework\TestCase;

final class ExecutedMessageTest extends TestCase {

	public function test_exactly_one_second_is_singular(): void {
		$this->assertSame( 'Cron event executed in 1.000 second and rescheduled.', Cron::executed_message( 'rescheduled', 1.0 ) );
	}

	public function test_a_fraction_of_a_second_is_plural(): void {
		$this->assertSame( 'Cron event executed in 0.250 seconds (schedule unchanged).', Cron::executed_message( 'unchanged', 0.25 ) );
	}

	public function test_one_and_a_half_seconds_is_plural(): void {
		$this->assertSame( 'One-time event executed in 1.500 seconds and removed from the schedule.', Cron::executed_message( 'removed', 1.5 ) );
	}

	public function test_a_reschedule_failure_carries_the_reason(): void {
		$this->assertSame(
			'Cron event executed in 2.000 seconds, but it could not be rescheduled. The slot was taken.',
			Cron::executed_message( 'reschedule_failed', 2.0, 'The slot was taken.' )
		);
	}

	public function test_plural_count_only_counts_a_displayed_one_as_one(): void {
		$this->assertSame( 1, Cron::seconds_plural_count( 1.0004 ) );
		$this->assertSame( 0, Cron::seconds_plural_count( 0.0 ) );
		$this->assertSame( 2, Cron::seconds_plural_count( 0.123 ) );
		$this->assertSame( 2, Cron::seconds_plural_count( 1.5 ) );
		$this->assertSame( 3, Cron::seconds_plural_count( 2.5 ) );
		$this->assertSame( 5, Cron::seconds_plural_count( 5.0 ) );
	}

	public function test_a_real_run_reports_its_duration_with_a_plural_form(): void {
		dragoncronmanager_test_reset();
		update_option( 'dragoncronmanager_log_enabled', false );
		wp_schedule_single_event( time() + 600, 'dragoncronmanager_test_plural', array() );
		add_action( 'dragoncronmanager_test_plural', static function () {} );

		$result = ( new Cron() )->run_event( 'dragoncronmanager_test_plural', array() );

		$this->assertMatchesRegularExpression( '/^One-time event executed in 0\.\d{3} seconds and removed from the schedule\.$/', $result['message'] );
	}
}
