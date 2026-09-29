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
	public const TYPE_CHOICE = 'choice';
	public const TYPE_FIELDS = 'fields';

	/**
	 * Sizes of the members table: see the "Members table size" setting.
	 */
	public const TABLE_SIZES = array( 'medium', 'small' );

	/**
	 * How the members table shows dates: see the "Dates in the members table" setting.
	 */
	public const DATE_DISPLAYS = array( 'relative', 'date' );

	/**
	 * @return array<string,array{label:string,type:string,default:mixed,description?:string,choices?:array<string,string>,rows?:int}>
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
			'hidden_roles' => array(
				'label'       => __( 'Roles hidden from the members table', 'mac-members' ),
				'type'        => self::TYPE_LIST,
				'default'     => 'administrator',
				'description' => __( 'Comma-separated role slugs, role names or capabilities, for example administrator, manage_options. Users who hold a matching role, or a role with one of these capabilities, never show in the members table, its counts, its search or its role filter, so nobody can change their status there. The four status roles cannot be hidden.', 'mac-members' ),
			),
			'table_size' => array(
				'label'       => __( 'Members table size', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'medium',
				'choices'     => array_combine(
					self::TABLE_SIZES,
					array( __( 'Medium', 'mac-members' ), __( 'Small', 'mac-members' ) )
				),
				'description' => __( 'Medium puts the table, its controls and its buttons in the normal text size, Small in the small one.', 'mac-members' ),
			),
			'date_display' => array(
				'label'       => __( 'Dates in the members table', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'relative',
				'choices'     => array_combine(
					self::DATE_DISPLAYS,
					array( __( 'Relative', 'mac-members' ), __( 'Date', 'mac-members' ) )
				),
				'description' => __( 'For Registered and Last Login. Relative shows the time since, like 3 days ago. Date shows the day in the site\'s date format, and Last Login the time too. Both show the full date and time on hover.', 'mac-members' ),
			),
			'detail_fields' => array(
				'label'       => __( 'Member details fields', 'mac-members' ),
				'type'        => self::TYPE_FIELDS,
				'default'     => '',
				'rows'        => 12,
				'description' => __( 'One field per line or comma-separated: the user meta key, a colon and the label, for example phone : Phone. Without a label, the ACF field label shows, or else the key made readable. The member details show these fields after the account fields, in this order. Private keys that start with an underscore, passwords, sessions and capabilities never show.', 'mac-members' ),
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
			'delete_data_on_uninstall' => array(
				'label'       => __( 'Delete plugin data on uninstall', 'mac-members' ),
				'type'        => self::TYPE_TOGGLE,
				'default'     => false,
				'description' => __( 'When the plugin is deleted, remove its settings, the review capability and the member roles that no user holds. Roles that users still hold are kept, so nobody is left without a role.', 'mac-members' ),
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
	 * Reads the member details fields: one "key : label" per line or comma-separated, the label optional.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function parse_fields( string $value ): array
	{
		$fields  = array();
		$entries = preg_split( '/[\r\n,]+/', $value );

		foreach ( false === $entries ? array() : $entries as $entry ) {
			$parts = explode( ':', $entry, 2 );
			$key   = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $parts[0] ) );

			if ( '' === $key ) {
				continue;
			}

			$fields[] = array(
				'key'   => $key,
				'label' => trim( $parts[1] ?? '' ),
			);
		}

		return $fields;
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
