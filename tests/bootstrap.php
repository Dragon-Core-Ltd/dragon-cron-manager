<?php
/**
 * PHPUnit bootstrap. The classes under test are WP-light; the core helpers
 * they touch are stubbed here with controllable stores so scheduling and
 * option writes can be made to fail on demand.
 *
 * @package DragonCronManager
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

// Test state: cron store keyed like WordPress's cron array, an option store,
// a queue of forced results for the schedule/unschedule stubs, and a switch
// that makes every option write fail.
$GLOBALS['dragoncronmanager_test_cron']              = array();
$GLOBALS['dragoncronmanager_test_options']           = array();
$GLOBALS['dragoncronmanager_test_schedule_results']  = array();
$GLOBALS['dragoncronmanager_test_option_write_fail'] = false;
$GLOBALS['dragoncronmanager_test_option_writes_left'] = null;
$GLOBALS['dragoncronmanager_test_actions']           = array();
$GLOBALS['dragoncronmanager_test_fired']             = array();

/**
 * Reset all stub state between tests.
 */
function dragoncronmanager_test_reset(): void {
	$GLOBALS['dragoncronmanager_test_cron']              = array();
	$GLOBALS['dragoncronmanager_test_options']           = array();
	$GLOBALS['dragoncronmanager_test_schedule_results']  = array();
	$GLOBALS['dragoncronmanager_test_option_write_fail'] = false;
	$GLOBALS['dragoncronmanager_test_option_writes_left'] = null;
	$GLOBALS['dragoncronmanager_test_actions']           = array();
	$GLOBALS['dragoncronmanager_test_fired']             = array();
	$GLOBALS['wpdb']                                     = new DragonCronManager_Test_Wpdb();
	$GLOBALS['dragoncronmanager_test_timezone']          = 'UTC';
	$GLOBALS['dragoncronmanager_test_denied_caps']       = array();
	$GLOBALS['dragoncronmanager_test_multisite']         = false;
	$GLOBALS['dragoncronmanager_test_filters']           = array();
	$GLOBALS['dragoncronmanager_test_doing_cron']        = false;
	$GLOBALS['dragoncronmanager_test_menu_pages']        = array();
}

/**
 * Whether an option write may proceed. A bool switch fails every write; an
 * integer budget allows that many writes and fails the rest.
 */
function dragoncronmanager_test_option_write_allowed(): bool {
	if ( $GLOBALS['dragoncronmanager_test_option_write_fail'] ) {
		return false;
	}
	if ( null === $GLOBALS['dragoncronmanager_test_option_writes_left'] ) {
		return true;
	}
	if ( $GLOBALS['dragoncronmanager_test_option_writes_left'] <= 0 ) {
		return false;
	}
	--$GLOBALS['dragoncronmanager_test_option_writes_left'];
	return true;
}

/**
 * Pop the next forced result for a schedule/unschedule stub, or null when the
 * real (store-backed) behaviour should apply. A queued null also means
 * store-backed, so later calls can be forced while earlier ones stay real.
 *
 * @param bool $wp_error Whether the caller asked for a WP_Error.
 * @return mixed
 */
function dragoncronmanager_test_forced_result( bool $wp_error ) {
	if ( empty( $GLOBALS['dragoncronmanager_test_schedule_results'] ) ) {
		return null;
	}
	$result = array_shift( $GLOBALS['dragoncronmanager_test_schedule_results'] );
	if ( 'fail' === $result ) {
		return $wp_error ? new WP_Error( 'schedule_event_false', 'A plugin prevented the event from being scheduled.' ) : false;
	}
	return $result;
}

/**
 * Minimal $wpdb stand-in: records queries and returns a controllable result.
 */
class DragonCronManager_Test_Wpdb {
	public string $prefix   = 'wp_';
	public array $queries   = array();
	public $query_result    = true;

