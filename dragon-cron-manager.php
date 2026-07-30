<?php
/**
 * Plugin Name: Dragon Cron Manager
 * Plugin URI: https://dragoncore.ltd/plugins/dragon-cron-manager
 * Description: View, manage, and debug WordPress cron jobs. See all scheduled events, run them manually, and track execution history.
 * Version: 1.0.0
 * Author: Dragon Core
 * Author URI: https://dragoncore.ltd
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragon-cron-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace DragonCronManager;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants - DCM is the standard prefix for Dragon Cron Manager.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'DCM_VERSION', '1.0.0' );
define( 'DCM_PLUGIN_FILE', __FILE__ );
define( 'DCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DCM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// Load plugin classes
require_once DCM_PLUGIN_DIR . 'includes/class-plugin.php';
require_once DCM_PLUGIN_DIR . 'includes/class-cron.php';
require_once DCM_PLUGIN_DIR . 'includes/class-logger.php';
require_once DCM_PLUGIN_DIR . 'includes/class-admin.php';
require_once DCM_PLUGIN_DIR . 'includes/class-ajax.php';

/**
 * Plugin activation hook
 */
function dcm_activate() {
	Plugin::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\dcm_activate' );

/**
 * Plugin deactivation hook
 */
function dcm_deactivate() {
	Plugin::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\dcm_deactivate' );

/**
 * Initialize the plugin
 */
function dcm_init() {
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\dcm_init' );

/**
 * Add settings link to plugin row
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function dcm_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'tools.php?page=dragon-cron-manager' ),
		__( 'Settings', 'dragon-cron-manager' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . DCM_PLUGIN_BASENAME, __NAMESPACE__ . '\dcm_plugin_action_links' );
