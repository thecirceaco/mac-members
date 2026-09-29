<?php
/**
 * Member statuses. Each status is marked by one of the roles MAC Members creates.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Security\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

enum MemberStatus: string
{
	case Pending  = 'pending';
	case Approved = 'approved';
	case Inactive = 'inactive';
	case Denied   = 'denied';

	/**
	 * The role that marks this status.
	 */
	public function role(): string
	{
		return match ( $this ) {
			self::Pending  => Roles::PENDING,
			self::Approved => Roles::APPROVED,
			self::Inactive => Roles::INACTIVE,
			self::Denied   => Roles::DENIED,
		};
	}

	public function label(): string
	{
		return match ( $this ) {
			self::Pending  => __( 'Pending', 'mac-members' ),
			self::Approved => __( 'Approved', 'mac-members' ),
			self::Inactive => __( 'Inactive', 'mac-members' ),
			self::Denied   => __( 'Denied', 'mac-members' ),
		};
	}
}
