<?php
/**
 * Adds 250 test members to a site with MAC Members, to test the members table: the four status roles in
 * rough proportions, names, registration dates over the past year, and about half with an Officer, Trustee
 * or Shop Steward role. Emails are off while it runs, and the addresses are @example.org, which never
 * receive mail.
 *
 * Every test user gets the user meta mac_members_test_user, so remove-test-members.php can find them. A
 * second run skips the users that already exist.
 *
 * Run it through WP-CLI from stdin: wp eval-file - < add-test-members.php
 */

add_filter( 'pre_wp_mail', '__return_false' );

$count    = 250;
$settings = get_option( 'mac_members_settings', array() );
$settings = is_array( $settings ) ? $settings : array();

$status_roles = array(
	'approved' => $settings['approved_role'] ?? 'mac_members_approved',
	'pending'  => $settings['pending_role'] ?? 'mac_members_pending',
	'inactive' => $settings['inactive_role'] ?? 'mac_members_inactive',
	'denied'   => $settings['denied_role'] ?? 'mac_members_denied',
);

foreach ( $status_roles as $role ) {
	if ( ! wp_roles()->is_role( $role ) ) {
		WP_CLI::error( "The role $role does not exist. Activate MAC Members first." );
	}
}

$union_roles = array(
	'officer'      => 'Officer',
	'trustee'      => 'Trustee',
	'shop_steward' => 'Shop Steward',
);
$added_roles = (array) get_option( 'mac_members_test_roles_added', array() );

foreach ( $union_roles as $slug => $name ) {
	if ( ! wp_roles()->is_role( $slug ) ) {
		add_role( $slug, $name, array( 'read' => true ) );
		$added_roles[] = $slug;
		WP_CLI::log( "Added the role $name." );
	}
}

update_option( 'mac_members_test_roles_added', array_values( array_unique( $added_roles ) ), false );

$first_names = array( 'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Carlos', 'Maria', 'Andrei', 'Ioana', 'Mihai', 'Elena' );
$last_names  = array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Popescu', 'Ionescu', 'Stan', 'Dumitru' );

// Out of 20 members: 12 approved, 3 pending, 2 inactive and 3 denied.
$statuses = array_merge(
	array_fill( 0, 12, 'approved' ),
	array_fill( 0, 3, 'pending' ),
	array_fill( 0, 2, 'inactive' ),
	array_fill( 0, 3, 'denied' )
);

// The same members on every run.
mt_srand( 350 );

$added = array_fill_keys( array_keys( $status_roles ), 0 );

for ( $i = 1; $i <= $count; $i++ ) {
	$login  = sprintf( 'mmtest-%04d', $i );
	$first  = $first_names[ mt_rand( 0, count( $first_names ) - 1 ) ];
	$last   = $last_names[ mt_rand( 0, count( $last_names ) - 1 ) ];
	$status = $statuses[ mt_rand( 0, count( $statuses ) - 1 ) ];
	$extra  = mt_rand( 0, 5 );
	$days   = mt_rand( 0, 365 * DAY_IN_SECONDS );

	if ( username_exists( $login ) ) {
		continue;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'      => $login,
			'user_email'      => $login . '@example.org',
			'user_pass'       => wp_generate_password( 32 ),
			'first_name'      => $first,
			'last_name'       => $last,
			'display_name'    => $first . ' ' . $last,
			'role'            => $status_roles[ $status ],
			'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $days ),
		)
	);

	if ( is_wp_error( $user_id ) ) {
		WP_CLI::warning( $login . ': ' . $user_id->get_error_message() );
		continue;
	}

	update_user_meta( $user_id, 'mac_members_test_user', 1 );

	// About half the members also hold a union role.
	$user = get_user_by( 'id', $user_id );

	if ( $user && $extra < 3 ) {
		$user->add_role( array_keys( $union_roles )[ $extra ] );
	}

	++$added[ $status ];
}

WP_CLI::success(
	sprintf(
		'Added %d test members: %d approved, %d pending, %d inactive, %d denied.',
		array_sum( $added ),
		$added['approved'],
		$added['pending'],
		$added['inactive'],
		$added['denied']
	)
);
