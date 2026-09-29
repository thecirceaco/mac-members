<?php
/**
 * Bootstrap tests.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use FilesystemIterator;
use MacMembers\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class BootstrapTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		mac_members_tests_reset_wp_state();

		if ( class_exists( Kernel::class ) ) {
			$kernel = new ReflectionClass( Kernel::class );

			if ( $kernel->hasProperty( 'booted' ) ) {
				$property = $kernel->getProperty( 'booted' );
				$property->setAccessible( true );
				$property->setValue( null, false );
			}
		}
	}

	public function test_plugin_constants_are_defined(): void
	{
		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';

		self::assertSame( '0.5.1', MAC_MEMBERS_VERSION );
		self::assertSame( 'mac-members', MAC_MEMBERS_ADMIN_SLUG );
		self::assertSame( 'mac_members_settings', MAC_MEMBERS_SETTINGS_OPTION );
		self::assertSame( dirname( __DIR__, 2 ) . '/', MAC_MEMBERS_PATH );
		self::assertSame( MAC_MEMBERS_PATH . 'mac-members.php', MAC_MEMBERS_PLUGIN_FILE );
		self::assertSame( MAC_MEMBERS_PATH . 'src/', MAC_MEMBERS_SRC_PATH );
		self::assertSame( MAC_MEMBERS_PATH . 'assets/', MAC_MEMBERS_ASSETS_PATH );
		self::assertSame( 'https://example.test/wp-content/plugins/mac-members/', MAC_MEMBERS_URL );
		self::assertSame( MAC_MEMBERS_URL . 'assets/', MAC_MEMBERS_ASSETS_URL );
	}

	public function test_autoloader_loads_mac_members_classes(): void
	{
		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
		require_once dirname( __DIR__, 2 ) . '/inc/autoload.php';

		self::assertTrue( interface_exists( \MacMembers\Contracts\Service::class ) );
		self::assertTrue( class_exists( Kernel::class ) );
	}

	/**
	 * Requesting a plugin file directly, without WordPress, must stop before any code runs.
	 */
	#[DataProvider( 'provide_src_files' )]
	public function test_src_file_exits_when_loaded_without_wordpress( string $file ): void
	{
		self::assertStringContainsString(
			"if ( ! defined( 'ABSPATH' ) ) {\n\texit; // Exit if accessed directly.\n}",
			(string) file_get_contents( $file )
		);

		exec( escapeshellarg( PHP_BINARY ) . ' -n -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit_code );

		self::assertSame( array(), $output, implode( "\n", $output ) );
		self::assertSame( 0, $exit_code );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_src_files(): array
	{
		$root  = dirname( __DIR__, 2 );
		$files = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
				$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = array( $file->getPathname() );
			}
		}

		ksort( $files );

		return $files;
	}

	public function test_kernel_registers_filtered_services_once(): void
	{
		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
		require_once dirname( __DIR__, 2 ) . '/inc/autoload.php';

		$service = new class implements \MacMembers\Contracts\Service {
			public int $register_count = 0;

			public function register(): void
			{
				$this->register_count++;
			}
		};

		add_filter(
			'mac_members_services',
			static fn ( array $services ): array => [ $service ]
		);

		Kernel::boot();
		Kernel::boot();

		self::assertSame( 1, $service->register_count );
	}
}
