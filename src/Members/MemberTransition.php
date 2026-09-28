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
	 * Button label in the members table.
	 */
	public function label(): string
	{
		return match ( $this ) {
			self::Approve    => __( 'Approve', 'mac-members' ),
			self::Deny       => __( 'Deny', 'mac-members' ),
			self::Deactivate => __( 'Deactivate', 'mac-members' ),
			self::Reactivate => __( 'Reactivate', 'mac-members' ),
		};
	}

	/**
	 * Automatic.css button classes: the success button for changes that give access, the danger button for
	 * changes that take it away. The colors come from the site's ACSS status colors.
	 */
	public function button_classes(): string
	{
		return match ( $this ) {
			self::Approve, self::Reactivate => 'btn--success btn--s',
			self::Deny, self::Deactivate    => 'btn--danger btn--s',
		};
	}

	/**
	 * Question the members table asks before it makes this change.
	 */
	public function confirm_message(): string
	{
		return match ( $this ) {
			self::Approve    => __( 'Are you sure you want to approve this member?', 'mac-members' ),
			self::Deny       => __( 'Are you sure you want to deny this member?', 'mac-members' ),
			self::Deactivate => __( 'Are you sure you want to deactivate this member?', 'mac-members' ),
			self::Reactivate => __( 'Are you sure you want to reactivate this member?', 'mac-members' ),
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
