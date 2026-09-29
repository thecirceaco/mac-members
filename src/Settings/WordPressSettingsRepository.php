<?php
/**
 * WordPress-backed settings repository.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

use MacMembers\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class WordPressSettingsRepository implements SettingsRepositoryInterface
{
	private ?array $settings = null;

	public function __construct(
		private readonly SettingsSchema $schema
	) {}

	public function all(): array
	{
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = \get_option( MAC_MEMBERS_SETTINGS_OPTION, null );
		$stored = \is_array( $stored ) ? $stored : array();

		// Development builds called the hidden roles "role_filter_exclusions".
		if ( ! array_key_exists( 'hidden_roles', $stored ) && array_key_exists( 'role_filter_exclusions', $stored ) ) {
			$stored['hidden_roles'] = $stored['role_filter_exclusions'];
		}

		$this->settings = $this->normalize(
			$stored,
			$this->schema->get_defaults(),
			false
		);

		return $this->settings;
	}

	public function get( string $key, mixed $default = null ): mixed
	{
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public function save( array $settings ): array
	{
		$normalized = $this->normalize( $settings, $this->all(), true );

		if ( array() !== $this->get_role_errors( $normalized ) ) {
			// Roles that break the role rules are never stored; the saved roles stay.
			foreach ( $this->schema->get_role_fields() as $key ) {
				$normalized[ $key ] = $this->all()[ $key ];
			}
		}

		\update_option( MAC_MEMBERS_SETTINGS_OPTION, $normalized );

		$this->settings = $normalized;

		return $normalized;
	}

	public function validate_roles( array $settings ): array
	{
		return $this->get_role_errors( $this->normalize( $settings, $this->all(), true ) );
	}

	public function ensure_defaults(): array
	{
		$stored = \get_option( MAC_MEMBERS_SETTINGS_OPTION, null );

		if ( \is_array( $stored ) ) {
			return $this->all();
		}

		$defaults = $this->schema->get_defaults();

		\update_option( MAC_MEMBERS_SETTINGS_OPTION, $defaults );

		$this->settings = $defaults;

		return $defaults;
	}

	public function get_available_roles(): array
	{
		$wp_roles = \wp_roles();
		$roles    = \is_object( $wp_roles ) && isset( $wp_roles->roles ) && \is_array( $wp_roles->roles )
			? $wp_roles->roles
			: array();

		$available = array();

		foreach ( $roles as $slug => $role ) {
			$slug = \sanitize_key( (string) $slug );

			if ( '' === $slug ) {
				continue;
			}

			$available[ $slug ] = isset( $role['name'] ) ? (string) $role['name'] : $slug;
		}

		\ksort( $available );

		return $available;
	}

	public function get_from_name(): string
	{
		return \sanitize_text_field( \get_bloginfo( 'name' ) );
	}

	public function role_exists( string $role ): bool
	{
		return '' !== $role && \wp_roles()->is_role( $role );
	}

	public function get_missing_role_slugs(): array
	{
		$settings = $this->all();
		$missing  = array();

		foreach ( $this->schema->get_role_fields() as $field_key ) {
			$role = isset( $settings[ $field_key ] ) ? \sanitize_key( (string) $settings[ $field_key ] ) : '';

			if ( '' !== $role && ! $this->role_exists( $role ) ) {
				$missing[] = $role;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	public function has_missing_roles(): bool
	{
		return array() !== $this->get_missing_role_slugs();
	}

	/**
	 * @param array<string,mixed> $settings Normalized settings.
	 *
	 * @return array<string,string> Error messages keyed by error code.
	 */
	private function get_role_errors( array $settings ): array
	{
		$roles  = array();
		$errors = array();

		foreach ( $this->schema->get_role_fields() as $key ) {
			$roles[ $key ] = (string) ( $settings[ $key ] ?? '' );
		}

		if ( count( array_unique( $roles ) ) !== count( $roles ) ) {
			$errors['roles_not_distinct'] = __( 'The pending, approved, inactive and denied roles must be four different roles. The settings were not saved.', 'mac-members' );
		}

		// The pending role comes from the registration form, and approve, reactivate, deactivate and deny add the others.
		$messages = array(
			/* translators: 1: role name, 2: comma-separated capability names. */
			'pending_role'  => __( 'The pending role "%1$s" grants administrative capabilities (%2$s). Choose a role without them. The settings were not saved.', 'mac-members' ),
			/* translators: 1: role name, 2: comma-separated capability names. */
			'approved_role' => __( 'The approved role "%1$s" grants administrative capabilities (%2$s). Choose a role without them. The settings were not saved.', 'mac-members' ),
			/* translators: 1: role name, 2: comma-separated capability names. */
			'inactive_role' => __( 'The inactive role "%1$s" grants administrative capabilities (%2$s). Choose a role without them. The settings were not saved.', 'mac-members' ),
			/* translators: 1: role name, 2: comma-separated capability names. */
			'denied_role'   => __( 'The denied role "%1$s" grants administrative capabilities (%2$s). Choose a role without them. The settings were not saved.', 'mac-members' ),
		);
		$role_names = $this->get_available_roles();

		foreach ( $messages as $key => $message ) {
			$capabilities = Capabilities::sensitive_capabilities_of_role( $roles[ $key ] ?? '' );

			if ( array() !== $capabilities ) {
				$errors[ $key . '_sensitive' ] = sprintf(
					$message,
					$role_names[ $roles[ $key ] ] ?? $roles[ $key ],
					implode( ', ', $capabilities )
				);
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input Input settings.
	 * @param array<string,mixed> $fallback Fallback settings.
	 *
	 * @return array<string,mixed>
	 */
	private function normalize( array $input, array $fallback, bool $for_save ): array
	{
		$fields     = $this->schema->get_fields();
		$normalized = array();

		foreach ( $fields as $key => $field ) {
			$value = $this->resolve_input_value( $key, $input, $fallback, $for_save );

			$normalized[ $key ] = match ( $field['type'] ) {
				SettingsSchema::TYPE_ROLE   => $this->sanitize_role( $value, $fallback[ $key ] ?? $field['default'] ),
				SettingsSchema::TYPE_EMAIL  => $this->sanitize_email( $value ),
				SettingsSchema::TYPE_TOGGLE => $this->sanitize_toggle( $value ),
				SettingsSchema::TYPE_LIST   => $this->sanitize_list( $value, (string) $field['default'] ),
				SettingsSchema::TYPE_CHOICE => $this->sanitize_choice( $value, array_keys( $field['choices'] ?? array() ), (string) $field['default'] ),
				SettingsSchema::TYPE_FIELDS => $this->sanitize_fields( $value, (string) $field['default'] ),
				default                     => $field['default'],
			};
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $input Input settings.
	 * @param array<string,mixed> $fallback Fallback settings.
	 */
	private function resolve_input_value( string $key, array $input, array $fallback, bool $for_save ): mixed
	{
		if ( array_key_exists( $key, $input ) ) {
			return $input[ $key ];
		}

		if ( $for_save && in_array( $key, $this->schema->get_toggle_fields(), true ) ) {
			return false;
		}

		return $fallback[ $key ] ?? null;
	}

	private function sanitize_role( mixed $value, mixed $fallback ): string
	{
		$role      = \sanitize_key( (string) $value );
		$available = $this->get_available_roles();

		if ( '' !== $role && array_key_exists( $role, $available ) ) {
			return $role;
		}

		return \sanitize_key( (string) $fallback );
	}

	private function sanitize_email( mixed $value ): string
	{
		$raw = \is_scalar( $value ) ? (string) $value : '';

		if ( str_contains( $raw, "\r" ) || str_contains( $raw, "\n" ) ) {
			return $this->get_site_admin_email();
		}

		$email = \sanitize_email( $raw );

		return \is_email( $email ) ? $email : $this->get_site_admin_email();
	}

	private function sanitize_toggle( mixed $value ): bool
	{
		if ( \is_bool( $value ) ) {
			return $value;
		}

		$normalized = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return \is_bool( $normalized ) ? $normalized : false;
	}

	/**
	 * The member details fields as "key : label" lines, or "key" alone without a label: keys keep only
	 * letters, digits, underscores and hyphens, labels are cleaned and cut to 100 characters, each key is
	 * listed once and at most 50 fields are kept. A value that is not text falls back to the default.
	 */
	private function sanitize_fields( mixed $value, string $default ): string
	{
		if ( ! \is_scalar( $value ) ) {
			return $default;
		}

		$lines = array();

		foreach ( SettingsSchema::parse_fields( (string) $value ) as $field ) {
			if ( isset( $lines[ $field['key'] ] ) ) {
				continue;
			}

			$label                  = mb_substr( \sanitize_text_field( $field['label'] ), 0, 100 );
			$lines[ $field['key'] ] = '' === $label ? $field['key'] : $field['key'] . ' : ' . $label;
		}

		return implode( "\n", array_slice( array_values( $lines ), 0, 50 ) );
	}

	/**
	 * @param array<int,string> $choices The values the setting allows.
	 *
	 * @return string One of the choices, or the default.
	 */
	private function sanitize_choice( mixed $value, array $choices, string $default ): string
	{
		$value = \is_scalar( $value ) ? \sanitize_key( (string) $value ) : '';

		return in_array( $value, $choices, true ) ? $value : $default;
	}

	/**
	 * A comma-separated list with each entry cleaned, cut to 100 characters and listed once, at most 50
	 * entries. A value that is not text falls back to the default.
	 */
	private function sanitize_list( mixed $value, string $default ): string
	{
		if ( ! \is_scalar( $value ) ) {
			return $default;
		}

		$entries = array();

		foreach ( SettingsSchema::parse_list( (string) $value ) as $entry ) {
			$entry = mb_substr( \sanitize_text_field( $entry ), 0, 100 );

			if ( '' !== $entry && ! in_array( strtolower( $entry ), array_map( 'strtolower', $entries ), true ) ) {
				$entries[] = $entry;
			}
		}

		return implode( ', ', array_slice( $entries, 0, 50 ) );
	}

	private function get_site_admin_email(): string
	{
		$email = \sanitize_email( (string) \get_option( 'admin_email', '' ) );

		return \is_email( $email ) ? $email : '';
	}
}
