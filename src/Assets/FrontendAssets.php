<?php
/**
 * Frontend asset registration.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Assets;

use MacMembers\Contracts\Service;
use MacMembers\Members\MembersTableRenderer;
use MacMembers\Members\MembersTableShortcode;
use MacMembers\Members\MemberStatus;
use MacMembers\Members\MemberTransition;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class FrontendAssets implements Service
{
	public const STYLE_HANDLE  = 'mac-members-table';
	public const SCRIPT_HANDLE = 'mac-members-table';
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
			MAC_MEMBERS_ASSETS_URL . 'members-table.css',
			array(),
			$this->get_asset_version( 'members-table.css' )
		);

		\wp_register_script(
			self::SCRIPT_HANDLE,
			MAC_MEMBERS_ASSETS_URL . 'members-table.js',
			array(),
			$this->get_asset_version( 'members-table.js' ),
			true
		);
	}

	/**
	 * The plugin version plus the file's modification time. Browsers and CDNs keep these files for a year, so
	 * a new build with the same plugin version still needs a new address to reach them.
	 */
	public function get_asset_version( string $file ): string
	{
		$path     = MAC_MEMBERS_ASSETS_PATH . $file;
		$modified = \is_file( $path ) ? filemtime( $path ) : false;

		return false === $modified ? MAC_MEMBERS_VERSION : MAC_MEMBERS_VERSION . '.' . $modified;
	}

	public function enqueue_members_table(): void
	{
		$this->register_assets();

		\wp_enqueue_style( self::STYLE_HANDLE );
		\wp_enqueue_script( self::SCRIPT_HANDLE );

		$settings = \wp_json_encode(
			$this->get_members_table_config(),
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
	 * @return array<string,mixed>
	 */
	private function get_members_table_config(): array
	{
		$transitions = array();

		foreach ( MemberTransition::cases() as $transition ) {
			$transitions[ $transition->value ] = array(
				'action'  => $transition->ajax_action(),
				'label'   => $transition->label(),
				'confirm' => $transition->confirm_message(),
				'classes' => $transition->button_classes(),
			);
		}

		$statuses = array();

		foreach ( MemberStatus::cases() as $status ) {
			$statuses[ $status->value ] = array(
				'label'       => $status->label(),
				'transitions' => array_map(
					static fn ( MemberTransition $transition ): string => $transition->value,
					MemberTransition::available_for( $status )
				),
			);
		}

		return array(
			'ajaxUrl'        => \admin_url( 'admin-ajax.php' ),
			'nonce'          => \wp_create_nonce( self::NONCE_ACTION ),
			'transitions'    => $transitions,
			'statuses'       => $statuses,
			'genericError'   => __( 'Something went wrong. Please try again.', 'mac-members' ),
			'genericSuccess' => __( 'Member updated.', 'mac-members' ),
			'rangeText'      => MembersTableRenderer::get_range_template(),
			'columnsCookie'  => MembersTableShortcode::COLUMNS_COOKIE,
		);
	}
}
