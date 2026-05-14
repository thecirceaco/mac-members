<?php
/**
 * Plugin kernel.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\PendingMembers\PendingMembersQuery;
use MacMembers\PendingMembers\PendingMembersShortcode;
use MacMembers\PendingMembers\PendingMembersTableRenderer;
use MacMembers\Settings\SettingsController;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;

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
		$frontend_assets      = new FrontendAssets();
		$pending_members_query = new PendingMembersQuery( $settings_repository );
		$services             = [
			new SettingsController( $settings_repository, $settings_schema ),
			$frontend_assets,
			new PendingMembersShortcode(
				$pending_members_query,
				new PendingMembersTableRenderer(),
				$frontend_assets
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