	public function query( $sql ) {
		$this->queries[] = $sql;
		return $this->query_result;
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = preg_replace_callback(
				'/%[dsf]/',
				static function ( $m ) use ( $arg ) {
					if ( '%d' === $m[0] ) {
						return (string) (int) $arg;
					}
					if ( '%f' === $m[0] ) {
						return (string) (float) $arg;
					}
					return "'" . addslashes( (string) $arg ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}
}

/**
 * Thrown by the wp_send_json_* stubs to emulate the die() in WordPress.
 */
class DragonCronManager_Test_Json_Exit extends Exception {
	public bool $success;
	public $data;

	public function __construct( bool $success, $data ) {
		parent::__construct( 'json exit' );
		$this->success = $success;
		$this->data    = $data;
	}
}

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function add_action( $hook, $callback, ...$args ) {
	unset( $args );
	$GLOBALS['dragoncronmanager_test_actions'][ $hook ][] = $callback;
	return true;
}

function has_action( $hook, $callback = false ) {
	unset( $callback );
	return ! empty( $GLOBALS['dragoncronmanager_test_actions'][ $hook ] );
}

function do_action( ...$args ) {
	unset( $args );
}

function do_action_ref_array( $hook, $args ) {
	$GLOBALS['dragoncronmanager_test_fired'][] = array( $hook, $args );
	foreach ( $GLOBALS['dragoncronmanager_test_actions'][ $hook ] ?? array() as $callback ) {
		if ( is_callable( $callback ) ) {
			call_user_func_array( $callback, array_values( $args ) );
		}
	}
}

function wp_get_schedules() {
	return array(
		'hourly'     => array( 'interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly' ),
		'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily' ),
		'daily'      => array( 'interval' => DAY_IN_SECONDS, 'display' => 'Once Daily' ),
	);
}

function _get_cron_array() {
	return $GLOBALS['dragoncronmanager_test_cron'];
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	$forced = dragoncronmanager_test_forced_result( (bool) $wp_error );
	if ( null !== $forced ) {
		return $forced;
	}
	$schedules = wp_get_schedules();
	if ( ! isset( $schedules[ $recurrence ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' ) : false;
	}
	$GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
		'schedule' => $recurrence,
		'args'     => $args,
		'interval' => $schedules[ $recurrence ]['interval'],
	);
	ksort( $GLOBALS['dragoncronmanager_test_cron'] );
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	$forced = dragoncronmanager_test_forced_result( (bool) $wp_error );
	if ( null !== $forced ) {
		return $forced;
	}
	$GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
		'schedule' => false,
		'args'     => $args,
	);
	ksort( $GLOBALS['dragoncronmanager_test_cron'] );
	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	$forced = dragoncronmanager_test_forced_result( (bool) $wp_error );
	if ( null !== $forced ) {
		return $forced;
	}
	$key = md5( serialize( $args ) );
	unset( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ][ $key ] );
	if ( empty( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ] ) ) {
		unset( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ] );
	}
	if ( empty( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ] ) ) {
		unset( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ] );
	}
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	$key = md5( serialize( $args ) );
	foreach ( $GLOBALS['dragoncronmanager_test_cron'] as $timestamp => $hooks ) {
		if ( isset( $hooks[ $hook ][ $key ] ) ) {
			return $timestamp;
		}
	}
	return false;
}

function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) {
	$key = md5( serialize( $args ) );
	if ( null === $timestamp ) {
		$timestamp = wp_next_scheduled( $hook, $args );
		if ( false === $timestamp ) {
			return false;
		}
	}
	if ( ! isset( $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ][ $key ] ) ) {
		return false;
	}
	$data = $GLOBALS['dragoncronmanager_test_cron'][ $timestamp ][ $hook ][ $key ];
	return (object) array(
		'hook'      => $hook,
		'timestamp' => $timestamp,
		'schedule'  => $data['schedule'],
		'args'      => $data['args'],
		'interval'  => $data['interval'] ?? null,
	);
}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['dragoncronmanager_test_options'] ) ? $GLOBALS['dragoncronmanager_test_options'][ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	if ( ! dragoncronmanager_test_option_write_allowed() ) {
		return false;
	}
	if ( array_key_exists( $name, $GLOBALS['dragoncronmanager_test_options'] ) && $GLOBALS['dragoncronmanager_test_options'][ $name ] === $value ) {
		return false;
	}
	$GLOBALS['dragoncronmanager_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['dragoncronmanager_test_options'] ) ) {
		return false;
	}
	return update_option( $name, $value );
}

function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['dragoncronmanager_test_options'] ) || ! dragoncronmanager_test_option_write_allowed() ) {
		return false;
	}
	unset( $GLOBALS['dragoncronmanager_test_options'][ $name ] );
	return true;
}

