<?php
/**
 * Tests for the links in the MAC Members row of the Plugins screen.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Admin\MenuPlacement;
use MacMembers\Admin\PluginListingLinks;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( PluginListingLinks::class )]
final class PluginListingLinksTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_filters_the_action_links_of_this_plugin_and_every_row_meta(): void {
		$links = $this->create_links( false );

		$links->register();

		$action_links = $GLOBALS['mac_members_test_filters']['plugin_action_links_mac-members/mac-members.php'][0];
		$row_meta     = $GLOBALS['mac_members_test_filters']['plugin_row_meta'][0];

		self::assertSame( array( $links, 'action_links' ), $action_links['callback'] );
		self::assertSame( array( $links, 'row_meta' ), $row_meta['callback'] );
		self::assertSame( 2, $row_meta['accepted_args'] );
	}

	public function test_action_links_put_settings_and_license_before_deactivate(): void {
		$links = $this->create_links( false )->action_links( array( 'deactivate' => '<a href="#deactivate">Deactivate</a>' ) );

		self::assertSame(
			array(
				'<a href="https://example.test/wp-admin/options-general.php?page=mac-members">Settings</a>',
				'<a href="https://example.test/wp-admin/options-general.php?page=mac-members&amp;tab=license">License</a>',
				'deactivate' => '<a href="#deactivate">Deactivate</a>',
			),
			$links
		);
	}

	public function test_action_links_follow_the_top_level_menu_setting(): void {
		$links = $this->create_links( true )->action_links( array() );

		self::assertSame( '<a href="https://example.test/wp-admin/admin.php?page=mac-members">Settings</a>', $links[0] );
		self::assertSame( '<a href="https://example.test/wp-admin/admin.php?page=mac-members&amp;tab=license">License</a>', $links[1] );
	}

	public function test_row_meta_adds_support_and_documentation_to_this_plugin_only(): void {
		$links = $this->create_links( false );
		$meta  = array( 'Version 0.4.0', 'By Circea', 'View details' );

		self::assertSame(
			array(
				'Version 0.4.0',
				'By Circea',
				'View details',
				'<a href="https://example.test/wp-admin/options-general.php?page=mac-members&amp;tab=support">Support</a>',
				'<a href="https://docs.circea.co/doc/mac-members/" target="_blank" rel="noopener noreferrer">Documentation</a>',
			),
			$links->row_meta( $meta, 'mac-members/mac-members.php' )
		);
		self::assertSame( $meta, $links->row_meta( $meta, 'mac-core/mac-core.php' ) );
	}

	private function create_links( bool $top_level ): PluginListingLinks {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'top_level_menu' => $top_level );

		return new PluginListingLinks( new MenuPlacement( new WordPressSettingsRepository( new SettingsSchema() ) ) );
	}
}
