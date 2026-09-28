<?php
/**
 * The status changes a reviewer can make.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Assets\FrontendAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

enum MemberTransition: string
{
	case Approve    = 'approve';
	case Deny       = 'deny';
	case Deactivate = 'deactivate';
	case Reactivate = 'reactivate';

	/**
	 * Statuses a member must have for this change. Deny only applies to pending requests, so "denied"
	 * always means a request that was refused; approve also corrects a denial.
	 *
	 * @return array<int,MemberStatus>
	 */
	public function from_statuses(): array
	{
		return match ( $this ) {
			self::Approve    => array( MemberStatus::Pending, MemberStatus::Denied ),
			self::Deny       => array( MemberStatus::Pending ),
			self::Deactivate => array( MemberStatus::Approved ),
			self::Reactivate => array( MemberStatus::Inactive ),
		};
	}

	public function to_status(): MemberStatus
	{
		return match ( $this ) {
			self::Approve, self::Reactivate => MemberStatus::Approved,
			self::Deny                      => MemberStatus::Denied,
			self::Deactivate                => MemberStatus::Inactive,
		};
	}

	/**
	 * The authenticated AJAX action that makes this change.
	 */
	public function ajax_action(): string
	{
		return match ( $this ) {
			self::Approve    => FrontendAssets::APPROVE_ACTION,
			self::Deny       => FrontendAssets::DENY_ACTION,
			self::Deactivate => FrontendAssets::DEACTIVATE_ACTION,
			self::Reactivate => FrontendAssets::REACTIVATE_ACTION,
		};
	}

	/**
	 * @return array<int,self> The changes a member with this status can get.
	 */
	public static function available_for( MemberStatus $status ): array
	{
		return array_values(
			array_filter(
				self::cases(),
				static fn ( self $transition ): bool => in_array( $status, $transition->from_statuses(), true )
			)
		);
	}
}
