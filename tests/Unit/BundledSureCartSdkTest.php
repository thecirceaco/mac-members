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

	/**
	 * Every hook the SDK fires and every constant it reads carries the MAC Members prefix, like MAC Core's copy
	 * with its own, so a value set for another plugin's copy can't change MAC Members' licensing. An SDK update
	 * that brings the upstream names back, or adds a new unprefixed one, fails here.
	 */
	public function test_the_sdk_reads_only_mac_members_prefixed_global_names(): void {
		$names = array();
		$files = glob( self::SDK_DIR . '/*.php' );

		foreach ( false === $files ? array() : $files as $file ) {
			$contents = (string) file_get_contents( $file );

			preg_match_all( '/\b(?:apply_filters|apply_filters_ref_array|do_action|do_action_ref_array)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $hooks );
			preg_match_all( '/\b(?:defined|constant)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $constants );

			foreach ( $hooks[1] as $hook ) {
				self::assertStringStartsWith( 'mac_members_', $hook, basename( $file ) );
			}

			foreach ( $constants[1] as $constant ) {
				self::assertStringStartsWith( 'MAC_MEMBERS_', $constant, basename( $file ) );
			}

			self::assertDoesNotMatchRegularExpression( '/(?<![A-Za-z0-9_])(SURECART_LICENSING_ENDPOINT|surecart_licensing_endpoint|surecart_client_license_form_action|surecart_licensing_is_local)(?![A-Za-z0-9_])/', $contents, basename( $file ) );

			$names = array_merge( $names, $hooks[1], $constants[1] );
		}

		sort( $names );

		self::assertSame(
			array(
				'MAC_MEMBERS_SURECART_LICENSING_ENDPOINT',
				'mac_members_surecart_client_license_form_action',
				'mac_members_surecart_licensing_endpoint',
				'mac_members_surecart_licensing_is_local',
			),
			$names
		);
	}

	public function test_the_settings_page_can_skip_its_menu(): void {
		$settings = (string) file_get_contents( self::SDK_DIR . '/Settings.php' );

		self::assertStringContainsString( "'register_menu'      => true,", $settings );
		self::assertStringContainsString( "if ( ! empty( \$this->menu_args['register_menu'] ) ) {\n\t\t\tadd_action( 'admin_menu', array( \$this, 'admin_menu' ), 99 );", $settings );
	}

	public function test_the_license_form_redirect_keeps_the_tab_in_the_query(): void {
		$settings = (string) file_get_contents( self::SDK_DIR . '/Settings.php' );

		// esc_url() would turn & into &#038;, which a script doesn't decode, and the tab would end in the fragment.
		self::assertStringContainsString( 'window.location.assign(<?php echo wp_json_encode( esc_url_raw( $url ) ); ?>);', $settings );
		self::assertStringNotContainsString( 'window.location.assign("<?php echo esc_url( $url ); ?>");', $settings );
	}

	public function test_the_readme_records_the_upstream_version_and_the_local_changes(): void {
		$readme = (string) file_get_contents( self::SDK_DIR . '/README.md' );

		self::assertStringContainsString( 'Version: `v1.2.1`', $readme );
		self::assertStringContainsString( 'c24515df17bc184686ca3c86761ce0541f60d7b5', $readme );
		self::assertStringContainsString( '## Local changes', $readme );
		self::assertStringContainsString( '3. **Prefixed global names.**', $readme );
		self::assertStringContainsString( '4. **Redirect after the license form**', $readme );
	}
}
