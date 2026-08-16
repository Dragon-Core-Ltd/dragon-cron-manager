<?php
/**
 * Cron doctor: answers "my cron isn't firing — why?" with a root-cause
 * diagnosis instead of a list of symptoms.
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers live signals (loopback test, lock age, overdue stats, config) and
 * turns them into an ordered list of findings. verdict() is pure so the
 * decision table is unit-testable without WordPress.
 */
class Doctor {

	/**
	 * A doing_cron lock older than this is considered stuck.
	 */
	private const STUCK_LOCK_SECONDS = 600;

	/**
	 * Overdue longer than this triggers root-cause analysis.
	 */
	private const OVERDUE_ALARM_SECONDS = 3600;

	/**
	 * Cron manager.
	 *
	 * @var Cron
	 */
	private Cron $cron;

	/**
	 * Constructor.
	 *
	 * @param Cron $cron Cron manager.
	 */
	public function __construct( Cron $cron ) {
		$this->cron = $cron;
	}

	/**
	 * Run the full diagnosis: gather live signals, return ordered findings.
	 *
	 * @return array{findings: array<int, array{severity:string, title:string, detail:string, fix:string}>, signals: array}
	 */
	public function diagnose(): array {
		$signals = $this->gather_signals();

		return array(
			'findings' => self::verdict( $signals ),
			'signals'  => $signals,
		);
	}

	/**
	 * Collect the live signals the verdict is based on.
	 *
	 * The loopback test performs the same spawn a real cron tick would, so a
	 * pass here both proves the path works and kicks any overdue queue.
	 *
	 * @return array
	 */
	public function gather_signals(): array {
		$events      = $this->cron->get_events();
		$overdue     = 0;
		$max_overdue = 0;
		foreach ( $events as $event ) {
			if ( ! empty( $event['is_overdue'] ) ) {
				++$overdue;
				$max_overdue = max( $max_overdue, time() - (int) $event['timestamp'] );
			}
		}

		$lock     = get_transient( 'doing_cron' );
		$lock_age = 0;
		if ( $lock ) {
			// The lock value is the microtime the run started.
			$lock_age = max( 0, (int) ( microtime( true ) - (float) $lock ) );
		}

		return array(
			'disable_wp_cron'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'overdue_count'     => $overdue,
			'max_overdue'       => $max_overdue,
			'event_count'       => count( $events ),
			'lock_age'          => $lock_age,
			'loopback'          => $this->loopback_test(),
			'last_log_activity' => $this->last_log_activity(),
			'site_url'          => site_url( 'wp-cron.php' ),
		);
	}

