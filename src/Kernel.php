<?php
/**
 * Plugin kernel.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers;

use MacMembers\Actions\MemberActionController;
use MacMembers\Admin\MenuPlacement;
use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\Email\MemberNotificationService;
use MacMembers\Licensing\LicensingService;
use MacMembers\Members\MembersQuery;
use MacMembers\Members\MembersTableRenderer;
use MacMembers\Members\MembersTableShortcode;
use MacMembers\Settings\SettingsController;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Kernel
{
	private static bool $booted = false;

	public static function boot(): void
	{
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		( new self() )->register_services();
	}

	private function register_services(): void
	{
		foreach ( $this->get_services() as $service ) {
			if ( $service instanceof Service ) {
				$service->register();
			}
		}
	}

	/**
	 * @return array<int,Service>
	 */
	private function get_services(): array
	{
		$settings_schema      = new SettingsSchema();
		$settings_repository  = new WordPressSettingsRepository( $settings_schema );
		$menu_placement       = new MenuPlacement( $settings_repository );
		$licensing            = new LicensingService( $menu_placement );
		$frontend_assets      = new FrontendAssets();
		$members_query        = new MembersQuery( $settings_repository );
		$notifications        = new MemberNotificationService( $settings_repository );
		$services             = [
			new Installer(),
			new SettingsController( $settings_repository, $settings_schema, null, $menu_placement, $licensing ),
			$licensing,
			new MemberActionController( $settings_repository, $notifications ),
			$frontend_assets,
			new MembersTableShortcode(
				$members_query,
				new MembersTableRenderer(),
				$frontend_assets,
				$settings_repository
			),
		];

		/**
		 * Filter the runtime service list for MAC Members.
		 *
		 * Follow-up issues append instantiated service objects here.
		 *
		 * @param array<int,Service> $services Service instances.
		 */
		$services = \apply_filters( 'mac_members_services', $services );

		return \is_array( $services ) ? $services : [];
	}
}
