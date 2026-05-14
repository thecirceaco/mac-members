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
use MacMembers\Settings\SettingsRepositoryInterface;

final class MemberActionController implements Service
{
	private const ERROR_INVALID_REQUEST = 'invalid_request';
	private const ERROR_INVALID_USER = 'invalid_user';
	private const ERROR_PERMISSION = 'permission_denied';
	private const ERROR_NOT_PENDING = 'not_pending';
	private const ERROR_UPDATE_FAILED = 'role_update_failed';
	private const SUCCESS_APPROVED = 'approved';
	private const SUCCESS_DENIED = 'denied';

	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	public function register(): void
	{
		\add_action( 'wp_ajax_' . FrontendAssets::APPROVE_ACTION, array( $this, 'approve' ) );
		\add_action( 'wp_ajax_' . FrontendAssets::DENY_ACTION, array( $this, 'deny' ) );
	}

	public function approve(): void
	{
		$this->handle( FrontendAssets::APPROVE_ACTION, 'approved_role', self::SUCCESS_APPROVED );
	}

	public function deny(): void
	{
		$this->handle( FrontendAssets::DENY_ACTION, 'denied_role', self::SUCCESS_DENIED );
	}

	private function handle( string $expected_action, string $target_role_setting, string $success_code ): void
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

		if ( ! $this->user_has_role( $user, $pending_role ) ) {
			$this->send_error( self::ERROR_NOT_PENDING, 409 );
		}

		$user->remove_role( $pending_role );
		$user->add_role( $target_role );

		if ( $this->user_has_role( $user, $pending_role ) || ! $this->user_has_role( $user, $target_role ) ) {
			$this->send_error( self::ERROR_UPDATE_FAILED, 500 );
		}

		$this->send_success( $success_code );
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

	private function send_success( string $message ): void
	{
		$response_message = self::SUCCESS_APPROVED === $message
			? __( 'Member approved successfully.', 'mac-members' )
			: __( 'Member denied successfully.', 'mac-members' );

		\wp_send_json_success(
			array(
				'message' => $response_message,
			),
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
			default => __( 'Invalid request.', 'mac-members' ),
		};
	}
}
