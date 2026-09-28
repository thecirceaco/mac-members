<?php
/**
 * Member statuses. Each status is marked by one configurable role.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

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
	 * Settings key of the role that marks this status.
	 */
	public function role_setting(): string
	{
		return $this->value . '_role';
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
