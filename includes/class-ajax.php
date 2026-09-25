<?php
/**
 * Ajax Class
 *
 * Handles AJAX requests
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

defined( 'ABSPATH' ) || exit;

class Ajax {

	/**
	 * Cron instance
	 */
	private Cron $cron;

	/**
	 * Logger instance
	 */
	private Logger $logger;

	/**
	 * Constructor
	 */
	public function __construct( Cron $cron, Logger $logger ) {
		$this->cron   = $cron;
		$this->logger = $logger;

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		add_action( 'wp_ajax_dragoncronmanager_run_event', array( $this, 'handle_run_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_test_event', array( $this, 'handle_test_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_add_event', array( $this, 'handle_add_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_trash_event', array( $this, 'handle_trash_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_restore_event', array( $this, 'handle_restore_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_delete_event', array( $this, 'handle_delete_event' ) );
		add_action( 'wp_ajax_dragoncronmanager_empty_trash', array( $this, 'handle_empty_trash' ) );
		add_action( 'wp_ajax_dragoncronmanager_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'wp_ajax_dragoncronmanager_diagnose', array( $this, 'handle_diagnose' ) );
	}

	/**
	 * Run the cron doctor (on demand — the loopback test is a live HTTP call).
	 */
	public function handle_diagnose(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		$result   = ( new Doctor( $this->cron ) )->diagnose();
		$last     = (int) ( $result['signals']['last_log_activity'] ?? 0 );
		$last_str = $last > 0
			? sprintf(
				/* translators: %s: human time diff */
				__( 'Last logged cron activity: %s ago.', 'dragon-cron-manager' ),
				human_time_diff( $last )
			)
			: __( 'No logged cron activity yet.', 'dragon-cron-manager' );

		wp_send_json_success(
			array(
				'findings'      => $result['findings'],
				'last_activity' => $last_str,
			)
		);
	}

	/**
	 * Handle run event request
	 */
	public function handle_run_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		// Decode the raw JSON without sanitize_text_field: it would corrupt
		// tag-like or whitespace arguments so the event no longer matches its
		// scheduled entry. run_event() only runs args that match a real scheduled
		// event, so the decoded value is validated against the trusted cron array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; JSON decoded and matched against the trusted cron array in run_event().
		$args_json = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : '[]';
		$args      = json_decode( is_string( $args_json ) ? $args_json : '[]', true );
		$args      = is_array( $args ) ? $args : array();

		if ( empty( $hook ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid hook.', 'dragon-cron-manager' ) ) );
		}

		// Slot of the row that was clicked, so a one-off booked twice runs the
		// booking the admin chose rather than the earliest.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$timestamp = isset( $_POST['timestamp'] ) ? absint( wp_unslash( $_POST['timestamp'] ) ) : 0;

		$result = $this->cron->run_event( $hook, $args, true, $timestamp );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle add-event request: schedule a new single or recurring cron event.
	 */
	public function handle_add_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		if ( '' === $hook || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $hook ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid hook name (letters, numbers, dashes and underscores only).', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$schedule = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
		if ( '' !== $schedule && ! array_key_exists( $schedule, wp_get_schedules() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown schedule.', 'dragon-cron-manager' ) ) );
		}

		// The form sends the first run as the site-local date and time it shows
		// (local_time); a Unix timestamp is still accepted for older callers.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$local_time = isset( $_POST['local_time'] ) ? sanitize_text_field( wp_unslash( $_POST['local_time'] ) ) : '';
		if ( '' !== $local_time ) {
			$timestamp = Cron::site_time_to_timestamp( $local_time, wp_timezone() );
			if ( null === $timestamp ) {
				wp_send_json_error( array( 'message' => __( 'Enter the first run as a date and time, or leave it blank to run as soon as possible.', 'dragon-cron-manager' ) ) );
			}
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$when      = isset( $_POST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['timestamp'] ) ) : '';
			$timestamp = ctype_digit( $when ) ? (int) $when : (int) strtotime( $when );
		}
		if ( $timestamp <= 0 ) {
			$timestamp = time();
		}
		// Never schedule in the past; clamp to now so the event is due immediately.
		$timestamp = max( $timestamp, time() );

		// Decode args as raw JSON (no sanitize_text_field — it would corrupt the
		// values); the result must be a plain array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; JSON decoded and validated to an array below.
		$args_json = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : '[]';
		$args      = json_decode( is_string( $args_json ) && '' !== $args_json ? $args_json : '[]', true );
		if ( ! is_array( $args ) ) {
			wp_send_json_error( array( 'message' => __( 'Arguments must be valid JSON (an array).', 'dragon-cron-manager' ) ) );
		}

		if ( '' !== $schedule && Cron::has_recurring_booking( $hook, $args ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: cron hook name */
						__( 'Cron event "%s" is already scheduled to repeat with these arguments, so it was not added again. Trash the existing event first if you want to replace it.', 'dragon-cron-manager' ),
						$hook
					),
				)
			);
		}

		// Checked before scheduling: a one-off event added for "now" is already
		// due. A recurring one stays listed, rescheduled for its next run.
		$due_now = '' === $schedule && $timestamp <= time();

		if ( $this->cron->add_event( $hook, $schedule, $timestamp, $args ) ) {
			// Loading any page spawns WP-Cron for due events, and it removes the
			// event before running it, so the reloaded list may not show it.
			wp_send_json_success(
				array(
					'message' => $due_now
						? __( 'Event scheduled to run now. WP-Cron may run it before this list reloads, so it may not appear below.', 'dragon-cron-manager' )
						: __( 'Event scheduled.', 'dragon-cron-manager' ),
					'due_now' => $due_now,
				)
			);
		}

		wp_send_json_error( array( 'message' => __( 'Could not schedule the event. An identical event may already be scheduled.', 'dragon-cron-manager' ) ) );
	}

	/**
	 * Handle test event request (run without rescheduling)
	 */
	public function handle_test_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		// Decode the raw JSON without sanitize_text_field: it would corrupt
		// tag-like or whitespace arguments so the event no longer matches its
		// scheduled entry. run_event() only runs args that match a real scheduled
		// event, so the decoded value is validated against the trusted cron array.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; JSON decoded and matched against the trusted cron array in run_event().
		$args_json = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : '[]';
		$args      = json_decode( is_string( $args_json ) ? $args_json : '[]', true );
		$args      = is_array( $args ) ? $args : array();

		if ( empty( $hook ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid hook.', 'dragon-cron-manager' ) ) );
		}

		// Run without rescheduling (test mode)
		$result = $this->cron->run_event( $hook, $args, false );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle trash event request (move to trash instead of permanent delete)
	 */
	public function handle_trash_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$timestamp = isset( $_POST['timestamp'] ) ? absint( $_POST['timestamp'] ) : 0;

		if ( empty( $hook ) || empty( $key ) || ! $timestamp ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'dragon-cron-manager' ) ) );
		}

		// Prevent trashing core WordPress cron events
		if ( $this->cron->is_core_hook( $hook ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot trash core WordPress cron events.', 'dragon-cron-manager' ),
				)
			);
		}

		$result = $this->cron->trash_event( $hook, $key, $timestamp );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle restore event request (restore from trash)
	 */
	public function handle_restore_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$trash_id = isset( $_POST['trash_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trash_id'] ) ) : '';

		if ( empty( $trash_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid trash ID.', 'dragon-cron-manager' ) ) );
		}

		$result = $this->cron->restore_event( $trash_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle permanent delete event request (delete from trash)
	 */
	public function handle_delete_event(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$trash_id = isset( $_POST['trash_id'] ) ? sanitize_text_field( wp_unslash( $_POST['trash_id'] ) ) : '';

		if ( empty( $trash_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid trash ID.', 'dragon-cron-manager' ) ) );
		}

		$result = $this->cron->delete_trashed_event( $trash_id );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => __( 'Cron event permanently deleted.', 'dragon-cron-manager' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to delete cron event.', 'dragon-cron-manager' ),
				)
			);
		}
	}

	/**
	 * Handle empty trash request
	 */
	public function handle_empty_trash(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		$count = $this->cron->empty_trash();

		if ( false === $count ) {
			wp_send_json_error( array( 'message' => __( 'Failed to empty the trash. The trashed events are still listed.', 'dragon-cron-manager' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: number of events deleted */
					_n(
						'%s cron event permanently deleted.',
						'%s cron events permanently deleted.',
						$count,
						'dragon-cron-manager'
					),
					number_format_i18n( $count )
				),
			)
		);
	}

	/**
	 * Handle clear logs request
	 */
	public function handle_clear_logs(): void {
		check_ajax_referer( 'dragoncronmanager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-cron-manager' ) ) );
		}

		if ( ! $this->logger->clear_logs() ) {
			wp_send_json_error( array( 'message' => __( 'Failed to clear the logs. The database refused the request.', 'dragon-cron-manager' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Logs cleared successfully.', 'dragon-cron-manager' ),
			)
		);
	}
}
