<?php
/**
 * Removes the test members that add-test-members.php added, found by their user meta mac_members_test_user,
 * and the Officer, Trustee and Shop Steward roles that script added, once no user holds them. Emails are off
 * while it runs.
 *
 * Run it through WP-CLI from stdin: wp eval-file - < remove-test-members.php
 */

require_once ABSPATH . 'wp-admin/includes/user.php';

add_filter( 'pre_wp_mail', '__return_false' );

$user_ids = get_users(
	array(
		'meta_key'   => 'mac_members_test_user',
		'meta_value' => '1',
		'fields'     => 'ID',
		'number'     => -1,
	)
);

foreach ( $user_ids as $user_id ) {
	wp_delete_user( (int) $user_id );
}

WP_CLI::log( sprintf( 'Removed %d test members.', count( $user_ids ) ) );

foreach ( (array) get_option( 'mac_members_test_roles_added', array() ) as $role ) {
	$holders = get_users(
		array(
			'role'   => $role,
			'number' => 1,
			'fields' => 'ID',
		)
	);

	if ( wp_roles()->is_role( $role ) && array() === $holders ) {
		remove_role( $role );
		WP_CLI::log( "Removed the role $role." );
	}
}

delete_option( 'mac_members_test_roles_added' );

WP_CLI::success( 'The test members are gone.' );
