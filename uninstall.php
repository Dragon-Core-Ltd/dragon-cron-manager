<?php
/**
 * Uninstall Dragon Cron Manager
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package DragonCronManager
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the log table.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall file only runs during uninstall.
$dcm_table_name = $wpdb->prefix . 'dcm_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe table name from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$dcm_table_name}" );

// Delete all plugin options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'dcm_cleanup_logs' );
wp_clear_scheduled_hook( 'dcm_cleanup_trash' );

// Delete any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
