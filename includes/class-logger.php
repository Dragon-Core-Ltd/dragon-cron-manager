<?php
/**
 * Logger Class
 *
 * Handles cron execution logging
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

class Logger {

	/**
	 * Log table name
	 */
	private string $table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'dcm_log';

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		// Clean up old logs daily
		add_action( 'dragoncronmanager_cleanup_logs', array( $this, 'cleanup_old_logs' ) );

		// Schedule cleanup if not scheduled
		if ( ! wp_next_scheduled( 'dragoncronmanager_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'dragoncronmanager_cleanup_logs' );
		}
	}

	/**
	 * Log cron start
	 *
	 * @param string $hook Event hook
	 * @param array  $args Event arguments
	 * @return int Log entry ID
	 */
	public function log_start( string $hook, array $args = array() ): int {
		if ( ! get_option( 'dragoncronmanager_log_enabled', true ) ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom logging table, no WP API available.
		$wpdb->insert(
			$this->table,
			array(
				'hook'       => $hook,
				'args'       => wp_json_encode( $args ),
				'start_time' => current_time( 'mysql' ),
				'status'     => 'running',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Log cron completion
	 *
	 * @param int   $log_id   Log entry ID
	 * @param float $duration Execution duration in seconds
	 */
	public function log_complete( int $log_id, float $duration ): void {
		if ( ! $log_id ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		$wpdb->update(
			$this->table,
			array(
				'end_time' => current_time( 'mysql' ),
				'duration' => $duration,
				'status'   => 'completed',
			),
			array( 'id' => $log_id ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Log cron error
	 *
	 * @param int    $log_id   Log entry ID
	 * @param string $error    Error message
	 * @param float  $duration Execution duration
	 */
	public function log_error( int $log_id, string $error, float $duration ): void {
		if ( ! $log_id ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		$wpdb->update(
			$this->table,
			array(
				'end_time'      => current_time( 'mysql' ),
				'duration'      => $duration,
				'status'        => 'error',
				'error_message' => $error,
			),
			array( 'id' => $log_id ),
			array( '%s', '%f', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get log entries
	 *
	 * @param int    $limit  Max entries
	 * @param int    $offset Offset
	 * @param string $hook   Filter by hook (optional)
	 * @return array Log entries
	 */
	public function get_logs( int $limit = 50, int $offset = 0, string $hook = '' ): array {
		global $wpdb;

		$where  = '';
		$params = array();

		if ( ! empty( $hook ) ) {
			$where    = 'WHERE hook = %s';
			$params[] = $hook;
		}

		$params[] = $limit;
		$params[] = $offset;

		$query = "SELECT * FROM {$this->table} {$where} ORDER BY start_time DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Query safely built with prepare(); table from $wpdb->prefix.
		$results = $wpdb->get_results(
			$wpdb->prepare( $query, ...$params ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get log entry count
	 *
	 * @param string $hook Filter by hook (optional)
	 * @return int Count
	 */
	public function get_log_count( string $hook = '' ): int {
		global $wpdb;

		if ( ! empty( $hook ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
					"SELECT COUNT(*) FROM {$this->table} WHERE hook = %s",
					$hook
				)
			);
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, constructed from $wpdb->prefix
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Get recent executions for a hook
	 *
	 * @param string $hook  Hook name
	 * @param int    $limit Max entries
	 * @return array Log entries
	 */
	public function get_hook_history( string $hook, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
				"SELECT * FROM {$this->table}
                 WHERE hook = %s
                 ORDER BY start_time DESC
                 LIMIT %d",
				$hook,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get statistics
	 *
	 * @return array Stats
	 */
	public function get_stats(): array {
		global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, constructed from $wpdb->prefix
		$total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		$completed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'completed'" );
		$errors       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'error'" );
		$avg_duration = (float) $wpdb->get_var( "SELECT AVG(duration) FROM {$this->table} WHERE duration IS NOT NULL" );
        // phpcs:enable

		// Get today's count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		$today = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
				"SELECT COUNT(*) FROM {$this->table} WHERE DATE(start_time) = %s",
				current_time( 'Y-m-d' )
			)
		);

		return array(
			'total'        => $total,
			'completed'    => $completed,
			'errors'       => $errors,
			'error_rate'   => $total > 0 ? round( ( $errors / $total ) * 100, 1 ) : 0,
			'avg_duration' => round( $avg_duration, 3 ),
			'today'        => $today,
		);
	}

	/**
	 * Clean up old log entries
	 */
	public function cleanup_old_logs(): void {
		global $wpdb;

		$retention_days = (int) get_option( 'dragoncronmanager_log_retention_days', 7 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
				"DELETE FROM {$this->table} WHERE start_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$retention_days
			)
		);
	}

	/**
	 * Clear all logs
	 */
	public function clear_logs(): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, constructed from $wpdb->prefix
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}

	/**
	 * Delete a single log entry
	 *
	 * @param int $log_id Log entry ID
	 * @return bool Success
	 */
	public function delete_log( int $log_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logging table.
		return (bool) $wpdb->delete(
			$this->table,
			array( 'id' => $log_id ),
			array( '%d' )
		);
	}
}
