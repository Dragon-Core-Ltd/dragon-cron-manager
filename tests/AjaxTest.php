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
}
