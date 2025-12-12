<?php
/**
 * Main Plugin Class
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

class Plugin {

	/**
	 * Singleton instance
	 */
	private static ?Plugin $instance = null;

	/**
	 * Component instances
	 */
	private ?Cron $cron     = null;
	private ?Logger $logger = null;
	private ?Admin $admin   = null;
	private ?Ajax $ajax     = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_components();
		$this->init_hooks();
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components(): void {
		$this->cron   = new Cron();
		$this->logger = new Logger();
		$this->ajax   = new Ajax( $this->cron, $this->logger );
		$this->admin  = new Admin( $this->cron, $this->logger );
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks(): void {
		add_action( 'dcm_cleanup_trash', [ $this, 'cleanup_expired_trash' ] );
	}

	/**
	 * Plugin activation
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();

		// Schedule trash cleanup (runs daily)
		if ( ! wp_next_scheduled( 'dcm_cleanup_trash' ) ) {
			wp_schedule_event( time(), 'daily', 'dcm_cleanup_trash' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate(): void {
		// Clear scheduled cron events
		wp_clear_scheduled_hook( 'dcm_cleanup_logs' );
		wp_clear_scheduled_hook( 'dcm_cleanup_trash' );
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Cron execution log table
		$table_log = $wpdb->prefix . 'dcm_log';
		$sql_log   = "CREATE TABLE $table_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hook varchar(255) NOT NULL,
            args longtext,
            start_time datetime NOT NULL,
            end_time datetime DEFAULT NULL,
            duration float DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'running',
            error_message text,
            PRIMARY KEY  (id),
            KEY idx_hook (hook),
            KEY idx_start_time (start_time),
            KEY idx_status (status)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_log );

		update_option( 'dcm_db_version', DCM_VERSION );
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options(): void {
		$defaults = [
			'dcm_log_retention_days' => 7,
			'dcm_log_enabled'        => true,
		];

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Get Cron instance
	 */
	public function get_cron(): Cron {
		return $this->cron;
	}

	/**
	 * Get Logger instance
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}

	/**
	 * Cleanup expired trashed cron events (called by cron)
	 */
	public function cleanup_expired_trash(): void {
		if ( $this->cron ) {
			$this->cron->cleanup_expired_trash();
		}
	}
}
