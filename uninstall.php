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
$dragoncronmanager_table_name = $wpdb->prefix . 'dcm_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe table name from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$dragoncronmanager_table_name}" );

// Delete all plugin options (current namespace-derived prefix and the pre-1.0.1
// dcm_ prefix, in case an install never ran the migration).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dragoncronmanager\_%' OR option_name LIKE 'dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear scheduled cron events (current and pre-1.0.1 hook names).
wp_clear_scheduled_hook( 'dragoncronmanager_cleanup_logs' );
wp_clear_scheduled_hook( 'dragoncronmanager_cleanup_trash' );
wp_clear_scheduled_hook( 'dcm_cleanup_logs' );
wp_clear_scheduled_hook( 'dcm_cleanup_trash' );

// Delete any transients (both prefixes).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dragoncronmanager\_%' OR option_name LIKE '%\_transient\_dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dragoncronmanager\_%' OR option_name LIKE '%\_transient\_timeout\_dcm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
