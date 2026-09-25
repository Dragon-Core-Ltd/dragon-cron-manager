<?php
/**
 * Ajax: bulk actions must not answer "success" when the underlying write failed.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Ajax;
use DragonCronManager\Cron;
use DragonCronManager\Logger;
use PHPUnit\Framework\TestCase;

final class AjaxTest extends TestCase {

	private Ajax $ajax;

	protected function setUp(): void {
		dragoncronmanager_test_reset();
		$_POST      = array();
		$this->ajax = new Ajax( new Cron(), new Logger() );
	}

	private function call( string $method ): DragonCronManager_Test_Json_Exit {
		try {
			$this->ajax->$method();
		} catch ( DragonCronManager_Test_Json_Exit $e ) {
			return $e;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	public function test_empty_trash_reports_failure_when_delete_does_not_persist(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array() ) );
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$response = $this->call( 'handle_empty_trash' );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'Failed to empty the trash', $response->data['message'] );
	}

	public function test_empty_trash_reports_count_on_success(): void {
		update_option( 'dragoncronmanager_trashed_crons', array( 'trash_1' => array(), 'trash_2' => array() ) );

		$response = $this->call( 'handle_empty_trash' );

		$this->assertTrue( $response->success );
		$this->assertSame( '2 cron events permanently deleted.', $response->data['message'] );
	}

	public function test_trash_event_relays_the_failure_reason(): void {
		add_action( 'some_hook', static function () {} );
		wp_schedule_event( time() + 600, 'hourly', 'some_hook', array() );
		$_POST = array(
			'hook'      => 'some_hook',
			'key'       => md5( serialize( array() ) ),
			'timestamp' => (string) wp_next_scheduled( 'some_hook' ),
		);
		$GLOBALS['dragoncronmanager_test_option_write_fail'] = true;

		$response = $this->call( 'handle_trash_event' );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'could not be saved to the trash', $response->data['message'] );
		$this->assertNotFalse( wp_next_scheduled( 'some_hook' ) );
	}

	public function test_trash_event_reports_success(): void {
		add_action( 'some_hook', static function () {} );
		wp_schedule_event( time() + 600, 'hourly', 'some_hook', array() );
		$_POST = array(
			'hook'      => 'some_hook',
			'key'       => md5( serialize( array() ) ),
			'timestamp' => (string) wp_next_scheduled( 'some_hook' ),
		);

		$response = $this->call( 'handle_trash_event' );

		$this->assertTrue( $response->success );
		$this->assertStringContainsString( 'moved to trash', $response->data['message'] );
		$this->assertFalse( wp_next_scheduled( 'some_hook' ) );
	}

	public function test_clear_logs_reports_failure_when_truncate_fails(): void {
		$GLOBALS['wpdb']->query_result = false;

		$response = $this->call( 'handle_clear_logs' );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'Failed to clear', $response->data['message'] );
	}

	public function test_clear_logs_reports_success(): void {
		$response = $this->call( 'handle_clear_logs' );

		$this->assertTrue( $response->success );
		$this->assertSame( 'Logs cleared successfully.', $response->data['message'] );
	}

	public function test_add_event_reads_first_run_in_the_site_timezone(): void {
		$GLOBALS['dragoncronmanager_test_timezone'] = 'Europe/Berlin';
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => '',
			'local_time' => '2031-01-15T09:30',
			'args'       => '[]',
		);

		$response = $this->call( 'handle_add_event' );

		$this->assertTrue( $response->success );
		// 09:30 in Berlin (CET, UTC+1) is 08:30 UTC, whatever the browser's zone.
		$this->assertSame( gmmktime( 8, 30, 0, 1, 15, 2031 ), wp_next_scheduled( 'my_added_hook' ) );
	}

	public function test_add_event_accepts_seconds_in_the_first_run(): void {
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => 'daily',
			'local_time' => '2031-01-15T09:30:15',
			'args'       => '[]',
		);

		$this->assertTrue( $this->call( 'handle_add_event' )->success );
		$this->assertSame( gmmktime( 9, 30, 15, 1, 15, 2031 ), wp_next_scheduled( 'my_added_hook' ) );
	}

	public function test_add_event_refuses_an_unreadable_first_run(): void {
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => '',
			'local_time' => 'next tuesday-ish',
			'args'       => '[]',
		);

		$response = $this->call( 'handle_add_event' );

		$this->assertFalse( $response->success );
		$this->assertFalse( wp_next_scheduled( 'my_added_hook' ) );
	}

	public function test_add_event_blank_first_run_means_now(): void {
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => '',
			'local_time' => '',
			'args'       => '[]',
		);
		$before = time();

		$this->assertTrue( $this->call( 'handle_add_event' )->success );
		$this->assertGreaterThanOrEqual( $before, wp_next_scheduled( 'my_added_hook' ) );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( 'my_added_hook' ) );
	}

	public function test_add_event_due_now_says_it_may_run_before_the_list_reloads(): void {
		// Core's wp_cron() on init spawns wp-cron.php for due events, and that
		// request unschedules the event before firing it, so the reloaded list
		// can already be without it.
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => '',
			'local_time' => '',
			'args'       => '[]',
		);

		$response = $this->call( 'handle_add_event' );

		$this->assertTrue( $response->success );
		$this->assertTrue( $response->data['due_now'] );
		$this->assertStringContainsString( 'may run it before this list reloads', $response->data['message'] );
	}

	public function test_add_event_in_the_future_is_not_due_now(): void {
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => '',
			'local_time' => '2031-01-15T09:30',
			'args'       => '[]',
		);

		$response = $this->call( 'handle_add_event' );

		$this->assertFalse( $response->data['due_now'] );
		$this->assertSame( 'Event scheduled.', $response->data['message'] );
	}

	public function test_a_recurring_event_due_now_is_not_flagged(): void {
		$_POST = array(
			'hook'       => 'my_added_hook',
			'schedule'   => 'daily',
			'local_time' => '',
			'args'       => '[]',
		);

		$this->assertFalse( $this->call( 'handle_add_event' )->data['due_now'] );
	}

	public function test_add_event_still_accepts_a_unix_timestamp(): void {
		$when  = gmmktime( 12, 0, 0, 6, 1, 2031 );
		$_POST = array(
			'hook'      => 'my_added_hook',
			'schedule'  => '',
			'timestamp' => (string) $when,
			'args'      => '[]',
		);

		$this->assertTrue( $this->call( 'handle_add_event' )->success );
		$this->assertSame( $when, wp_next_scheduled( 'my_added_hook' ) );
	}

	public function test_add_event_refuses_to_double_book_a_recurring_hook(): void {
		$first = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $first, 'daily', 'my_added_hook', array( 'a' ) );
		$_POST = array(
			'hook'      => 'my_added_hook',
			'schedule'  => 'hourly',
			'timestamp' => (string) ( time() + DAY_IN_SECONDS ),
			'args'      => '["a"]',
		);

		$response = $this->call( 'handle_add_event' );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'already scheduled', $response->data['message'] );
		$this->assertSame( $first, wp_next_scheduled( 'my_added_hook', array( 'a' ) ) );
		$this->assertSame( 'daily', wp_get_scheduled_event( 'my_added_hook', array( 'a' ), $first )->schedule );
	}

	public function test_add_event_allows_a_recurring_hook_with_different_args(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'my_added_hook', array( 'a' ) );
		$_POST = array(
			'hook'      => 'my_added_hook',
			'schedule'  => 'daily',
			'timestamp' => (string) ( time() + DAY_IN_SECONDS ),
			'args'      => '["b"]',
		);

		$this->assertTrue( $this->call( 'handle_add_event' )->success );
	}

	public function test_cron_add_event_refuses_a_recurring_double_booking(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'my_added_hook' );

		$this->assertFalse( ( new Cron() )->add_event( 'my_added_hook', 'daily', time() + DAY_IN_SECONDS ) );
	}

	public function test_run_event_runs_the_booking_on_the_clicked_row(): void {
		$early = time() + 600;
		$late  = time() + 7200;
		wp_schedule_single_event( $early, 'my_single_hook', array( 'x' ) );
		wp_schedule_single_event( $late, 'my_single_hook', array( 'x' ) );
		add_action( 'my_single_hook', static function () {} );
		update_option( 'dragoncronmanager_log_enabled', false );
		$_POST = array(
			'hook'      => 'my_single_hook',
			'args'      => '["x"]',
			'timestamp' => (string) $late,
		);

		$this->assertTrue( $this->call( 'handle_run_event' )->success );
		$this->assertNotFalse( wp_get_scheduled_event( 'my_single_hook', array( 'x' ), $early ), 'the other booking is untouched' );
		$this->assertFalse( wp_get_scheduled_event( 'my_single_hook', array( 'x' ), $late ) );
	}
}
