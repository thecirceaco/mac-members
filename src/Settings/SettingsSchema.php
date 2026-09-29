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
	public const TYPE_EMAIL  = 'email';
	public const TYPE_TOGGLE = 'toggle';
	public const TYPE_ROLES  = 'roles';
	public const TYPE_CHOICE = 'choice';
	public const TYPE_FIELDS = 'fields';

	/**
	 * Sizes of the members table and its modal: see the "Interface scale" setting.
	 */
	public const TABLE_SIZES = array( 'medium', 'small' );

	/**
	 * How the members table and its modal show dates: see the "Date format" setting.
	 */
	public const DATE_DISPLAYS = array( 'date', 'datetime', 'relative' );

	/**
	 * The sections of the settings page, in order, with their titles.
	 *
	 * @return array<string,array{title:string,description?:string}>
	 */
	public function get_sections(): array
	{
		return array(
			'interface' => array(
				'title' => __( 'Interface', 'mac-members' ),
			),
			'emails'    => array(
				'title' => __( 'Emails', 'mac-members' ),
			),
			'plugin'    => array(
				'title' => __( 'Plugin', 'mac-members' ),
			),
		);
	}

	/**
	 * Rows that hold several toggles, each shown with its option label.
	 *
	 * @return array<string,string> Row labels keyed by group.
	 */
	public function get_groups(): array
	{
		return array(
			'member_emails' => __( 'Member emails', 'mac-members' ),
			'admin_emails'  => __( 'Admin emails', 'mac-members' ),
		);
	}

	/**
	 * The settings in the order of the settings page, each in a section. Toggles with a group share one row, and
	 * a toggle shows its option label next to its checkbox.
	 *
	 * @return array<string,array{label:string,type:string,default:mixed,section:string,description?:string,choices?:array<string,string>,rows?:int,group?:string,option?:string}>
	 */
	public function get_fields(): array
	{
		return array(
			'hidden_roles' => array(
				'label'       => __( 'Hidden roles', 'mac-members' ),
				'type'        => self::TYPE_ROLES,
				'default'     => array(),
				'section'     => 'interface',
				'description' => __( 'Members with a checked role don\'t show in the members table, its counts, its search or its role filter, so their status can\'t be changed there. Roles with administrative capabilities, like Administrator, are always hidden. The four member roles can\'t be hidden, so they aren\'t listed.', 'mac-members' ),
			),
			'detail_fields' => array(
				'label'       => __( 'Extra modal fields', 'mac-members' ),
				'type'        => self::TYPE_FIELDS,
				'default'     => '',
				'section'     => 'interface',
				'rows'        => 12,
				'description' => __( 'Fields the member details modal shows after the table\'s fields, in this order; the table itself doesn\'t show them. One per line or comma-separated, as key : Label, for example phone : Phone, where the key is a user meta key, an ACF field name or a user field like user_url. With the key alone, the label is the ACF field\'s label, or else the key made readable: local_number shows as Local number. Keys that start with an underscore never show, nor do passwords, password reset keys, login sessions, capabilities and user levels.', 'mac-members' ),
			),
			'date_display' => array(
				'label'       => __( 'Date format', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'date',
				'section'     => 'interface',
				'choices'     => array_combine(
					self::DATE_DISPLAYS,
					array( __( 'Date', 'mac-members' ), __( 'Datetime', 'mac-members' ), __( 'Relative', 'mac-members' ) )
				),
				'description' => __( 'For Registered and Last Login, in the table and the modal. Date and Datetime use the site\'s date and time formats; Relative shows the time since, like 3 days ago. The full date and time show on hover.', 'mac-members' ),
			),
			'table_size' => array(
				'label'       => __( 'Interface scale', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'medium',
				'section'     => 'interface',
				'choices'     => array_combine(
					self::TABLE_SIZES,
					array( __( 'Medium', 'mac-members' ), __( 'Small', 'mac-members' ) )
				),
				'description' => __( 'The text and button size of the members table, its controls and its modal. Medium uses the normal text size, Small the small one.', 'mac-members' ),
			),
			'from_email' => array(
				'label'   => __( 'From email', 'mac-members' ),
				'type'    => self::TYPE_EMAIL,
				'default' => $this->get_site_admin_email(),
				'section' => 'emails',
			),
			'send_member_approval_email' => array(
				'label'   => __( 'Send member approval email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'member_emails',
				'option'  => __( 'Approval', 'mac-members' ),
			),
			'send_member_denial_email' => array(
				'label'   => __( 'Send member denial email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'member_emails',
				'option'  => __( 'Denial', 'mac-members' ),
			),
			'send_member_deactivation_email' => array(
				'label'   => __( 'Send member deactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'member_emails',
				'option'  => __( 'Deactivation', 'mac-members' ),
			),
			'send_member_reactivation_email' => array(
				'label'   => __( 'Send member reactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'member_emails',
				'option'  => __( 'Reactivation', 'mac-members' ),
			),
			'admin_notification_email' => array(
				'label'   => __( 'Admin notification email', 'mac-members' ),
				'type'    => self::TYPE_EMAIL,
				'default' => $this->get_site_admin_email(),
				'section' => 'emails',
			),
			'send_admin_approval_email' => array(
				'label'   => __( 'Send admin approval email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'admin_emails',
				'option'  => __( 'Approval', 'mac-members' ),
			),
			'send_admin_denial_email' => array(
				'label'   => __( 'Send admin denial email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'admin_emails',
				'option'  => __( 'Denial', 'mac-members' ),
			),
			'send_admin_deactivation_email' => array(
				'label'   => __( 'Send admin deactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'admin_emails',
				'option'  => __( 'Deactivation', 'mac-members' ),
			),
			'send_admin_reactivation_email' => array(
				'label'   => __( 'Send admin reactivation email', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => true,
				'section' => 'emails',
				'group'   => 'admin_emails',
				'option'  => __( 'Reactivation', 'mac-members' ),
			),
			'top_level_menu' => array(
				'label'   => __( 'Top-level admin menu', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => false,
				'section' => 'plugin',
				'option'  => __( 'Show MAC Members as a top-level admin menu item', 'mac-members' ),
			),
			'delete_data_on_uninstall' => array(
				'label'       => __( 'Delete plugin data', 'mac-members' ),
				'type'        => self::TYPE_TOGGLE,
				'default'     => false,
				'section'     => 'plugin',
				'option'      => __( 'Delete plugin data on uninstall', 'mac-members' ),
				'description' => __( 'When the plugin is deleted, also remove its settings, the review capability and the member roles that no user holds. Roles that users still hold stay.', 'mac-members' ),
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
	public function get_roles_fields(): array
	{
		return $this->get_fields_by_type( self::TYPE_ROLES );
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
	 * Splits a comma-separated list into its trimmed, non-empty entries. Development builds kept the hidden roles
	 * as such a list.
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
