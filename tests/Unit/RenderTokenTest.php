<?php
/**
 * Tests for the members table render token.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Members\RenderToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( RenderToken::class )]
final class RenderTokenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();
	}

	public function test_token_allows_only_the_rendered_users(): void {
		$tokens = new RenderToken();
		$token  = $tokens->issue( array( 12, 13 ) );

		self::assertTrue( $tokens->allows( $token, 12 ) );
		self::assertTrue( $tokens->allows( $token, 13 ) );
		self::assertFalse( $tokens->allows( $token, 1 ) );
		self::assertFalse( $tokens->allows( $token, 123 ) );
		self::assertFalse( $tokens->allows( $token, 0 ) );
	}

	public function test_token_for_an_empty_table_allows_nobody(): void {
		$tokens = new RenderToken();

		self::assertFalse( $tokens->allows( $tokens->issue( array() ), 12 ) );
	}

	public function test_token_is_bound_to_the_user_it_was_rendered_for(): void {
		$tokens = new RenderToken();
		$token  = $tokens->issue( array( 12 ) );

		$GLOBALS['mac_members_test_current_user_id'] = 2;

		self::assertFalse( $tokens->allows( $token, 12 ) );
	}

	public function test_token_is_bound_to_the_login_session(): void {
		$tokens = new RenderToken();
		$token  = $tokens->issue( array( 12 ) );

		$GLOBALS['mac_members_test_session_token'] = 'session-two';

		self::assertFalse( $tokens->allows( $token, 12 ) );
	}

	public function test_expired_token_is_refused(): void {
		self::assertFalse( ( new RenderToken( -1 ) )->allows( ( new RenderToken( -1 ) )->issue( array( 12 ) ), 12 ) );
	}

	public function test_changed_token_is_refused(): void {
		$tokens = new RenderToken();
		$parts  = explode( '.', $tokens->issue( array( 12 ) ) );

		$later_expiry    = $parts;
		$later_expiry[0] = (string) ( (int) $parts[0] + 3600 );
		$more_users      = $parts;
		$more_users[1]   = '12-13';

		self::assertFalse( $tokens->allows( implode( '.', $later_expiry ), 12 ) );
		self::assertFalse( $tokens->allows( implode( '.', $more_users ), 13 ) );
		self::assertFalse( $tokens->allows( '', 12 ) );
		self::assertFalse( $tokens->allows( 'a.b.c', 12 ) );
		self::assertFalse( $tokens->allows( implode( '.', $parts ) . '.extra', 12 ) );
	}

	public function test_each_token_is_unique(): void {
		$tokens = new RenderToken();

		self::assertNotSame( $tokens->issue( array( 12 ) ), $tokens->issue( array( 12 ) ) );
	}
}
