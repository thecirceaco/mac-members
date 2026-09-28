<?php
/**
 * Tests for hardcoded member notification emails.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Email\MemberNotificationService;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;

#[CoversClass( MemberNotificationService::class )]
final class MemberNotificationServiceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_member_approval_email_replaces_placeholders_and_escapes_html(): void {
		$GLOBALS['mac_members_test_bloginfo']['name'] = 'Example <Site>';

		$user = $this->create_user(
			array(
				'first_name'   => 'Mia & "Co" <Admin>',
				'last_name'    => 'O\'Connor',
				'display_name' => 'Mia Admin',
			)
		);

		$result = $this->create_service()->send_approval_notifications( $user );

		self::assertFalse( $result->has_failures() );
		self::assertCount( 2, $GLOBALS['mac_members_test_mail'] );

		$mail = $GLOBALS['mac_members_test_mail'][0];

		self::assertSame( 'pending@example.test', $mail['to'] );
		self::assertSame( 'Your account has been approved', $mail['subject'] );
		// Tags are removed from the applicant's value, and what is left is still escaped.
		self::assertStringContainsString( 'Hi Mia &amp; &quot;Co&quot;,', $mail['message'] );
		self::assertStringContainsString( 'Your account has been approved and your membership is now active.', $mail['message'] );
		// The site's home page, not wp-login.php: many sites use their own login pages.
		self::assertStringContainsString( 'https://example.test/', $mail['message'] );
		self::assertStringNotContainsString( 'wp-login.php', $mail['message'] );
		self::assertStringContainsString( 'Best,<br>', $mail['message'] );
		self::assertStringContainsString( 'Example &lt;Site&gt;', $mail['message'] );
		self::assertStringNotContainsString( '<Admin>', $mail['message'] );
		self::assertStringNotContainsString( '&lt;Admin&gt;', $mail['message'] );
	}

	public function test_applicant_values_cannot_add_lines_to_an_email(): void {
		$user = $this->create_user(
			array( 'first_name' => "Mia\r\n\r\nYour payment failed. Pay at https://pay.example.test\nThanks" )
		);

		$this->create_service()->send_approval_notifications( $user );

		$message = $GLOBALS['mac_members_test_mail'][0]['message'];

		self::assertStringContainsString( 'Hi Mia Your payment failed. Pay at https://pay.example.test Thanks,<br>', $message );
		self::assertSame( substr_count( $this->render_plain_member_approval(), '<br>' ), substr_count( $message, '<br>' ) );
		self::assertStringNotContainsString( "\r", $message );
	}

	public function test_line_breaks_are_removed_even_if_a_sanitize_filter_returns_them(): void {
		add_filter(
			'sanitize_text_field',
			static fn ( string $filtered ): string => str_replace( 'Mia', "Mia\r\n\r\nExtra line", $filtered )
		);

		$this->create_service()->send_approval_notifications( $this->create_user() );

		self::assertStringContainsString( 'Hi Mia Extra line,<br>', $GLOBALS['mac_members_test_mail'][0]['message'] );
		self::assertStringNotContainsString( "\r", $GLOBALS['mac_members_test_mail'][0]['message'] );
	}

	public function test_admin_email_cleans_every_applicant_value(): void {
		$user = $this->create_user(
			array(
				'ID'         => 42,
				'first_name' => "Mia\nMember",
				'last_name'  => "<b>Bold</b>\r\nLast",
				'user_login' => "mia\tmember",
			)
		);

		$this->create_service()->send_denial_notifications( $user );

		$message = $GLOBALS['mac_members_test_mail'][1]['message'];

		self::assertStringContainsString( "User ID: 42<br>\nFirst Name: Mia Member<br>\nLast Name: Bold Last<br>\nUsername: mia member<br>\nEmail Address: pending@example.test<br>\nProfile: ", $message );
	}

	public function test_applicant_values_are_capped_at_100_characters(): void {
		$user = $this->create_user(
			array(
				'first_name' => str_repeat( 'a', 300 ),
				'last_name'  => str_repeat( 'é', 150 ),
			)
		);

		$this->create_service()->send_approval_notifications( $user );

		self::assertStringContainsString( 'Hi ' . str_repeat( 'a', 100 ) . ',', $GLOBALS['mac_members_test_mail'][0]['message'] );
		self::assertStringNotContainsString( str_repeat( 'a', 101 ), $GLOBALS['mac_members_test_mail'][0]['message'] );
		self::assertStringContainsString( 'Last Name: ' . str_repeat( 'é', 100 ) . '<br>', $GLOBALS['mac_members_test_mail'][1]['message'] );
	}

	public function test_admin_approval_email_contains_member_fields_and_profile_url(): void {
		$user = $this->create_user(
			array(
				'ID'           => 42,
				'first_name'   => 'Mia',
				'last_name'    => 'Member',
				'display_name' => 'Mia Member',
				'user_login'   => 'miamember',
			)
		);

		$this->create_service()->send_approval_notifications( $user );

		$mail = $GLOBALS['mac_members_test_mail'][1];

		self::assertSame( 'admin@example.test', $mail['to'] );
		self::assertSame( 'Member account approved', $mail['subject'] );
		self::assertStringContainsString( 'A member account has been approved on Example Site.', $mail['message'] );
		self::assertStringContainsString( 'First Name: Mia', $mail['message'] );
		self::assertStringContainsString( 'Last Name: Member', $mail['message'] );
		self::assertStringContainsString( 'Username: miamember', $mail['message'] );
		self::assertStringContainsString( 'Email Address: pending@example.test', $mail['message'] );
		self::assertStringContainsString( 'User ID: 42', $mail['message'] );
		self::assertStringContainsString( 'Profile: https://example.test/wp-admin/user-edit.php?user_id=42', $mail['message'] );
	}

	public function test_headers_use_sanitized_from_and_reply_to_routing(): void {
		$repository = $this->create_repository();
		$repository->save(
			array(
				'admin_notification_email' => 'notify@example.test',
				'from_email'               => 'from@example.test',
				'send_member_approval_email' => '1',
				'send_admin_approval_email' => '1',
				'send_member_denial_email' => '1',
				'send_admin_denial_email' => '1',
			)
		);

		$service = new MemberNotificationService( $repository );
		$service->send_approval_notifications( $this->create_user() );

		self::assertSame(
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: Example Site <from@example.test>',
				'Reply-To: notify@example.test',
			),
			$GLOBALS['mac_members_test_mail'][0]['headers']
		);
		self::assertSame(
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: Example Site <from@example.test>',
				'Reply-To: pending@example.test',
			),
			$GLOBALS['mac_members_test_mail'][1]['headers']
		);
	}

	public function test_toggle_settings_control_all_four_notifications(): void {
		$repository = $this->create_repository();
		$repository->save(
			array(
				'send_member_approval_email' => '0',
				'send_admin_approval_email'  => '1',
				'send_member_denial_email'   => '1',
				'send_admin_denial_email'    => '0',
			)
		);

		$service = new MemberNotificationService( $repository );
		$user    = $this->create_user();

		$service->send_approval_notifications( $user );
		self::assertCount( 1, $GLOBALS['mac_members_test_mail'] );
		self::assertSame( 'Member account approved', $GLOBALS['mac_members_test_mail'][0]['subject'] );

		$GLOBALS['mac_members_test_mail'] = array();

		$service->send_denial_notifications( $user );
		self::assertCount( 1, $GLOBALS['mac_members_test_mail'] );
		self::assertSame( 'Your account request was not approved', $GLOBALS['mac_members_test_mail'][0]['subject'] );
	}

	public function test_deactivation_emails_go_to_the_member_and_the_admin(): void {
		$this->create_service()->send_deactivation_notifications( $this->create_user() );

		self::assertCount( 2, $GLOBALS['mac_members_test_mail'] );

		$member = $GLOBALS['mac_members_test_mail'][0];
		$admin  = $GLOBALS['mac_members_test_mail'][1];

		self::assertSame( 'pending@example.test', $member['to'] );
		self::assertSame( 'Your membership is no longer active', $member['subject'] );
		self::assertStringContainsString( 'Hi Mia,', $member['message'] );
		self::assertStringContainsString( 'Your membership on Example Site is no longer active.', $member['message'] );
		self::assertStringContainsString( "<br>\nhttps://example.test/<br>", $member['message'] );
		self::assertSame( 'admin@example.test', $admin['to'] );
		self::assertSame( 'Member account deactivated', $admin['subject'] );
		self::assertStringContainsString( 'A member account has been deactivated on Example Site.', $admin['message'] );
		self::assertStringContainsString( 'user-edit.php?user_id=12', $admin['message'] );
		self::assertContains( 'Reply-To: pending@example.test', $admin['headers'] );
	}

	public function test_denial_email_links_to_the_site(): void {
		$this->create_service()->send_denial_notifications( $this->create_user() );

		$member = $GLOBALS['mac_members_test_mail'][0];

		self::assertSame( 'Your account request was not approved', $member['subject'] );
		self::assertStringContainsString( "<br>\nhttps://example.test/<br>", $member['message'] );
		self::assertStringStartsWith( "Hi,<br>\n<br>\nA pending member account has been denied on Example Site.<br>\n<br>\nUser ID: 12<br>", $GLOBALS['mac_members_test_mail'][1]['message'] );
	}

	public function test_toggle_settings_control_the_deactivation_notifications(): void {
		$repository = $this->create_repository();
		$repository->save(
			array(
				'send_member_deactivation_email' => '0',
				'send_admin_deactivation_email'  => '1',
			)
		);

		( new MemberNotificationService( $repository ) )->send_deactivation_notifications( $this->create_user() );

		self::assertSame( array( 'Member account deactivated' ), array_column( $GLOBALS['mac_members_test_mail'], 'subject' ) );
	}

	public function test_invalid_admin_and_from_settings_fall_back_to_site_admin_email(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array(
			'admin_notification_email' => "notify@example.test\r\nBcc: leak@example.test",
			'from_email'               => 'not-an-email',
		);

		$this->create_service()->send_approval_notifications( $this->create_user() );

		self::assertSame( 'admin@example.test', $GLOBALS['mac_members_test_mail'][1]['to'] );
		self::assertContains( 'From: Example Site <admin@example.test>', $GLOBALS['mac_members_test_mail'][0]['headers'] );
		self::assertContains( 'Reply-To: admin@example.test', $GLOBALS['mac_members_test_mail'][0]['headers'] );
	}

	public function test_email_failure_result_reports_warning_without_stopping_later_notifications(): void {
		$GLOBALS['mac_members_test_mail_fail_next'] = 1;

		$result = $this->create_service()->send_approval_notifications( $this->create_user() );

		self::assertTrue( $result->has_failures() );
		self::assertSame( 1, $result->get_failed_count() );
		self::assertSame( 'One or more notification emails could not be sent.', $result->get_warning_message() );
		self::assertCount( 2, $GLOBALS['mac_members_test_mail'] );
	}

	/**
	 * @param array<string,mixed> $overrides Overrides.
	 */
	private function create_user( array $overrides = array() ): \WP_User {
		return new \WP_User(
			array_merge(
				array(
					'ID'              => 12,
					'user_email'      => 'pending@example.test',
					'user_login'      => 'pending-user',
					'user_registered' => '2026-05-01 12:00:00',
					'first_name'      => 'Mia',
					'last_name'       => 'Member',
					'display_name'    => 'Mia Member',
				),
				$overrides
			)
		);
	}

	private function render_plain_member_approval(): string {
		$GLOBALS['mac_members_test_mail'] = array();

		$this->create_service()->send_approval_notifications( $this->create_user() );

		$message                          = $GLOBALS['mac_members_test_mail'][0]['message'];
		$GLOBALS['mac_members_test_mail'] = array();

		return $message;
	}

	private function create_service(): MemberNotificationService {
		return new MemberNotificationService( $this->create_repository() );
	}

	private function create_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
