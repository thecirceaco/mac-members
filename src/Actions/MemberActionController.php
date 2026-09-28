<?php
/**
 * Authenticated member approval actions.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Actions;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\Email\MemberNotificationService;
use MacMembers\Email\NotificationResult;
use MacMembers\Settings\SettingsRepositoryInterface;

final class MemberActionController implements Service
{
	private const ERROR_INVALID_REQUEST = 'invalid_request';
	private const ERROR_INVALID_USER = 'invalid_user';
	private const ERROR_PERMISSION = 'permission_denied';
	private const ERROR_NOT_PENDING = 'not_pending';
	private const ERROR_UPDATE_FAILED = 'role_update_failed';
	private const ERROR_MISSING_ROLE = 'missing_role';
	private const SUCCESS_APPROVED = 'approved';
	private const SUCCESS_DENIED = 'denied';

	public function __construct(
		private readonly SettingsRepositoryInterface $settings,
		private readonly ?MemberNotificationService $notifications = null
	) {}

	public function register(): void
	{
		\add_action( 'wp_ajax_' . FrontendAssets::APPROVE_ACTION, array( $this, 'approve' ) );
		\add_action( 'wp_ajax_' . FrontendAssets::DENY_ACTION, array( $this, 'deny' ) );
	}

	public function approve(): void
	{
		$this->handle( FrontendAssets::APPROVE_ACTION, 'approved_role', 'denied_role', self::SUCCESS_APPROVED );
	}

	public function deny(): void
	{
		$this->handle( FrontendAssets::DENY_ACTION, 'denied_role', 'approved_role', self::SUCCESS_DENIED );
	}

	private function handle( string $expected_action, string $target_role_setting, string $other_role_setting, string $success_code ): void
	{
		if ( ! $this->is_valid_request( $expected_action ) ) {
			$this->send_error( self::ERROR_INVALID_REQUEST, 400 );
		}

		if ( ! \current_user_can( 'promote_users' ) ) {
			$this->send_error( self::ERROR_PERMISSION, 403 );
		}

		$user_id = $this->get_posted_user_id();

		if ( 1 > $user_id ) {
			$this->send_error( self::ERROR_INVALID_USER, 404 );
		}

		$user = \get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			$this->send_error( self::ERROR_INVALID_USER, 404 );
		}

		if ( $user_id === \get_current_user_id() || $this->is_elevated_target( $user ) ) {
			$this->send_error( self::ERROR_PERMISSION, 403 );
		}

		$pending_role = (string) $this->settings->get( 'pending_role', 'member-pending' );
		$target_role  = (string) $this->settings->get( $target_role_setting, '' );
		// Approve and deny exclude each other: a user who went back to pending must not keep an earlier outcome.
		$other_role = (string) $this->settings->get( $other_role_setting, '' );

		if ( $other_role === $target_role ) {
			// Never remove the role that is being added.
			$other_role = '';
		}

		// Check the roles before any write, so a missing role cannot leave the user half-changed.
		if ( ! $this->settings->role_exists( $pending_role ) || ! $this->settings->role_exists( $target_role ) ) {
			$this->send_error( self::ERROR_MISSING_ROLE, 500 );
		}

		if ( ! $this->user_has_role( $user, $pending_role ) ) {
			$this->send_error( self::ERROR_NOT_PENDING, 409 );
		}

		if ( ! $this->change_roles( $user, $pending_role, $target_role, $other_role ) ) {
			$this->send_error( self::ERROR_UPDATE_FAILED, 500 );
		}

		$this->send_success(
			$success_code,
			$this->send_notifications( $success_code, $user )
		);
	}

	private function is_valid_request( string $expected_action ): bool
	{
		return $expected_action === $this->get_post_string( 'action' )
			&& false !== \wp_verify_nonce( $this->get_post_string( 'nonce' ), FrontendAssets::NONCE_ACTION );
	}

	private function get_posted_user_id(): int
	{
		return \absint( $this->get_post_string( 'user_id' ) );
	}

	private function get_post_string( string $key ): string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request nonce is validated before any role mutation in is_valid_request().
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request nonce is validated before any role mutation in is_valid_request().
		return \sanitize_text_field( \wp_unslash( $_POST[ $key ] ) );
	}

	private function is_elevated_target( \WP_User $user ): bool
	{
		return \user_can( $user, 'promote_users' ) || \user_can( $user, 'manage_options' );
	}

	private function user_has_role( \WP_User $user, string $role ): bool
	{
		return '' !== $role && in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Moves the user from the pending role to the target role. When the stored roles do not match the
	 * expected result, the roles the user had before are restored and false is returned.
	 */
	private function change_roles( \WP_User $user, string $pending_role, string $target_role, string $other_role ): bool
	{
		$original_roles = $this->get_roles( $user );

		$user->remove_role( $pending_role );

		if ( '' !== $other_role ) {
			$user->remove_role( $other_role );
		}

		$user->add_role( $target_role );

		// Check what was stored, not the object in memory: add_role() and remove_role() do not report failed writes.
		$stored = $this->read_user( $user->ID );

		if ( ! $stored instanceof \WP_User ) {
			return false;
		}

		if (
			$this->user_has_role( $stored, $target_role )
			&& ! $this->user_has_role( $stored, $pending_role )
			&& ! $this->user_has_role( $stored, $other_role )
		) {
			return true;
		}

		$this->restore_roles( $stored, $original_roles );

		return false;
	}

	/**
	 * @param array<int,string> $original_roles Roles the user had before the change.
	 */
	private function restore_roles( \WP_User $user, array $original_roles ): void
	{
		$current_roles = $this->get_roles( $user );

		foreach ( array_diff( $current_roles, $original_roles ) as $role ) {
			$user->remove_role( $role );
		}

		foreach ( array_diff( $original_roles, $current_roles ) as $role ) {
			$user->add_role( $role );
		}
	}

	/**
	 * Reads the user again, bypassing the object cache.
	 */
	private function read_user( int $user_id ): ?\WP_User
	{
		\clean_user_cache( $user_id );
		\wp_cache_delete( $user_id, 'user_meta' );

		$user = \get_user_by( 'id', $user_id );

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * @return array<int,string>
	 */
	private function get_roles( \WP_User $user ): array
	{
		return array_values( array_map( 'strval', (array) $user->roles ) );
	}

	private function send_notifications( string $success_code, \WP_User $user ): ?NotificationResult
	{
		if ( ! $this->notifications instanceof MemberNotificationService ) {
			return null;
		}

		return self::SUCCESS_APPROVED === $success_code
			? $this->notifications->send_approval_notifications( $user )
			: $this->notifications->send_denial_notifications( $user );
	}

	private function send_success( string $message, ?NotificationResult $notification_result = null ): void
	{
		$response_message = self::SUCCESS_APPROVED === $message
			? __( 'Member approved successfully.', 'mac-members' )
			: __( 'Member denied successfully.', 'mac-members' );

		$response = array(
			'message' => $response_message,
		);

		if ( $notification_result instanceof NotificationResult && $notification_result->has_failures() ) {
			$response['warning'] = true;
			$response['message'] = $response_message . ' ' . $notification_result->get_warning_message();
		}

		\wp_send_json_success(
			$response,
			200
		);
	}

	private function send_error( string $code, int $status_code ): void
	{
		\wp_send_json_error(
			array(
				'message' => $this->get_error_message( $code ),
				'code'    => $code,
			),
			$status_code
		);
	}

	private function get_error_message( string $code ): string
	{
		return match ( $code ) {
			self::ERROR_INVALID_USER => __( 'Invalid user.', 'mac-members' ),
			self::ERROR_PERMISSION => __( 'You do not have permission to perform this action.', 'mac-members' ),
			self::ERROR_NOT_PENDING => __( 'This user is no longer pending.', 'mac-members' ),
			self::ERROR_UPDATE_FAILED => __( 'Unable to update user role.', 'mac-members' ),
			self::ERROR_MISSING_ROLE => __( 'A role needed for this action does not exist. Please review Settings > MAC Members.', 'mac-members' ),
			default => __( 'Invalid request.', 'mac-members' ),
		};
	}
}
