<?php
/**
 * Tests for the bundled SureCart licensing SDK: the copy keeps only its documented local changes.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BundledSureCartSdkTest extends TestCase {
	private const SDK_DIR = __DIR__ . '/../../inc/Vendor/SureCart/Licensing';

	public function test_the_sdk_files_use_the_mac_members_namespace(): void {
		$files = glob( self::SDK_DIR . '/*.php' );

		self::assertSame(
			array( 'Activation.php', 'Client.php', 'License.php', 'Settings.php', 'Updater.php' ),
			array_map( 'basename', false === $files ? array() : $files )
		);

		foreach ( false === $files ? array() : $files as $file ) {
			$contents = (string) file_get_contents( $file );

			// MAC Core's copy uses its own namespace, so the two copies never clash on one site.
			self::assertStringContainsString( "namespace MacMembers\\Vendor\\SureCart\\Licensing;\n", $contents, basename( $file ) );
			self::assertStringNotContainsString( 'namespace SureCart\\Licensing;', $contents, basename( $file ) );
			self::assertStringNotContainsString( 'MacCore', $contents, basename( $file ) );
		}
	}

	public function test_the_settings_page_can_skip_its_menu(): void {
		$settings = (string) file_get_contents( self::SDK_DIR . '/Settings.php' );

		self::assertStringContainsString( "'register_menu'      => true,", $settings );
		self::assertStringContainsString( "if ( ! empty( \$this->menu_args['register_menu'] ) ) {\n\t\t\tadd_action( 'admin_menu', array( \$this, 'admin_menu' ), 99 );", $settings );
	}

	public function test_the_readme_records_the_upstream_version_and_the_local_changes(): void {
		$readme = (string) file_get_contents( self::SDK_DIR . '/README.md' );

		self::assertStringContainsString( 'Version: `v1.2.1`', $readme );
		self::assertStringContainsString( 'c24515df17bc184686ca3c86761ce0541f60d7b5', $readme );
		self::assertStringContainsString( '## Local changes', $readme );
	}
}
