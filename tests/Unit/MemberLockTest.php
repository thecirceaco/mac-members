<?php
/**
 * Tests for the per-member lock.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Actions\MemberLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MemberLock::class )]
final class MemberLockTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();
	}

	public function test_only_one_request_can_hold_the_lock_for_a_member(): void {
		$first  = new MemberLock();
		$second = new MemberLock();

		self::assertTrue( $first->acquire( 12 ) );
		self::assertArrayHasKey( 'mac_members_lock_12', $this->rows() );
		self::assertFalse( $second->acquire( 12 ) );

		$first->release( 12 );

		self::assertArrayNotHasKey( 'mac_members_lock_12', $this->rows() );
		self::assertTrue( $second->acquire( 12 ) );
	}

	public function test_lock_is_per_member(): void {
		self::assertTrue( ( new MemberLock() )->acquire( 12 ) );
		self::assertTrue( ( new MemberLock() )->acquire( 13 ) );
	}

	public function test_lock_is_created_with_an_atomic_insert(): void {
		( new MemberLock() )->acquire( 12 );

		self::assertStringStartsWith(
			"INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('mac_members_lock_12', '",
			$GLOBALS['wpdb']->queries[0]
		);
	}

	public function test_expired_lock_left_by_a_request_that_died_is_taken_over(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() - 1 ) . ':dead';

		$lock = new MemberLock();

		self::assertTrue( $lock->acquire( 12 ) );
		self::assertNotSame( ( time() - 1 ) . ':dead', $this->rows()['mac_members_lock_12'] );
	}

	public function test_lock_that_has_not_expired_is_not_taken_over(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() + 30 ) . ':other';

		self::assertFalse( ( new MemberLock() )->acquire( 12 ) );
		self::assertSame( ( time() + 30 ) . ':other', $this->rows()['mac_members_lock_12'] );
	}

	public function test_release_keeps_a_lock_that_another_request_took_over(): void {
		$first = new MemberLock();
		$first->acquire( 12 );

		// The first lock expires and another request takes it over.
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() - 1 ) . ':expired';
		$second = new MemberLock();
		self::assertTrue( $second->acquire( 12 ) );
		$taken_over = $this->rows()['mac_members_lock_12'];

		$first->release( 12 );

		self::assertSame( $taken_over, $this->rows()['mac_members_lock_12'] );
	}

	public function test_release_without_the_lock_sends_no_query(): void {
		( new MemberLock() )->release( 12 );

		self::assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_failed_insert_does_not_count_as_holding_the_lock(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() + 30 ) . ':other';
		$GLOBALS['wpdb']->fail_next_query            = true;

		self::assertFalse( ( new MemberLock() )->acquire( 12 ) );
	}

	public function test_delete_all_removes_only_lock_rows(): void {
		$GLOBALS['wpdb']->rows = array(
			'mac_members_lock_12'  => '1:a',
			'mac_members_lock_345' => '1:b',
			'mac_members_settings' => 'keep',
			'mac_membersXlockY1'   => 'keep',
		);

		MemberLock::delete_all();

		self::assertSame(
			array(
				'mac_members_settings' => 'keep',
				'mac_membersXlockY1'   => 'keep',
			),
			$this->rows()
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function rows(): array {
		return $GLOBALS['wpdb']->rows;
	}
}
