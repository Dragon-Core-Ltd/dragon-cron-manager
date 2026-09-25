<?php
/**
 * Tick Logger Class
 *
 * Records automatic WP-Cron executions (real ticks), not just manual runs, so
 * the run log and the Cron Doctor reflect what actually happens on schedule.
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps each scheduled hook that fires during a cron run with start/complete
 * bracket listeners, logging the real execution and its duration.
 */
class Tick_Logger {

	/**
	 * Logger instance.
	 */
	private Logger $logger;

	/**
	 * Open log rows for the current run, as a LIFO stack of
	 * array{ id: int, start: float }. A hook can fire more than once per run and
	 * runs can nest, so entries are pushed on start and popped on complete.
	 *
	 * @var array<int, array{id: int, start: float}>
	 */
	private array $stack = array();

	/**
	 * The plugin's own maintenance hooks, excluded from tick logging so the log
	 * does not fill with entries for its own housekeeping.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_HOOKS = array(
		'dragoncronmanager_cleanup_logs',
		'dragoncronmanager_cleanup_trash',
	);

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Hook the bracket registration.
	 *
	 * Registration is deferred to wp_loaded priority 0 (before core's alternate
	 * cron runner at priority 20) and only ever attaches on requests that can
	 * execute cron (including WP-CLI, which runs events with `wp cron event run`)
	 * — never on admin or AJAX requests, so has_action() stays clean
	 * for the "is this event actually scheduled?" guard in Cron::run_event().
	 */
	public function init(): void {
		if ( ! get_option( 'dragoncronmanager_log_enabled', true ) ) {
			return;
		}

		add_action( 'wp_loaded', array( $this, 'maybe_register' ), 0 );
	}

	/**
	 * Register bracket listeners for every scheduled hook, when this request will
	 * actually run cron.
	 *
	 * Normal WP-Cron requests (wp-cron.php) already have DOING_CRON defined here.
	 * Under ALTERNATE_WP_CRON the callbacks run later in this same front-end
	 * request (core requires wp-cron.php during wp_loaded priority 20), so we also
	 * register on a front-end GET when a job is due; the listeners self-gate on
	 * wp_doing_cron() so they only log once the cron loop is actually running.
	 */
	public function maybe_register(): void {
		$doing_cron = wp_doing_cron();

		$alternate = ! $doing_cron
			&& defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON
			&& ! is_admin()
			&& ! wp_doing_ajax()
			&& isset( $_SERVER['REQUEST_METHOD'] )
			&& 'GET' === $_SERVER['REQUEST_METHOD'];

		// `wp cron event run` defines DOING_CRON only as it runs each event,
		// after wp_loaded; the listeners self-gate on wp_doing_cron().
		$cli = ! $doing_cron && ! $alternate && $this->running_under_cli();

		if ( ! $doing_cron && ! $alternate && ! $cli ) {
			return;
		}

		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return;
		}

		// Under alternate cron only pay the registration cost when a job is
		// actually due — the same condition core uses before spawning cron.
		if ( $alternate && ! $this->has_due_event( $crons ) ) {
			return;
		}

		$registered = array();
		foreach ( $crons as $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				$hook = (string) $hook;
				if ( isset( $registered[ $hook ] ) || in_array( $hook, self::EXCLUDED_HOOKS, true ) ) {
					continue;
				}
				$registered[ $hook ] = true;

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Bracketing existing scheduled hooks to time their execution.
				add_action( $hook, array( $this, 'on_start' ), PHP_INT_MIN, 20 );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Bracketing existing scheduled hooks to time their execution.
				add_action( $hook, array( $this, 'on_complete' ), PHP_INT_MAX, 20 );
			}
		}

		// A fatal or timeout inside a callback would leave a row open; reconcile
		// anything still running to an error on shutdown.
		register_shutdown_function( array( $this, 'reconcile' ) );
	}

	/**
	 * Open a log row when a scheduled hook starts (priority PHP_INT_MIN).
	 *
	 * Self-gates on wp_doing_cron() so it records only real cron executions, not
	 * an incidental front-end firing of the same hook name.
	 */
	public function on_start(): void {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$hook = (string) current_action();
		$args = func_get_args();

		$this->stack[] = array(
			'id'    => $this->logger->log_start( $hook, $args, 'auto' ),
			'start' => microtime( true ),
		);
	}

	/**
	 * Close the most recent open log row when the hook finishes (PHP_INT_MAX).
	 */
	public function on_complete(): void {
		$entry = array_pop( $this->stack );
		if ( null === $entry ) {
			return;
		}

		$this->logger->log_complete( $entry['id'], microtime( true ) - $entry['start'] );
	}

	/**
	 * Reconcile any rows left open by a crashed callback into errors.
	 */
	public function reconcile(): void {
		if ( empty( $this->stack ) ) {
			return;
		}

		$last    = error_get_last();
		$message = ( is_array( $last ) && isset( $last['message'] ) )
			? (string) $last['message']
			: __( 'Cron callback did not complete (possible fatal error or timeout).', 'dragon-cron-manager' );

		foreach ( $this->stack as $entry ) {
			$this->logger->log_error( $entry['id'], $message, microtime( true ) - $entry['start'] );
		}

		$this->stack = array();
	}

	/**
	 * Whether this request is a WP-CLI command.
	 *
	 * @return bool
	 */
	protected function running_under_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Whether any event in the cron array is due now.
	 *
	 * @param array $crons Cron array keyed by timestamp.
	 * @return bool
	 */
	private function has_due_event( array $crons ): bool {
		$now = time();
		foreach ( array_keys( $crons ) as $timestamp ) {
			if ( (int) $timestamp <= $now ) {
				return true;
			}
		}

		return false;
	}
}
