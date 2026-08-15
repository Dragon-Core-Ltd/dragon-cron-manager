<?php
/**
 * Cron Class
 *
 * Handles WP-Cron interactions
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

class Cron {

	/**
	 * Option name for trashed crons
	 */
	private const TRASH_OPTION = 'dragoncronmanager_trashed_crons';

	/**
	 * Trash retention period in days
	 */
	private const TRASH_RETENTION_DAYS = 30;

	/**
	 * Core WordPress cron hooks (should not be deleted)
	 */
	private const CORE_HOOKS = array(
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_version_check',
		'wp_privacy_delete_old_export_files',
		'wp_site_health_scheduled_check',
		'recovery_mode_clean_expired_keys',
		'delete_expired_transients',
	);

	/**
	 * Get all scheduled cron events
	 *
	 * @return array Formatted cron events
	 */
	public function get_events(): array {
		$crons = _get_cron_array();

		if ( empty( $crons ) ) {
			return array();
		}

		$events = array();

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $schedules ) {
				foreach ( $schedules as $key => $data ) {
					$events[] = array(
						'timestamp'    => $timestamp,
						'hook'         => $hook,
						'key'          => $key,
						'schedule'     => $data['schedule'] ?? false,
						'interval'     => $data['interval'] ?? 0,
						'args'         => $data['args'] ?? array(),
						'next_run'     => $this->format_time_until( $timestamp ),
						'next_run_raw' => $timestamp - time(),
						'is_overdue'   => $timestamp < time(),
						'is_core'      => $this->is_core_hook( $hook ),
						'is_recurring' => ! empty( $data['schedule'] ),
					);
				}
			}
		}

		// Sort by timestamp
		usort( $events, fn( $a, $b ) => $a['timestamp'] <=> $b['timestamp'] );

		return $events;
	}

	/**
	 * Get event by hook and key
	 *
	 * @param string $hook Event hook
	 * @param string $key  Event key (md5 of args)
	 * @return array|null Event data or null
	 */
	public function get_event( string $hook, string $key ): ?array {
		$events = $this->get_events();

		foreach ( $events as $event ) {
			if ( $event['hook'] === $hook && $event['key'] === $key ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * Run a cron event immediately
	 *
	 * @param string $hook       Event hook
	 * @param array  $args       Event arguments
	 * @param bool   $reschedule Whether to reschedule recurring events (default true)
	 * @return array Result with success status and message
	 */
	public function run_event( string $hook, array $args = array(), bool $reschedule = true ): array {
		// Check if hook has any callbacks
		if ( ! has_action( $hook ) ) {
			return array(
				'success' => false,
				'message' => __( 'No callbacks registered for this hook.', 'dragon-cron-manager' ),
			);
		}

		// Get event details for rescheduling
		$crons           = _get_cron_array();
		$event_schedule  = null;
		$event_timestamp = null;

		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				foreach ( $hooks[ $hook ] as $key => $data ) {
					if ( $data['args'] === $args ) {
						$event_schedule  = $data['schedule'] ?? false;
						$event_timestamp = $timestamp;
						break 2;
					}
				}
			}
		}

		$start_time = microtime( true );

		try {
			// Log start
			$logger = new Logger();
			$log_id = $logger->log_start( $hook, $args );

			// Execute the cron hook.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Intentional: cron manager executes registered cron hooks.
			do_action_ref_array( $hook, $args );

			$duration = microtime( true ) - $start_time;

			// Log completion
			$logger->log_complete( $log_id, $duration );

			// Reschedule the event if it's recurring and reschedule is enabled
			$rescheduled = false;
			if ( $reschedule && $event_schedule && $event_timestamp ) {
				// Calculate next run time
				$schedule_info = wp_get_schedules()[ $event_schedule ] ?? null;

				if ( $schedule_info ) {
					$next_timestamp = time() + $schedule_info['interval'];

					// Unschedule the old event and reschedule it
					wp_unschedule_event( $event_timestamp, $hook, $args );
					wp_schedule_event( $next_timestamp, $event_schedule, $hook, $args );
					$rescheduled = true;
				}
			}

			if ( $rescheduled ) {
				/* translators: %s: execution duration in seconds */
				$message = __( 'Cron event executed in %s seconds and rescheduled.', 'dragon-cron-manager' );
			} else {
				/* translators: %s: execution duration in seconds */
				$message = __( 'Cron event executed in %s seconds (schedule unchanged).', 'dragon-cron-manager' );
			}

			return array(
				'success'     => true,
				'message'     => sprintf( $message, number_format( $duration, 3 ) ),
				'duration'    => $duration,
				'rescheduled' => $rescheduled,
			);
		} catch ( \Throwable $e ) {
			$duration = microtime( true ) - $start_time;

			// Log error
			if ( isset( $log_id ) ) {
				$logger->log_error( $log_id, $e->getMessage(), $duration );
			}

			/* translators: %s: error message */
			$error_message = __( 'Error: %s', 'dragon-cron-manager' );

			return array(
				'success' => false,
				'message' => sprintf( $error_message, $e->getMessage() ),
			);
		}
	}

	/**
	 * Delete a cron event
	 *
	 * @param string $hook      Event hook
	 * @param string $key       Event key
	 * @param int    $timestamp Event timestamp
	 * @return bool Success
	 */
	public function delete_event( string $hook, string $key, int $timestamp ): bool {
		$crons = _get_cron_array();

		if ( ! isset( $crons[ $timestamp ][ $hook ][ $key ] ) ) {
			return false;
		}

		// Get args for unscheduling
		$args = $crons[ $timestamp ][ $hook ][ $key ]['args'] ?? array();

		// Unschedule the event
		$result = wp_unschedule_event( $timestamp, $hook, $args );

		return false !== $result;
	}

	/**
	 * Add a new cron event
	 *
	 * @param string $hook      Event hook
	 * @param string $schedule  Schedule name (hourly, daily, etc.) or empty for single
	 * @param int    $timestamp First run timestamp
	 * @param array  $args      Event arguments
	 * @return bool Success
	 */
	public function add_event( string $hook, string $schedule, int $timestamp, array $args = array() ): bool {
		if ( empty( $schedule ) ) {
			// Single event
			$result = wp_schedule_single_event( $timestamp, $hook, $args );
		} else {
			// Recurring event
			$result = wp_schedule_event( $timestamp, $schedule, $hook, $args );
		}

		return false !== $result;
	}

	/**
	 * Get all registered cron schedules
	 *
	 * @return array Schedules with intervals
	 */
	public function get_schedules(): array {
		$schedules = wp_get_schedules();

		// Sort by interval
		uasort( $schedules, fn( $a, $b ) => $a['interval'] <=> $b['interval'] );

		return $schedules;
	}

	/**
	 * Get health check status
	 *
	 * @return array Health status
	 */
	public function get_health(): array {
		$health = array(
			'status' => 'good',
			'issues' => array(),
			'info'   => array(),
		);

		// Check if WP-Cron is disabled
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$health['info'][] = array(
				'label'   => __( 'WP-Cron Disabled', 'dragon-cron-manager' ),
				'message' => __( 'WP-Cron is disabled. Make sure you have a system cron configured.', 'dragon-cron-manager' ),
				'type'    => 'info',
			);
		}

		// Check if ALTERNATE_WP_CRON is enabled
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$health['info'][] = array(
				'label'   => __( 'Alternate Cron', 'dragon-cron-manager' ),
				'message' => __( 'Using alternate cron method (redirect-based).', 'dragon-cron-manager' ),
				'type'    => 'info',
			);
		}

		// Check for overdue events
		$events        = $this->get_events();
		$overdue_count = 0;
		$max_overdue   = 0;

		foreach ( $events as $event ) {
			if ( $event['is_overdue'] ) {
				++$overdue_count;
				$overdue_seconds = time() - $event['timestamp'];
				$max_overdue     = max( $max_overdue, $overdue_seconds );
			}
		}

		if ( $overdue_count > 0 ) {
			$health['status'] = 'warning';
			/* translators: %1$d: number of overdue events, %2$s: time since oldest overdue event */
			$overdue_message    = __( '%1$d cron events are overdue (oldest: %2$s ago).', 'dragon-cron-manager' );
			$health['issues'][] = array(
				'label'   => __( 'Overdue Events', 'dragon-cron-manager' ),
				'message' => sprintf( $overdue_message, $overdue_count, human_time_diff( time() - $max_overdue ) ),
				'type'    => 'warning',
			);
		}

		// Check for very large cron array
		$cron_count = count( $events );
		if ( $cron_count > 50 ) {
			/* translators: %d: number of scheduled cron events */
			$many_events_message = __( 'You have %d scheduled cron events. Consider cleaning up unused ones.', 'dragon-cron-manager' );
			$health['info'][]    = array(
				'label'   => __( 'Many Cron Events', 'dragon-cron-manager' ),
				'message' => sprintf( $many_events_message, $cron_count ),
				'type'    => 'info',
			);
		}

		// Add general info
		$health['info'][] = array(
			'label' => __( 'Total Events', 'dragon-cron-manager' ),
			'value' => $cron_count,
		);

		$health['info'][] = array(
			'label' => __( 'Schedules', 'dragon-cron-manager' ),
			'value' => count( $this->get_schedules() ),
		);

		return $health;
	}

	/**
	 * Check if hook is a core WordPress hook
	 *
	 * @param string $hook Hook name
	 * @return bool
	 */
	public function is_core_hook( string $hook ): bool {
		// Check against known core hooks
		if ( in_array( $hook, self::CORE_HOOKS, true ) ) {
			return true;
		}

		// Check for wp_ prefix (likely core)
		if ( strpos( $hook, 'wp_' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Format time until timestamp
	 *
	 * @param int $timestamp Unix timestamp
	 * @return string Formatted time
	 */
	private function format_time_until( int $timestamp ): string {
		$diff = $timestamp - time();

		if ( $diff < 0 ) {
			/* translators: %s: human-readable time difference */
			$overdue_format = __( '%s ago (overdue)', 'dragon-cron-manager' );
			return sprintf( $overdue_format, human_time_diff( $timestamp ) );
		}

		if ( $diff < 60 ) {
			return __( 'Less than a minute', 'dragon-cron-manager' );
		}

		return human_time_diff( time(), $timestamp );
	}

	/**
	 * Get summary statistics
	 *
	 * @return array Stats
	 */
	public function get_summary(): array {
		$events = $this->get_events();

		$total     = count( $events );
		$recurring = 0;
		$single    = 0;
		$overdue   = 0;
		$core      = 0;

		foreach ( $events as $event ) {
			if ( $event['is_recurring'] ) {
				++$recurring;
			} else {
				++$single;
			}

			if ( $event['is_overdue'] ) {
				++$overdue;
			}

			if ( $event['is_core'] ) {
				++$core;
			}
		}

		return array(
			'total'     => $total,
			'recurring' => $recurring,
			'single'    => $single,
			'overdue'   => $overdue,
			'core'      => $core,
			'plugins'   => $total - $core,
		);
	}

	/**
	 * Move a cron event to trash instead of deleting permanently
	 *
	 * @param string $hook      Event hook
	 * @param string $key       Event key
	 * @param int    $timestamp Event timestamp
	 * @return bool Success
	 */
	public function trash_event( string $hook, string $key, int $timestamp ): bool {
		$crons = _get_cron_array();

		if ( ! isset( $crons[ $timestamp ][ $hook ][ $key ] ) ) {
			return false;
		}

		// Get event data before removing
		$event_data = $crons[ $timestamp ][ $hook ][ $key ];
		$args       = $event_data['args'] ?? array();
		$schedule   = $event_data['schedule'] ?? false;
		$interval   = $event_data['interval'] ?? 0;

		// Store in trash
		$trashed  = get_option( self::TRASH_OPTION, array() );
		$trash_id = uniqid( 'trash_', true );

		$trashed[ $trash_id ] = array(
			'hook'       => $hook,
			'args'       => $args,
			'schedule'   => $schedule,
			'interval'   => $interval,
			'trashed_at' => time(),
			'expires_at' => time() + ( self::TRASH_RETENTION_DAYS * DAY_IN_SECONDS ),
		);

		update_option( self::TRASH_OPTION, $trashed );

		// Unschedule the event
		$result = wp_unschedule_event( $timestamp, $hook, $args );

		return false !== $result;
	}

	/**
	 * Get all trashed cron events
	 *
	 * @return array Trashed events with expiration info
	 */
	public function get_trashed_events(): array {
		$trashed = get_option( self::TRASH_OPTION, array() );

		if ( empty( $trashed ) ) {
			return array();
		}

		$events = array();
		$now    = time();

		foreach ( $trashed as $trash_id => $event ) {
			$events[] = array(
				'trash_id'   => $trash_id,
				'hook'       => $event['hook'],
				'args'       => $event['args'] ?? array(),
				'schedule'   => $event['schedule'] ?? false,
				'interval'   => $event['interval'] ?? 0,
				'trashed_at' => $event['trashed_at'],
				'expires_at' => $event['expires_at'],
				'days_left'  => max( 0, ceil( ( $event['expires_at'] - $now ) / DAY_IN_SECONDS ) ),
				'is_expired' => $event['expires_at'] <= $now,
			);
		}

		// Sort by trashed_at descending (newest first)
		usort( $events, fn( $a, $b ) => $b['trashed_at'] <=> $a['trashed_at'] );

		return $events;
	}

	/**
	 * Restore a trashed cron event
	 *
	 * @param string $trash_id Trash ID
	 * @return array Result with success status and message
	 */
	public function restore_event( string $trash_id ): array {
		$trashed = get_option( self::TRASH_OPTION, array() );

		if ( ! isset( $trashed[ $trash_id ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Trashed event not found.', 'dragon-cron-manager' ),
			);
		}

		$event    = $trashed[ $trash_id ];
		$hook     = $event['hook'];
		$args     = $event['args'] ?? array();
		$schedule = $event['schedule'] ?? false;

		// Re-schedule the event
		if ( $schedule && ! empty( $event['interval'] ) ) {
			// Recurring event - schedule from now
			$result = wp_schedule_event( time(), $schedule, $hook, $args );
		} else {
			// Single event - schedule 1 minute from now
			$result = wp_schedule_single_event( time() + 60, $hook, $args );
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to restore cron event.', 'dragon-cron-manager' ),
			);
		}

		// Remove from trash
		unset( $trashed[ $trash_id ] );
		update_option( self::TRASH_OPTION, $trashed );

		/* translators: %s: cron hook name */
		$restored_message = __( 'Cron event "%s" restored successfully.', 'dragon-cron-manager' );

		return array(
			'success' => true,
			'message' => sprintf( $restored_message, $hook ),
		);
	}

	/**
	 * Permanently delete a trashed cron event
	 *
	 * @param string $trash_id Trash ID
	 * @return bool Success
	 */
	public function delete_trashed_event( string $trash_id ): bool {
		$trashed = get_option( self::TRASH_OPTION, array() );

		if ( ! isset( $trashed[ $trash_id ] ) ) {
			return false;
		}

		unset( $trashed[ $trash_id ] );
		update_option( self::TRASH_OPTION, $trashed );

		return true;
	}

	/**
	 * Empty all trashed events permanently
	 *
	 * @return int Number of events deleted
	 */
	public function empty_trash(): int {
		$trashed = get_option( self::TRASH_OPTION, array() );
		$count   = count( $trashed );

		delete_option( self::TRASH_OPTION );

		return $count;
	}

	/**
	 * Cleanup expired trashed events (called by cron)
	 *
	 * @return int Number of events purged
	 */
	public function cleanup_expired_trash(): int {
		$trashed = get_option( self::TRASH_OPTION, array() );

		if ( empty( $trashed ) ) {
			return 0;
		}

		$now    = time();
		$purged = 0;

		foreach ( $trashed as $trash_id => $event ) {
			if ( $event['expires_at'] <= $now ) {
				unset( $trashed[ $trash_id ] );
				++$purged;
			}
		}

		if ( $purged > 0 ) {
			update_option( self::TRASH_OPTION, $trashed );
		}

		return $purged;
	}

	/**
	 * Get trash count for UI display
	 *
	 * @return int Number of trashed events
	 */
	public function get_trash_count(): int {
		$trashed = get_option( self::TRASH_OPTION, array() );
		return count( $trashed );
	}
}
