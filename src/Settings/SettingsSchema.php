<?php
/**
 * Settings field schema.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class SettingsSchema
{
	public const TYPE_ROLE   = 'role';
	public const TYPE_EMAIL  = 'email';
	public const TYPE_TOGGLE = 'toggle';

	/**
	 * @return array<string,array{label:string,type:string,default:mixed}>
	 */
	public function get_fields(): array
	{
		return array(
			'pending_role' => array(
				'label'   => __( 'Pending role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => 'member-pending',
			),
			'approved_role' => array(
				'label'   => __( 'Approved role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => 'member',
			),
			'denied_role' => array(
				'label'   => __( 'Denied role', 'mac-members' ),
				'type'    => self::TYPE_ROLE,
				'default' => 'member-invalid',
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
