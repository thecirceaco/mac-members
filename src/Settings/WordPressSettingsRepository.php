<?php
/**
 * WordPress-backed settings repository.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

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

		$this->settings = $this->normalize(
			\is_array( $stored ) ? $stored : array(),
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

		\update_option( MAC_MEMBERS_SETTINGS_OPTION, $normalized );

		$this->settings = $normalized;

		return $normalized;
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

	private function get_site_admin_email(): string
	{
		$email = \sanitize_email( (string) \get_option( 'admin_email', '' ) );

		return \is_email( $email ) ? $email : '';
	}
}
