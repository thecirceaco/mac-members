<?php
/**
 * Authenticated member status actions: approve, deny, deactivate and reactivate.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Actions;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Contracts\Service;
use MacMembers\Email\MemberNotificationService;
use MacMembers\Email\NotificationResult;
use MacMembers\Members\MembersQuery;
use MacMembers\Members\MemberStatus;
use MacMembers\Members\MemberTransition;
use MacMembers\Members\RenderToken;
use MacMembers\Security\Capabilities;
use MacMembers\Settings\SettingsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MemberActionController implements Service
{
	private const ERROR_INVALID_REQUEST = 'invalid_request';
	private const ERROR_INVALID_USER = 'invalid_user';
	private const ERROR_PERMISSION = 'permission_denied';
	private const ERROR_STATUS_CHANGED = 'status_changed';
	private const ERROR_UPDATE_FAILED = 'role_update_failed';
	private const ERROR_MISSING_ROLE = 'missing_role';
	private const ERROR_UNSAFE_ROLE = 'unsafe_role';
	private const ERROR_STALE_TABLE = 'stale_table';
	private const ERROR_BUSY = 'busy';

	/**
	 * @param \Closure|null $end_request Runs before the request exits, after a response was sent. Tests pass a
	 *                                   closure that throws, to see where the request ended. The request exits
	 *                                   even when the closure returns.
	 */
	public function __construct(
		private readonly SettingsRepositoryInterface $settings,
		private readonly ?MemberNotificationService $notifications = null,
		private readonly RenderToken $render_token = new RenderToken(),
		private readonly MemberLock $lock = new MemberLock(),
		private readonly ?\Closure $end_request = null
	) {}

	public function register(): void
	{
		foreach ( MemberTransition::cases() as $transition ) {
			\add_action( 'wp_ajax_' . $transition->ajax_action(), array( $this, $transition->value ) );
		}
	}

	public function approve(): void
	{
		$this->handle( MemberTransition::Approve );
	}

	public function deny(): void
	{
		$this->handle( MemberTransition::Deny );
	}

	public function deactivate(): void
	{
		$this->handle( MemberTransition::Deactivate );
	}

	public function reactivate(): void
	{
		$this->handle( MemberTransition::Reactivate );
	}

	/**
	 * Every failed check sends an error and returns. send_error() also ends the request itself, so a
	 * response function that does not exit can never let a failed check reach the role change.
	 */
	private function handle( MemberTransition $transition ): void
	{
		if ( ! $this->is_valid_request( $transition->ajax_action() ) ) {
			$this->send_error( self::ERROR_INVALID_REQUEST, 400 );
			return;
		}

		if ( ! Capabilities::current_user_can_review() ) {
			$this->send_error( self::ERROR_PERMISSION, 403 );
			return;
		}

		$user_id = $this->get_posted_user_id();

		if ( 1 > $user_id ) {
			$this->send_error( self::ERROR_INVALID_USER, 404 );
			return;
		}

		// Only rows of a table the plugin rendered for this user and session can be acted on.
		if ( ! $this->render_token->allows( $this->get_post_string( 'render_token' ), $user_id ) ) {
			$this->send_error( self::ERROR_STALE_TABLE, 403 );
			return;
		}

		$user = \get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			$this->send_error( self::ERROR_INVALID_USER, 404 );
			return;
		}

		if ( ! $this->can_act_on( $user ) ) {
			$this->send_error( self::ERROR_PERMISSION, 403 );
			return;
		}

		$status_roles = $this->get_status_roles();
		$target_role  = $status_roles[ $transition->to_status()->value ];
		$from_roles   = $this->get_from_roles( $transition, $status_roles );

		// Check the roles before any write, so a missing role cannot leave the user half-changed.
		if ( ! $this->role_exists( $target_role ) || ! $this->any_role_exists( $from_roles ) ) {
			$this->send_error( self::ERROR_MISSING_ROLE, 500 );
			return;
		}

		// A role editor can give the member roles more capabilities after the plugin created them.
		if ( array() !== Capabilities::sensitive_capabilities_of_role( $target_role ) ) {
			$this->send_error( self::ERROR_UNSAFE_ROLE, 500 );
			return;
		}

		if ( ! $this->user_has_any_role( $user, $from_roles ) ) {
			$this->send_error( self::ERROR_STATUS_CHANGED, 409 );
			return;
		}

		if ( ! $this->can_assign_roles( $user, $status_roles, $target_role ) ) {
			$this->send_error( self::ERROR_PERMISSION, 403 );
			return;
		}

		// Only one status change can change this user at a time.
		if ( ! $this->lock->acquire( $user_id ) ) {
			$this->send_error( self::ERROR_BUSY, 409 );
			return;
		}

		try {
			// Read the user again under the lock: another request may have changed the roles since the checks above.
			$user    = $this->read_user( $user_id );
			$allowed = $user instanceof \WP_User && $this->user_has_any_role( $user, $from_roles );
			$changed = $allowed && $this->change_roles( $user, $status_roles, $target_role );
		} finally {
			$this->lock->release( $user_id );
		}

		if ( ! $allowed ) {
			$this->send_error( self::ERROR_STATUS_CHANGED, 409 );
			return;
		}

		if ( ! $changed ) {
			$this->send_error( self::ERROR_UPDATE_FAILED, 500 );
			return;
		}

		$this->send_success(
			$transition,
			$this->send_notifications( $transition, $user )
		);
	}

	private function is_valid_request( string $expected_action ): bool
	{
		return $expected_action === $this->get_post_string( 'action' )
			&& false !== \check_ajax_referer( FrontendAssets::NONCE_ACTION, 'nonce', false );
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

	/**
	 * @return array<string,string> The role of each status, keyed by status value.
	 */
	private function get_status_roles(): array
	{
		$roles = array();

		foreach ( MemberStatus::cases() as $status ) {
			$roles[ $status->value ] = $status->role();
		}

		return $roles;
	}

	/**
	 * @param array<string,string> $status_roles The role of each status.
	 *
	 * @return array<int,string> Roles of the statuses a member must have for this change.
	 */
	private function get_from_roles( MemberTransition $transition, array $status_roles ): array
	{
		return array_map(
			static fn ( MemberStatus $status ): string => $status_roles[ $status->value ],
			$transition->from_statuses()
		);
	}

	private function role_exists( string $role ): bool
	{
		return '' !== $role && \wp_roles()->is_role( $role );
	}

	/**
	 * @param array<int,string> $roles Roles.
	 */
	private function any_role_exists( array $roles ): bool
	{
		foreach ( $roles as $role ) {
			if ( $this->role_exists( $role ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The acting user may not change their own roles, those of a user who holds an administrative capability, or
	 * those of a user with a role the members table hides. The render token already leaves hidden users out; this
	 * checks again, because the hidden roles can change after the table was rendered.
	 */
	private function can_act_on( \WP_User $user ): bool
	{
		return $user->ID !== \get_current_user_id()
			&& ! Capabilities::user_has_administrative_capability( $user )
			&& array() === array_intersect( ( new MembersQuery( $this->settings ) )->get_hidden_roles(), (array) $user->roles );
	}

	/**
	 * The acting user must be allowed to assign every role this change adds or removes, as in wp-admin.
	 *
	 * @param array<string,string> $status_roles The role of each status.
	 */
	private function can_assign_roles( \WP_User $user, array $status_roles, string $target_role ): bool
	{
		if ( ! \function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$roles          = array_merge( array( $target_role ), $this->get_roles_to_remove( $user, $status_roles, $target_role ) );
		$editable_roles = \get_editable_roles();

		foreach ( $roles as $role ) {
			if ( ! isset( $editable_roles[ $role ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The statuses exclude each other, so every other status role the user holds is removed. A user who went
	 * back to pending must not keep the outcome of an earlier review.
	 *
	 * @param array<string,string> $status_roles The role of each status.
	 *
	 * @return array<int,string>
	 */
	private function get_roles_to_remove( \WP_User $user, array $status_roles, string $target_role ): array
	{
		$roles = array();

		foreach ( $status_roles as $role ) {
			if ( $role !== $target_role && $this->user_has_role( $user, $role ) ) {
				$roles[] = $role;
			}
		}

		return $roles;
	}

	/**
	 * @param array<int,string> $roles Roles.
	 */
	private function user_has_any_role( \WP_User $user, array $roles ): bool
	{
		foreach ( $roles as $role ) {
			if ( $this->user_has_role( $user, $role ) ) {
				return true;
			}
		}

		return false;
	}

	private function user_has_role( \WP_User $user, string $role ): bool
	{
		return '' !== $role && in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Gives the user the target role and removes every other status role. When the stored roles do not match
	 * the expected result, the roles the user had before are restored and false is returned.
	 *
	 * @param array<string,string> $status_roles The role of each status.
	 */
	private function change_roles( \WP_User $user, array $status_roles, string $target_role ): bool
	{
		$original_roles = $this->get_roles( $user );

		foreach ( $this->get_roles_to_remove( $user, $status_roles, $target_role ) as $role ) {
			$user->remove_role( $role );
		}

		$user->add_role( $target_role );

		// Check what was stored, not the object in memory: add_role() and remove_role() do not report failed writes.
		$stored = $this->read_user( $user->ID );

		if ( ! $stored instanceof \WP_User ) {
			return false;
		}

		if ( $this->user_has_role( $stored, $target_role ) && array() === $this->get_roles_to_remove( $stored, $status_roles, $target_role ) ) {
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

	private function send_notifications( MemberTransition $transition, \WP_User $user ): ?NotificationResult
	{
		if ( ! $this->notifications instanceof MemberNotificationService ) {
			return null;
		}

		return match ( $transition ) {
			MemberTransition::Approve, MemberTransition::Reactivate => $this->notifications->send_approval_notifications( $user ),
			MemberTransition::Deny       => $this->notifications->send_denial_notifications( $user ),
			MemberTransition::Deactivate => $this->notifications->send_deactivation_notifications( $user ),
		};
	}

	private function send_success( MemberTransition $transition, ?NotificationResult $notification_result = null ): never
	{
		$response_message = match ( $transition ) {
			MemberTransition::Approve    => __( 'Member approved successfully.', 'mac-members' ),
			MemberTransition::Deny       => __( 'Member denied successfully.', 'mac-members' ),
			MemberTransition::Deactivate => __( 'Member deactivated successfully.', 'mac-members' ),
			MemberTransition::Reactivate => __( 'Member reactivated successfully.', 'mac-members' ),
		};

		$response = array(
			'message'      => $response_message,
			'status'       => $transition->to_status()->value,
			'status_label' => $transition->to_status()->label(),
		);

		if ( $notification_result instanceof NotificationResult && $notification_result->has_failures() ) {
			$response['warning'] = true;
			$response['message'] = $response_message . ' ' . $notification_result->get_warning_message();
		}

		\wp_send_json_success(
			$response,
			200
		);

		$this->end_request();
	}

	private function send_error( string $code, int $status_code ): never
	{
		\wp_send_json_error(
			array(
				'message' => $this->get_error_message( $code ),
				'code'    => $code,
			),
			$status_code
		);

		$this->end_request();
	}

	/**
	 * Ends the request after a response was sent. wp_send_json_*() normally ends it through wp_die(),
	 * but a wp_die handler can return, so the request is ended here as well.
	 */
	private function end_request(): never
	{
		if ( null !== $this->end_request ) {
			( $this->end_request )();
		}

		exit;
	}

	private function get_error_message( string $code ): string
	{
		return match ( $code ) {
			self::ERROR_INVALID_USER => __( 'Invalid user.', 'mac-members' ),
			self::ERROR_PERMISSION => __( 'You do not have permission to perform this action.', 'mac-members' ),
			self::ERROR_STATUS_CHANGED => __( 'This member\'s status has changed. Please reload the page.', 'mac-members' ),
			self::ERROR_UPDATE_FAILED => __( 'Unable to update user role.', 'mac-members' ),
			self::ERROR_MISSING_ROLE => __( 'A member role this change needs does not exist. Deactivate and activate MAC Members to create it again.', 'mac-members' ),
			self::ERROR_UNSAFE_ROLE => __( 'The member role this change adds grants administrative capabilities. Remove them from the role and try again.', 'mac-members' ),
			self::ERROR_STALE_TABLE => __( 'This table is out of date. Please reload the page and try again.', 'mac-members' ),
			self::ERROR_BUSY => __( 'This member is being updated in another request. Please wait a moment and reload the page.', 'mac-members' ),
			default => __( 'Invalid request.', 'mac-members' ),
		};
	}
}
