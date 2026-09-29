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
use MacMembers\Integrations\MacCoreLastLogin;
use MacMembers\Security\Capabilities;
use MacMembers\Security\Roles;
use MacMembers\Settings\SettingsRepositoryInterface;
use MacMembers\Settings\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MembersTableShortcode implements Service
{
	public const SHORTCODE = 'mac_members_table';

	/**
	 * Query arguments that choose the view, the page, the page size, the role and the search.
	 */
	public const STATUS_QUERY_ARG   = 'mac_members_status';
	public const PAGE_QUERY_ARG     = 'mac_members_page';
	public const PER_PAGE_QUERY_ARG = 'mac_members_per_page';
	public const ROLE_QUERY_ARG     = 'mac_members_role';
	public const SEARCH_QUERY_ARG   = 'mac_members_search';

	/**
	 * View that lists every member.
	 */
	public const VIEW_ALL = 'all';

	/**
	 * Cookie the script writes with the columns the viewer hid, comma-separated.
	 */
	public const COLUMNS_COOKIE = 'mac_members_hidden_columns';

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
		private readonly RenderToken $render_token = new RenderToken(),
		private readonly MacCoreLastLogin $last_login = new MacCoreLastLogin(),
		private readonly ?MemberDetails $details = null
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
		$per_page   = $this->parse_per_page( $this->get_query_string( self::PER_PAGE_QUERY_ARG ) );
		$roles      = $this->query->get_filter_roles();
		$role       = $this->parse_role( $this->get_query_string( self::ROLE_QUERY_ARG ), $roles );
		$search     = $this->parse_search( $this->get_query_string( self::SEARCH_QUERY_ARG ) );
		$members    = $this->query->get_members( $status, $page, $role, $search, $per_page );
		$last_page  = max( 1, (int) ceil( $members['total'] / $per_page ) );

		// A page past the end, for example after a larger page size or a search, shows the last page instead.
		if ( $page > $last_page ) {
			$page    = $last_page;
			$members = $this->query->get_members( $status, $page, $role, $search, $per_page );
		}

		$show_last_login = $this->last_login->is_enabled();
		$user_ids        = $this->get_user_ids( $members['users'] );

		// One query loads the user meta of every member on the page, instead of one query per member.
		if ( array() !== $user_ids ) {
			\update_meta_cache( 'user', $user_ids );
		}

		$base_url = \remove_query_arg( array( self::STATUS_QUERY_ARG, self::PAGE_QUERY_ARG, self::PER_PAGE_QUERY_ARG, self::ROLE_QUERY_ARG, self::SEARCH_QUERY_ARG ) );
		$kept     = $this->get_kept_args( $role, $search, $per_page );

		return $this->renderer->render(
			$this->get_rows( $members['users'], $show_last_login ),
			$view,
			null === $fixed_view ? $this->get_filters( $view, $base_url, $role, $search, $kept ) : array(),
			$this->get_pagination( $view, $page, $per_page, $members['total'], $base_url, $kept ),
			Roles::missing(),
			$this->render_token->issue( $user_ids ),
			$this->get_search_form( null === $fixed_view ? $view : null, $base_url, $roles, $role, $search ),
			array(
				'hidden_columns' => $this->get_hidden_columns(),
				'size'           => $this->get_choice( 'table_size', SettingsSchema::TABLE_SIZES ),
				'dates'          => $this->get_choice( 'date_display', SettingsSchema::DATE_DISPLAYS ),
				'last_login'     => $show_last_login,
			)
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
	 * @return int One of the page sizes the table offers, or the default.
	 */
	private function parse_per_page( string $value ): int
	{
		$per_page = \absint( $value );

		return in_array( $per_page, MembersQuery::PER_PAGE_OPTIONS, true ) ? $per_page : MembersQuery::PER_PAGE;
	}

	/**
	 * The page size, role and search query arguments that differ from the defaults, for links that keep
	 * them. They are encoded here because add_query_arg() adds new values as they are.
	 *
	 * @return array<string,string>
	 */
	private function get_kept_args( string $role, string $search, int $per_page ): array
	{
		$args = array_filter(
			array(
				self::PER_PAGE_QUERY_ARG => MembersQuery::PER_PAGE === $per_page ? '' : (string) $per_page,
				self::ROLE_QUERY_ARG     => $role,
				self::SEARCH_QUERY_ARG   => $search,
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
	 * @param array<int,object> $users           Members on this page.
	 * @param bool              $show_last_login Whether the table shows MAC Core's last login.
	 *
	 * @return array<int,array{user:object,status:?MemberStatus,roles:array<string,string>,last_login:int|null,details:array<int,array{label:string,value:string}>}>
	 */
	private function get_rows( array $users, bool $show_last_login ): array
	{
		$rows    = array();
		$details = $this->details ?? new MemberDetails( $this->settings );

		foreach ( $users as $user ) {
			$user_id = isset( $user->ID ) ? \absint( $user->ID ) : 0;
			$rows[]  = array(
				'user'       => $user,
				'status'     => $this->query->get_status( $user ),
				'roles'      => $this->query->get_other_roles( $user ),
				'last_login' => $show_last_login && 0 < $user_id ? $this->last_login->get( $user_id ) : null,
				'details'    => $details->get_values( $user ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,string> $choices The values the setting allows; the first is its default.
	 *
	 * @return string The setting's value, or its default.
	 */
	private function get_choice( string $key, array $choices ): string
	{
		$value = (string) $this->settings->get( $key, $choices[0] );

		return in_array( $value, $choices, true ) ? $value : $choices[0];
	}

	/**
	 * The hidden columns: the ones that start hidden, changed by the cookie the script writes. The cookie lists
	 * the columns the viewer hid, and a column that starts hidden with a + when the viewer showed it.
	 *
	 * @return array<int,string> Keys of the hidden columns.
	 */
	private function get_hidden_columns(): array
	{
		$hidden = MembersTableRenderer::DEFAULT_HIDDEN_COLUMNS;

		if ( isset( $_COOKIE[ self::COLUMNS_COOKIE ] ) && \is_string( $_COOKIE[ self::COLUMNS_COOKIE ] ) ) {
			foreach ( explode( ',', \sanitize_text_field( \wp_unslash( $_COOKIE[ self::COLUMNS_COOKIE ] ) ) ) as $token ) {
				$token = trim( $token );
				$key   = \sanitize_key( ltrim( $token, '+' ) );

				if ( str_starts_with( $token, '+' ) ) {
					$hidden = array_diff( $hidden, array( $key ) );
				} else {
					$hidden[] = $key;
				}
			}
		}

		return array_values( array_intersect( MembersTableRenderer::COLUMN_KEYS, $hidden ) );
	}

	/**
	 * Status filters whose counts follow the role and the search, and whose links keep them and the page size.
	 *
	 * @param array<string,string> $kept The page size, role and search query arguments that are set.
	 *
	 * @return array<int,array{view:string,label:string,count:int,url:string,current:bool}>
	 */
	private function get_filters( string $view, string $base_url, string $role, string $search, array $kept ): array
	{
		$counts  = $this->query->count_by_status( $role, $search );
		$filters = array();

		foreach ( MemberStatus::cases() as $status ) {
			$filters[] = array(
				'view'    => $status->value,
				'label'   => $status->label(),
				'count'   => $counts[ $status->value ] ?? 0,
				'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => $status->value ) + $kept, $base_url ),
				'current' => $status->value === $view,
			);
		}

		$filters[] = array(
			'view'    => self::VIEW_ALL,
			'label'   => __( 'All', 'mac-members' ),
			'count'   => array_sum( $counts ),
			'url'     => \add_query_arg( array( self::STATUS_QUERY_ARG => self::VIEW_ALL ) + $kept, $base_url ),
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
	 * @return array{action:string,hidden:array<string,string>,roles:array<string,string>,role:string,search:string}
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
			'action' => $parts[0],
			'hidden' => $hidden,
			'roles'  => $roles,
			'role'   => $role,
			'search' => $search,
		);
	}

	/**
	 * The page links, the members this page shows (first, last and total) and the page size.
	 *
	 * @param array<string,string> $kept The page size, role and search query arguments that are set.
	 *
	 * @return array{page:int,pages:int,per_page:int,total:int,first:int,last:int,links:array<int,array{page:int,url:string,current:bool}|null>,previous_url:string,next_url:string}
	 */
	private function get_pagination( string $view, int $page, int $per_page, int $total, string $base_url, array $kept ): array
	{
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$links = array();

		foreach ( $this->get_page_numbers( $page, $pages ) as $number ) {
			$links[] = null === $number ? null : array(
				'page'    => $number,
				'url'     => $this->get_page_url( $view, $number, $base_url, $kept ),
				'current' => $number === $page,
			);
		}

		return array(
			'page'         => $page,
			'pages'        => $pages,
			'per_page'     => $per_page,
			'total'        => $total,
			'first'        => 0 < $total ? ( $page - 1 ) * $per_page + 1 : 0,
			'last'         => min( $total, $page * $per_page ),
			'links'        => $links,
			'previous_url' => 1 < $page ? $this->get_page_url( $view, $page - 1, $base_url, $kept ) : '',
			'next_url'     => $page < $pages ? $this->get_page_url( $view, $page + 1, $base_url, $kept ) : '',
		);
	}

	/**
	 * The pages the pagination links to: the first and last page, the current page and one page on each side
	 * of it. Null marks a gap; a gap of a single page shows that page instead.
	 *
	 * @return array<int,int|null>
	 */
	private function get_page_numbers( int $page, int $pages ): array
	{
		$shown = array_filter(
			array_unique( array( 1, $page - 1, $page, $page + 1, $pages ) ),
			static fn ( int $number ): bool => 1 <= $number && $number <= $pages
		);

		sort( $shown );

		$numbers  = array();
		$previous = 0;

		foreach ( $shown as $number ) {
			if ( 2 === $number - $previous ) {
				$numbers[] = $number - 1;
			} elseif ( 2 < $number - $previous ) {
				$numbers[] = null;
			}

			$numbers[] = $number;
			$previous  = $number;
		}

		return $numbers;
	}

	/**
	 * @param array<string,string> $kept The page size, role and search query arguments that are set.
	 */
	private function get_page_url( string $view, int $page, string $base_url, array $kept ): string
	{
		$args = array( self::STATUS_QUERY_ARG => $view ) + $kept;

		if ( 1 < $page ) {
			$args[ self::PAGE_QUERY_ARG ] = $page;
		}

		return \add_query_arg( $args, $base_url );
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
