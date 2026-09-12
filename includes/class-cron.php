<?php
/**
 * Cron Class
 *
 * Handles WP-Cron interactions
 *
 * @package DragonCronManager
 */

namespace DragonCronManager;

defined( 'ABSPATH' ) || exit;

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

		// Only run events that are ACTUALLY scheduled. Without this, run_event
		// would fire any hook that merely has a listener, letting an admin (on
		// multisite, a site admin) invoke network-level or other-plugin callbacks
		// with crafted arguments. Look the event up the way WordPress keys its
		// cron array — md5( serialize( $args ) ) — which also makes the argument
		// match robust to the JSON round-trip the UI performs.
		$crons           = _get_cron_array();
		$event_key       = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matches WordPress's own cron-array event keying.
		$event_schedule  = null;
		$event_timestamp = null;
		$event_found     = false;

		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $event_key ] ) ) {
				$event_found     = true;
				$event_schedule  = $hooks[ $hook ][ $event_key ]['schedule'] ?? false;
				$event_timestamp = $timestamp;
				break;
			}
		}

		if ( ! $event_found ) {
			return array(
				'success' => false,
				'message' => __( 'No scheduled event matches this hook and arguments.', 'dragon-cron-manager' ),
			);
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
			$rescheduled      = false;
			$reschedule_error = '';
			if ( $reschedule && $event_schedule && $event_timestamp ) {
				// Calculate next run time
				$schedule_info = wp_get_schedules()[ $event_schedule ] ?? null;

				if ( $schedule_info ) {
					$outcome          = $this->move_recurring_event( $event_timestamp, $event_schedule, $hook, $args, time() + $schedule_info['interval'] );
					$rescheduled      = $outcome['success'];
					$reschedule_error = $outcome['message'];
				}
			}

			$formatted_duration = number_format( $duration, 3 );

			if ( '' !== $reschedule_error ) {
				return array(
					'success'     => false,
					'message'     => sprintf(
						/* translators: 1: execution duration in seconds, 2: why rescheduling failed */
						__( 'Cron event executed in %1$s seconds, but it could not be rescheduled: %2$s', 'dragon-cron-manager' ),
						$formatted_duration,
						$reschedule_error
					),
					'duration'    => $duration,
					'rescheduled' => false,
				);
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
				'message'     => sprintf( $message, $formatted_duration ),
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
	 * Move a recurring event from its current slot to a new timestamp.
	 *
	 * The replacement is booked first and verified in its exact slot; only then
	 * is the original booking removed, so a refused replacement never touches
	 * the original. Core applies no duplicate guard to recurring bookings, so
	 * both may coexist briefly. If the original cannot be removed afterwards,
	 * the replacement is taken back out; if even that fails, the double
	 * booking is reported. Every claim is checked against the cron array with
	 * wp_get_scheduled_event(), never inferred from a return value.
	 *
	 * @param int    $old_timestamp  Timestamp of the current booking.
	 * @param string $schedule       Recurrence name.
	 * @param string $hook           Event hook.
	 * @param array  $args           Event arguments.
	 * @param int    $next_timestamp Timestamp of the new booking.
	 * @return array{success: bool, message: string} message is empty on success.
	 */
	private function move_recurring_event( int $old_timestamp, string $schedule, string $hook, array $args, int $next_timestamp ): array {
		// Same slot: the booking is already where it should be.
		if ( $next_timestamp === $old_timestamp ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		$scheduled = wp_schedule_event( $next_timestamp, $schedule, $hook, $args, true );
		if ( true !== $scheduled || ! self::booking_exists( $hook, $args, $next_timestamp, $schedule ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: reason WordPress gave */
					__( '%s The existing booking was left in place.', 'dragon-cron-manager' ),
					self::schedule_error_reason( $scheduled )
				),
			);
		}

		$unscheduled = wp_unschedule_event( $old_timestamp, $hook, $args, true );
		if ( true === $unscheduled && ! self::booking_exists( $hook, $args, $old_timestamp ) ) {
			return array(
				'success' => true,
				'message' => '',
			);
		}

		$reason = self::schedule_error_reason( $unscheduled );

		// The original is still booked; take the replacement back out so the
		// schedule is left as it was.
		wp_unschedule_event( $next_timestamp, $hook, $args );
		if ( self::booking_exists( $hook, $args, $next_timestamp ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: reason WordPress gave */
					__( 'the previous booking could not be removed (%s), so this event is now booked twice. Trash one of the two bookings from the Events tab.', 'dragon-cron-manager' ),
					$reason
				),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: reason WordPress gave */
				__( 'the previous booking could not be removed (%s). The existing booking was left in place.', 'dragon-cron-manager' ),
				$reason
			),
		);
	}

	/**
	 * Whether a booking exists in exactly this slot of the cron array.
	 *
	 * @param string      $hook      Event hook.
	 * @param array       $args      Event arguments.
	 * @param int         $timestamp Slot to check.
	 * @param string|null $schedule  When given, the booking must also carry this recurrence.
	 * @return bool
	 */
	private static function booking_exists( string $hook, array $args, int $timestamp, ?string $schedule = null ): bool {
		$event = wp_get_scheduled_event( $hook, $args, $timestamp );
		if ( ! is_object( $event ) ) {
			return false;
		}

		return null === $schedule || ( isset( $event->schedule ) && $event->schedule === $schedule );
	}

	/**
	 * Human-readable reason from a wp_schedule_event() / wp_unschedule_event()
	 * result.
	 *
	 * @param mixed $result WP_Error, false, or anything else the call returned.
	 * @return string
	 */
	private static function schedule_error_reason( $result ): string {
		if ( is_wp_error( $result ) && '' !== $result->get_error_message() ) {
			return $result->get_error_message();
		}

		return __( 'WordPress did not apply the change (a plugin may be blocking it, or the cron array could not be saved).', 'dragon-cron-manager' );
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
	 * The event is only unscheduled once its trash copy is confirmed saved. If
	 * the unschedule then fails, the trash entry is removed again; if that
	 * removal also fails, the retained entry is reported so the admin knows the
	 * event is both still scheduled and listed in Trash.
	 *
	 * @param string $hook      Event hook
	 * @param string $key       Event key
	 * @param int    $timestamp Event timestamp
	 * @return array Result with success status and message
	 */
	public function trash_event( string $hook, string $key, int $timestamp ): array {
		$crons = _get_cron_array();

		if ( ! isset( $crons[ $timestamp ][ $hook ][ $key ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No scheduled event matches this hook, arguments and time.', 'dragon-cron-manager' ),
			);
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

		update_option( self::TRASH_OPTION, $trashed, false );

		$stored = get_option( self::TRASH_OPTION, array() );
		if ( ! isset( $stored[ $trash_id ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'The cron event could not be saved to the trash, so it was left scheduled.', 'dragon-cron-manager' ),
			);
		}

		$unscheduled = wp_unschedule_event( $timestamp, $hook, $args, true );
		if ( true === $unscheduled && ! self::booking_exists( $hook, $args, $timestamp ) ) {
			return array(
				'success' => true,
				'message' => __( 'Cron event moved to trash. It will be permanently deleted in 30 days.', 'dragon-cron-manager' ),
			);
		}

		$reason = self::schedule_error_reason( $unscheduled );

		// The event is still scheduled; take the trash entry back out.
		unset( $stored[ $trash_id ] );
		update_option( self::TRASH_OPTION, $stored, false );

		$stored = get_option( self::TRASH_OPTION, array() );
		if ( isset( $stored[ $trash_id ] ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: reason WordPress gave */
					__( 'The cron event could not be unscheduled (%s) and is still listed in the trash as well. It remains scheduled; permanently delete the trash entry rather than restoring it.', 'dragon-cron-manager' ),
					$reason
				),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: reason WordPress gave */
				__( 'The cron event could not be unscheduled (%s). It was left scheduled.', 'dragon-cron-manager' ),
				$reason
			),
		);
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
	 * Whether a recurring booking of this hook and arguments already exists.
	 *
	 * wp_next_scheduled() answers for one-off events too, which is the wrong
	 * question when deciding whether restoring a recurring event would double
	 * book it.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Event arguments.
	 * @return bool
	 */
	private static function has_recurring_booking( string $hook, array $args ): bool {
		$key = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress keys cron events by exactly this hash.

		foreach ( _get_cron_array() as $events ) {
			$event = $events[ $hook ][ $key ] ?? null;

			if ( is_array( $event ) && ! empty( $event['schedule'] ) ) {
				return true;
			}
		}

		return false;
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

		$recurring = $schedule && ! empty( $event['interval'] );

		/*
		 * A second recurring booking of the same hook and arguments is a genuine
		 * double booking, so it is refused. One-off events are different:
		 * WordPress allows several with the same hook and arguments at different
		 * times and only refuses a duplicate within its own short window, so a
		 * matching single event elsewhere in the schedule must not block a
		 * restore.
		 */
		if ( $recurring && self::has_recurring_booking( $hook, $args ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: cron hook name */
					__( 'Cron event "%s" is already scheduled with these arguments, so it was not restored. Permanently delete the trash entry instead.', 'dragon-cron-manager' ),
					$hook
				),
			);
		}

		// Re-schedule the event
		if ( $recurring ) {
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
		update_option( self::TRASH_OPTION, $trashed, false );

		$stored = get_option( self::TRASH_OPTION, array() );
		if ( isset( $stored[ $trash_id ] ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: cron hook name */
					__( 'Cron event "%s" was scheduled again, but it could not be removed from the trash list. Permanently delete the trash entry; restoring it again is refused while the event is scheduled.', 'dragon-cron-manager' ),
					$hook
				),
			);
		}

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
		update_option( self::TRASH_OPTION, $trashed, false );

		// Re-read rather than trust update_option(): it also returns false when
		// the value is unchanged, so only the stored value proves the delete.
		$stored = get_option( self::TRASH_OPTION, array() );

		return ! isset( $stored[ $trash_id ] );
	}

	/**
	 * Empty all trashed events permanently
	 *
	 * @return int|false Number of events deleted, or false when the trash could
	 *                   not be cleared.
	 */
	public function empty_trash() {
		$trashed = get_option( self::TRASH_OPTION, array() );
		$count   = count( $trashed );

		if ( 0 === $count ) {
			return 0;
		}

		delete_option( self::TRASH_OPTION );

		if ( count( get_option( self::TRASH_OPTION, array() ) ) > 0 ) {
			return false;
		}

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
		$before = count( $trashed );

		foreach ( $trashed as $trash_id => $event ) {
			if ( $event['expires_at'] <= $now ) {
				unset( $trashed[ $trash_id ] );
			}
		}

		if ( count( $trashed ) === $before ) {
			return 0;
		}

		update_option( self::TRASH_OPTION, $trashed, false );

		// Report only what was actually removed from the stored option.
		return max( 0, $before - count( get_option( self::TRASH_OPTION, array() ) ) );
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
