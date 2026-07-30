<?php
/**
 * Dashboard View
 *
 * @package DragonCronManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are not truly global.

// Provided by Admin::render_dashboard_page(): tab, events, health, summary, schedules, logs, log_stats, trashed_events, trash_count.
$dcm_tabs = array(
	'events'    => __( 'Cron Events', 'dragon-cron-manager' ),
	'trash'     => __( 'Trash', 'dragon-cron-manager' ),
	'logs'      => __( 'Execution Log', 'dragon-cron-manager' ),
	'schedules' => __( 'Schedules', 'dragon-cron-manager' ),
);
?>
<div class="wrap dcm-dashboard">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Dragon Cron Manager', 'dragon-cron-manager' ); ?>
	</h1>

	<!-- Health Status -->
	<?php if ( ! empty( $health['issues'] ) || ! empty( $health['info'] ) ) : ?>
		<div class="dcm-health-bar dcm-health-<?php echo esc_attr( $health['status'] ); ?>">
			<?php foreach ( $health['issues'] as $dcm_issue ) : ?>
				<span class="dcm-health-item dcm-health-<?php echo esc_attr( $dcm_issue['type'] ); ?>">
					<span class="dashicons dashicons-<?php echo 'warning' === $dcm_issue['type'] ? 'warning' : 'info'; ?>"></span>
					<strong><?php echo esc_html( $dcm_issue['label'] ); ?>:</strong>
					<?php echo esc_html( $dcm_issue['message'] ); ?>
				</span>
			<?php endforeach; ?>
			<?php foreach ( $health['info'] as $dcm_info ) : ?>
				<?php if ( isset( $dcm_info['message'] ) ) : ?>
					<span class="dcm-health-item dcm-health-info">
						<span class="dashicons dashicons-info"></span>
						<strong><?php echo esc_html( $dcm_info['label'] ); ?>:</strong>
						<?php echo esc_html( $dcm_info['message'] ); ?>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- Stats Cards -->
	<div class="dcm-stats-grid">
		<div class="dcm-stat-card">
			<div class="dcm-stat-number"><?php echo esc_html( $summary['total'] ); ?></div>
			<div class="dcm-stat-label"><?php esc_html_e( 'Total Events', 'dragon-cron-manager' ); ?></div>
		</div>
		<div class="dcm-stat-card">
			<div class="dcm-stat-number"><?php echo esc_html( $summary['recurring'] ); ?></div>
			<div class="dcm-stat-label"><?php esc_html_e( 'Recurring', 'dragon-cron-manager' ); ?></div>
		</div>
		<div class="dcm-stat-card <?php echo $summary['overdue'] > 0 ? 'dcm-warning' : ''; ?>">
			<div class="dcm-stat-number"><?php echo esc_html( $summary['overdue'] ); ?></div>
			<div class="dcm-stat-label"><?php esc_html_e( 'Overdue', 'dragon-cron-manager' ); ?></div>
		</div>
		<div class="dcm-stat-card">
			<div class="dcm-stat-number"><?php echo esc_html( $log_stats['today'] ); ?></div>
			<div class="dcm-stat-label"><?php esc_html_e( 'Runs Today', 'dragon-cron-manager' ); ?></div>
		</div>
	</div>

	<!-- Tabs -->
	<nav class="nav-tab-wrapper">
		<?php foreach ( $dcm_tabs as $dcm_tab_key => $dcm_tab_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $dcm_tab_key ) ); ?>"
				class="nav-tab <?php echo $tab === $dcm_tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $dcm_tab_label ); ?>
				<?php if ( 'trash' === $dcm_tab_key && $trash_count > 0 ) : ?>
					<span class="dcm-trash-count"><?php echo esc_html( $trash_count ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<!-- Tab Content -->
	<div class="dcm-tab-content">
		<?php if ( 'events' === $tab ) : ?>
			<!-- Events Table -->
			<table class="wp-list-table widefat fixed striped dcm-events-table">
				<thead>
					<tr>
						<th class="dcm-col-hook"><?php esc_html_e( 'Hook', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-schedule"><?php esc_html_e( 'Schedule', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-next"><?php esc_html_e( 'Next Run', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-args"><?php esc_html_e( 'Args', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-actions"><?php esc_html_e( 'Actions', 'dragon-cron-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No cron events found.', 'dragon-cron-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $events as $dcm_event ) : ?>
							<tr class="<?php echo $dcm_event['is_overdue'] ? 'dcm-overdue' : ''; ?>"
								data-hook="<?php echo esc_attr( $dcm_event['hook'] ); ?>"
								data-key="<?php echo esc_attr( $dcm_event['key'] ); ?>"
								data-timestamp="<?php echo esc_attr( $dcm_event['timestamp'] ); ?>"
								data-args="<?php echo esc_attr( wp_json_encode( $dcm_event['args'] ) ); ?>">
								<td class="dcm-col-hook">
									<strong><?php echo esc_html( $dcm_event['hook'] ); ?></strong>
									<?php if ( $dcm_event['is_core'] ) : ?>
										<span class="dcm-badge dcm-badge-core" title="<?php esc_attr_e( 'WordPress Core', 'dragon-cron-manager' ); ?>">core</span>
									<?php endif; ?>
								</td>
								<td class="dcm-col-schedule">
									<?php if ( $dcm_event['is_recurring'] ) : ?>
										<span class="dcm-schedule"><?php echo esc_html( $dcm_event['schedule'] ); ?></span>
										<br><small><?php echo esc_html( human_time_diff( 0, $dcm_event['interval'] ) ); ?></small>
									<?php else : ?>
										<span class="dcm-schedule dcm-single"><?php esc_html_e( 'Single', 'dragon-cron-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="dcm-col-next">
									<?php if ( $dcm_event['is_overdue'] ) : ?>
										<span class="dcm-overdue-badge"><?php echo esc_html( $dcm_event['next_run'] ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $dcm_event['next_run'] ); ?>
									<?php endif; ?>
									<br><small><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $dcm_event['timestamp'] ) ); ?></small>
								</td>
								<td class="dcm-col-args">
									<?php if ( ! empty( $dcm_event['args'] ) ) : ?>
										<code class="dcm-args"><?php echo esc_html( wp_json_encode( $dcm_event['args'] ) ); ?></code>
									<?php else : ?>
										<span class="dcm-no-args">—</span>
									<?php endif; ?>
								</td>
								<td class="dcm-col-actions">
									<button type="button" class="button button-small dcm-run-event" title="<?php esc_attr_e( 'Execute now and reschedule next run', 'dragon-cron-manager' ); ?>">
										<span class="dashicons dashicons-controls-play"></span>
										<?php esc_html_e( 'Run', 'dragon-cron-manager' ); ?>
									</button>
									<button type="button" class="button button-small dcm-test-event" title="<?php esc_attr_e( 'Execute now without changing schedule', 'dragon-cron-manager' ); ?>">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Test', 'dragon-cron-manager' ); ?>
									</button>
									<?php if ( ! $dcm_event['is_core'] ) : ?>
										<button type="button" class="button button-small dcm-trash-event" title="<?php esc_attr_e( 'Move to trash (recoverable for 30 days)', 'dragon-cron-manager' ); ?>">
											<span class="dashicons dashicons-trash"></span>
											<?php esc_html_e( 'Trash', 'dragon-cron-manager' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

		<?php elseif ( 'trash' === $tab ) : ?>
			<!-- Trash Table -->
			<div class="dcm-trash-header">
				<p class="dcm-trash-info">
					<?php esc_html_e( 'Trashed cron events are automatically deleted after 30 days.', 'dragon-cron-manager' ); ?>
				</p>
				<?php if ( $trash_count > 0 ) : ?>
					<button type="button" id="dcm-empty-trash" class="button">
						<?php esc_html_e( 'Empty Trash', 'dragon-cron-manager' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped dcm-trash-table">
				<thead>
					<tr>
						<th class="dcm-col-hook"><?php esc_html_e( 'Hook', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-schedule"><?php esc_html_e( 'Schedule', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-trashed"><?php esc_html_e( 'Trashed', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-expires"><?php esc_html_e( 'Auto-Delete', 'dragon-cron-manager' ); ?></th>
						<th class="dcm-col-actions"><?php esc_html_e( 'Actions', 'dragon-cron-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $trashed_events ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No trashed cron events.', 'dragon-cron-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $trashed_events as $dcm_trashed ) : ?>
							<tr data-trash-id="<?php echo esc_attr( $dcm_trashed['trash_id'] ); ?>">
								<td class="dcm-col-hook">
									<strong><?php echo esc_html( $dcm_trashed['hook'] ); ?></strong>
								</td>
								<td class="dcm-col-schedule">
									<?php if ( $dcm_trashed['schedule'] ) : ?>
										<span class="dcm-schedule"><?php echo esc_html( $dcm_trashed['schedule'] ); ?></span>
										<br><small><?php echo esc_html( human_time_diff( 0, $dcm_trashed['interval'] ) ); ?></small>
									<?php else : ?>
										<span class="dcm-schedule dcm-single"><?php esc_html_e( 'Single', 'dragon-cron-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="dcm-col-trashed">
									<?php echo esc_html( human_time_diff( $dcm_trashed['trashed_at'] ) ); ?> <?php esc_html_e( 'ago', 'dragon-cron-manager' ); ?>
									<br><small><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $dcm_trashed['trashed_at'] ) ); ?></small>
								</td>
								<td class="dcm-col-expires">
									<?php if ( $dcm_trashed['is_expired'] ) : ?>
										<span class="dcm-expired-badge"><?php esc_html_e( 'Expired', 'dragon-cron-manager' ); ?></span>
									<?php else : ?>
										<?php
										printf(
											/* translators: %d: number of days */
											esc_html( _n( '%d day', '%d days', $dcm_trashed['days_left'], 'dragon-cron-manager' ) ),
											esc_html( $dcm_trashed['days_left'] )
										);
										?>
									<?php endif; ?>
								</td>
								<td class="dcm-col-actions">
									<button type="button" class="button button-small dcm-restore-event" title="<?php esc_attr_e( 'Restore this cron event to active schedule', 'dragon-cron-manager' ); ?>">
										<span class="dashicons dashicons-undo"></span>
										<?php esc_html_e( 'Restore', 'dragon-cron-manager' ); ?>
									</button>
									<button type="button" class="button button-small dcm-delete-event" title="<?php esc_attr_e( 'Permanently delete - cannot be undone', 'dragon-cron-manager' ); ?>">
										<span class="dashicons dashicons-dismiss"></span>
										<?php esc_html_e( 'Delete', 'dragon-cron-manager' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

		<?php elseif ( 'logs' === $tab ) : ?>
			<!-- Logs Table -->
			<div class="dcm-logs-header">
				<div class="dcm-log-stats">
					<?php /* translators: %d: total number of log entries */ ?>
					<span><?php printf( esc_html__( 'Total: %d', 'dragon-cron-manager' ), absint( $log_stats['total'] ) ); ?></span>
					<?php /* translators: %1$d: error count, %2$s: error rate percentage */ ?>
					<span><?php printf( esc_html__( 'Errors: %1$d (%2$s%%)', 'dragon-cron-manager' ), absint( $log_stats['errors'] ), esc_html( $log_stats['error_rate'] ) ); ?></span>
					<?php /* translators: %s: average duration in seconds */ ?>
					<span><?php printf( esc_html__( 'Avg Duration: %ss', 'dragon-cron-manager' ), esc_html( $log_stats['avg_duration'] ) ); ?></span>
				</div>
				<button type="button" id="dcm-clear-logs" class="button">
					<?php esc_html_e( 'Clear Logs', 'dragon-cron-manager' ); ?>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped dcm-logs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hook', 'dragon-cron-manager' ); ?></th>
						<th><?php esc_html_e( 'Start Time', 'dragon-cron-manager' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'dragon-cron-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dragon-cron-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No log entries yet.', 'dragon-cron-manager' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $dcm_log ) : ?>
							<tr class="dcm-log-<?php echo esc_attr( $dcm_log['status'] ); ?>">
								<td><code><?php echo esc_html( $dcm_log['hook'] ); ?></code></td>
								<td><?php echo esc_html( $dcm_log['start_time'] ); ?></td>
								<td>
									<?php if ( $dcm_log['duration'] ) : ?>
										<?php echo esc_html( number_format( $dcm_log['duration'], 3 ) ); ?>s
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td>
									<span class="dcm-status dcm-status-<?php echo esc_attr( $dcm_log['status'] ); ?>">
										<?php echo esc_html( ucfirst( $dcm_log['status'] ) ); ?>
									</span>
									<?php if ( ! empty( $dcm_log['error_message'] ) ) : ?>
										<br><small class="dcm-error-msg"><?php echo esc_html( $dcm_log['error_message'] ); ?></small>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

		<?php elseif ( 'schedules' === $tab ) : ?>
			<!-- Schedules Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'dragon-cron-manager' ); ?></th>
						<th><?php esc_html_e( 'Interval', 'dragon-cron-manager' ); ?></th>
						<th><?php esc_html_e( 'Display', 'dragon-cron-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $schedules as $dcm_name => $dcm_schedule ) : ?>
						<tr>
							<td><code><?php echo esc_html( $dcm_name ); ?></code></td>
							<td>
								<?php echo esc_html( number_format( $dcm_schedule['interval'] ) ); ?> seconds
								<br><small>(<?php echo esc_html( human_time_diff( 0, $dcm_schedule['interval'] ) ); ?>)</small>
							</td>
							<td><?php echo esc_html( $dcm_schedule['display'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
