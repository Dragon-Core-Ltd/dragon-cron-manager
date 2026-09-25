<?php
/**
 * Only WordPress's own events are protected as core; a wp_ prefix alone does
 * not make a plugin's event core.
 *
 * @package DragonCronManager
 */

use DragonCronManager\Cron;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreHookTest extends TestCase {

	public static function plugin_hooks(): array {
		return array(
			array( 'wp_rocket_preload_cron' ),
			array( 'wp_1_wc_updater_cron' ),
			array( 'wp_mail_smtp_summary_report_email' ),
			array( 'dragoncronmanager_cleanup_logs' ),
		);
	}

	#[DataProvider( 'plugin_hooks' )]
	public function test_plugin_hooks_are_not_core( string $hook ): void {
		$this->assertFalse( ( new Cron() )->is_core_hook( $hook ) );
	}

	public static function core_hooks(): array {
		$hooks = array(
			'delete_expired_transients',
			'do_pings',
			'importer_scheduled_cleanup',
			'publish_future_post',
			'recovery_mode_clean_expired_keys',
			'update_network_counts',
			'upgrader_scheduled_cleanup',
			'wp_delete_temp_updater_backups',
			'wp_https_detection',
			'wp_maybe_auto_update',
			'wp_privacy_delete_old_export_files',
			'wp_privacy_personal_data_cleanup_requests',
			'wp_scheduled_auto_draft_delete',
			'wp_scheduled_delete',
			'wp_site_health_scheduled_check',
			'wp_split_shared_term_batch',
			'wp_update_comment_type_batch',
			'wp_update_plugins',
			'wp_update_themes',
			'wp_update_user_counts',
			'wp_version_check',
		);
		return array_map( static fn( $h ) => array( $h ), $hooks );
	}

	#[DataProvider( 'core_hooks' )]
	public function test_core_hooks_are_core( string $hook ): void {
		$this->assertTrue( ( new Cron() )->is_core_hook( $hook ) );
	}
}