function human_time_diff( $from, $to = 0 ) {
	$to = $to ? $to : time();
	return abs( $to - $from ) . ' secs';
}


if ( ! function_exists( 'dragon_test_repair_utf8' ) ) {
	/**
	 * Mirrors wp_check_invalid_utf8( $text, true ) over a whole structure:
	 * invalid byte sequences are stripped rather than causing a failure, which is
	 * what core does before encoding.
	 *
	 * @param mixed $value Value to repair.
	 * @return mixed
	 */
	function dragon_test_repair_utf8( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? dragon_test_repair_utf8( $key ) : $key ] = dragon_test_repair_utf8( $item );
			}
			return $out;
		}

		if ( ! is_string( $value ) || '' === $value || 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '', $value );
	}
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( dragon_test_repair_utf8( $data ), $options, $depth );
}

function current_time( $type, $gmt = 0 ) {
	// Core: 'timestamp'/'U' return a Unix timestamp; 'mysql' and any other
	// format return the wall-clock time in the SITE timezone unless $gmt.
	if ( 'timestamp' === $type || 'U' === $type ) {
		return time();
	}
	$format = 'mysql' === $type ? 'Y-m-d H:i:s' : $type;
	$tz     = $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
	return ( new DateTime( 'now', $tz ) )->format( $format );
}

function wp_timezone() {
	return new DateTimeZone( $GLOBALS['dragoncronmanager_test_timezone'] ?? 'UTC' );
}

function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
	$datetime = date_create( $date_string, wp_timezone() );
	if ( false === $datetime ) {
		return false;
	}
	return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
}

function check_ajax_referer( ...$args ) {
	unset( $args );
	return 1;
}

function current_user_can( $capability, ...$args ) {
	unset( $args );
	return ! in_array( $capability, $GLOBALS['dragoncronmanager_test_denied_caps'] ?? array(), true );
}

function is_multisite() {
	return ! empty( $GLOBALS['dragoncronmanager_test_multisite'] );
}

function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
	unset( $callback, $position );
	$GLOBALS['dragoncronmanager_test_menu_pages'][ $menu_slug ] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
	);
	if ( ! current_user_can( $capability ) ) {
		return false;
	}
	return 'tools_page_' . $menu_slug;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority );
	$GLOBALS['dragoncronmanager_test_filters'][ $hook ][] = array( $callback, (int) $accepted_args );
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['dragoncronmanager_test_filters'][ $hook ] ?? array() as $filter ) {
		$value = call_user_func_array( $filter[0], array_slice( array_merge( array( $value ), $args ), 0, $filter[1] ) );
	}
	return $value;
}

function site_url( $path = '' ) {
	return 'https://example.test/' . ltrim( (string) $path, '/' );
}

function wp_doing_cron() {
	return ! empty( $GLOBALS['dragoncronmanager_test_doing_cron'] );
}


function wp_send_json_success( $data = null ) {
	throw new DragonCronManager_Test_Json_Exit( true, $data );
}

function wp_send_json_error( $data = null ) {
	throw new DragonCronManager_Test_Json_Exit( false, $data );
}

function sanitize_text_field( $str ) {
	/*
	 * Mirrors core's _sanitize_text_fields( $str, false ): invalid UTF-8 is
	 * dropped, tags are stripped whenever the value contains "<", runs of
	 * whitespace fold to one space, and percent-encoded sequences such as %20
	 * are REMOVED entirely. That last rule surprises people and matters for a
	 * fleet that handles URLs, so a stub that only folds whitespace hides it.
	 */
	$filtered = (string) $str;

	if ( '' !== $filtered && 1 !== preg_match( '//u', $filtered ) ) {
		$filtered = (string) preg_replace( '/[\x80-\xFF]/', '', $filtered );
	}

	if ( str_contains( $filtered, '<' ) ) {
		$filtered = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered );
		$filtered = strip_tags( $filtered );
		$filtered = (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	}

	$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );

	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}

	if ( $found ) {
		$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
	}

	return $filtered;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

dragoncronmanager_test_reset();

require_once dirname( __DIR__ ) . '/includes/class-cron.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-doctor.php';
require_once dirname( __DIR__ ) . '/includes/class-tick-logger.php';
