<?php
/**
 * Main Plugin Class
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

defined( 'ABSPATH' ) || exit;

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
		self::migrate_legacy_prefix();
		$this->init_components();
		$this->init_hooks();
	}

	/**
	 * Move options and scheduled events off the pre-1.0.1 three-letter (dcm_)
	 * prefix.
	 *
	 * The prefix was renamed to the namespace-derived `dragoncronmanager_` to
	 * satisfy the WordPress.org uniqueness rule. Option values are carried across
	 * once and the two cleanup events are re-pointed at the renamed hooks. The
	 * log table keeps its `dcm_log` name (matched by exact name), so log history
	 * is untouched.
	 */
	private static function migrate_legacy_prefix(): void {
		// Run once, not per request: the marker is autoloaded so this check is
		// free, while the get_option() misses below each cost a query forever.
		if ( get_option( 'dragoncronmanager_prefix_migrated' ) ) {
			return;
		}

		// db_version is a schema marker managed by activation, not user data.
		delete_option( 'dcm_db_version' );

		// Includes trashed_crons — the recovery payload for cron events the user
		// moved to Trash (unscheduled from WP-cron; restorable for 30 days).
		$options = array( 'log_enabled', 'log_retention_days', 'trashed_crons' );

		// Copy each legacy value onto the new name, then remove the legacy copy —
		// per option, so the delete only ever runs after a successful copy. (A
		// single shared guard would delete on a deactivate/reactivate cycle, where
		// activation re-stamps the new db_version before the copy could run.)
		foreach ( $options as $name ) {
			$legacy = get_option( 'dcm_' . $name, null );
			if ( null !== $legacy ) {
				update_option( 'dragoncronmanager_' . $name, $legacy );
				delete_option( 'dcm_' . $name );
			}
		}

		foreach ( array( 'dcm_cleanup_logs', 'dcm_cleanup_trash' ) as $legacy_hook ) {
			$timestamp = wp_next_scheduled( $legacy_hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $legacy_hook );
			}
		}

		if ( ! wp_next_scheduled( 'dragoncronmanager_cleanup_trash' ) ) {
			wp_schedule_event( time(), 'daily', 'dragoncronmanager_cleanup_trash' );
		}

		// Flip the trash payload out of autoload on existing installs — it is
		// only read on the admin screen but was loading on every request.
		$dragoncronmanager_trash = get_option( 'dragoncronmanager_trashed_crons', null );
		if ( null !== $dragoncronmanager_trash ) {
			delete_option( 'dragoncronmanager_trashed_crons' );
			add_option( 'dragoncronmanager_trashed_crons', $dragoncronmanager_trash, '', false );
		}

		update_option( 'dragoncronmanager_prefix_migrated', 1 );
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
		add_action( 'dragoncronmanager_cleanup_trash', array( $this, 'cleanup_expired_trash' ) );
	}

	/**
	 * Plugin activation
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();

		// Schedule trash cleanup (runs daily)
		if ( ! wp_next_scheduled( 'dragoncronmanager_cleanup_trash' ) ) {
			wp_schedule_event( time(), 'daily', 'dragoncronmanager_cleanup_trash' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate(): void {
		// Clear scheduled cron events
		wp_clear_scheduled_hook( 'dragoncronmanager_cleanup_logs' );
		wp_clear_scheduled_hook( 'dragoncronmanager_cleanup_trash' );
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

		update_option( 'dragoncronmanager_db_version', DRAGONCRONMANAGER_VERSION );
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'dragoncronmanager_log_retention_days' => 7,
			'dragoncronmanager_log_enabled'        => true,
		);

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
