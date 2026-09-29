<?php
/**
 * Gives the test members from add-test-members.php a made-up last login in MAC Core's mac_core_last_login user
 * meta, so the members table's Last Login column has something to show. About four in five members get one, at a
 * random time between their registration and now, within the last 90 days; the rest keep none, like members who
 * never signed in. Running it again gives the same times. remove-test-members.php deletes the test members and
 * this meta with them.
 *
 * Run it through WP-CLI from stdin: wp eval-file - < add-test-last-logins.php
 */

$user_ids = get_users(
	array(
		'meta_key'   => 'mac_members_test_user',
		'meta_value' => '1',
		'fields'     => 'ID',
		'number'     => -1,
		'orderby'    => 'ID',
		'order'      => 'ASC',
	)
);

// The same times on every run.
mt_srand( 351 );

$now = time();
$set = 0;

foreach ( $user_ids as $user_id ) {
	$roll   = mt_rand( 1, 5 );
	$random = mt_rand( 0, PHP_INT_MAX ) / PHP_INT_MAX;
	$user   = get_user_by( 'id', (int) $user_id );

	if ( ! $user || 1 === $roll ) {
		continue;
	}

	$registered = strtotime( $user->user_registered . ' UTC' );
	$earliest   = max( false === $registered ? 0 : $registered, $now - 90 * DAY_IN_SECONDS );
	$last_login = (int) ( $earliest + ( $now - $earliest ) * $random );

	update_user_meta( (int) $user_id, 'mac_core_last_login', (string) $last_login );
	++$set;
}

WP_CLI::success( sprintf( 'Set a made-up last login on %d of %d test members.', $set, count( $user_ids ) ) );
