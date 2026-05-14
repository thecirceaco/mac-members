<?php
/**
 * Settings repository contract.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

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
	 * @return array<string,mixed>
	 */
	public function ensure_defaults(): array;

	/**
	 * @return array<string,string>
	 */
	public function get_available_roles(): array;

	public function get_from_name(): string;

	/**
	 * @return array<int,string>
	 */
	public function get_missing_role_slugs(): array;

	public function has_missing_roles(): bool;
}
