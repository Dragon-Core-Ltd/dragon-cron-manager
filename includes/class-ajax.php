<?php
/**
 * Ajax Class
 *
 * Handles AJAX requests
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$args_json = isset( $_POST['args'] ) ? sanitize_text_field( wp_unslash( $_POST['args'] ) ) : '[]';
		$args      = json_decode( stripslashes( $args_json ), true );
		$args      = is_array( $args ) ? $args : array();

		if ( empty( $hook ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid hook.', 'dragon-cron-manager' ) ) );
		}

		$result = $this->cron->run_event( $hook, $args );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$args_json = isset( $_POST['args'] ) ? sanitize_text_field( wp_unslash( $_POST['args'] ) ) : '[]';
		$args      = json_decode( stripslashes( $args_json ), true );
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

		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => __( 'Cron event moved to trash. It will be permanently deleted in 30 days.', 'dragon-cron-manager' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to trash cron event.', 'dragon-cron-manager' ),
				)
			);
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

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of events deleted */
					_n(
						'%d cron event permanently deleted.',
						'%d cron events permanently deleted.',
						$count,
						'dragon-cron-manager'
					),
					$count
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

		$this->logger->clear_logs();

		wp_send_json_success(
			array(
				'message' => __( 'Logs cleared successfully.', 'dragon-cron-manager' ),
			)
		);
	}
}