	/**
	 * Turn signals into ordered findings. Pure — no WordPress calls.
	 *
	 * @param array $s Signals from gather_signals() (or a test fixture).
	 * @return array<int, array{severity:string, title:string, detail:string, fix:string}>
	 */
	public static function verdict( array $s ): array {
		$findings = array();
		$stalled  = (int) $s['max_overdue'] >= self::OVERDUE_ALARM_SECONDS;

		// Root cause 1: WP-Cron handed off to a system cron that is not running.
		if ( $stalled && ! empty( $s['disable_wp_cron'] ) ) {
			$findings[] = array(
				'severity' => 'critical',
				'title'    => __( 'Your system cron is not running', 'dragon-cron-manager' ),
				'detail'   => __( 'DISABLE_WP_CRON is set, which hands scheduling to a server cron job — but tasks are hours overdue, so that job is missing, disabled, or failing.', 'dragon-cron-manager' ),
				'fix'      => sprintf(
					/* translators: %s: wp-cron.php URL */
					__( 'Add a server cron entry such as: */5 * * * * curl -s %s >/dev/null 2>&1 — or ask your host to confirm the existing one still runs.', 'dragon-cron-manager' ),
					(string) $s['site_url']
				),
			);
		}

		// Root cause 2: WordPress cannot reach its own wp-cron.php.
		$loopback = (array) ( $s['loopback'] ?? array() );
		if ( $stalled && empty( $s['disable_wp_cron'] ) && empty( $loopback['ok'] ) ) {
			$findings[] = array(
				'severity' => 'critical',
				'title'    => __( 'Your site cannot reach its own cron endpoint', 'dragon-cron-manager' ),
				'detail'   => sprintf(
					/* translators: %s: the loopback error */
					__( 'WP-Cron fires by the site requesting its own wp-cron.php, and that request is failing: %s', 'dragon-cron-manager' ),
					(string) ( $loopback['error'] ?? __( 'unknown error', 'dragon-cron-manager' ) )
				),
				'fix'      => __( 'Common causes: password protection / basic auth on the site, a firewall or security plugin blocking loopback requests, a maintenance page, or DNS that does not resolve from the server itself. Fix the loopback, or set DISABLE_WP_CRON and use a server cron.', 'dragon-cron-manager' ),
			);
		}

		// Root cause 3: a stuck lock is blocking every subsequent run.
		if ( (int) $s['lock_age'] >= self::STUCK_LOCK_SECONDS ) {
			$findings[] = array(
				'severity' => 'warning',
				'title'    => __( 'A previous cron run appears to have crashed', 'dragon-cron-manager' ),
				'detail'   => __( 'The doing_cron lock is older than ten minutes, which means a run started and never finished — usually a fatal error or timeout inside one scheduled task.', 'dragon-cron-manager' ),
				'fix'      => __( 'The lock expires on its own, but if this recurs, check the Logs tab for the task that starts and never completes, and your PHP error log for the fatal.', 'dragon-cron-manager' ),
			);
		}

		// Stalled but every mechanism checks out: point at the queue itself.
		if ( $stalled && empty( $findings ) ) {
			$findings[] = array(
				'severity' => 'warning',
				'title'    => __( 'Cron can run, but the queue is behind', 'dragon-cron-manager' ),
				'detail'   => __( 'The spawn mechanism works, yet tasks are overdue. On low-traffic sites WP-Cron only fires when someone visits; a long-running task can also starve everything scheduled after it.', 'dragon-cron-manager' ),
				'fix'      => __( 'The diagnosis itself just kicked the queue. For a permanent fix on a quiet site, set DISABLE_WP_CRON and add a server cron every 5 minutes; use the Logs tab to spot slow tasks.', 'dragon-cron-manager' ),
			);
		}

		if ( empty( $findings ) ) {
			$findings[] = array(
				'severity' => 'ok',
				'title'    => __( 'Cron is healthy', 'dragon-cron-manager' ),
				'detail'   => ( ! empty( $s['disable_wp_cron'] ) )
					? __( 'A system cron is in charge (DISABLE_WP_CRON is set) and nothing is meaningfully overdue.', 'dragon-cron-manager' )
					: __( 'The loopback test passed and nothing is meaningfully overdue.', 'dragon-cron-manager' ),
				'fix'      => '',
			);
		}

		return $findings;
	}

	/**
	 * Perform the same spawn request core cron uses and report the outcome.
	 *
	 * @return array{ok: bool, status: int, error: string}
	 */
	private function loopback_test(): array {
		$url      = site_url( 'wp-cron.php?doing_wp_cron=' . rawurlencode( (string) microtime( true ) ) );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 10,
				'blocking'  => true,
				/** This filter is documented in wp-includes/cron.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 400 ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'error'  => sprintf(
					/* translators: %d: HTTP status code */
					__( 'wp-cron.php answered HTTP %d', 'dragon-cron-manager' ),
					$status
				),
			);
		}

		return array(
			'ok'     => true,
			'status' => $status,
			'error'  => '',
		);
	}

	/**
	 * Timestamp of the most recent logged cron execution, or 0.
	 *
	 * @return int Unix timestamp (site-local converted), 0 when unknown.
	 */
	private function last_log_activity(): int {
		global $wpdb;

		if ( ! get_option( 'dragoncronmanager_log_enabled', true ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'dcm_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single MAX() over the custom log table on demand; table name is plugin-built, matching Logger's queries (plugin supports WP 6.0, predating %i).
		$latest = $wpdb->get_var( "SELECT MAX(start_time) FROM {$table}" );

		return $latest ? (int) strtotime( (string) $latest ) : 0;
	}
}
