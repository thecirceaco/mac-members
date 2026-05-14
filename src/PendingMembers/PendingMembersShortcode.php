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

final class PendingMembersShortcode implements Service
{
	public const SHORTCODE = 'mac_members_pending_table';

	public function __construct(
		private readonly PendingMembersQuery $query,
		private readonly PendingMembersTableRenderer $renderer,
		private readonly FrontendAssets $assets
	) {}

	public function register(): void
	{
		\add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $attributes Shortcode attributes.
	 */
	public function render( array|string $attributes = array(), ?string $content = null, string $tag = '' ): string
	{
		unset( $attributes, $content, $tag );

		if ( ! \is_user_logged_in() || ! \current_user_can( 'promote_users' ) ) {
			return '';
		}

		$this->assets->enqueue_pending_members();

		return $this->renderer->render( $this->query->get_pending_users() );
	}
}
