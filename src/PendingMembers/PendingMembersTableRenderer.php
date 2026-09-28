<?php
/**
 * Pending members table renderer.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\PendingMembers;

final class PendingMembersTableRenderer
{
	/**
	 * @param array<int,object> $users Pending users.
	 * @param array<int,string> $missing_roles Configured role slugs that do not exist.
	 * @param string            $render_token Token that approve and deny requests from this table must send.
	 */
	public function render( array $users, array $missing_roles = array(), string $render_token = '' ): string
	{
		$rows = $this->render_rows( $users );

		$output  = '<div class="mac-members-pending" data-mac-members-pending-table data-mac-members-render-token="' . esc_attr( $render_token ) . '">';
		$output .= $this->render_missing_roles_warning( $missing_roles );
		$output .= '<div class="mac-members-notices" aria-live="polite" aria-atomic="true"></div>';

		if ( '' === $rows ) {
			$output .= '<p class="mac-members-empty">' . esc_html__( 'There are no pending members.', 'mac-members' ) . '</p>';
			$output .= '</div>';

			return $output;
		}

		$output .= '<p class="mac-members-empty" hidden>' . esc_html__( 'There are no pending members.', 'mac-members' ) . '</p>';
		$output .= '<div class="mac-members-table-wrap">';
		$output .= '<table class="mac-members-pending-table">';
		$output .= '<thead><tr>';
		$output .= '<th scope="col">' . esc_html__( 'Email', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'First Name', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Last Name', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Username', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'User ID', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Registered Date', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Profile', 'mac-members' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Actions', 'mac-members' ) . '</th>';
		$output .= '</tr></thead>';
		$output .= '<tbody>' . $rows . '</tbody>';
		$output .= '</table>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * @param array<int,object> $users Pending users.
	 */
	private function render_rows( array $users ): string
	{
		$rows = '';

		foreach ( $users as $user ) {
			$id = $this->get_user_id( $user );

			if ( 1 > $id ) {
				continue;
			}

			$rows .= '<tr class="mac-members-pending-table__row" data-mac-members-user-id="' . esc_attr( (string) $id ) . '">';
			$rows .= '<td>' . esc_html( $this->get_user_value( $user, 'user_email' ) ) . '</td>';
			$rows .= '<td>' . esc_html( $this->get_user_value( $user, 'first_name' ) ) . '</td>';
			$rows .= '<td>' . esc_html( $this->get_user_value( $user, 'last_name' ) ) . '</td>';
			$rows .= '<td>' . esc_html( $this->get_user_value( $user, 'user_login' ) ) . '</td>';
			$rows .= '<td>' . esc_html( (string) $id ) . '</td>';
			$rows .= '<td>' . esc_html( $this->format_registered_date( $this->get_user_value( $user, 'user_registered' ) ) ) . '</td>';
			$rows .= '<td><a class="mac-members-profile-link" href="' . esc_url( $this->get_profile_url( $id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View profile', 'mac-members' ) . '</a></td>';
			$rows .= '<td class="mac-members-actions">';
			$rows .= $this->render_action_button( 'approve', __( 'Approve', 'mac-members' ), $id );
			$rows .= $this->render_action_button( 'deny', __( 'Deny', 'mac-members' ), $id );
			$rows .= '</td>';
			$rows .= '</tr>';
		}

		return $rows;
	}

	/**
	 * @param array<int,string> $missing_roles Configured role slugs that do not exist.
	 */
	private function render_missing_roles_warning( array $missing_roles ): string
	{
		if ( array() === $missing_roles ) {
			return '';
		}

		$message = sprintf(
			/* translators: %s: comma-separated list of role slugs. */
			__( 'MAC Members: One or more configured roles do not exist (%s). Approve and deny are blocked for any action that needs a missing role. Please review Settings > MAC Members.', 'mac-members' ),
			implode( ', ', $missing_roles )
		);

		return '<p class="mac-members-notice mac-members-notice--warning" role="alert">' . esc_html( $message ) . '</p>';
	}

	private function render_action_button( string $action, string $label, int $user_id ): string
	{
		return '<button type="button" class="mac-members-button mac-members-button--' . esc_attr( $action ) . '" data-mac-members-action="' . esc_attr( $action ) . '" data-mac-members-user-id="' . esc_attr( (string) $user_id ) . '">' . esc_html( $label ) . '</button>';
	}

	private function get_user_id( object $user ): int
	{
		return isset( $user->ID ) ? \absint( $user->ID ) : 0;
	}

	private function get_user_value( object $user, string $key ): string
	{
		if ( method_exists( $user, 'get' ) ) {
			$value = $user->get( $key );

			if ( null !== $value && '' !== $value ) {
				return (string) $value;
			}
		}

		return isset( $user->{$key} ) ? (string) $user->{$key} : '';
	}

	private function get_profile_url( int $user_id ): string
	{
		return \admin_url( 'user-edit.php?user_id=' . $user_id );
	}

	private function format_registered_date( string $registered_date ): string
	{
		$timestamp = strtotime( $registered_date );

		if ( false === $timestamp ) {
			return $registered_date;
		}

		return \wp_date( (string) \get_option( 'date_format', 'F j, Y' ), $timestamp );
	}
}
