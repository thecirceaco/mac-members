<?php
/**
 * Plugin kernel.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers;

use MacMembers\Contracts\Service;

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
		$services = [];

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
