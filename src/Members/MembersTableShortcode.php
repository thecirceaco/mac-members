<?php
/**
 * Members table shortcode service.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\Security\Capabilities;
use MacMembers\Settings\SettingsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MembersTableShortcode implements Service
{
	public const SHORTCODE = 'mac_members_table';

	/**
	 * Query arguments that choose the view and the page.
	 */
	public const STATUS_QUERY_ARG = 'mac_members_status';
	public const PAGE_QUERY_ARG   = 'mac_members_page';

	/**
	 * View that lists every member.
	 */
	public const VIEW_ALL = 'all';

	/**
	 * no-store keeps the table, its nonce and its render token out of browser, proxy and page caches.
	 */
	private const CACHE_CONTROL = 'no-cache, must-revalidate, max-age=0, no-store, private';

	private const FRAME_ANCESTORS = "frame-ancestors 'self'";

	private bool $response_protected = false;

	public function __construct(
		private readonly MembersQuery $query,
		private readonly MembersTableRenderer $renderer,
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
	 * Renders the table. With a `status` attribute (pending, approved, inactive, denied or all) the table
	 * shows only that view, without the status filters.
	 *
	 * @param array<string,mixed>|string $attributes Shortcode attributes.
	 */
	public function render( array|string $attributes = array(), ?string $content = null, string $tag = '' ): string
	{
		unset( $content, $tag );

		if ( ! Capabilities::current_user_can_review() ) {
			return '';
		}

		// For layouts that protect_table_page() cannot detect: works as long as output has not started.
		$this->protect_response();
		$this->assets->enqueue_members_table();

		$attributes = \shortcode_atts( array( 'status' => '' ), \is_array( $attributes ) ? $attributes : array(), self::SHORTCODE );
		$fixed_view = $this->parse_view( (string) $attributes['status'] );
		$view       = $fixed_view ?? $this->parse_view( $this->get_query_string( self::STATUS_QUERY_ARG ) ) ?? MemberStatus::Pending->value;
		$status     = self::VIEW_ALL === $view ? null : MemberStatus::from( $view );
		$page       = max( 1, \absint( $this->get_query_string( self::PAGE_QUERY_ARG ) ) );
		$members    = $this->query->get_members( $status, $page );
		$base_url   = \remove_query_arg( array( self::STATUS_QUERY_ARG, self::PAGE_QUERY_ARG ) );

		return $this->renderer->render(
			$this->get_rows( $members['users'] ),
			$view,
			null === $fixed_view ? $this->get_filters( $view, $base_url ) : array(),
			$this->get_pagination( $view, $page, $members['total'], $base_url ),
			$this->settings->get_missing_role_slugs(),
			$this->render_token->issue( $this->get_user_ids( $members['users'] ) )
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

	/**
	 * @return string|null A valid view value, or null.
	 */
	private function parse_view( string $value ): ?string
	{
		$value = \sanitize_key( $value );

		if ( self::VIEW_ALL === $value || null !== MemberStatus::tryFrom( $value ) ) {
			return $value;
		}

		return null;
	}

	private function get_query_string( string $key ): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which view and page to show does not change state.
		if ( ! isset( $_GET[ $key ] ) || ! \is_string( $_GET[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which view and page to show does not change state.
		return \sanitize_text_field( \wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * @param array<int,object> $users Members on this page.
	 *
	 * @return array<int,array{user:object,status:?MemberStatus}>
	 */
	private function get_rows( array $users ): array
	{
		$rows = array();

		foreach ( $users as $user ) {
			$rows[] = array(
				'user'   => $user,
				'status' => $this->query->get_status( $user ),
			);
		}

		return $rows;
	}

	/**
	 * @return array<int,array{view:string,label:string,count:int,url:string,current:bool}>
	 */
	private function get_filters( string $view, string $base_url ): array
	{
		$counts  = $this->query->count_by_status();
		$filters = array();

		foreach ( MemberStatus::cases() as $status ) {
			$filters[] = array(
				'view'    => $status->value,
				'label'   => $status->label(),
				'count'   => $counts[ $status->value ] ?? 0,
				'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => $status->value ), $base_url ),
				'current' => $status->value === $view,
			);
		}

		$filters[] = array(
			'view'    => self::VIEW_ALL,
			'label'   => __( 'All', 'mac-members' ),
			'count'   => array_sum( $counts ),
			'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => self::VIEW_ALL ), $base_url ),
			'current' => self::VIEW_ALL === $view,
		);

		return $filters;
	}

	/**
	 * @return array{page:int,pages:int,previous_url:string,next_url:string}
	 */
	private function get_pagination( string $view, int $page, int $total, string $base_url ): array
	{
		$pages = max( 1, (int) ceil( $total / MembersQuery::PER_PAGE ) );

		return array(
			'page'         => $page,
			'pages'        => $pages,
			'previous_url' => 1 < $page ? $this->get_page_url( $view, $page - 1, $base_url ) : '',
			'next_url'     => $page < $pages ? $this->get_page_url( $view, $page + 1, $base_url ) : '',
		);
	}

	private function get_page_url( string $view, int $page, string $base_url ): string
	{
		return \add_query_arg(
			array(
				self::STATUS_QUERY_ARG => $view,
				self::PAGE_QUERY_ARG   => $page,
			),
			$base_url
		);
	}

	private function is_table_page(): bool
	{
		$post      = \get_queried_object();
		$has_table = \is_singular() && $post instanceof \WP_Post && \has_shortcode( $post->post_content, self::SHORTCODE );

		/**
		 * Filters whether the current request renders the members table.
		 *
		 * Return true when the shortcode is placed outside the post content, for example in a page builder
		 * layout or a template, so the page still gets its no-cache and framing headers before output starts.
		 *
		 * @param bool $has_table Whether the queried post's content contains the shortcode.
		 */
		return (bool) \apply_filters( 'mac_members_is_table_page', $has_table );
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
	 * @param array<int,object> $users Members on this page.
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
