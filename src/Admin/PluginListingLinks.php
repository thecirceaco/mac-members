<?php
/**
 * Links in the MAC Members row of the Plugins screen, like MAC Core's.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Admin;

use MacMembers\Contracts\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class PluginListingLinks implements Service
{
	public function __construct(
		private readonly MenuPlacement $placement
	) {}

	public function register(): void
	{
		\add_filter( 'plugin_action_links_' . $this->plugin_basename(), array( $this, 'action_links' ) );
		\add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Puts Settings and License before the row's own links, such as Deactivate.
	 *
	 * @param array<int|string,string> $links The row's action links.
	 *
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array
	{
		return array_merge(
			array(
				'<a href="' . esc_url( $this->placement->url() ) . '">' . esc_html__( 'Settings', 'mac-members' ) . '</a>',
				'<a href="' . esc_url( $this->placement->url( 'license' ) ) . '">' . esc_html__( 'License', 'mac-members' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * Adds Support and Documentation after the version, the author and View details.
	 *
	 * @param array<int|string,string> $links       The row's meta links.
	 * @param string                   $plugin_file The row's plugin file, relative to the plugins folder.
	 *
	 * @return array<int|string,string>
	 */
	public function row_meta( array $links, string $plugin_file ): array
	{
		if ( $plugin_file !== $this->plugin_basename() ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( $this->placement->url( 'support' ) ) . '">' . esc_html__( 'Support', 'mac-members' ) . '</a>';
		$links[] = '<a href="' . esc_url( MAC_MEMBERS_DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'mac-members' ) . '</a>';

		return $links;
	}

	private function plugin_basename(): string
	{
		return \plugin_basename( MAC_MEMBERS_PLUGIN_FILE );
	}
}
