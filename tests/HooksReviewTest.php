<?php
/**
 * WP-CLI cron runs are logged, the loopback test sends what core's spawn
 * sends, multisite Add Event needs a network capability, and Run/Test find
 * events whose arguments do not survive a JSON round trip.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Ajax;
use DragonCronManager\Cron;
use DragonCronManager\Doctor;
use DragonCronManager\Logger;
use DragonCronManager\Tick_Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tick logger that reports a WP-CLI request.
 */
final class DragonCronManager_Test_Cli_Tick_Logger extends Tick_Logger {
	protected function running_under_cli(): bool {
		return true;
	}
}

final class HooksReviewTest extends TestCase {

	protected function setUp(): void {
		dragoncronmanager_test_reset();
		$_POST   = array();
		$_SERVER = array_diff_key( $_SERVER, array( 'REQUEST_METHOD' => true ) );
	}

	private function call( Ajax $ajax, string $method ): DragonCronManager_Test_Json_Exit {
		try {
			$ajax->$method();
		} catch ( DragonCronManager_Test_Json_Exit $e ) {
			return $e;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	public function test_a_wp_cli_request_registers_the_run_listeners(): void {
		// `wp cron event run` defines DOING_CRON only when it runs the event,
		// after wp_loaded, so registration cannot wait for it.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'my_cli_hook' );

		( new DragonCronManager_Test_Cli_Tick_Logger( new Logger() ) )->maybe_register();

		$this->assertTrue( has_action( 'my_cli_hook' ) );
	}

	public function test_a_plain_request_registers_nothing(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'my_cli_hook' );

		( new Tick_Logger( new Logger() ) )->maybe_register();

		$this->assertFalse( has_action( 'my_cli_hook' ) );
	}

	public function test_loopback_request_applies_the_core_cron_request_filter(): void {
		add_filter(
			'cron_request',
			static function ( $request ) {
				$request['args']['headers'] = array( 'Authorization' => 'Basic abc' );
				$request['url']             = str_replace( 'https://example.test/', 'https://internal.test/', $request['url'] );
				return $request;
			}
		);

		$request = Doctor::loopback_request( '123.45' );

		$this->assertSame( 'https://internal.test/wp-cron.php?doing_wp_cron=123.45', $request['url'] );
		$this->assertSame( array( 'Authorization' => 'Basic abc' ), $request['args']['headers'] );
		$this->assertTrue( $request['args']['blocking'], 'The test waits for the answer.' );
		$this->assertSame( 10, $request['args']['timeout'] );
	}

	public function test_loopback_request_without_filters_matches_core(): void {
		$request = Doctor::loopback_request( '1.5' );

		$this->assertSame( 'https://example.test/wp-cron.php?doing_wp_cron=1.5', $request['url'] );
		$this->assertFalse( $request['args']['sslverify'] );
	}

	public function test_multisite_add_event_needs_the_network_capability(): void {
		$GLOBALS['dragoncronmanager_test_multisite']   = true;
		$GLOBALS['dragoncronmanager_test_denied_caps'] = array( 'manage_network_options' );
		$_POST = array(
			'hook' => 'network_hook',
			'args' => '[]',
		);

		$response = $this->call( new Ajax( new Cron(), new Logger() ), 'handle_add_event' );

		$this->assertFalse( $response->success );
		$this->assertSame( array(), $GLOBALS['dragoncronmanager_test_cron'] );
	}

	public function test_single_site_add_event_needs_only_manage_options(): void {
		$GLOBALS['dragoncronmanager_test_denied_caps'] = array( 'manage_network_options' );
		$_POST = array(
			'hook'      => 'site_hook',
			'args'      => '[]',
			'timestamp' => (string) ( time() + HOUR_IN_SECONDS ),
		);

		$this->assertTrue( $this->call( new Ajax( new Cron(), new Logger() ), 'handle_add_event' )->success );
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function lossy_args(): array {
		return array(
			'whole-number float' => array( array( 1.0 ) ),
			'integer above 2^53' => array( array( 9007199254740993 ) ),
		);
	}

	/**
	 * @dataProvider lossy_args
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'lossy_args' )]
	public function test_run_and_test_find_the_event_by_its_key( array $args ): void {
		$when = time() + 600;
		wp_schedule_single_event( $when, 'my_lossy_hook', $args );
		add_action( 'my_lossy_hook', static function () {} );
		update_option( 'dragoncronmanager_log_enabled', false );
		$key = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Core's event key.
		// What the browser sends back after JSON.parse/JSON.stringify.
		$browser_args = 1.0 === ( $args[0] ?? null ) ? '[1]' : '[9007199254740992]';

		$_POST = array(
			'hook'      => 'my_lossy_hook',
			'args'      => $browser_args,
			'key'       => $key,
			'timestamp' => (string) $when,
		);
		$this->assertTrue( $this->call( new Ajax( new Cron(), new Logger() ), 'handle_test_event' )->success );

		$_POST['timestamp'] = (string) $when;
		$this->assertTrue( $this->call( new Ajax( new Cron(), new Logger() ), 'handle_run_event' )->success );
		$this->assertFalse( wp_get_scheduled_event( 'my_lossy_hook', $args, $when ) );
	}

	public function test_an_unknown_key_falls_back_to_the_posted_args(): void {
		wp_schedule_single_event( time() + 600, 'my_plain_hook', array( 'x' ) );
		add_action( 'my_plain_hook', static function () {} );
		update_option( 'dragoncronmanager_log_enabled', false );
		$_POST = array(
			'hook' => 'my_plain_hook',
			'args' => '["x"]',
			'key'  => str_repeat( 'f', 32 ),
		);

		$this->assertTrue( $this->call( new Ajax( new Cron(), new Logger() ), 'handle_test_event' )->success );
	}
}
