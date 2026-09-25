<?php
/**
 * Running a one-off event by hand consumes its booking, as WP-Cron does, so
 * it does not run a second time at the old slot. A Test run keeps it.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Cron;
use PHPUnit\Framework\TestCase;

final class CronOneOffRunTest extends TestCase {

	private const HOOK = 'dragoncronmanager_test_single';

	private int $slot;

	protected function setUp(): void {
		dragoncronmanager_test_reset();
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'dragoncronmanager_cleanup_logs' );
		update_option( 'dragoncronmanager_log_enabled', false );

		$this->slot = time() + 600;
		wp_schedule_single_event( $this->slot, self::HOOK, array( 'x' ) );
	}

	public function test_run_removes_the_one_off_booking(): void {
		add_action( self::HOOK, static function () {} );

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
		$this->assertStringNotContainsString( 'schedule unchanged', $result['message'] );
	}

	public function test_booking_is_removed_before_the_callback_runs(): void {
		// Core's wp-cron.php unschedules before firing, so a callback that
		// books its own follow-up is not refused as a duplicate and keeps it.
		$seen = array();
		add_action(
			self::HOOK,
			static function () use ( &$seen ) {
				$seen[] = wp_next_scheduled( self::HOOK, array( 'x' ) );
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK, array( 'x' ) );
			}
		);

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( false ), $seen );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'x' ) ), 'the follow-up booked by the callback survives' );
		$this->assertFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
	}

	public function test_a_test_run_keeps_the_one_off_booking(): void {
		add_action( self::HOOK, static function () {} );

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ), false );

		$this->assertTrue( $result['success'] );
		$this->assertNotFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
	}

	public function test_a_booking_that_cannot_be_removed_is_not_run(): void {
		add_action( self::HOOK, static function () {} );
		$GLOBALS['dragoncronmanager_test_schedule_results'] = array( 'fail' );

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $GLOBALS['dragoncronmanager_test_fired'] );
		$this->assertNotFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
	}

	public function test_a_one_off_no_longer_at_the_clicked_slot_is_not_run(): void {
		add_action( self::HOOK, static function () {} );
		$other = time() + 7200;
		wp_schedule_single_event( $other, self::HOOK, array( 'x' ) );

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ), true, $this->slot + 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $GLOBALS['dragoncronmanager_test_fired'] );
		$this->assertNotFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
		$this->assertNotFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $other ) );
	}

	public function test_a_throwing_one_off_says_it_was_removed(): void {
		add_action(
			self::HOOK,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['consumed'] );
		$this->assertStringContainsString( 'boom', $result['message'] );
		$this->assertStringContainsString( 'removed from the schedule', $result['message'] );
		$this->assertFalse( wp_get_scheduled_event( self::HOOK, array( 'x' ), $this->slot ) );
	}

	public function test_a_throwing_test_run_keeps_the_booking_and_says_nothing_about_removal(): void {
		add_action(
			self::HOOK,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$result = ( new Cron() )->run_event( self::HOOK, array( 'x' ), false );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['consumed'] );
		$this->assertStringNotContainsString( 'removed', $result['message'] );
	}
}
