<?php
/**
 * wp-env integration checks for the cron doctor. Run inside wp-env:
 *
 *   wp eval-file wp-content/plugins/dragon-cron-manager/tests/integration/doctor.php
 *
 * @package DragonCronManager
 */

use DragonCronManager\Cron;
use DragonCronManager\Doctor;

function dragoncronmanager_doctor_check( string $label, bool $cond ): void {
	if ( $cond ) {
		WP_CLI::log( 'PASS ' . $label );
	} else {
		WP_CLI::warning( 'FAIL ' . $label );
	}
}

$doctor = new Doctor( new Cron() );

// Baseline: signals gather without error and carry every expected key.
$signals = $doctor->gather_signals();
foreach ( array( 'disable_wp_cron', 'overdue_count', 'max_overdue', 'event_count', 'lock_age', 'loopback', 'last_log_activity', 'site_url' ) as $key ) {
	dragoncronmanager_doctor_check( "signal present: $key", array_key_exists( $key, $signals ) );
}
WP_CLI::log( 'loopback: ' . wp_json_encode( $signals['loopback'] ) );
WP_CLI::log( sprintf( 'overdue: %d (max %ds), events: %d', $signals['overdue_count'], $signals['max_overdue'], $signals['event_count'] ) );

// Full diagnose returns at least one finding with a valid severity.
$result = $doctor->diagnose();
$finds  = $result['findings'];
dragoncronmanager_doctor_check( 'diagnose returns findings', ! empty( $finds ) );
$valid = true;
foreach ( $finds as $f ) {
	if ( ! in_array( $f['severity'], array( 'critical', 'warning', 'ok', 'info' ), true ) || '' === $f['title'] ) {
		$valid = false;
	}
	WP_CLI::log( sprintf( '  [%s] %s', $f['severity'], $f['title'] ) );
}
dragoncronmanager_doctor_check( 'findings well-formed', $valid );

// Stall the queue: an event 2 hours overdue must change the verdict away from ok.
wp_schedule_single_event( time() - 7200, 'dragoncronmanager_doctor_test_evt' );
$stalled = $doctor->diagnose();
$first   = $stalled['findings'][0];
WP_CLI::log( sprintf( 'stalled verdict: [%s] %s', $first['severity'], $first['title'] ) );
dragoncronmanager_doctor_check( 'stalled queue is not reported ok', 'ok' !== $first['severity'] );
wp_unschedule_event( wp_next_scheduled( 'dragoncronmanager_doctor_test_evt' ), 'dragoncronmanager_doctor_test_evt' );

WP_CLI::success( 'Doctor checks done' );
