<?php
/**
 * Members table renderer.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MembersTableRenderer
{
	/**
	 * @param array<int,array{user:object,status:?MemberStatus}> $rows Members on this page and their status.
	 * @param string                                                 $view Shown view: a status value or "all".
	 * @param array<int,array{view:string,label:string,count:int,url:string,current:bool}> $filters Status filters; empty hides them.
	 * @param array{page?:int,pages?:int,previous_url?:string,next_url?:string} $pagination Pagination.
	 * @param array<int,string>                                      $missing_roles Configured role slugs that do not exist.
	 * @param string                                                 $render_token Token that status changes from this table must send.
	 * @param array{action?:string,hidden?:array<string,string>,roles?:array<string,string>,role?:string,search?:string} $search_form Role and search form; empty hides it.
	 */
	public function render(
		array $rows,
		string $view = 'pending',
		array $filters = array(),
		array $pagination = array(),
		array $missing_roles = array(),
		string $render_token = '',
		array $search_form = array()
	): string {
		$body     = $this->render_rows( $rows );
		$narrowed = '' !== ( $search_form['role'] ?? '' ) || '' !== ( $search_form['search'] ?? '' );

		$output  = '<div class="mac-members-table" data-mac-members-table data-mac-members-view="' . esc_attr( $view ) . '" data-mac-members-render-token="' . esc_attr( $render_token ) . '">';
		$output .= $this->render_missing_roles_warning( $missing_roles );
		$output .= $this->render_filters( $filters );
		$output .= $this->render_search_form( $search_form );
		$output .= '<div class="mac-members-notices" aria-live="polite" aria-atomic="true"></div>';
		$output .= '<p class="mac-members-empty"' . ( '' === $body ? '' : ' hidden' ) . '>' . esc_html( $this->get_empty_text( $view, $narrowed ) ) . '</p>';

		if ( '' !== $body ) {
			$output .= '<div class="mac-members-table-wrap">';
			$output .= '<table class="mac-members-list">';
			$output .= '<thead><tr>';
			$output .= '<th scope="col">' . esc_html__( 'User ID', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Email', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'First Name', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Last Name', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Username', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Registered', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Profile', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Status', 'mac-members' ) . '</th>';
			$output .= '<th scope="col">' . esc_html__( 'Actions', 'mac-members' ) . '</th>';
			$output .= '</tr></thead>';
			$output .= '<tbody>' . $body . '</tbody>';
			$output .= '</table>';
			$output .= '</div>';
		}

		$output .= $this->render_pagination( $pagination );
		$output .= '</div>';

		return $output;
	}

	/**
	 * Text shown when the view has no members. The script shows it too, after the last row goes.
	 *
	 * @param bool $narrowed Whether a role or a search narrows the view.
	 */
	public function get_empty_text( string $view, bool $narrowed = false ): string
	{
		if ( $narrowed ) {
			return __( 'No members match these filters.', 'mac-members' );
		}

		return match ( $view ) {
			'approved' => __( 'There are no approved members.', 'mac-members' ),
			'inactive' => __( 'There are no inactive members.', 'mac-members' ),
			'denied'   => __( 'There are no denied members.', 'mac-members' ),
			'all'      => __( 'There are no members yet.', 'mac-members' ),
			default    => __( 'There are no pending members.', 'mac-members' ),
		};
	}

	/**
	 * @param array<int,array{user:object,status:?MemberStatus}> $rows Members and their status.
	 */
	private function render_rows( array $rows ): string
	{
		$output = '';

		foreach ( $rows as $row ) {
			$user   = $row['user'];
			$status = $row['status'];
			$id     = $this->get_user_id( $user );

			if ( 1 > $id || ! $status instanceof MemberStatus ) {
				continue;
			}

			$output .= '<tr class="mac-members-list__row" data-mac-members-user-id="' . esc_attr( (string) $id ) . '" data-mac-members-status="' . esc_attr( $status->value ) . '">';
			$output .= '<td>' . esc_html( (string) $id ) . '</td>';
			$output .= '<td>' . esc_html( $this->get_user_value( $user, 'user_email' ) ) . '</td>';
			$output .= '<td>' . esc_html( $this->get_user_value( $user, 'first_name' ) ) . '</td>';
			$output .= '<td>' . esc_html( $this->get_user_value( $user, 'last_name' ) ) . '</td>';
			$output .= '<td>' . esc_html( $this->get_user_value( $user, 'user_login' ) ) . '</td>';
			$output .= '<td>' . esc_html( $this->format_registered_date( $this->get_user_value( $user, 'user_registered' ) ) ) . '</td>';
			$output .= '<td><a class="mac-members-profile-link" href="' . esc_url( $this->get_profile_url( $id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View profile', 'mac-members' ) . '</a></td>';
			$output .= '<td class="mac-members-status"><span class="mac-members-status__label mac-members-status__label--' . esc_attr( $status->value ) . '">' . esc_html( $status->label() ) . '</span></td>';
			$output .= '<td class="mac-members-actions">';

			foreach ( MemberTransition::available_for( $status ) as $transition ) {
				$output .= $this->render_action_button( $transition, $id );
			}

			$output .= '</td>';
			$output .= '</tr>';
		}

		return $output;
	}

	/**
	 * @param array<int,array{view:string,label:string,count:int,url:string,current:bool}> $filters Status filters.
	 */
	private function render_filters( array $filters ): string
	{
		if ( array() === $filters ) {
			return '';
		}

		$output = '<nav class="mac-members-filters" aria-label="' . esc_attr__( 'Member status', 'mac-members' ) . '"><ul>';

		foreach ( $filters as $filter ) {
			$current = ! empty( $filter['current'] );

			// The current filter is a solid neutral ACSS button, the others outline buttons.
			$output .= '<li>';
			$output .= '<a class="mac-members-filter btn--neutral' . ( $current ? ' is-current' : ' btn--outline' ) . ' btn--s" href="' . esc_url( $filter['url'] ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>';
			$output .= esc_html( $filter['label'] ) . ' ';
			$output .= '<span class="mac-members-count" data-mac-members-count-for="' . esc_attr( $filter['view'] ) . '">' . esc_html( (string) (int) $filter['count'] ) . '</span>';
			$output .= '</a>';
			$output .= '</li>';
		}

		$output .= '</ul></nav>';

		return $output;
	}

	/**
	 * A GET form without buttons: the role filter, when members hold other roles, and the search. Enter in the
	 * search sends the form, and the script sends it when the role changes.
	 *
	 * @param array{action?:string,hidden?:array<string,string>,roles?:array<string,string>,role?:string,search?:string} $form Role and search form.
	 */
	private function render_search_form( array $form ): string
	{
		if ( array() === $form ) {
			return '';
		}

		$roles  = $form['roles'] ?? array();
		$role   = (string) ( $form['role'] ?? '' );
		$search = (string) ( $form['search'] ?? '' );
		$output = '<form class="mac-members-search" method="get" action="' . esc_url( (string) ( $form['action'] ?? '' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filter members', 'mac-members' ) . '">';

		foreach ( $form['hidden'] ?? array() as $name => $value ) {
			$output .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}

		if ( array() !== $roles ) {
			$output .= '<select class="mac-members-search__role" name="' . esc_attr( MembersTableShortcode::ROLE_QUERY_ARG ) . '" aria-label="' . esc_attr__( 'Role', 'mac-members' ) . '" data-mac-members-role-filter>';
			$output .= '<option value="">' . esc_html__( 'All roles', 'mac-members' ) . '</option>';

			foreach ( $roles as $slug => $name ) {
				$output .= '<option value="' . esc_attr( (string) $slug ) . '"' . ( (string) $slug === $role ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
			}

			$output .= '</select>';
		}

		$output .= '<input class="mac-members-search__input" type="search" name="' . esc_attr( MembersTableShortcode::SEARCH_QUERY_ARG ) . '" value="' . esc_attr( $search ) . '" maxlength="' . esc_attr( (string) MembersQuery::SEARCH_MAX_LENGTH ) . '" placeholder="' . esc_attr__( 'Search by name, email or username', 'mac-members' ) . '" aria-label="' . esc_attr__( 'Search members', 'mac-members' ) . '">';
		$output .= '</form>';

		return $output;
	}

	/**
	 * @param array{page?:int,pages?:int,previous_url?:string,next_url?:string} $pagination Pagination.
	 */
	private function render_pagination( array $pagination ): string
	{
		$pages = (int) ( $pagination['pages'] ?? 1 );

		if ( 2 > $pages ) {
			return '';
		}

		$page   = (int) ( $pagination['page'] ?? 1 );
		$output = '<nav class="mac-members-pagination" aria-label="' . esc_attr__( 'Members table pages', 'mac-members' ) . '">';

		if ( '' !== ( $pagination['previous_url'] ?? '' ) ) {
			$output .= '<a class="mac-members-page-link btn--neutral btn--outline btn--s" href="' . esc_url( $pagination['previous_url'] ) . '">' . esc_html__( 'Previous', 'mac-members' ) . '</a>';
		}

		$output .= '<span class="mac-members-page-count">' . esc_html(
			sprintf(
				/* translators: 1: current page number, 2: number of pages. */
				__( 'Page %1$d of %2$d', 'mac-members' ),
				$page,
				$pages
			)
		) . '</span>';

		if ( '' !== ( $pagination['next_url'] ?? '' ) ) {
			$output .= '<a class="mac-members-page-link btn--neutral btn--outline btn--s" href="' . esc_url( $pagination['next_url'] ) . '">' . esc_html__( 'Next', 'mac-members' ) . '</a>';
		}

		$output .= '</nav>';

		return $output;
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
			__( 'MAC Members: One or more configured roles do not exist (%s). Status changes that need a missing role are blocked. Please review the MAC Members settings.', 'mac-members' ),
			implode( ', ', $missing_roles )
		);

		return '<p class="mac-members-notice mac-members-notice--warning" role="alert">' . esc_html( $message ) . '</p>';
	}

	private function render_action_button( MemberTransition $transition, int $user_id ): string
	{
		return '<button type="button" class="mac-members-button mac-members-button--' . esc_attr( $transition->value ) . ' ' . esc_attr( $transition->button_classes() ) . '" data-mac-members-action="' . esc_attr( $transition->value ) . '" data-mac-members-user-id="' . esc_attr( (string) $user_id ) . '">' . esc_html( $transition->label() ) . '</button>';
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
