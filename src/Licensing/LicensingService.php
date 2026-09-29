<?php
/**
 * SureCart licensing: the license key and the plugin updates.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Licensing;

use MacMembers\Admin\MenuPlacement;
use MacMembers\Contracts\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Connects MAC Members to its SureCart product through the bundled SureCart licensing SDK, the way MAC Core
 * does: the license form sits in the License tab of the settings page, and SureCart delivers the updates.
 */
final class LicensingService implements Service
{
	/**
	 * Capability needed to view and change the license.
	 */
	private const CAPABILITY = 'manage_options';

	private ?\MacMembers\Vendor\SureCart\Licensing\Client $client = null;

	public function __construct(
		private readonly MenuPlacement $placement
	) {}

	public function register(): void
	{
		\add_action( 'init', array( $this, 'initialize' ), 20 );
	}

	/**
	 * Starts the SDK when a public token is set. The SDK's client also starts its updater, which offers
	 * SureCart's releases to WordPress.
	 */
	public function initialize(): void
	{
		$public_token = $this->get_public_token();

		if ( '' === $public_token ) {
			$this->add_admin_notice( __( 'MAC Members licensing is not configured. Define MAC_MEMBERS_SURECART_PUBLIC_TOKEN or provide a token through the mac_members_surecart_public_token filter.', 'mac-members' ) );
			return;
		}

		if ( ! $this->load_sdk() ) {
			$this->add_admin_notice( __( 'MAC Members licensing could not load the bundled SureCart licensing SDK.', 'mac-members' ) );
			return;
		}

		if ( null === $this->client ) {
			$this->client = new \MacMembers\Vendor\SureCart\Licensing\Client( 'MAC Members', $public_token, MAC_MEMBERS_PLUGIN_FILE );
		}

		$this->client->set_textdomain( 'mac-members' );
		$this->client->settings()->add_page(
			array(
				'type'                 => 'menu',
				'page_title'           => __( 'MAC Members License', 'mac-members' ),
				'menu_title'           => 'MAC Members',
				'capability'           => self::CAPABILITY,
				'menu_slug'            => MAC_MEMBERS_ADMIN_SLUG,
				'icon_url'             => '',
				'position'             => null,
				'activated_redirect'   => $this->placement->url( 'license' ),
				'deactivated_redirect' => $this->placement->url( 'license' ),
				// The form sits in the settings page's License tab, so the SDK adds no menu page of its own.
				'register_menu'        => false,
			)
		);
	}

	/**
	 * The License tab. The SDK handles a submitted license form while it renders, so the capability is checked
	 * before the SDK runs.
	 */
	public function render_view(): void
	{
		if ( ! \current_user_can( self::CAPABILITY ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You do not have permission to manage the MAC Members license.', 'mac-members' ) . '</p></div>';
			return;
		}

		if ( null === $this->client ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'MAC Members licensing is not available. Check the public token and the bundled SureCart licensing SDK.', 'mac-members' ) . '</p></div>';
			return;
		}

		$this->client->settings()->settings_output();
	}

	/**
	 * The SureCart product's public token, from MAC_MEMBERS_SURECART_PUBLIC_TOKEN or the
	 * mac_members_surecart_public_token filter. It isn't a secret: every copy of the plugin carries it.
	 */
	private function get_public_token(): string
	{
		$public_token = defined( 'MAC_MEMBERS_SURECART_PUBLIC_TOKEN' ) ? (string) constant( 'MAC_MEMBERS_SURECART_PUBLIC_TOKEN' ) : '';
		$public_token = \apply_filters( 'mac_members_surecart_public_token', $public_token );

		return is_scalar( $public_token ) ? trim( (string) $public_token ) : '';
	}

	/**
	 * Loads the bundled SDK, which the plugin's autoloader doesn't cover. Its client loads the other files.
	 */
	private function load_sdk(): bool
	{
		if ( class_exists( \MacMembers\Vendor\SureCart\Licensing\Client::class ) ) {
			return true;
		}

		$sdk_file = MAC_MEMBERS_PATH . 'inc/Vendor/SureCart/Licensing/Client.php';

		if ( ! is_readable( $sdk_file ) ) {
			return false;
		}

		require_once $sdk_file;

		return class_exists( \MacMembers\Vendor\SureCart\Licensing\Client::class );
	}

	/**
	 * A notice for administrators on every admin page, since licensing is broken until someone fixes it.
	 */
	private function add_admin_notice( string $message ): void
	{
		\add_action(
			'admin_notices',
			static function () use ( $message ): void {
				if ( ! \current_user_can( 'manage_options' ) ) {
					return;
				}

				echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
			}
		);
	}
}
