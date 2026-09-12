<?php
/**
 * Cron: run-now rescheduling and trash writes must report their real outcome.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Cron;
use PHPUnit\Framework\TestCase;

final class CronTest extends TestCase {

	private const HOOK = 'dragoncronmanager_test_hook';

	private Cron $cron;

	private int $original_timestamp;

	protected function setUp(): void {
		dragoncronmanager_test_reset();

		// Logger::__construct() books its own cleanup event; pre-book it so that
		// call never consumes a forced schedule result meant for the test.
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'dragoncronmanager_cleanup_logs' );
		update_option( 'dragoncronmanager_log_enabled', false );

		add_action( self::HOOK, static function () {} );
		$this->original_timestamp = time() + 600;
		wp_schedule_event( $this->original_timestamp, 'hourly', self::HOOK, array( 'a' => 1 ) );

		$this->cron = new Cron();
	}

	private function force( ...$results ): void {
		$GLOBALS['dragoncronmanager_test_schedule_results'] = $results;
	}

	private function trash_option(): array {
		return get_option( 'dragoncronmanager_trashed_crons', array() );
	}

	/**
	 * Every booking of the test hook/args, as timestamp => schedule.
	 */
	private function bookings(): array {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$out = array();
		foreach ( $GLOBALS['dragoncronmanager_test_cron'] as $timestamp => $hooks ) {
			if ( isset( $hooks[ self::HOOK ][ $key ] ) ) {
				$out[ $timestamp ] = $hooks[ self::HOOK ][ $key ]['schedule'];
			}
		}
		return $out;
	}

	public function test_run_event_reschedules_when_booking_succeeds(): void {
		$before = time();
		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['rescheduled'] );
		$this->assertStringContainsString( 'rescheduled', $result['message'] );
		$this->assertCount( 1, $GLOBALS['dragoncronmanager_test_fired'] );

		// Exactly one booking remains, in the new slot, and the old slot is gone.
		$bookings = $this->bookings();
		$this->assertCount( 1, $bookings );
		$this->assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS, array_key_first( $bookings ) );
		$this->assertSame( 'hourly', reset( $bookings ) );
		$this->assertFalse( wp_get_scheduled_event( self::HOOK, array( 'a' => 1 ), $this->original_timestamp ) );
	}

	public function test_run_event_leaves_original_in_place_when_replacement_is_refused(): void {
		$this->force( 'fail' );

		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertCount( 1, $GLOBALS['dragoncronmanager_test_fired'], 'the event still runs' );
		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertStringNotContainsString( 'and rescheduled', $result['message'] );
		$this->assertStringContainsString( 'A plugin prevented the event from being scheduled.', $result['message'] );
		$this->assertStringContainsString( 'left in place', $result['message'] );

		// The original booking was never touched and nothing else was booked.
		$this->assertSame( array( $this->original_timestamp => 'hourly' ), $this->bookings() );
	}

	public function test_run_event_does_not_trust_a_true_return_when_the_slot_is_empty(): void {
		// wp_schedule_event() answers true but nothing lands in the cron array;
		// the existing original booking must not make that look like success.
		$this->force( true );

		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertStringContainsString( 'left in place', $result['message'] );
		$this->assertSame( array( $this->original_timestamp => 'hourly' ), $this->bookings() );
	}

	public function test_run_event_removes_replacement_when_original_cannot_be_unscheduled(): void {
		// Replacement lands (store-backed), the unschedule of the original is
		// refused, the rollback unschedule of the replacement is store-backed.
		$this->force( null, 'fail' );

		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertStringContainsString( 'A plugin prevented the event from being scheduled.', $result['message'] );
		$this->assertStringContainsString( 'left in place', $result['message'] );
		$this->assertSame( array( $this->original_timestamp => 'hourly' ), $this->bookings() );
	}

	public function test_run_event_reports_double_booking_when_unschedule_and_rollback_both_fail(): void {
		$this->force( null, 'fail', 'fail' );

		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertStringContainsString( 'booked twice', $result['message'] );
		$bookings = $this->bookings();
		$this->assertCount( 2, $bookings );
		$this->assertArrayHasKey( $this->original_timestamp, $bookings );
	}

	public function test_run_event_never_unschedules_when_replacement_slot_equals_original(): void {
		// Book the original exactly one interval ahead so the replacement slot
		// can coincide with it; either way exactly one booking must remain.
		wp_unschedule_event( $this->original_timestamp, self::HOOK, array( 'a' => 1 ) );
		$this->original_timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $this->original_timestamp, 'hourly', self::HOOK, array( 'a' => 1 ) );

		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['rescheduled'] );
		$bookings = $this->bookings();
		$this->assertCount( 1, $bookings );
		$this->assertGreaterThanOrEqual( $this->original_timestamp, array_key_first( $bookings ) );
	}

	public function test_run_event_in_test_mode_leaves_schedule_alone(): void {
		$result = $this->cron->run_event( self::HOOK, array( 'a' => 1 ), false );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertSame( array( $this->original_timestamp => 'hourly' ), $this->bookings() );
	}

	public function test_delete_trashed_event_reports_failed_write(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array( 'hook' => 'x' ) ) );
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$this->assertFalse( $this->cron->delete_trashed_event( 'trash_1' ) );
		$this->assertArrayHasKey( 'trash_1', $this->trash_option() );
	}

	public function test_delete_trashed_event_succeeds_when_write_persists(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array( 'hook' => 'x' ) ) );

		$this->assertTrue( $this->cron->delete_trashed_event( 'trash_1' ) );
		$this->assertArrayNotHasKey( 'trash_1', $this->trash_option() );
		$this->assertFalse( $this->cron->delete_trashed_event( 'trash_1' ), 'already gone' );
	}

	public function test_empty_trash_reports_failed_delete(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array(), 'trash_2' => array() ) );
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$this->assertFalse( $this->cron->empty_trash() );
		$this->assertCount( 2, $this->trash_option() );
	}

	public function test_empty_trash_returns_count_when_delete_persists(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array(), 'trash_2' => array() ) );

		$this->assertSame( 2, $this->cron->empty_trash() );
		$this->assertSame( array(), $this->trash_option() );
		$this->assertSame( 0, $this->cron->empty_trash(), 'an already-empty trash is not a failure' );
	}

	public function test_trash_event_keeps_event_scheduled_when_trash_write_fails(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$result = $this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'could not be saved to the trash', $result['message'] );
		$this->assertSame( $this->original_timestamp, wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ) );
	}

	public function test_trash_event_rolls_back_trash_entry_when_unschedule_fails(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$this->force( 'fail' );

		$result = $this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'could not be unscheduled', $result['message'] );
		$this->assertStringNotContainsString( 'still listed', $result['message'] );
		$this->assertSame( array(), $this->trash_option() );
		$this->assertSame( $this->original_timestamp, wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ) );
	}

	public function test_trash_event_reports_retained_trash_entry_when_rollback_write_fails(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$this->force( 'fail' );
		// The first write (the trash copy) succeeds; the rollback write fails.
		$GLOBALS['dragoncronmanager_test_option_writes_left'] = 1;

		$result = $this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'still listed in the trash', $result['message'] );
		$this->assertCount( 1, $this->trash_option() );
		$this->assertSame( $this->original_timestamp, wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ) );
	}

	public function test_restore_event_restores_a_single_event_despite_another_at_a_different_time(): void {
		// WordPress allows two one-off events with the same hook and arguments at
		// different times; it only refuses a duplicate inside a short window. So
		// a matching single event elsewhere in the schedule is not a double
		// booking, and refusing the restore would push the user into deleting a
		// legitimate trashed event.
		$args = array( 'b' => 2 );
		wp_schedule_single_event( time() + 7200, self::HOOK, $args );
		$key = md5( serialize( $args ) );
		$this->cron->trash_event( self::HOOK, $key, time() + 7200 );
		$trash_id = array_key_first( $this->trash_option() );

		// A second, unrelated one-off booking of the same hook and arguments.
		wp_schedule_single_event( time() + 86400, self::HOOK, $args );

		$result = $this->cron->restore_event( $trash_id );

		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( array(), $this->trash_option() );
	}

	public function test_restore_event_still_refuses_a_second_recurring_booking(): void {
		$args = array( 'c' => 3 );
		wp_schedule_event( time() + 900, 'hourly', self::HOOK, $args );
		$key = md5( serialize( $args ) );
		$this->cron->trash_event( self::HOOK, $key, time() + 900 );
		$trash_id = array_key_first( $this->trash_option() );

		// An identical recurring booking really would be a double booking.
		wp_schedule_event( time() + 1800, 'hourly', self::HOOK, $args );

		$result = $this->cron->restore_event( $trash_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'already scheduled', $result['message'] );
		$this->assertArrayHasKey( $trash_id, $this->trash_option() );
	}

	public function test_restore_event_refuses_when_identical_booking_exists(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );
		$trash_id = array_key_first( $this->trash_option() );
		wp_schedule_event( time() + 300, 'hourly', self::HOOK, array( 'a' => 1 ) );

		$result = $this->cron->restore_event( $trash_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'already scheduled', $result['message'] );
		$this->assertCount( 1, $this->bookings(), 'no second booking was made' );
		$this->assertArrayHasKey( $trash_id, $this->trash_option() );
	}

	public function test_trash_event_succeeds_and_unschedules(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );

		$this->assertTrue( $this->cron->trash_event( self::HOOK, $key, $this->original_timestamp )['success'] );
		$this->assertFalse( wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ) );
		$this->assertCount( 1, $this->trash_option() );
	}

	public function test_restore_event_reports_trash_entry_left_behind(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );
		$trash_id = array_key_first( $this->trash_option() );
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$result = $this->cron->restore_event( $trash_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'could not be removed from the trash', $result['message'] );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ), 'the event was re-booked' );
	}

	public function test_restore_event_succeeds_and_clears_trash_entry(): void {
		$key = md5( serialize( array( 'a' => 1 ) ) );
		$this->cron->trash_event( self::HOOK, $key, $this->original_timestamp );
		$trash_id = array_key_first( $this->trash_option() );

		$result = $this->cron->restore_event( $trash_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $this->trash_option() );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 'a' => 1 ) ) );
	}

	public function test_cleanup_expired_trash_reports_zero_when_write_fails(): void {
		update_option(
			'dragoncronmanager_trashed_crons',
			array(
				'old' => array( 'expires_at' => time() - 10 ),
				'new' => array( 'expires_at' => time() + DAY_IN_SECONDS ),
			)
		);
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$this->assertSame( 0, $this->cron->cleanup_expired_trash() );
		$this->assertCount( 2, $this->trash_option() );
	}

	public function test_cleanup_expired_trash_purges_expired_entries(): void {
		update_option(
			'dragoncronmanager_trashed_crons',
			array(
				'old' => array( 'expires_at' => time() - 10 ),
				'new' => array( 'expires_at' => time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 1, $this->cron->cleanup_expired_trash() );
		$this->assertSame( array( 'new' ), array_keys( $this->trash_option() ) );
	}
}
