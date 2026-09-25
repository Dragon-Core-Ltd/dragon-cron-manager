<?php
/**
 * Admin menu titles follow the fleet convention.
 *
 * @package DragonCronManager
 */

namespace DragonCronManager\Tests;

use DragonCronManager\Admin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-admin.php';

/**
 * Tests for the Tools submenu registration.
 */
final class AdminMenuTest extends TestCase {

	protected function setUp(): void {
		dragoncronmanager_test_reset();
	}

	public function test_page_title_carries_the_brand_and_menu_title_does_not(): void {
		$admin = ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
		$admin->add_admin_menu();

		$page = $GLOBALS['dragoncronmanager_test_menu_pages']['dragon-cron-manager'] ?? null;
		$this->assertNotNull( $page );
		$this->assertSame( 'Dragon Cron Manager', $page['page_title'] );
		$this->assertSame( 'Cron Manager', $page['menu_title'] );
		$this->assertSame( 'manage_options', $page['capability'] );
	}
}
