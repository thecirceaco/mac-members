<?php
/**
 * Settings field schema.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

use MacMembers\Security\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class SettingsSchema
{
	public const TYPE_ROLE   = 'role';
	public const TYPE_EMAIL  = 'email';
	public const TYPE_TOGGLE = 'toggle';
	public const TYPE_LIST   = 'list';

	/**
	 * @return array<string,array{label:string,type:string,default:mixed,description?:string}>
	 */
	public function get_fields(): array
	{
		return array(
			'pending_role' => array(
				'label'   => __( 'Pending role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => Roles::PENDING,
			),
			'approved_role' => array(
				'label'   => __( 'Approved role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => Roles::APPROVED,
			),
			'inactive_role' => array(
				'label'   => __( 'Inactive role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => Roles::INACTIVE,
			),
			'denied_role' => array(
				'label'   => __( 'Denied role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => Roles::DENIED,
			),
			'role_filter_exclusions' => array(
				'label'       => __( 'Roles left out of the role filter', 'mac-members' ),
				'type'        => self::TYPE_LIST,
				'default'     => 'administrator',
				'description' => __( 'Comma-separated role slugs, role names or capabilities, for example administrator, manage_options. The members table offers every role its members hold, except the status roles and the roles that match this list or have one of these capabilities.', 'mac-members' ),
			),
			'admin_notification_email' => array(
				'label'   => __( 'Admin notification email', 'mac-members' ),
				'type'    => self::TYPE_EMAIL,
				'default' => $this->get_site_admin_email(),
			),
			'from_email' => array(
				'label'   => __( 'From email', 'mac-members' ),
				'type'    => self::TYPE_EMAIL,
				'default' => $this->get_site_admin_email(),
			),
			'send_member_approval_email' => array(
				'label'   => __( 'Send member approval email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'send_member_denial_email' => array(
				'label'   => __( 'Send member denial email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'send_admin_approval_email' => array(
				'label'   => __( 'Send admin approval email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'send_admin_denial_email' => array(
				'label'   => __( 'Send admin denial email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'send_member_deactivation_email' => array(
				'label'   => __( 'Send member deactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'send_admin_deactivation_email' => array(
				'label'   => __( 'Send admin deactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
			),
			'top_level_menu' => array(
				'label'   => __( 'Show MAC Members as a top-level admin menu item', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => false,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_defaults(): array
	{
		$defaults = array();

		foreach ( $this->get_fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_role_fields(): array
	{
		return $this->get_fields_by_type( self::TYPE_ROLE );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_email_fields(): array
	{
		return $this->get_fields_by_type( self::TYPE_EMAIL );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_toggle_fields(): array
	{
		return $this->get_fields_by_type( self::TYPE_TOGGLE );
	}

	/**
	 * Splits a comma-separated list into its trimmed, non-empty entries.
	 *
	 * @return array<int,string>
	 */
	public static function parse_list( string $value ): array
	{
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn ( string $entry ): bool => '' !== $entry ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function get_fields_by_type( string $type ): array
	{
		$keys = array();

		foreach ( $this->get_fields() as $key => $field ) {
			if ( $type === $field['type'] ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	private function get_site_admin_email(): string
	{
		$email = \sanitize_email( (string) \get_option( 'admin_email', '' ) );

		return \is_email( $email ) ? $email : '';
	}
}
