<?php
/**
 * Token that ties status changes to a members table the plugin rendered.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class RenderToken
{
	/**
	 * Keeps these signatures apart from other signatures made with the same salt.
	 */
	private const CONTEXT = 'mac_members_table';

	/**
	 * @param int $lifetime Seconds a rendered table can be used for.
	 */
	public function __construct(
		private readonly int $lifetime = 12 * HOUR_IN_SECONDS
	) {}

	/**
	 * Creates the token for one rendering of the table. It lists the IDs of the users in the table and is
	 * signed for the current user and login session, so it cannot be changed or used by anyone else.
	 *
	 * @param array<int,int> $user_ids IDs of the users in the table.
	 */
	public function issue( array $user_ids ): string
	{
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		$payload  = implode(
			'.',
			array(
				(string) ( time() + $this->lifetime ),
				implode( '-', $user_ids ),
				bin2hex( random_bytes( 8 ) ),
			)
		);

		return $payload . '.' . $this->sign( $payload );
	}

	/**
	 * Whether the token comes from a table that was rendered for the current user and session, has not
	 * expired, and showed this user.
	 */
	public function allows( string $token, int $user_id ): bool
	{
		$parts = explode( '.', $token );

		if ( 4 !== count( $parts ) || 1 > $user_id ) {
			return false;
		}

		$payload = $parts[0] . '.' . $parts[1] . '.' . $parts[2];

		if ( ! hash_equals( $this->sign( $payload ), $parts[3] ) || (int) $parts[0] < time() ) {
			return false;
		}

		return in_array( (string) $user_id, explode( '-', $parts[1] ), true );
	}

	private function sign( string $payload ): string
	{
		return hash_hmac(
			'sha256',
			implode( '|', array( self::CONTEXT, (string) \get_current_user_id(), \wp_get_session_token(), $payload ) ),
			\wp_salt( 'nonce' )
		);
	}
}
