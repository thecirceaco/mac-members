<?php
/**
 * Tests for the members table shortcode.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Integrations\MacCoreLastLogin;
use MacMembers\Members\MembersQuery;
use MacMembers\Members\MembersTableRenderer;
use MacMembers\Members\MembersTableShortcode;
use MacMembers\Members\RenderToken;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;

#[CoversClass( MembersTableShortcode::class )]
#[CoversClass( MembersTableRenderer::class )]
final class MembersTableShortcodeTest extends TestCase {
	/**
	 * The columns without Last Login, which shows only while MAC Core records last logins.
	 */
	private const SHOWN_COLUMNS = array( 'user_id', 'email', 'first_name', 'last_name', 'username', 'registered', 'profile', 'status', 'roles', 'actions' );

	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_the_table_shortcode_and_page_protection(): void {
		$shortcode = $this->create_shortcode();

		$shortcode->register();

		self::assertSame( 'mac_members_table', MembersTableShortcode::SHORTCODE );
		self::assertSame(
			array( $shortcode, 'render' ),
			$GLOBALS['mac_members_test_shortcodes'][ MembersTableShortcode::SHORTCODE ]
		);
		self::assertSame(
			array( $shortcode, 'protect_table_page' ),
			$GLOBALS['mac_members_test_actions']['template_redirect'][0]['callback']
		);
	}

	#[DataProvider( 'provide_users_who_cannot_review' )]
	public function test_render_returns_empty_for_users_who_cannot_review( string $case ): void {
		match ( $case ) {
			'logged out'       => $GLOBALS['mac_members_test_logged_in'] = false,
			'no review cap'    => $GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false,
			'no promote_users' => $GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false,
		};

		self::assertSame( '', $this->create_shortcode()->render() );
		self::assertSame( array(), $GLOBALS['mac_members_test_enqueued_scripts'] );
		self::assertSame( 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_users_who_cannot_review(): array {
		return array(
			'logged out'       => array( 'logged out' ),
			'no review cap'    => array( 'no review cap' ),
			'no promote_users' => array( 'no promote_users' ),
		);
	}

	public function test_render_adds_a_render_token_that_allows_only_the_rendered_users(): void {
		$this->store_members(
			array(
				123 => 'mac_members_pending',
				124 => 'mac_members_pending',
				125 => 'mac_members_approved',
			)
		);

		$token  = $this->get_render_token( $this->create_shortcode()->render() );
		$tokens = new RenderToken();

		self::assertTrue( $tokens->allows( $token, 123 ) );
		self::assertTrue( $tokens->allows( $token, 124 ) );
		// Approved members are not in the pending view, so this table cannot act on them.
		self::assertFalse( $tokens->allows( $token, 125 ) );
	}

	public function test_each_render_gets_its_own_token(): void {
		$shortcode = $this->create_shortcode();

		self::assertNotSame(
			$this->get_render_token( $shortcode->render() ),
			$this->get_render_token( $shortcode->render() )
		);
	}

	public function test_default_view_is_pending_with_status_filters_and_counts(): void {
		$_SERVER['REQUEST_URI'] = '/members/?tab=x&mac_members_page=3';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				2 => 'mac_members_pending',
				3 => 'mac_members_approved',
				4 => 'mac_members_denied',
			)
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-view="pending"', $output );
		self::assertStringContainsString( 'aria-label="Member status"', $output );
		self::assertStringContainsString( '<a class="mac-members-filter btn--neutral is-current btn--s" href="/members/?tab=x&amp;mac_members_status=pending" aria-current="page">Pending <span class="mac-members-count" data-mac-members-count-for="pending">2</span></a>', $output );
		self::assertStringContainsString( 'href="/members/?tab=x&amp;mac_members_status=approved">Approved <span class="mac-members-count" data-mac-members-count-for="approved">1</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="inactive">0</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="denied">1</span>', $output );
		self::assertStringContainsString( 'href="/members/?tab=x&amp;mac_members_status=all">All <span class="mac-members-count" data-mac-members-count-for="all">4</span>', $output );
		self::assertSame( 2, substr_count( $output, 'data-mac-members-status="pending"' ) );
		self::assertStringNotContainsString( 'data-mac-members-user-id="3"', $output );
	}

	public function test_status_query_argument_selects_the_view_and_its_actions(): void {
		$_GET['mac_members_status'] = 'approved';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				3 => 'mac_members_approved',
			)
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-view="approved"', $output );
		self::assertStringContainsString( 'aria-current="page">Approved', $output );
		self::assertStringContainsString( '<tr class="mac-members-list__row" data-mac-members-user-id="3" data-mac-members-status="approved">', $output );
		self::assertStringContainsString( 'mac-members-status__label--approved">Approved</span>', $output );
		self::assertStringContainsString( 'data-mac-members-action="deactivate" data-mac-members-user-id="3">Deactivate</button>', $output );
		self::assertStringNotContainsString( 'data-mac-members-action="approve"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="1"', $output );
	}

	public function test_all_view_shows_every_member_with_its_status_and_actions(): void {
		$_GET['mac_members_status'] = 'all';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				2 => 'mac_members_approved',
				3 => 'mac_members_inactive',
				4 => 'mac_members_denied',
				5 => 'subscriber',
			)
		);

		$output = $this->create_shortcode()->render();
		$token  = $this->get_render_token( $output );

		self::assertStringContainsString( 'data-mac-members-view="all"', $output );
		self::assertMatchesRegularExpression( '/data-mac-members-user-id="1" data-mac-members-status="pending">.*data-mac-members-action="approve".*data-mac-members-action="deny"/s', $output );
		self::assertStringContainsString( 'class="mac-members-button mac-members-button--deactivate btn--danger btn--outline btn--s" data-mac-members-action="deactivate" data-mac-members-user-id="2"', $output );
		self::assertStringContainsString( 'class="mac-members-button mac-members-button--reactivate btn--success btn--outline btn--s" data-mac-members-action="reactivate" data-mac-members-user-id="3"', $output );
		self::assertStringContainsString( 'data-mac-members-action="approve" data-mac-members-user-id="4"', $output );
		self::assertStringNotContainsString( 'data-mac-members-action="deny" data-mac-members-user-id="4"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="5"', $output );

		foreach ( array( 1, 2, 3, 4 ) as $user_id ) {
			self::assertTrue( ( new RenderToken() )->allows( $token, $user_id ), (string) $user_id );
		}
	}

	public function test_columns_start_with_the_user_id_and_end_with_status_and_actions(): void {
		$this->store_members( array( 7 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertSame( 10, preg_match_all( '/<th scope="col" data-mac-members-column="([^"]+)">([^<]+)<\/th>/', $output, $headers ) );
		self::assertSame(
			array( 'User ID', 'Email', 'First Name', 'Last Name', 'Username', 'Registered', 'Profile', 'Status', 'Roles', 'Actions' ),
			$headers[2]
		);
		self::assertSame( self::SHOWN_COLUMNS, $headers[1] );
		self::assertStringContainsString( '<tr class="mac-members-list__row" data-mac-members-user-id="7" data-mac-members-status="pending"><td data-mac-members-column="user_id">7</td><td data-mac-members-column="email">member7@example.test</td>', $output );
	}

	public function test_roles_column_lists_the_other_roles_of_each_member(): void {
		$this->add_union_roles();
		$this->store_people(
			array(
				1 => array( 'roles' => array( 'mac_members_approved', 'officer', 'trustee' ) ),
				2 => array( 'roles' => array( 'mac_members_approved' ) ),
			)
		);
		$_GET['mac_members_status'] = 'approved';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( '<td class="mac-members-roles" data-mac-members-column="roles">Officer, Trustee</td>', $output );
		self::assertStringContainsString( '<td class="mac-members-roles" data-mac-members-column="roles"></td>', $output );
	}

	public function test_column_checkboxes_are_all_checked_by_default(): void {
		$this->store_members( array( 1 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertSame( 10, preg_match_all( '/<input type="checkbox" value="([^"]+)" data-mac-members-column-toggle( checked)?>/', $output, $boxes ) );
		self::assertSame( self::SHOWN_COLUMNS, $boxes[1] );
		self::assertSame( array_fill( 0, 10, ' checked' ), $boxes[2] );
		self::assertStringContainsString( '<label class="mac-members-columns__option"><input type="checkbox" value="roles" data-mac-members-column-toggle checked>Roles</label>', $output );
		// The checkboxes sit right above the table, under the status filters and the role and search form.
		self::assertLessThan( strpos( $output, 'class="mac-members-search"' ), strpos( $output, 'class="mac-members-filters"' ) );
		self::assertLessThan( strpos( $output, 'class="mac-members-columns"' ), strpos( $output, 'class="mac-members-search"' ) );
		self::assertLessThan( strpos( $output, 'class="mac-members-table-wrap"' ), strpos( $output, 'class="mac-members-columns"' ) );
	}

	public function test_the_cookie_unchecks_the_columns_the_viewer_hid(): void {
		$this->store_members( array( 1 => 'mac_members_pending' ) );
		$_COOKIE['mac_members_hidden_columns'] = 'email,roles,nope';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( '<input type="checkbox" value="email" data-mac-members-column-toggle>', $output );
		self::assertStringContainsString( '<input type="checkbox" value="roles" data-mac-members-column-toggle>', $output );
		self::assertStringContainsString( '<input type="checkbox" value="user_id" data-mac-members-column-toggle checked>', $output );
		// Hidden columns are still rendered: the stylesheet hides them while their checkbox is unchecked.
		self::assertStringContainsString( '<td data-mac-members-column="email">member1@example.test</td>', $output );
	}

	/**
	 * @param mixed $setting Stored table size.
	 */
	#[DataProvider( 'provide_table_sizes' )]
	public function test_table_size_setting_sets_the_size_class( mixed $setting, string $class ): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'table_size' => $setting );

		self::assertStringContainsString( '<div class="mac-members-table ' . $class . '" data-mac-members-table', $this->create_shortcode()->render() );
	}

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function provide_table_sizes(): array {
		return array(
			'medium by default'  => array( null, 'mac-members-table--medium' ),
			'small'              => array( 'small', 'mac-members-table--small' ),
			'medium'             => array( 'medium', 'mac-members-table--medium' ),
			'the old mixed size' => array( 'mixed', 'mac-members-table--medium' ),
			'unknown'            => array( 'huge', 'mac-members-table--medium' ),
		);
	}

	public function test_registered_shows_the_date_with_the_full_date_and_time_on_hover(): void {
		$this->store_people(
			array(
				1 => array(
					'roles'           => array( 'mac_members_pending' ),
					'user_registered' => '2026-05-01 12:00:00',
				),
			)
		);

		self::assertStringContainsString(
			'<td data-mac-members-column="registered"><time datetime="2026-05-01T12:00:00+00:00" title="May 1, 2026 12:00 pm">May 1, 2026</time></td>',
			$this->create_shortcode()->render()
		);
	}

	public function test_relative_dates_show_the_time_since(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'date_display' => 'relative' );
		$this->store_people(
			array(
				1 => array(
					'roles'           => array( 'mac_members_pending' ),
					'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ),
				),
			)
		);

		self::assertMatchesRegularExpression(
			'/<td data-mac-members-column="registered"><time datetime="[^"]+" title="[^"]+">3 days ago<\/time><\/td>/',
			$this->create_shortcode()->render()
		);
	}

	public function test_last_login_column_shows_after_registered_while_mac_core_records_last_logins(): void {
		$this->store_people(
			array(
				1 => array(
					'roles'               => array( 'mac_members_approved' ),
					'mac_core_last_login' => '1790000000',
				),
				2 => array( 'roles' => array( 'mac_members_approved' ) ),
			)
		);
		$_GET['mac_members_status'] = 'approved';

		$output = $this->create_shortcode( $this->mac_core_last_login( true ) )->render();

		self::assertSame( 11, preg_match_all( '/<th scope="col" data-mac-members-column="([^"]+)">/', $output, $headers ) );
		self::assertSame( MembersTableRenderer::COLUMN_KEYS, $headers[1] );
		self::assertStringContainsString( '<th scope="col" data-mac-members-column="last_login">Last Login</th>', $output );
		self::assertStringContainsString( '<input type="checkbox" value="last_login" data-mac-members-column-toggle checked>Last Login</label>', $output );
		// Last Login shows the time too, and stays empty for a member without a recorded login.
		self::assertStringContainsString(
			'<td data-mac-members-column="last_login"><time datetime="' . gmdate( 'c', 1790000000 ) . '" title="' . gmdate( 'F j, Y g:i a', 1790000000 ) . '">' . gmdate( 'F j, Y g:i a', 1790000000 ) . '</time></td>',
			$output
		);
		self::assertStringContainsString( '<td data-mac-members-column="last_login"></td>', $output );
	}

	public function test_last_login_column_is_missing_while_mac_core_does_not_record_last_logins(): void {
		$this->store_members( array( 1 => 'mac_members_pending' ) );

		self::assertStringNotContainsString( 'last_login', $this->create_shortcode( $this->mac_core_last_login( false ) )->render() );
	}

	public function test_the_page_loads_the_meta_of_its_members_in_one_query(): void {
		$this->store_members(
			array(
				3 => 'mac_members_pending',
				5 => 'mac_members_pending',
			)
		);

		$this->create_shortcode()->render();

		self::assertSame( array( array( 'user', array( 3, 5 ) ) ), $GLOBALS['mac_members_test_meta_cache_loads'] );
	}

	public function test_empty_view_has_no_column_checkboxes(): void {
		self::assertStringNotContainsString( 'mac-members-columns', $this->create_shortcode()->render() );
	}

	public function test_buttons_use_automatic_css_classes(): void {
		$this->store_members( array( 7 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'class="mac-members-button mac-members-button--approve btn--success btn--s"', $output );
		self::assertStringContainsString( 'class="mac-members-button mac-members-button--deny btn--danger btn--s"', $output );
		self::assertStringContainsString( 'class="mac-members-filter btn--neutral btn--outline btn--s" href="/?mac_members_status=approved"', $output );
	}

	public function test_unknown_status_query_argument_falls_back_to_pending(): void {
		$_GET['mac_members_status'] = 'administrator';

		self::assertStringContainsString( 'data-mac-members-view="pending"', $this->create_shortcode()->render() );
	}

	public function test_status_attribute_fixes_the_view_without_filters(): void {
		$_GET['mac_members_status'] = 'approved';
		$this->store_members(
			array(
				1 => 'mac_members_inactive',
				2 => 'mac_members_approved',
			)
		);

		$output = $this->create_shortcode()->render( array( 'status' => 'inactive' ) );

		self::assertStringContainsString( 'data-mac-members-view="inactive"', $output );
		self::assertStringNotContainsString( 'mac-members-filters', $output );
		self::assertStringContainsString( 'data-mac-members-action="reactivate" data-mac-members-user-id="1"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="2"', $output );
	}

	public function test_invalid_status_attribute_keeps_the_filters(): void {
		$output = $this->create_shortcode()->render( array( 'status' => 'nope' ) );

		self::assertStringContainsString( 'mac-members-filters', $output );
		self::assertStringContainsString( 'data-mac-members-view="pending"', $output );
	}

	public function test_footer_has_numbered_pages_the_range_and_the_page_size(): void {
		$this->store_approved_members( 51 );
		$_SERVER['REQUEST_URI']     = '/members/?mac_members_status=approved';
		$_GET['mac_members_status'] = 'approved';

		$first = $this->create_shortcode()->render();

		self::assertSame( 24, substr_count( $first, '<tr class="mac-members-list__row"' ) );
		self::assertSame( array( '1', '2', '3', 'Next' ), $this->get_pagination_items( $first ) );
		self::assertStringContainsString( '<span class="mac-members-page-link btn--neutral btn--s" aria-current="page">1</span>', $first );
		self::assertStringContainsString( '<a class="mac-members-page-link btn--neutral btn--outline btn--s" href="/members/?mac_members_status=approved&amp;mac_members_page=2">2</a>', $first );
		self::assertStringContainsString( 'href="/members/?mac_members_status=approved&amp;mac_members_page=2" rel="next">Next</a>', $first );
		self::assertStringContainsString( 'data-mac-members-range-first="1" data-mac-members-range-last="24" data-mac-members-range-total="51">1-24 of 51</p>', $first );
		// The page size select sits in the footer and belongs to the role and search form.
		self::assertStringContainsString( '<form class="mac-members-search" id="mac-members-search-1"', $first );
		self::assertStringContainsString( '<span class="mac-members-select"><select class="mac-members-per-page__select" name="mac_members_per_page" form="mac-members-search-1" data-mac-members-autosubmit><option value="24" selected>24</option><option value="48">48</option><option value="96">96</option><option value="192">192</option></select></span>', $first );

		$_GET['mac_members_page'] = '3';
		$third                    = $this->create_shortcode()->render();

		self::assertSame( 3, substr_count( $third, '<tr class="mac-members-list__row"' ) );
		self::assertSame( array( 'Previous', '1', '2', '3' ), $this->get_pagination_items( $third ) );
		// Page 1 needs no page argument.
		self::assertStringContainsString( 'href="/members/?mac_members_status=approved">1</a>', $third );
		self::assertStringContainsString( 'href="/members/?mac_members_status=approved&amp;mac_members_page=2" rel="prev">Previous</a>', $third );
		self::assertStringContainsString( '>49-51 of 51</p>', $third );
	}

	public function test_pagination_shows_gaps_around_the_current_page(): void {
		$this->store_approved_members( 200 );
		$_GET = array(
			'mac_members_status' => 'approved',
			'mac_members_page'   => '5',
		);

		self::assertSame(
			array( 'Previous', '1', '&hellip;', '4', '5', '6', '&hellip;', '9', 'Next' ),
			$this->get_pagination_items( $this->create_shortcode()->render() )
		);
	}

	public function test_a_gap_of_one_page_shows_that_page(): void {
		$this->store_approved_members( 200 );
		$_GET = array(
			'mac_members_status' => 'approved',
			'mac_members_page'   => '4',
		);

		self::assertSame(
			array( 'Previous', '1', '2', '3', '4', '5', '&hellip;', '9', 'Next' ),
			$this->get_pagination_items( $this->create_shortcode()->render() )
		);
	}

	public function test_page_size_query_argument_sets_the_page_and_stays_in_the_links(): void {
		$this->store_approved_members( 50 );
		$_GET = array(
			'mac_members_status'   => 'approved',
			'mac_members_per_page' => '48',
		);

		$output = $this->create_shortcode()->render();

		self::assertSame( 48, substr_count( $output, '<tr class="mac-members-list__row"' ) );
		self::assertStringContainsString( '<option value="48" selected>48</option>', $output );
		self::assertStringContainsString( '>1-48 of 50</p>', $output );
		self::assertStringContainsString( 'href="/?mac_members_status=approved&amp;mac_members_per_page=48&amp;mac_members_page=2" rel="next">Next</a>', $output );
		self::assertStringContainsString( 'href="/?mac_members_status=pending&amp;mac_members_per_page=48">Pending', $output );
	}

	public function test_unknown_page_size_falls_back_to_24(): void {
		$this->store_approved_members( 30 );
		$_GET = array(
			'mac_members_status'   => 'approved',
			'mac_members_per_page' => '50',
		);

		$output = $this->create_shortcode()->render();

		self::assertSame( 24, substr_count( $output, '<tr class="mac-members-list__row"' ) );
		self::assertStringContainsString( '<option value="24" selected>24</option>', $output );
		self::assertStringNotContainsString( 'mac_members_per_page=', $output );
	}

	public function test_a_page_past_the_end_shows_the_last_page(): void {
		$this->store_approved_members( 30 );
		$_GET = array(
			'mac_members_status' => 'approved',
			'mac_members_page'   => '5',
		);

		$output = $this->create_shortcode()->render();

		self::assertSame( 6, substr_count( $output, '<tr class="mac-members-list__row"' ) );
		self::assertStringContainsString( '<span class="mac-members-page-link btn--neutral btn--s" aria-current="page">2</span>', $output );
		self::assertStringContainsString( '>25-30 of 30</p>', $output );
	}

	public function test_range_uses_thousands_separators(): void {
		$output = ( new MembersTableRenderer() )->render(
			array(),
			'all',
			array(),
			array(
				'page'     => 1,
				'pages'    => 99,
				'per_page' => 24,
				'total'    => 2353,
				'first'    => 1,
				'last'     => 24,
				'links'    => array(),
			)
		);

		self::assertStringContainsString( 'data-mac-members-range-total="2353">1-24 of 2,353</p>', $output );
		// Without the role and search form there is no form for the page size select to belong to.
		self::assertStringNotContainsString( 'mac-members-per-page', $output );
	}

	public function test_role_filter_offers_the_roles_members_hold_and_narrows_the_view_and_counts(): void {
		$this->add_union_roles();
		$this->store_people(
			array(
				1 => array( 'roles' => array( 'mac_members_pending', 'officer' ) ),
				2 => array( 'roles' => array( 'mac_members_approved', 'officer' ) ),
				3 => array( 'roles' => array( 'mac_members_approved', 'trustee' ) ),
				4 => array( 'roles' => array( 'mac_members_approved', 'administrator' ) ),
				5 => array( 'roles' => array( 'subscriber', 'shop_steward' ) ),
			)
		);
		$_SERVER['REQUEST_URI']     = '/members/?mac_members_status=approved&mac_members_role=officer';
		$_GET['mac_members_status'] = 'approved';
		$_GET['mac_members_role']   = 'officer';

		$output = $this->create_shortcode()->render();

		// Administrator is left out by default, and only a non-member holds Shop Steward.
		self::assertSame( 1, preg_match( '/<select class="mac-members-search__role"[^>]*>(.*?)<\/select>/s', $output, $role_select ) );
		self::assertSame( 3, preg_match_all( '/<option value="([^"]*)"[^>]*>([^<]*)<\/option>/', $role_select[1], $options ) );
		self::assertSame( array( '', 'officer', 'trustee' ), $options[1] );
		self::assertSame( array( 'All roles', 'Officer', 'Trustee' ), $options[2] );
		self::assertStringContainsString( '<option value="officer" selected>Officer</option>', $output );
		self::assertStringContainsString( 'data-mac-members-user-id="2" data-mac-members-status="approved"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="3"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="4"', $output );
		self::assertStringContainsString( 'href="/members/?mac_members_status=pending&amp;mac_members_role=officer">Pending <span class="mac-members-count" data-mac-members-count-for="pending">1</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="approved">1</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="all">2</span>', $output );
		// Choosing a role sends the form, so the dropdown needs no button next to it.
		// The span around the select draws its chevron.
		self::assertStringContainsString( '<span class="mac-members-select"><select class="mac-members-search__role" name="mac_members_role" aria-label="Role" data-mac-members-autosubmit>', $output );
		self::assertStringNotContainsString( '<button type="submit"', $output );
	}

	public function test_role_that_is_not_offered_is_ignored(): void {
		$this->store_people(
			array(
				1 => array( 'roles' => array( 'mac_members_approved', 'administrator' ) ),
				2 => array( 'roles' => array( 'mac_members_approved' ) ),
			)
		);
		$_GET['mac_members_status'] = 'approved';
		$_GET['mac_members_role']   = 'administrator';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-user-id="1"', $output );
		self::assertStringContainsString( 'data-mac-members-user-id="2"', $output );
		// No member holds a role the filter offers, so only the search shows.
		self::assertStringNotContainsString( 'mac-members-search__role', $output );
		self::assertStringContainsString( '<input class="mac-members-search__input" type="search" name="mac_members_search" value=""', $output );
	}

	public function test_search_narrows_the_view_and_the_counts_and_stays_in_the_links(): void {
		$this->store_people(
			array(
				1 => array(
					'roles'      => array( 'mac_members_pending' ),
					'first_name' => 'Test',
					'last_name'  => 'Unu',
				),
				2 => array(
					'roles'      => array( 'mac_members_approved' ),
					'first_name' => 'Test',
					'last_name'  => 'Doi',
				),
				3 => array( 'roles' => array( 'mac_members_denied' ) ),
			)
		);
		$_GET['mac_members_status'] = 'all';
		$_GET['mac_members_search'] = '  test doi ';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-user-id="2" data-mac-members-status="approved"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="1"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="3"', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="pending">0</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="approved">1</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="all">1</span>', $output );
		self::assertStringContainsString( 'href="/?mac_members_status=pending&amp;mac_members_search=test+doi"', $output );
		self::assertStringContainsString( '<input class="mac-members-search__input" type="search" name="mac_members_search" value="test doi" maxlength="100" placeholder="Search by name, email or username" aria-label="Search members">', $output );
	}

	public function test_links_encode_the_search(): void {
		$_GET['mac_members_status'] = 'approved';
		$_GET['mac_members_search'] = 'a&b #1';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'href="/?mac_members_status=denied&amp;mac_members_search=a%26b+%231"', $output );
		self::assertStringContainsString( 'name="mac_members_search" value="a&amp;b #1"', $output );
	}

	public function test_search_without_matches_says_no_members_match(): void {
		$this->store_people( array( 1 => array( 'roles' => array( 'mac_members_pending' ) ) ) );
		$_GET['mac_members_search'] = 'nobody';

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( '<p class="mac-members-empty">No members match these filters.</p>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="all">0</span>', $output );
	}

	public function test_form_sends_the_page_query_arguments_and_the_view(): void {
		$_SERVER['REQUEST_URI']     = '/?page_id=5&mac_members_status=denied&mac_members_page=2&mac_members_search=x';
		$_GET['mac_members_status'] = 'denied';

		$output = $this->create_shortcode()->render();

		// Without a role to offer, the form is the hidden fields and the search. Enter sends it.
		self::assertStringContainsString( '<form class="mac-members-search" id="mac-members-search-1" method="get" action="/" role="search" aria-label="Filter members"><input type="hidden" name="page_id" value="5"><input type="hidden" name="mac_members_status" value="denied"><input class="mac-members-search__input" type="search"', $output );
		self::assertStringContainsString( 'aria-label="Search members"></form>', $output );
	}

	public function test_fixed_view_form_has_no_status_field(): void {
		$output = $this->create_shortcode()->render( array( 'status' => 'approved' ) );

		self::assertStringContainsString( '<form class="mac-members-search"', $output );
		self::assertStringNotContainsString( 'name="mac_members_status"', $output );
	}

	public function test_pagination_links_keep_the_role_and_the_search(): void {
		$this->add_union_roles();
		$members = array();

		for ( $id = 1; $id <= 51; $id++ ) {
			$members[ $id ] = array(
				'roles'      => array( 'mac_members_approved', 'officer' ),
				'first_name' => 'Ion',
			);
		}

		$this->store_people( $members );
		$_GET = array(
			'mac_members_status' => 'approved',
			'mac_members_role'   => 'officer',
			'mac_members_search' => 'ion',
		);

		self::assertStringContainsString(
			'href="/?mac_members_status=approved&amp;mac_members_role=officer&amp;mac_members_search=ion&amp;mac_members_page=2" rel="next">Next</a>',
			$this->create_shortcode()->render()
		);
	}

	public function test_single_page_has_no_page_links_but_keeps_the_range(): void {
		$this->store_members( array( 1 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertStringNotContainsString( 'mac-members-pagination', $output );
		self::assertStringContainsString( '>1-1 of 1</p>', $output );
	}

	public function test_empty_view_has_no_footer(): void {
		self::assertStringNotContainsString( 'mac-members-footer', $this->create_shortcode()->render() );
	}

	#[DataProvider( 'provide_table_page_detection' )]
	public function test_protect_table_page_detects_pages_whose_content_has_the_shortcode( bool $is_singular, ?string $content, bool $expected ): void {
		$GLOBALS['mac_members_test_is_singular']    = $is_singular;
		$GLOBALS['mac_members_test_queried_object'] = null === $content ? null : new \WP_Post(
			array(
				'ID'           => 5,
				'post_content' => $content,
			)
		);

		$seen = null;
		add_filter(
			'mac_members_is_table_page',
			static function ( bool $has_table ) use ( &$seen ): bool {
				$seen = $has_table;

				return $has_table;
			}
		);

		$this->create_shortcode()->protect_table_page();

		self::assertSame( $expected, $seen );
		self::assertSame( $expected ? 1 : 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	/**
	 * @return array<string,array{0:bool,1:?string,2:bool}>
	 */
	public static function provide_table_page_detection(): array {
		return array(
			'page with the shortcode'        => array( true, "Intro\n[mac_members_table]\nOutro", true ),
			'shortcode with attributes'      => array( true, '[mac_members_table status="approved"]', true ),
			'page without the shortcode'     => array( true, 'Just text', false ),
			'other shortcode with that name' => array( true, '[mac_members_table_other]', false ),
			'not a singular request'         => array( false, '[mac_members_table]', false ),
			'no queried post'                => array( true, null, false ),
		);
	}

	public function test_filter_marks_layouts_that_place_the_shortcode_elsewhere(): void {
		add_filter( 'mac_members_is_table_page', static fn (): bool => true );

		$this->create_shortcode()->protect_table_page();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_protects_the_response_once_when_template_redirect_already_did(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_table]' ) );

		$shortcode = $this->create_shortcode();
		$shortcode->protect_table_page();
		$shortcode->render();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_protects_the_response_for_layouts_that_were_not_detected(): void {
		$this->create_shortcode()->render();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_table_page_defines_donotcachepage(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_table]' ) );

		self::assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->create_shortcode()->protect_table_page();

		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_other_pages_do_not_define_donotcachepage(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => 'No table here.' ) );

		$this->create_shortcode()->protect_table_page();

		self::assertFalse( defined( 'DONOTCACHEPAGE' ) );
		self::assertSame( 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_outputs_escaped_member_rows_and_enqueues_assets(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User(
				array(
					'ID'              => 123,
					'user_email'      => 'pending@example.test',
					'user_login'      => 'pending<script>',
					'user_registered' => '2026-05-01 12:00:00',
					'first_name'      => 'Mia <Admin>',
					'last_name'       => 'O\'Connor',
					'roles'           => array( 'mac_members_pending' ),
				)
			),
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'class="mac-members-table mac-members-table--medium"', $output );
		self::assertStringContainsString( 'class="mac-members-list"', $output );
		self::assertStringContainsString( 'data-mac-members-user-id="123"', $output );
		self::assertStringContainsString( 'pending@example.test', $output );
		self::assertStringContainsString( 'Mia &lt;Admin&gt;', $output );
		self::assertStringNotContainsString( 'Mia <Admin>', $output );
		self::assertStringContainsString( 'pending&lt;script&gt;', $output );
		self::assertStringContainsString( 'user-edit.php?user_id=123', $output );
		self::assertStringContainsString( 'target="_blank"', $output );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $output );
		self::assertStringContainsString( 'data-mac-members-action="approve"', $output );
		self::assertStringContainsString( 'data-mac-members-action="deny"', $output );
		self::assertStringContainsString( '<p class="mac-members-empty" hidden>There are no pending members.</p>', $output );
		self::assertContains( FrontendAssets::SCRIPT_HANDLE, $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	/**
	 * @param array<string,string> $query Query arguments.
	 */
	#[DataProvider( 'provide_empty_views' )]
	public function test_empty_view_says_which_members_are_missing( array $query, string $text ): void {
		$_GET = $query;

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( '<p class="mac-members-empty">' . $text . '</p>', $output );
		self::assertStringNotContainsString( '<table', $output );
		self::assertStringNotContainsString( 'do not exist', $output );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function provide_empty_views(): array {
		return array(
			'pending'  => array( array(), 'There are no pending members.' ),
			'approved' => array( array( 'mac_members_status' => 'approved' ), 'There are no approved members.' ),
			'inactive' => array( array( 'mac_members_status' => 'inactive' ), 'There are no inactive members.' ),
			'denied'   => array( array( 'mac_members_status' => 'denied' ), 'There are no denied members.' ),
			'all'      => array( array( 'mac_members_status' => 'all' ), 'There are no members yet.' ),
		);
	}

	public function test_render_warns_about_missing_roles_in_the_table(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_pending'], $GLOBALS['mac_members_test_roles']['mac_members_denied'] );

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'mac-members-notice--warning', $output );
		self::assertStringContainsString(
			'MAC Members: One or more configured roles do not exist (mac_members_pending, mac_members_denied). Status changes that need a missing role are blocked. Please review the MAC Members settings.',
			$output
		);
	}

	public function test_renderer_escapes_missing_role_names(): void {
		$output = ( new MembersTableRenderer() )->render( array(), 'pending', array(), array(), array( '<b>role</b>' ) );

		self::assertStringContainsString( '&lt;b&gt;role&lt;/b&gt;', $output );
		self::assertStringNotContainsString( '<b>role</b>', $output );
	}

	/**
	 * @param array<int,string> $members Status role of each member, keyed by user ID.
	 */
	private function store_members( array $members ): void {
		$GLOBALS['mac_members_test_users'] = array();

		foreach ( $members as $user_id => $role ) {
			$GLOBALS['mac_members_test_users'][] = new \WP_User(
				array(
					'ID'         => $user_id,
					'user_email' => 'member' . $user_id . '@example.test',
					'user_login' => 'member' . $user_id,
					'roles'      => array( $role ),
				)
			);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $people User data keyed by user ID; email and username default to member<ID>.
	 */
	private function store_people( array $people ): void {
		$GLOBALS['mac_members_test_users'] = array();

		foreach ( $people as $user_id => $data ) {
			$GLOBALS['mac_members_test_users'][] = new \WP_User(
				$data + array(
					'ID'         => $user_id,
					'user_email' => 'member' . $user_id . '@example.test',
					'user_login' => 'member' . $user_id,
				)
			);
		}
	}

	private function add_union_roles(): void {
		foreach ( array( 'officer' => 'Officer', 'trustee' => 'Trustee', 'shop_steward' => 'Shop Steward' ) as $slug => $name ) {
			$GLOBALS['mac_members_test_roles'][ $slug ] = array(
				'name'         => $name,
				'capabilities' => array( 'read' => true ),
			);
		}
	}

	private function store_approved_members( int $count ): void {
		$members = array();

		for ( $id = 1; $id <= $count; $id++ ) {
			$members[ $id ] = 'mac_members_approved';
		}

		$this->store_members( $members );
	}

	/**
	 * @return array<int,string> The text of each link, page and gap in the pagination, in order.
	 */
	private function get_pagination_items( string $output ): array {
		self::assertSame( 1, preg_match( '/<nav class="mac-members-pagination"[^>]*>(.*?)<\/nav>/s', $output, $nav ) );
		preg_match_all( '/<(?:a|span)[^>]*>([^<]*)<\/(?:a|span)>/', $nav[1], $items );

		return $items[1];
	}

	private function get_render_token( string $output ): string {
		self::assertSame( 1, preg_match( '/data-mac-members-render-token="([^"]+)"/', $output, $matches ) );

		return $matches[1];
	}

	private function create_shortcode( ?MacCoreLastLogin $last_login = null ): MembersTableShortcode {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new MembersTableShortcode(
			new MembersQuery( $repository ),
			new MembersTableRenderer(),
			new FrontendAssets(),
			$repository,
			new RenderToken(),
			$last_login ?? new MacCoreLastLogin()
		);
	}

	/**
	 * MAC Core's last login, recorded or not, without defining MAC Core's constant in this process.
	 */
	private function mac_core_last_login( bool $enabled ): MacCoreLastLogin {
		return new class( $enabled ) extends MacCoreLastLogin {
			public function __construct( private readonly bool $enabled ) {}

			public function is_enabled(): bool {
				return $this->enabled;
			}
		};
	}
}
