<?php
/**
 * Hardcoded member notification emails.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Email;

use MacMembers\Settings\SettingsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MemberNotificationService
{
	private const BODY_MEMBER_APPROVAL = "Hi {first_name},\n\nYour account has been approved and your membership is now active. You can now log in and access your account.\n\n{login_url}\n\nBest,\n{site_name}";
	private const BODY_MEMBER_DENIAL = "Hi {first_name},\n\nYour account request has been reviewed and was not approved at this time.\n\nIf you believe this was a mistake, please contact us for assistance.\n\nBest,\n{site_name}";
	private const BODY_MEMBER_DEACTIVATION = "Hi {first_name},\n\nYour membership on {site_name} is no longer active.\n\nIf you believe this was a mistake, please contact us for assistance.\n\nBest,\n{site_name}";
	private const BODY_ADMIN_APPROVAL = "Hi,\n\nA member account has been approved on {site_name}.\n\nFirst Name: {first_name}\nLast Name: {last_name}\nUsername: {username}\nEmail Address: {email}\nUser ID: {user_id}\nProfile: {profile_url}\n\nBest,\n{site_name}";
	private const BODY_ADMIN_DENIAL = "Hi,\n\nA pending member account has been denied on {site_name}.\n\nFirst Name: {first_name}\nLast Name: {last_name}\nUsername: {username}\nEmail Address: {email}\nUser ID: {user_id}\nProfile: {profile_url}\n\nBest,\n{site_name}";
	private const BODY_ADMIN_DEACTIVATION = "Hi,\n\nA member account has been deactivated on {site_name}.\n\nFirst Name: {first_name}\nLast Name: {last_name}\nUsername: {username}\nEmail Address: {email}\nUser ID: {user_id}\nProfile: {profile_url}\n\nBest,\n{site_name}";

	/**
	 * Longest value, in characters, that an applicant can put into an email through a placeholder.
	 */
	private const MAX_USER_VALUE_LENGTH = 100;

	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	public function send_approval_notifications( \WP_User $user ): NotificationResult
	{
		return $this->send_notifications(
			$user,
			array(
				array(
					'toggle'     => 'send_member_approval_email',
					'to'         => $this->get_user_email( $user ),
					'subject'    => __( 'Your account has been approved', 'mac-members' ),
					'body'       => self::BODY_MEMBER_APPROVAL,
					'reply_to'   => $this->get_admin_email(),
					'log_action' => 'member_approval_email',
				),
				array(
					'toggle'     => 'send_admin_approval_email',
					'to'         => $this->get_admin_email(),
					'subject'    => __( 'Member account approved', 'mac-members' ),
					'body'       => self::BODY_ADMIN_APPROVAL,
					'reply_to'   => $this->get_user_email( $user ),
					'log_action' => 'admin_approval_email',
				),
			)
		);
	}

	public function send_denial_notifications( \WP_User $user ): NotificationResult
	{
		return $this->send_notifications(
			$user,
			array(
				array(
					'toggle'     => 'send_member_denial_email',
					'to'         => $this->get_user_email( $user ),
					'subject'    => __( 'Your account request was not approved', 'mac-members' ),
					'body'       => self::BODY_MEMBER_DENIAL,
					'reply_to'   => $this->get_admin_email(),
					'log_action' => 'member_denial_email',
				),
				array(
					'toggle'     => 'send_admin_denial_email',
					'to'         => $this->get_admin_email(),
					'subject'    => __( 'Member account denied', 'mac-members' ),
					'body'       => self::BODY_ADMIN_DENIAL,
					'reply_to'   => $this->get_user_email( $user ),
					'log_action' => 'admin_denial_email',
				),
			)
		);
	}

	public function send_deactivation_notifications( \WP_User $user ): NotificationResult
	{
		return $this->send_notifications(
			$user,
			array(
				array(
					'toggle'     => 'send_member_deactivation_email',
					'to'         => $this->get_user_email( $user ),
					'subject'    => __( 'Your membership is no longer active', 'mac-members' ),
					'body'       => self::BODY_MEMBER_DEACTIVATION,
					'reply_to'   => $this->get_admin_email(),
					'log_action' => 'member_deactivation_email',
				),
				array(
					'toggle'     => 'send_admin_deactivation_email',
					'to'         => $this->get_admin_email(),
					'subject'    => __( 'Member account deactivated', 'mac-members' ),
					'body'       => self::BODY_ADMIN_DEACTIVATION,
					'reply_to'   => $this->get_user_email( $user ),
					'log_action' => 'admin_deactivation_email',
				),
			)
		);
	}

	/**
	 * @param array<int,array<string,string>> $messages Messages.
	 */
	private function send_notifications( \WP_User $user, array $messages ): NotificationResult
	{
		$sent_count   = 0;
		$failed_count = 0;

		foreach ( $messages as $message ) {
			if ( true !== (bool) $this->settings->get( $message['toggle'], true ) ) {
				continue;
			}

			$sent = \wp_mail(
				$message['to'],
				$message['subject'],
				$this->render_body( $message['body'], $user ),
				$this->build_headers( $message['reply_to'] )
			);

			if ( $sent ) {
				++$sent_count;
				continue;
			}

			++$failed_count;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- MAC-591 requires lightweight action/user ID logging for notification failures.
			\error_log( 'MAC Members email failed: action=' . $message['log_action'] . ' user_id=' . $this->get_user_id( $user ) );
		}

		return new NotificationResult( $sent_count, $failed_count );
	}

	/**
	 * @return array<int,string>
	 */
	private function build_headers( string $reply_to ): array
	{
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->sanitize_header_name( $this->settings->get_from_name() ) . ' <' . $this->get_from_email() . '>',
			'Reply-To: ' . $this->sanitize_header_email( $reply_to ),
		);

		return $headers;
	}

	private function render_body( string $template, \WP_User $user ): string
	{
		$body = strtr( $template, $this->get_placeholders( $user ) );

		return nl2br( esc_html( $body ), false );
	}

	/**
	 * @return array<string,string>
	 */
	private function get_placeholders( \WP_User $user ): array
	{
		$user_id = $this->get_user_id( $user );

		return array(
			'{site_name}'    => $this->get_site_name(),
			'{first_name}'   => $this->clean_user_value( $this->get_user_value( $user, 'first_name' ) ),
			'{last_name}'    => $this->clean_user_value( $this->get_user_value( $user, 'last_name' ) ),
			'{display_name}' => $this->clean_user_value( $this->get_user_value( $user, 'display_name' ) ),
			'{username}'     => $this->clean_user_value( $this->get_user_value( $user, 'user_login' ) ),
			'{email}'        => $this->clean_user_value( $this->get_user_email( $user ) ),
			'{user_id}'      => (string) $user_id,
			'{login_url}'    => \wp_login_url(),
			'{profile_url}'  => \admin_url( 'user-edit.php?user_id=' . $user_id ),
		);
	}

	/**
	 * Cleans a value the applicant controls before it goes into a template: no tags, no line breaks, no more
	 * than MAX_USER_VALUE_LENGTH characters. The rendered body is still escaped as a whole.
	 */
	private function clean_user_value( string $value ): string
	{
		$value = \sanitize_text_field( $value );
		// After sanitize_text_field(), so a filter on it cannot bring line breaks back.
		$value = (string) preg_replace( '/[\r\n]+/', ' ', $value );

		return trim( mb_substr( $value, 0, self::MAX_USER_VALUE_LENGTH ) );
	}

	private function get_admin_email(): string
	{
		return $this->sanitize_header_email( (string) $this->settings->get( 'admin_notification_email', '' ) );
	}

	private function get_site_name(): string
	{
		return (string) \get_bloginfo( 'name' );
	}

	private function get_from_email(): string
	{
		return $this->sanitize_header_email( (string) $this->settings->get( 'from_email', '' ) );
	}

	private function get_user_email( \WP_User $user ): string
	{
		return $this->sanitize_header_email( (string) $user->user_email );
	}

	private function sanitize_header_email( string $email ): string
	{
		if ( str_contains( $email, "\r" ) || str_contains( $email, "\n" ) ) {
			return '';
		}

		$email = \sanitize_email( $email );

		return \is_email( $email ) ? $email : '';
	}

	private function sanitize_header_name( string $name ): string
	{
		$name = \sanitize_text_field( $name );
		$name = str_replace( array( "\r", "\n", '<', '>' ), '', $name );

		return '' !== $name ? $name : __( 'Website', 'mac-members' );
	}

	private function get_user_id( \WP_User $user ): int
	{
		return \absint( $user->ID );
	}

	private function get_user_value( \WP_User $user, string $key ): string
	{
		if ( 'display_name' === $key && '' !== $user->display_name ) {
			return $user->display_name;
		}

		if ( isset( $user->{$key} ) && '' !== $user->{$key} ) {
			return (string) $user->{$key};
		}

		$value = $user->get( $key );

		return null !== $value ? (string) $value : '';
	}
}
