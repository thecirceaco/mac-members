<?php
/**
 * Frontend asset registration.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Assets;

use MacMembers\Contracts\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class FrontendAssets implements Service
{
	public const STYLE_HANDLE  = 'mac-members-pending-members';
	public const SCRIPT_HANDLE = 'mac-members-pending-members';
	public const NONCE_ACTION  = 'mac_members_member_action';
	public const APPROVE_ACTION    = 'mac_members_approve_user';
	public const DENY_ACTION       = 'mac_members_deny_user';
	public const DEACTIVATE_ACTION = 'mac_members_deactivate_user';
	public const REACTIVATE_ACTION = 'mac_members_reactivate_user';

	public function register(): void
	{
		\add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets(): void
	{
		\wp_register_style(
			self::STYLE_HANDLE,
			MAC_MEMBERS_ASSETS_URL . 'pending-members.css',
			array(),
			MAC_MEMBERS_VERSION
		);

		\wp_register_script(
			self::SCRIPT_HANDLE,
			MAC_MEMBERS_ASSETS_URL . 'pending-members.js',
			array(),
			MAC_MEMBERS_VERSION,
			true
		);
	}

	public function enqueue_pending_members(): void
	{
		$this->register_assets();

		\wp_enqueue_style( self::STYLE_HANDLE );
		\wp_enqueue_script( self::SCRIPT_HANDLE );

		$settings = \wp_json_encode(
			$this->get_pending_members_config(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $settings ) {
			return;
		}

		\wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.macMembers = ' . $settings . ';',
			'before'
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function get_pending_members_config(): array
	{
		return array(
			'ajaxUrl'         => \admin_url( 'admin-ajax.php' ),
			'nonce'           => \wp_create_nonce( self::NONCE_ACTION ),
			'approveAction'   => self::APPROVE_ACTION,
			'denyAction'      => self::DENY_ACTION,
			'confirmApprove'  => __( 'Are you sure you want to approve this member?', 'mac-members' ),
			'confirmDeny'     => __( 'Are you sure you want to deny this member?', 'mac-members' ),
			'genericError'    => __( 'Something went wrong. Please try again.', 'mac-members' ),
			'genericSuccess'  => __( 'Member updated.', 'mac-members' ),
			'emptyStateText'  => __( 'There are no pending members.', 'mac-members' ),
		);
	}
}
