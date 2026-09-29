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
	 * Sizes of the members table: see the "Members table size" setting.
	 */
	public const TABLE_SIZES = array( 'medium', 'small' );

	/**
	 * How the members table shows dates: see the "Dates in the members table" setting.
	 */
	public const DATE_DISPLAYS = array( 'relative', 'date' );

	/**
	 * The sections of the settings page, in order, with their titles and an optional description.
	 *
	 * @return array<string,array{title:string,description?:string}>
	 */
	public function get_sections(): array
	{
		return array(
			'table'  => array(
				'title' => __( 'Members table', 'mac-members' ),
			),
			'emails' => array(
				'title'       => __( 'Emails', 'mac-members' ),
				'description' => __( 'The approval emails also go out when an inactive member is reactivated.', 'mac-members' ),
			),
			'plugin' => array(
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
	 * The settings in the order of the settings page, each in a section. Toggles with a group share one row.
	 *
	 * @return array<string,array{label:string,type:string,default:mixed,section:string,description?:string,choices?:array<string,string>,rows?:int,group?:string,option?:string}>
	 */
	public function get_fields(): array
	{
		return array(
			'hidden_roles' => array(
				'label'       => __( 'Roles hidden from the members table', 'mac-members' ),
				'type'        => self::TYPE_ROLES,
				'default'     => array(),
				'section'     => 'table',
				'description' => __( 'Users who hold a checked role never show in the members table, its counts, its search or its role filter, so nobody can change their status there. Roles with administrative capabilities, like Administrator, are always hidden, because the plugin never changes their users. The four member roles cannot be hidden, so they are not listed.', 'mac-members' ),
			),
			'detail_fields' => array(
				'label'       => __( 'Member details fields', 'mac-members' ),
				'type'        => self::TYPE_FIELDS,
				'default'     => '',
				'section'     => 'table',
				'rows'        => 12,
				'description' => __( 'One field per line or comma-separated: the user meta key, a colon and the label, for example phone : Phone. Without a label, the ACF field label shows, or else the key made readable. The member details show these fields after the account fields, in this order. Private keys that start with an underscore, passwords, sessions and capabilities never show.', 'mac-members' ),
			),
			'date_display' => array(
				'label'       => __( 'Dates in the members table', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'relative',
				'section'     => 'table',
				'choices'     => array_combine(
					self::DATE_DISPLAYS,
					array( __( 'Relative', 'mac-members' ), __( 'Date', 'mac-members' ) )
				),
				'description' => __( 'For Registered and Last Login. Relative shows the time since, like 3 days ago. Date shows the day in the site\'s date format, and Last Login the time too. Both show the full date and time on hover.', 'mac-members' ),
			),
			'table_size' => array(
				'label'       => __( 'Members table size', 'mac-members' ),
				'type'        => self::TYPE_CHOICE,
				'default'     => 'medium',
				'section'     => 'table',
				'choices'     => array_combine(
					self::TABLE_SIZES,
					array( __( 'Medium', 'mac-members' ), __( 'Small', 'mac-members' ) )
				),
				'description' => __( 'Medium puts the table, its controls and its buttons in the normal text size, Small in the small one.', 'mac-members' ),
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
			'top_level_menu' => array(
				'label'   => __( 'Show MAC Members as a top-level admin menu item', 'mac-members' ),
				'type'    => self::TYPE_TOGGLE,
				'default' => false,
				'section' => 'plugin',
			),
			'delete_data_on_uninstall' => array(
				'label'       => __( 'Delete plugin data on uninstall', 'mac-members' ),
				'type'        => self::TYPE_TOGGLE,
				'default'     => false,
				'section'     => 'plugin',
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
