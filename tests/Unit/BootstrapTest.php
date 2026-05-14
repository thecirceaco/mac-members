<?php
/**
 * Bootstrap tests.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Kernel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

		self::assertSame( '0.2.0', MAC_MEMBERS_VERSION );
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
