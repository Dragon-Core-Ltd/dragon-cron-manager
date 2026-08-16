<?php
/**
 * Admin Class
 *
 * Handles admin pages and assets
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

class Admin {

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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu(): void {
		add_management_page(
			__( 'Cron Manager', 'dragon-cron-manager' ),
			__( 'Cron Manager', 'dragon-cron-manager' ),
			'manage_options',
			'dragon-cron-manager',
			array( $this, 'render_dashboard_page' )
		);
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_dragon-cron-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'dcm-admin',
			DRAGONCRONMANAGER_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			DRAGONCRONMANAGER_VERSION
		);

		wp_enqueue_script(
			'dcm-admin',
			DRAGONCRONMANAGER_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			DRAGONCRONMANAGER_VERSION,
			true
		);

		wp_localize_script(
			'dcm-admin',
			'dcmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dragoncronmanager_admin_nonce' ),
				'i18n'    => array(
					'running'           => __( 'Running...', 'dragon-cron-manager' ),
					'success'           => __( 'Success!', 'dragon-cron-manager' ),
					'error'             => __( 'Error', 'dragon-cron-manager' ),
					'confirmTrash'      => __( 'Move this cron event to trash?', 'dragon-cron-manager' ),
					'confirmRestore'    => __( 'Restore this cron event?', 'dragon-cron-manager' ),
					'confirmDelete'     => __( "PERMANENTLY DELETE this cron event?\n\nThis action CANNOT be undone. The cron event will be gone forever.", 'dragon-cron-manager' ),
					'confirmEmptyTrash' => __( "PERMANENTLY DELETE all trashed cron events?\n\nThis action CANNOT be undone. All items in trash will be gone forever.", 'dragon-cron-manager' ),
					'confirmClear'      => __( 'Are you sure you want to clear all logs?', 'dragon-cron-manager' ),
					'diagnose'          => __( 'Diagnose', 'dragon-cron-manager' ),
					'diagnosing'        => __( 'Diagnosing…', 'dragon-cron-manager' ),
				),
			)
		);
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dragon-cron-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab is a display-only parameter.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'events';

		$events         = $this->cron->get_events();
		$health         = $this->cron->get_health();
		$summary        = $this->cron->get_summary();
		$schedules      = $this->cron->get_schedules();
		$logs           = $this->logger->get_logs( 50 );
		$log_stats      = $this->logger->get_stats();
		$trashed_events = $this->cron->get_trashed_events();
		$trash_count    = count( $trashed_events );

		include DRAGONCRONMANAGER_PLUGIN_DIR . 'admin/views/dashboard.php';
	}
}
