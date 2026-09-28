<?php
/**
 * Tests for approve and deny AJAX actions.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Actions\MemberActionController;
use MacMembers\Assets\FrontendAssets;
use MacMembers\Email\MemberNotificationService;
use MacMembers\PendingMembers\RenderToken;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;
use function wp_create_nonce;

#[CoversClass( MemberActionController::class )]
final class MemberActionControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_authenticated_ajax_actions_only(): void {
		$controller = $this->create_controller();

		$controller->register();

		foreach ( array( 'approve', 'deny', 'deactivate', 'reactivate' ) as $method ) {
			self::assertSame(
				array( $controller, $method ),
				$GLOBALS['mac_members_test_actions'][ 'wp_ajax_mac_members_' . $method . '_user' ][0]['callback'],
				$method
			);
			self::assertArrayNotHasKey( 'wp_ajax_nopriv_mac_members_' . $method . '_user', $GLOBALS['mac_members_test_actions'] );
		}
	}

	/**
	 * Regression test: wp_send_json_*() only records here and returns, as it does under a wp_die
	 * handler that does not exit. Every failed check must still end the request before a role changes.
	 */
	#[DataProvider( 'provide_failed_gates' )]
	public function test_failed_gate_ends_the_request_before_any_role_change( string $gate, string $expected_code ): void {
		$GLOBALS['mac_members_test_ajax_send_exits'] = false;

		$this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, 12 );
		$this->arrange_failed_gate( $gate );

		$roles_before = $GLOBALS['mac_members_test_user_roles'][12];

		try {
			$this->create_controller()->approve();
			self::fail( 'The request was not ended after the failed check.' );
		} catch ( \MacMembers_Test_Request_Ended ) {
			// The controller ended the request itself after sending the error.
		}

		self::assertCount( 1, $GLOBALS['mac_members_test_ajax_responses'] );
		self::assertFalse( $GLOBALS['mac_members_test_ajax_responses'][0]['success'] );
		self::assertSame( $expected_code, $GLOBALS['mac_members_test_ajax_responses'][0]['data']['code'] );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
		self::assertSame( $roles_before, $GLOBALS['mac_members_test_user_roles'][12] );

		if ( 'busy' !== $gate ) {
			// Every other check runs before the lock row, the first write.
			self::assertSame( array(), $GLOBALS['wpdb']->queries );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_failed_gates(): array {
		return array(
			'invalid nonce'   => array( 'invalid nonce', 'invalid_request' ),
			'wrong action'    => array( 'wrong action', 'invalid_request' ),
			'no capability'   => array( 'no capability', 'permission_denied' ),
			'no user id'      => array( 'no user id', 'invalid_user' ),
			'unknown user'    => array( 'unknown user', 'invalid_user' ),
			'self action'     => array( 'self action', 'permission_denied' ),
			'elevated target' => array( 'elevated target', 'permission_denied' ),
			'missing role'    => array( 'missing role', 'missing_role' ),
			'not pending'     => array( 'not pending', 'status_changed' ),
			'no review cap'   => array( 'no review cap', 'permission_denied' ),
			'cannot promote'  => array( 'cannot promote', 'permission_denied' ),
			'unsafe roles'    => array( 'unsafe roles', 'invalid_role_settings' ),
			'not editable'    => array( 'not editable', 'permission_denied' ),
			'stale table'     => array( 'stale table', 'stale_table' ),
			'busy'            => array( 'busy', 'busy' ),
		);
	}

	public function test_success_response_ends_the_request_once(): void {
		$GLOBALS['mac_members_test_ajax_send_exits'] = false;

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		try {
			$this->create_controller()->approve();
			self::fail( 'The request was not ended after the success response.' );
		} catch ( \MacMembers_Test_Request_Ended ) {
			// The controller ended the request itself after sending the response.
		}

		self::assertCount( 1, $GLOBALS['mac_members_test_ajax_responses'] );
		self::assertTrue( $GLOBALS['mac_members_test_ajax_responses'][0]['success'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_nonce_is_checked_with_check_ajax_referer(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertSame(
			array(
				array(
					'action'    => FrontendAssets::NONCE_ACTION,
					'query_arg' => 'nonce',
					'stop'      => false,
				),
			),
			$GLOBALS['mac_members_test_ajax_referer_checks']
		);
	}

	#[DataProvider( 'provide_render_tokens_that_do_not_allow_the_action' )]
	public function test_request_without_a_matching_render_token_is_refused( string $case ): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID, $this->make_render_token( $case ) );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'stale_table', $response['data']['code'] );
		self::assertSame( 'This table is out of date. Please reload the page and try again.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_render_tokens_that_do_not_allow_the_action(): array {
		return array(
			'no token'                    => array( 'no token' ),
			'malformed token'             => array( 'malformed token' ),
			'table without this user'     => array( 'table without this user' ),
			'table rendered for another user' => array( 'table rendered for another user' ),
			'table from another session'  => array( 'table from another session' ),
			'user list changed'           => array( 'user list changed' ),
			'expired table'               => array( 'expired table' ),
		);
	}

	public function test_render_token_for_a_table_with_several_users_allows_each_of_them(): void {
		$token = ( new RenderToken() )->issue( array( 11, 12, 13 ) );

		foreach ( array( 11, 12, 13 ) as $user_id ) {
			$user = $this->store_user( $user_id, array( 'mac_members_pending' ) );
			$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user_id, $token );

			$response = $this->capture_ajax_response(
				fn (): mixed => $this->create_controller()->approve()
			);

			self::assertTrue( $response['success'], (string) $user_id );
			self::assertSame( array( 'mac_members_approved' ), $user->roles );
		}
	}

	public function test_member_locked_by_another_request_is_busy(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() + 30 ) . ':other-request';

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 409, $response['status'] );
		self::assertSame( 'busy', $response['data']['code'] );
		self::assertSame( 'This member is being updated in another request. Please wait a moment and reload the page.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
		self::assertSame( ( time() + 30 ) . ':other-request', $GLOBALS['wpdb']->rows['mac_members_lock_12'] );
	}

	public function test_expired_lock_does_not_block_the_action(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() - 1 ) . ':dead-request';

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_lock_is_released_after_success_and_after_a_failed_write(): void {
		$approved = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $approved->ID );

		self::assertTrue( $this->capture_ajax_response( fn (): mixed => $this->create_controller()->approve() )['success'] );
		self::assertSame( array(), $GLOBALS['wpdb']->rows );

		$failed = $this->store_user( 13, array( 'mac_members_pending' ), array(), true, array( 'blocked_roles' => array( 'mac_members_approved' ) ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $failed->ID );

		self::assertSame( 'role_update_failed', $this->capture_ajax_response( fn (): mixed => $this->create_controller()->approve() )['data']['code'] );
		self::assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_roles_are_read_again_after_the_lock_is_taken(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		// Another request approves the user after this one checked the roles and before it took the lock.
		$GLOBALS['mac_members_test_after_lock_insert'] = static function (): void {
			$GLOBALS['mac_members_test_user_roles'][12] = array( 'mac_members_approved' );
		};

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 409, $response['status'] );
		self::assertSame( 'status_changed', $response['data']['code'] );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
		self::assertSame( array( 'mac_members_approved' ), $GLOBALS['mac_members_test_user_roles'][12] );
		self::assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	/**
	 * @param array<int,string> $roles_before Roles before the change.
	 * @param array<int,string> $roles_after Expected roles after the change.
	 */
	#[DataProvider( 'provide_status_changes' )]
	public function test_status_change_moves_the_member_and_keeps_unrelated_roles( string $transition, array $roles_before, array $roles_after, string $message, string $status ): void {
		$user = $this->store_user( 12, $roles_before );
		$this->prepare_ajax_request( 'mac_members_' . $transition . '_user', $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->{$transition}()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( $message, $response['data']['message'] );
		self::assertSame( $status, $response['data']['status'] );
		self::assertSame( $roles_after, $user->roles );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>,2:array<int,string>,3:string,4:string}>
	 */
	public static function provide_status_changes(): array {
		return array(
			'approve a pending request'     => array( 'approve', array( 'subscriber', 'mac_members_pending' ), array( 'subscriber', 'mac_members_approved' ), 'Member approved successfully.', 'approved' ),
			'approve a denied request'      => array( 'approve', array( 'subscriber', 'mac_members_denied' ), array( 'subscriber', 'mac_members_approved' ), 'Member approved successfully.', 'approved' ),
			'deny a pending request'        => array( 'deny', array( 'subscriber', 'mac_members_pending' ), array( 'subscriber', 'mac_members_denied' ), 'Member denied successfully.', 'denied' ),
			'deactivate an approved member' => array( 'deactivate', array( 'subscriber', 'mac_members_approved' ), array( 'subscriber', 'mac_members_inactive' ), 'Member deactivated successfully.', 'inactive' ),
			'reactivate an inactive member' => array( 'reactivate', array( 'subscriber', 'mac_members_inactive' ), array( 'subscriber', 'mac_members_approved' ), 'Member reactivated successfully.', 'approved' ),
			'deactivate removes a stray pending role' => array( 'deactivate', array( 'mac_members_approved', 'mac_members_pending' ), array( 'mac_members_inactive' ), 'Member deactivated successfully.', 'inactive' ),
		);
	}

	/**
	 * @param array<int,string> $roles Roles the member has.
	 */
	#[DataProvider( 'provide_status_changes_that_do_not_apply' )]
	public function test_status_change_that_does_not_apply_to_the_current_status_is_refused( string $transition, array $roles ): void {
		$user = $this->store_user( 12, $roles );
		$this->prepare_ajax_request( 'mac_members_' . $transition . '_user', $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->{$transition}()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 409, $response['status'] );
		self::assertSame( 'status_changed', $response['data']['code'] );
		self::assertSame( 'This member\'s status has changed. Please reload the page.', $response['data']['message'] );
		self::assertSame( $roles, $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>}>
	 */
	public static function provide_status_changes_that_do_not_apply(): array {
		return array(
			'approve an approved member'   => array( 'approve', array( 'mac_members_approved' ) ),
			'approve an inactive member'   => array( 'approve', array( 'mac_members_inactive' ) ),
			'deny an approved member'      => array( 'deny', array( 'mac_members_approved' ) ),
			'deny a denied request'        => array( 'deny', array( 'mac_members_denied' ) ),
			'deny an inactive member'      => array( 'deny', array( 'mac_members_inactive' ) ),
			'deactivate a pending request' => array( 'deactivate', array( 'mac_members_pending' ) ),
			'deactivate an inactive one'   => array( 'deactivate', array( 'mac_members_inactive' ) ),
			'reactivate an approved one'   => array( 'reactivate', array( 'mac_members_approved' ) ),
			'reactivate a denied request'  => array( 'reactivate', array( 'mac_members_denied' ) ),
			'any change on a non-member'   => array( 'deactivate', array( 'subscriber' ) ),
		);
	}

	public function test_deactivate_refuses_an_inactive_role_with_sensitive_capabilities(): void {
		$GLOBALS['mac_members_test_roles']['mac_members_inactive']['capabilities']['edit_users'] = true;

		$user = $this->store_user( 12, array( 'mac_members_approved' ) );
		$this->prepare_ajax_request( FrontendAssets::DEACTIVATE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deactivate()
		);

		self::assertSame( 'invalid_role_settings', $response['data']['code'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_missing_inactive_role_blocks_deactivate_before_any_write(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_inactive'] );

		$user = $this->store_user( 12, array( 'mac_members_approved' ) );
		$this->prepare_ajax_request( FrontendAssets::DEACTIVATE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deactivate()
		);

		self::assertSame( 'missing_role', $response['data']['code'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
	}

	public function test_deactivate_and_reactivate_send_their_notifications(): void {
		$user = $this->store_user( 12, array( 'mac_members_approved' ) );
		$this->prepare_ajax_request( FrontendAssets::DEACTIVATE_ACTION, $user->ID );

		self::assertTrue( $this->capture_ajax_response( fn (): mixed => $this->create_controller( $this->create_notification_service() )->deactivate() )['success'] );
		self::assertSame(
			array( 'Your membership is no longer active', 'Member account deactivated' ),
			array_column( $GLOBALS['mac_members_test_mail'], 'subject' )
		);

		$GLOBALS['mac_members_test_mail'] = array();
		$this->prepare_ajax_request( FrontendAssets::REACTIVATE_ACTION, $user->ID );

		self::assertTrue( $this->capture_ajax_response( fn (): mixed => $this->create_controller( $this->create_notification_service() )->reactivate() )['success'] );
		self::assertSame(
			array( 'Your account has been approved', 'Member account approved' ),
			array_column( $GLOBALS['mac_members_test_mail'], 'subject' )
		);
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_second_action_on_the_same_member_finds_it_no_longer_pending(): void {
		$user  = $this->store_user( 12, array( 'mac_members_pending' ) );
		$token = ( new RenderToken() )->issue( array( 12 ) );

		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID, $token );
		self::assertTrue( $this->capture_ajax_response( fn (): mixed => $this->create_controller()->approve() )['success'] );

		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID, $token );
		$response = $this->capture_ajax_response( fn (): mixed => $this->create_controller()->deny() );

		self::assertSame( 'status_changed', $response['data']['code'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_capability_denial_returns_permission_error(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false;

		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'You do not have permission to perform this action.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_invalid_nonce_returns_invalid_request(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );

		$_POST    = array(
			'action'  => FrontendAssets::APPROVE_ACTION,
			'nonce'   => 'bad-nonce',
			'user_id' => (string) $user->ID,
		);
		$_REQUEST = $_POST;

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 400, $response['status'] );
		self::assertSame( 'Invalid request.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_invalid_user_returns_invalid_user_error(): void {
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, 999 );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 404, $response['status'] );
		self::assertSame( 'Invalid user.', $response['data']['message'] );
	}

	public function test_stale_pending_row_returns_409_error(): void {
		$user = $this->store_user( 12, array( 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 409, $response['status'] );
		self::assertSame( 'This member\'s status has changed. Please reload the page.', $response['data']['message'] );
		self::assertSame( 'status_changed', $response['data']['code'] );
	}

	public function test_self_action_is_blocked(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$GLOBALS['mac_members_test_current_user_id'] = 12;
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'You do not have permission to perform this action.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_elevated_target_is_blocked(): void {
		$user = $this->store_user(
			12,
			array( 'mac_members_pending' ),
			array(
				'manage_options' => true,
			)
		);
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'You do not have permission to perform this action.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_approve_preserves_extra_roles_while_removing_pending_and_adding_approved(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'Member approved successfully.', $response['data']['message'] );
		self::assertSame( array( 'subscriber', 'mac_members_approved' ), $user->roles );
	}

	public function test_deny_preserves_extra_roles_while_removing_pending_and_adding_denied(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deny()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'Member denied successfully.', $response['data']['message'] );
		self::assertSame( array( 'subscriber', 'mac_members_denied' ), $user->roles );
	}

	/**
	 * @param array<int,string> $roles_before Roles before the action.
	 * @param array<int,string> $roles_after Expected roles after the action.
	 */
	#[DataProvider( 'provide_users_with_outcome_roles' )]
	public function test_action_removes_the_other_outcome_role( string $action, array $roles_before, array $roles_after ): void {
		$user = $this->store_user( 12, $roles_before );
		$this->prepare_ajax_request( 'approve' === $action ? FrontendAssets::APPROVE_ACTION : FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => 'approve' === $action ? $this->create_controller()->approve() : $this->create_controller()->deny()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( $roles_after, $user->roles );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>,2:array<int,string>}>
	 */
	public static function provide_users_with_outcome_roles(): array {
		return array(
			'approve a user denied earlier'           => array( 'approve', array( 'mac_members_pending', 'mac_members_denied', 'subscriber' ), array( 'subscriber', 'mac_members_approved' ) ),
			'approve a user holding both outcomes'    => array( 'approve', array( 'mac_members_pending', 'mac_members_approved', 'mac_members_denied' ), array( 'mac_members_approved' ) ),
			'approve a user already holding approved' => array( 'approve', array( 'mac_members_pending', 'mac_members_approved' ), array( 'mac_members_approved' ) ),
			'deny a user approved earlier'            => array( 'deny', array( 'mac_members_pending', 'mac_members_approved', 'subscriber' ), array( 'subscriber', 'mac_members_denied' ) ),
			'deny a user holding both outcomes'       => array( 'deny', array( 'mac_members_pending', 'mac_members_approved', 'mac_members_denied' ), array( 'mac_members_denied' ) ),
			'deny a user already holding denied'      => array( 'deny', array( 'mac_members_pending', 'mac_members_denied' ), array( 'mac_members_denied' ) ),
		);
	}

	/**
	 * @param array<string,string> $stored_settings Stored role settings.
	 */
	#[DataProvider( 'provide_role_settings_that_are_not_allowed' )]
	public function test_role_settings_that_are_not_allowed_are_refused_before_any_write( array $stored_settings, string $action ): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = $stored_settings;

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( 'approve' === $action ? FrontendAssets::APPROVE_ACTION : FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => 'approve' === $action ? $this->create_controller()->approve() : $this->create_controller()->deny()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'invalid_role_settings', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function provide_role_settings_that_are_not_allowed(): array {
		return array(
			'approved and denied are the same role' => array(
				array(
					'approved_role' => 'mac_members_approved',
					'denied_role'   => 'mac_members_approved',
				),
				'deny',
			),
			'pending and approved are the same role' => array(
				array(
					'pending_role'  => 'mac_members_pending',
					'approved_role' => 'mac_members_pending',
				),
				'approve',
			),
			'approved role is an administrator role' => array(
				array( 'approved_role' => 'administrator' ),
				'approve',
			),
			'denied role is an administrator role' => array(
				array( 'denied_role' => 'administrator' ),
				'deny',
			),
		);
	}

	public function test_target_role_that_gained_a_sensitive_capability_after_saving_is_refused(): void {
		$GLOBALS['mac_members_test_roles']['mac_members_approved']['capabilities']['edit_users'] = true;

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertSame( 'invalid_role_settings', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_review_capability_is_required_in_addition_to_promote_users(): void {
		$GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false;

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertSame( 403, $response['status'] );
		self::assertSame( 'permission_denied', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_promote_user_is_checked_for_the_target_user(): void {
		$GLOBALS['mac_members_test_current_user_object_caps']['promote_user'][12] = false;

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->store_user( 13, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertSame( 403, $response['status'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );

		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, 13 );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
	}

	public function test_target_holding_any_sensitive_capability_is_blocked(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ), array( 'edit_users' => true ) );
		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deny()
		);

		self::assertSame( 403, $response['status'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	#[DataProvider( 'provide_roles_that_are_not_editable' )]
	public function test_roles_the_acting_user_cannot_assign_are_refused( string $role ): void {
		$this->remove_editable_role( $role );

		$user = $this->store_user( 12, array( 'mac_members_pending', 'mac_members_denied' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertSame( 403, $response['status'] );
		self::assertSame( 'permission_denied', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending', 'mac_members_denied' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_role_changes'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_roles_that_are_not_editable(): array {
		return array(
			'pending role'                => array( 'mac_members_pending' ),
			'approved role'               => array( 'mac_members_approved' ),
			'held denied role to remove' => array( 'mac_members_denied' ),
		);
	}

	public function test_other_outcome_role_need_not_be_editable_when_the_user_does_not_hold_it(): void {
		$this->remove_editable_role( 'mac_members_denied' );

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_missing_target_role_returns_error_before_any_write(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_approved'] );

		$user = $this->store_user( 12, array( 'mac_members_pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'missing_role', $response['data']['code'] );
		self::assertSame( 'A role needed for this action does not exist. Please review Settings > MAC Members.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending', 'subscriber' ), $user->roles );
		self::assertSame( array(), $GLOBALS['mac_members_test_cleaned_user_cache'] );
	}

	public function test_missing_pending_role_returns_error_before_any_write(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_pending'] );

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deny()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'missing_role', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_missing_other_outcome_role_does_not_block_the_action(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_denied'] );

		$user = $this->store_user( 12, array( 'mac_members_pending' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( array( 'mac_members_approved' ), $user->roles );
	}

	public function test_failed_approval_write_restores_the_original_roles(): void {
		$user = $this->store_user(
			12,
			array( 'mac_members_pending', 'subscriber' ),
			array(),
			true,
			array( 'blocked_roles' => array( 'mac_members_approved' ) )
		);
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'role_update_failed', $response['data']['code'] );
		self::assertEqualsCanonicalizing( array( 'mac_members_pending', 'subscriber' ), $user->roles );
		self::assertEqualsCanonicalizing( array( 'mac_members_pending', 'subscriber' ), $GLOBALS['mac_members_test_user_roles'][12] );
	}

	public function test_failed_denial_write_restores_the_removed_outcome_role_too(): void {
		$user = $this->store_user(
			12,
			array( 'mac_members_pending', 'mac_members_approved' ),
			array(),
			true,
			array( 'blocked_roles' => array( 'mac_members_denied' ) )
		);
		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deny()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 'role_update_failed', $response['data']['code'] );
		self::assertEqualsCanonicalizing( array( 'mac_members_pending', 'mac_members_approved' ), $GLOBALS['mac_members_test_user_roles'][12] );
	}

	public function test_role_change_is_checked_against_the_stored_roles(): void {
		$user = $this->store_user(
			12,
			array( 'mac_members_pending' ),
			array(),
			true,
			array( 'persist_roles' => false )
		);
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 'role_update_failed', $response['data']['code'] );
		self::assertSame( array( 'mac_members_pending' ), $GLOBALS['mac_members_test_user_roles'][12] );
		self::assertContains( 12, $GLOBALS['mac_members_test_cleaned_user_cache'] );
	}

	public function test_role_update_failure_returns_error(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending' ), array(), false );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'Unable to update user role.', $response['data']['message'] );
		self::assertSame( array( 'mac_members_pending' ), $user->roles );
	}

	public function test_approve_sends_enabled_notifications_after_role_update(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller( $this->create_notification_service() )->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( array( 'subscriber', 'mac_members_approved' ), $user->roles );
		self::assertSame(
			array( 'Your account has been approved', 'Member account approved' ),
			array_column( $GLOBALS['mac_members_test_mail'], 'subject' )
		);
	}

	public function test_email_failure_returns_warning_without_rolling_back_role_update(): void {
		$user = $this->store_user( 12, array( 'mac_members_pending', 'subscriber' ) );
		$GLOBALS['mac_members_test_mail_fail_next'] = 1;
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller( $this->create_notification_service() )->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( array( 'subscriber', 'mac_members_approved' ), $user->roles );
		self::assertTrue( $response['data']['warning'] );
		self::assertSame(
			'Member approved successfully. One or more notification emails could not be sent.',
			$response['data']['message']
		);
	}

	/**
	 * @param array<int,string> $roles Roles.
	 * @param array<string,bool> $caps Caps.
	 * @param array<string,mixed> $data Extra user data for the stub, such as blocked_roles or persist_roles.
	 */
	private function store_user( int $id, array $roles, array $caps = array(), bool $can_update_roles = true, array $data = array() ): \WP_User {
		$user = new \WP_User(
			array_merge(
				array(
					'ID'               => $id,
					'user_email'       => 'member' . $id . '@example.test',
					'user_login'       => 'member' . $id,
					'user_registered'  => '2026-05-01 12:00:00',
					'roles'            => $roles,
					'caps'             => $caps,
					'can_update_roles' => $can_update_roles,
				),
				$data
			)
		);

		$GLOBALS['mac_members_test_users_by_id'][ $id ] = $user;

		return $user;
	}

	private function arrange_failed_gate( string $gate ): void {
		switch ( $gate ) {
			case 'invalid nonce':
				$_POST['nonce'] = 'bad-nonce';
				break;
			case 'wrong action':
				$_POST['action'] = FrontendAssets::DENY_ACTION;
				break;
			case 'no capability':
				$GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false;
				break;
			case 'no user id':
				$_POST['user_id'] = '0';
				break;
			case 'unknown user':
				$_POST['user_id']      = '999';
				$_POST['render_token'] = ( new RenderToken() )->issue( array( 999 ) );
				break;
			case 'self action':
				$GLOBALS['mac_members_test_current_user_id'] = 12;
				// The table was rendered for this user.
				$_POST['render_token'] = ( new RenderToken() )->issue( array( 12 ) );
				break;
			case 'stale table':
				$_POST['render_token'] = ( new RenderToken() )->issue( array( 13 ) );
				break;
			case 'busy':
				$GLOBALS['wpdb']->rows['mac_members_lock_12'] = ( time() + 30 ) . ':other-request';
				break;
			case 'elevated target':
				$this->store_user( 12, array( 'mac_members_pending' ), array( 'manage_options' => true ) );
				break;
			case 'missing role':
				unset( $GLOBALS['mac_members_test_roles']['mac_members_approved'] );
				break;
			case 'not pending':
				$this->store_user( 12, array( 'subscriber' ) );
				break;
			case 'no review cap':
				$GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false;
				break;
			case 'cannot promote':
				$GLOBALS['mac_members_test_current_user_object_caps']['promote_user'][12] = false;
				break;
			case 'unsafe roles':
				$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'approved_role' => 'administrator' );
				break;
			case 'not editable':
				$this->remove_editable_role( 'mac_members_approved' );
				break;
			default:
				self::fail( 'Unknown gate: ' . $gate );
		}

		$_REQUEST = $_POST;
	}

	private function make_render_token( string $case ): string {
		switch ( $case ) {
			case 'no token':
				return '';
			case 'malformed token':
				return 'not-a-token';
			case 'table without this user':
				return ( new RenderToken() )->issue( array( 13, 14 ) );
			case 'table rendered for another user':
				$GLOBALS['mac_members_test_current_user_id'] = 2;
				$token = ( new RenderToken() )->issue( array( 12 ) );
				$GLOBALS['mac_members_test_current_user_id'] = 1;

				return $token;
			case 'table from another session':
				$GLOBALS['mac_members_test_session_token'] = 'session-two';
				$token = ( new RenderToken() )->issue( array( 12 ) );
				$GLOBALS['mac_members_test_session_token'] = 'session-one';

				return $token;
			case 'user list changed':
				$parts    = explode( '.', ( new RenderToken() )->issue( array( 13 ) ) );
				$parts[1] = '12';

				return implode( '.', $parts );
			case 'expired table':
				return ( new RenderToken( -1 ) )->issue( array( 12 ) );
		}

		self::fail( 'Unknown token case: ' . $case );
	}

	private function remove_editable_role( string $role ): void {
		add_filter(
			'editable_roles',
			static function ( array $roles ) use ( $role ): array {
				unset( $roles[ $role ] );

				return $roles;
			}
		);
	}

	private function prepare_ajax_request( string $action, int $user_id, ?string $render_token = null ): void {
		$_POST    = array(
			'action'       => $action,
			'nonce'        => wp_create_nonce( FrontendAssets::NONCE_ACTION ),
			'user_id'      => (string) $user_id,
			'render_token' => $render_token ?? ( new RenderToken() )->issue( array( $user_id ) ),
		);
		$_REQUEST = $_POST;
	}

	/**
	 * @param callable():mixed $callback Callback.
	 *
	 * @return array{success:bool,data:array<string,mixed>,status:int}
	 */
	private function capture_ajax_response( callable $callback ): array {
		try {
			$callback();
		} catch ( \MacMembers_Test_Ajax_Exit | \MacMembers_Test_Request_Ended ) {
			return $GLOBALS['mac_members_test_ajax_response'];
		}

		self::fail( 'Expected the request to end after the response.' );
	}

	private function create_controller( ?MemberNotificationService $notifications = null ): MemberActionController {
		return new MemberActionController(
			new WordPressSettingsRepository( new SettingsSchema() ),
			$notifications,
			end_request: static function (): never {
				throw new \MacMembers_Test_Request_Ended();
			}
		);
	}

	private function create_notification_service(): MemberNotificationService {
		return new MemberNotificationService(
			new WordPressSettingsRepository( new SettingsSchema() )
		);
	}
}
