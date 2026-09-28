<?php
/**
 * Tests for pending members query construction.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\PendingMembers\PendingMembersQuery;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( PendingMembersQuery::class )]
final class PendingMembersQueryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_query_args_use_configured_pending_role_oldest_first_and_limit_to_50(): void {
		$repository = $this->create_settings_repository();
		$repository->save(
			array(
				'pending_role' => 'subscriber',
			)
		);

		$query = new PendingMembersQuery( $repository );

		self::assertSame(
			array(
				'role'        => 'subscriber',
				'number'      => 50,
				'orderby'     => 'registered',
				'order'       => 'ASC',
				'fields'      => 'all',
				'count_total' => false,
			),
			$query->get_query_args()
		);
	}

	public function test_get_pending_users_uses_isolated_query_arguments(): void {
		$users = array(
			new \WP_User(
				array(
					'ID'              => 12,
					'user_email'      => 'member@example.test',
					'user_login'      => 'member12',
					'user_registered' => '2026-05-01 12:00:00',
					'roles'           => array( 'mac_members_pending' ),
				)
			),
		);

		$GLOBALS['mac_members_test_users'] = $users;

		$query = new PendingMembersQuery( $this->create_settings_repository() );

		self::assertSame( $users, $query->get_pending_users() );
		self::assertSame( $query->get_query_args(), $GLOBALS['mac_members_test_last_user_query'] );
	}

	private function create_settings_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
