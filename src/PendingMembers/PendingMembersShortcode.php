<?php
/**
 * Pending members shortcode service.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\PendingMembers;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\Security\Capabilities;
use MacMembers\Settings\SettingsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class PendingMembersShortcode implements Service
{
	public const SHORTCODE = 'mac_members_pending_table';

	/**
	 * no-store keeps the table, its nonce and its render token out of browser, proxy and page caches.
	 */
	private const CACHE_CONTROL = 'no-cache, must-revalidate, max-age=0, no-store, private';

	private const FRAME_ANCESTORS = "frame-ancestors 'self'";

	private bool $response_protected = false;

	public function __construct(
		private readonly PendingMembersQuery $query,
		private readonly PendingMembersTableRenderer $renderer,
		private readonly FrontendAssets $assets,
		private readonly SettingsRepositoryInterface $settings,
		private readonly RenderToken $render_token = new RenderToken()
	) {}

	public function register(): void
	{
		\add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		\add_action( 'template_redirect', array( $this, 'protect_table_page' ) );
	}

	/**
	 * @param array<string,mixed>|string $attributes Shortcode attributes.
	 */
	public function render( array|string $attributes = array(), ?string $content = null, string $tag = '' ): string
	{
		unset( $attributes, $content, $tag );

		if ( ! Capabilities::current_user_can_review() ) {
			return '';
		}

		// For layouts that protect_table_page() cannot detect: works as long as output has not started.
		$this->protect_response();
		$this->assets->enqueue_pending_members();

		$users = $this->query->get_pending_users();

		return $this->renderer->render(
			$users,
			$this->settings->get_missing_role_slugs(),
			$this->render_token->issue( $this->get_user_ids( $users ) )
		);
	}

	/**
	 * Runs on template_redirect, before any output, for pages whose content has the shortcode.
	 */
	public function protect_table_page(): void
	{
		if ( $this->is_table_page() ) {
			$this->protect_response();
		}
	}

	private function is_table_page(): bool
	{
		$post      = \get_queried_object();
		$has_table = \is_singular() && $post instanceof \WP_Post && \has_shortcode( $post->post_content, self::SHORTCODE );

		/**
		 * Filters whether the current request renders the pending members table.
		 *
		 * Return true when the shortcode is placed outside the post content, for example in a page builder
		 * layout or a template, so the page still gets its no-cache and framing headers before output starts.
		 *
		 * @param bool $has_table Whether the queried post's content contains the shortcode.
		 */
		return (bool) \apply_filters( 'mac_members_is_pending_table_page', $has_table );
	}

	/**
	 * Keeps the page out of page caches and out of frames on other sites.
	 */
	private function protect_response(): void
	{
		if ( ! \defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant that page cache plugins read.
			\define( 'DONOTCACHEPAGE', true );
		}

		if ( $this->response_protected || \headers_sent() ) {
			return;
		}

		$this->response_protected = true;

		\nocache_headers();
		\header( 'Cache-Control: ' . self::CACHE_CONTROL );
		// Sent next to any policy the site already has; browsers apply each policy they receive.
		\header( 'Content-Security-Policy: ' . self::FRAME_ANCESTORS, false );
	}

	/**
	 * @param array<int,object> $users Pending users.
	 *
	 * @return array<int,int>
	 */
	private function get_user_ids( array $users ): array
	{
		$user_ids = array();

		foreach ( $users as $user ) {
			$user_id = isset( $user->ID ) ? \absint( $user->ID ) : 0;

			if ( 0 < $user_id ) {
				$user_ids[] = $user_id;
			}
		}

		return $user_ids;
	}
}
