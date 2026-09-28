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
	 * Query arguments that choose the view, the page, the role and the search.
	 */
	public const STATUS_QUERY_ARG = 'mac_members_status';
	public const PAGE_QUERY_ARG   = 'mac_members_page';
	public const ROLE_QUERY_ARG   = 'mac_members_role';
	public const SEARCH_QUERY_ARG = 'mac_members_search';

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
	 * shows only that view, without the status filters. The role filter and the search narrow any view.
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
		$roles      = $this->query->get_filter_roles();
		$role       = $this->parse_role( $this->get_query_string( self::ROLE_QUERY_ARG ), $roles );
		$search     = $this->parse_search( $this->get_query_string( self::SEARCH_QUERY_ARG ) );
		$members    = $this->query->get_members( $status, $page, $role, $search );
		$base_url   = \remove_query_arg( array( self::STATUS_QUERY_ARG, self::PAGE_QUERY_ARG, self::ROLE_QUERY_ARG, self::SEARCH_QUERY_ARG ) );
		$narrowing  = $this->get_narrowing_args( $role, $search );

		return $this->renderer->render(
			$this->get_rows( $members['users'] ),
			$view,
			null === $fixed_view ? $this->get_filters( $view, $base_url, $role, $search ) : array(),
			$this->get_pagination( $view, $page, $members['total'], $base_url, $narrowing ),
			$this->settings->get_missing_role_slugs(),
			$this->render_token->issue( $this->get_user_ids( $members['users'] ) ),
			$this->get_search_form( null === $fixed_view ? $view : null, $base_url, $roles, $role, $search )
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

	/**
	 * @param array<string,string> $roles Roles the role filter offers, keyed by slug.
	 *
	 * @return string An offered role, or '' for any role.
	 */
	private function parse_role( string $value, array $roles ): string
	{
		return array_key_exists( $value, $roles ) ? $value : '';
	}

	private function parse_search( string $value ): string
	{
		return trim( mb_substr( trim( $value ), 0, MembersQuery::SEARCH_MAX_LENGTH ) );
	}

	/**
	 * The role and search query arguments that are set, for links that keep them. They are encoded here
	 * because add_query_arg() adds new values as they are.
	 *
	 * @return array<string,string>
	 */
	private function get_narrowing_args( string $role, string $search ): array
	{
		$args = array_filter(
			array(
				self::ROLE_QUERY_ARG   => $role,
				self::SEARCH_QUERY_ARG => $search,
			),
			static fn ( string $value ): bool => '' !== $value
		);

		return array_map( 'urlencode', $args );
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
	 * Status filters whose counts and links follow the role and the search.
	 *
	 * @return array<int,array{view:string,label:string,count:int,url:string,current:bool}>
	 */
	private function get_filters( string $view, string $base_url, string $role, string $search ): array
	{
		$counts    = $this->query->count_by_status( $role, $search );
		$narrowing = $this->get_narrowing_args( $role, $search );
		$filters   = array();

		foreach ( MemberStatus::cases() as $status ) {
			$filters[] = array(
				'view'    => $status->value,
				'label'   => $status->label(),
				'count'   => $counts[ $status->value ] ?? 0,
				'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => $status->value ) + $narrowing, $base_url ),
				'current' => $status->value === $view,
			);
		}

		$filters[] = array(
			'view'    => self::VIEW_ALL,
			'label'   => __( 'All', 'mac-members' ),
			'count'   => array_sum( $counts ),
			'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => self::VIEW_ALL ) + $narrowing, $base_url ),
			'current' => self::VIEW_ALL === $view,
		);

		return $filters;
	}

	/**
	 * The role and search form. It sends the page's other query arguments and the current view as hidden
	 * fields, because a GET form replaces the query string of its action.
	 *
	 * @param string|null          $view  The current view, or null when the shortcode fixes it.
	 * @param array<string,string> $roles Roles the role filter offers, keyed by slug.
	 *
	 * @return array{action:string,hidden:array<string,string>,roles:array<string,string>,role:string,search:string,clear_url:string}
	 */
	private function get_search_form( ?string $view, string $base_url, array $roles, string $role, string $search ): array
	{
		$parts  = explode( '?', $base_url, 2 );
		$query  = array();
		$hidden = array();

		parse_str( $parts[1] ?? '', $query );

		foreach ( $query as $name => $value ) {
			if ( \is_string( $value ) ) {
				$hidden[ (string) $name ] = $value;
			}
		}

		if ( null !== $view ) {
			$hidden[ self::STATUS_QUERY_ARG ] = $view;
		}

		return array(
			'action'    => $parts[0],
			'hidden'    => $hidden,
			'roles'     => $roles,
			'role'      => $role,
			'search'    => $search,
			'clear_url' => null === $view ? $base_url : \add_query_arg( array( self::STATUS_QUERY_ARG => $view ), $base_url ),
		);
	}

	/**
	 * @param array<string,string> $narrowing The role and search query arguments that are set.
	 *
	 * @return array{page:int,pages:int,previous_url:string,next_url:string}
	 */
	private function get_pagination( string $view, int $page, int $total, string $base_url, array $narrowing ): array
	{
		$pages = max( 1, (int) ceil( $total / MembersQuery::PER_PAGE ) );

		return array(
			'page'         => $page,
			'pages'        => $pages,
			'previous_url' => 1 < $page ? $this->get_page_url( $view, $page - 1, $base_url, $narrowing ) : '',
			'next_url'     => $page < $pages ? $this->get_page_url( $view, $page + 1, $base_url, $narrowing ) : '',
		);
	}

	/**
	 * @param array<string,string> $narrowing The role and search query arguments that are set.
	 */
	private function get_page_url( string $view, int $page, string $base_url, array $narrowing ): string
	{
		return \add_query_arg(
			array( self::STATUS_QUERY_ARG => $view ) + $narrowing + array( self::PAGE_QUERY_ARG => $page ),
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
