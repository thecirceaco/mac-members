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
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

		self::assertArrayHasKey( 'wp_ajax_mac_members_approve_user', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayHasKey( 'wp_ajax_mac_members_deny_user', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayNotHasKey( 'wp_ajax_nopriv_mac_members_approve_user', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayNotHasKey( 'wp_ajax_nopriv_mac_members_deny_user', $GLOBALS['mac_members_test_actions'] );
	}

	public function test_capability_denial_returns_permission_error(): void {
		$user = $this->store_user( 12, array( 'member-pending' ) );
		$GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false;

		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'You do not have permission to perform this action.', $response['data']['message'] );
		self::assertSame( array( 'member-pending' ), $user->roles );
	}

	public function test_invalid_nonce_returns_invalid_request(): void {
		$user = $this->store_user( 12, array( 'member-pending' ) );

		$_POST = array(
			'action'  => FrontendAssets::APPROVE_ACTION,
			'nonce'   => 'bad-nonce',
			'user_id' => (string) $user->ID,
		);

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 400, $response['status'] );
		self::assertSame( 'Invalid request.', $response['data']['message'] );
		self::assertSame( array( 'member-pending' ), $user->roles );
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
		self::assertSame( 'This user is no longer pending.', $response['data']['message'] );
		self::assertSame( 'not_pending', $response['data']['code'] );
	}

	public function test_self_action_is_blocked(): void {
		$user = $this->store_user( 12, array( 'member-pending' ) );
		$GLOBALS['mac_members_test_current_user_id'] = 12;
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 403, $response['status'] );
		self::assertSame( 'You do not have permission to perform this action.', $response['data']['message'] );
		self::assertSame( array( 'member-pending' ), $user->roles );
	}

	public function test_elevated_target_is_blocked(): void {
		$user = $this->store_user(
			12,
			array( 'member-pending' ),
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
		self::assertSame( array( 'member-pending' ), $user->roles );
	}

	public function test_approve_preserves_extra_roles_while_removing_pending_and_adding_approved(): void {
		$user = $this->store_user( 12, array( 'member-pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'Member approved successfully.', $response['data']['message'] );
		self::assertSame( array( 'subscriber', 'member' ), $user->roles );
	}

	public function test_deny_preserves_extra_roles_while_removing_pending_and_adding_denied(): void {
		$user = $this->store_user( 12, array( 'member-pending', 'subscriber' ) );
		$this->prepare_ajax_request( FrontendAssets::DENY_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->deny()
		);

		self::assertTrue( $response['success'] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'Member denied successfully.', $response['data']['message'] );
		self::assertSame( array( 'subscriber', 'member-invalid' ), $user->roles );
	}

	public function test_role_update_failure_returns_error(): void {
		$user = $this->store_user( 12, array( 'member-pending' ), array(), false );
		$this->prepare_ajax_request( FrontendAssets::APPROVE_ACTION, $user->ID );

		$response = $this->capture_ajax_response(
			fn (): mixed => $this->create_controller()->approve()
		);

		self::assertFalse( $response['success'] );
		self::assertSame( 500, $response['status'] );
		self::assertSame( 'Unable to update user role.', $response['data']['message'] );
		self::assertSame( array( 'member-pending' ), $user->roles );
	}

	/**
	 * @param array<int,string> $roles Roles.
	 * @param array<string,bool> $caps Caps.
	 */
	private function store_user( int $id, array $roles, array $caps = array(), bool $can_update_roles = true ): \WP_User {
		$user = new \WP_User(
			array(
				'ID'               => $id,
				'user_email'       => 'member' . $id . '@example.test',
				'user_login'       => 'member' . $id,
				'user_registered'  => '2026-05-01 12:00:00',
				'roles'            => $roles,
				'caps'             => $caps,
				'can_update_roles' => $can_update_roles,
			)
		);

		$GLOBALS['mac_members_test_users_by_id'][ $id ] = $user;

		return $user;
	}

	private function prepare_ajax_request( string $action, int $user_id ): void {
		$_POST = array(
			'action'  => $action,
			'nonce'   => wp_create_nonce( FrontendAssets::NONCE_ACTION ),
			'user_id' => (string) $user_id,
		);
	}

	/**
	 * @param callable():mixed $callback Callback.
	 *
	 * @return array{success:bool,data:array<string,mixed>,status:int}
	 */
	private function capture_ajax_response( callable $callback ): array {
		try {
			$callback();
		} catch ( \MacMembers_Test_Ajax_Exit ) {
			return $GLOBALS['mac_members_test_ajax_response'];
		}

		self::fail( 'Expected wp_send_json_* to stop execution.' );
	}

	private function create_controller(): MemberActionController {
		return new MemberActionController(
			new WordPressSettingsRepository( new SettingsSchema() )
		);
	}
}
