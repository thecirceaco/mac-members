<?php
/**
 * Minimal SureCart client test double, in the namespace of the bundled SDK.
 *
 * @package MacMembers\Tests
 */

declare(strict_types=1);

namespace MacMembers\Vendor\SureCart\Licensing;

final class Client {
	/** @var array<int,array{name:string,public_token:string,file:string}> */
	public static array $instances = array();

	/** @var array<int,string> */
	public static array $textdomains = array();

	/** @var array<int,array<string,mixed>> */
	public static array $pages = array();

	public static int $settings_output_calls = 0;

	private ?Settings $settings = null;

	public function __construct(
		public string $name,
		public string $public_token,
		public string $file
	) {
		self::$instances[] = array(
			'name'         => $name,
			'public_token' => $public_token,
			'file'         => $file,
		);
	}

	public static function reset(): void {
		self::$instances             = array();
		self::$textdomains           = array();
		self::$pages                 = array();
		self::$settings_output_calls = 0;
	}

	public function set_textdomain( string $textdomain ): void {
		self::$textdomains[] = $textdomain;
	}

	public function settings(): Settings {
		$this->settings ??= new Settings();

		return $this->settings;
	}
}

final class Settings {
	/**
	 * @param array<string,mixed> $args Settings page arguments.
	 */
	public function add_page( array $args ): void {
		Client::$pages[] = $args;
	}

	public function settings_output(): void {
		++Client::$settings_output_calls;
		echo '<div class="surecart-license-view">SureCart License</div>';
	}
}
