<?php
/**
 * Settings repository contract.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

interface SettingsRepositoryInterface
{
	/**
	 * @return array<string,mixed>
	 */
	public function all(): array;

	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * @param array<string,mixed> $settings Submitted settings.
	 *
	 * @return array<string,mixed>
	 */
	public function save( array $settings ): array;

	/**
	 * Checks the roles that saving these settings would store: the pending, approved and denied roles must
	 * be three different roles, and the approved and denied roles must not grant sensitive capabilities.
	 *
	 * @param array<string,mixed> $settings Submitted settings.
	 *
	 * @return array<string,string> Error messages keyed by error code, empty when the roles are allowed.
	 */
	public function validate_roles( array $settings ): array;

	/**
	 * @return array<string,mixed>
	 */
	public function ensure_defaults(): array;

	/**
	 * @return array<string,string>
	 */
	public function get_available_roles(): array;

	public function get_from_name(): string;

	/**
	 * Whether the role is registered on the site.
	 */
	public function role_exists( string $role ): bool;

	/**
	 * @return array<int,string>
	 */
	public function get_missing_role_slugs(): array;

	public function has_missing_roles(): bool;
}
