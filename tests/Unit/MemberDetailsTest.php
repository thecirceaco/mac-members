<?php
/**
 * Tests for the extra fields of the member details.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Members\MemberDetails;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MemberDetails::class )]
final class MemberDetailsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_fields_keep_their_order_and_labels_and_read_a_label_from_the_key(): void {
		self::assertSame(
			array(
				array( 'key' => 'phone', 'label' => 'Phone' ),
				array( 'key' => 'local_number', 'label' => 'Local number' ),
				array( 'key' => 'user_url', 'label' => 'Website' ),
				array( 'key' => 'shop-name', 'label' => 'Shop name' ),
			),
			$this->create_details( "phone : Phone\nlocal_number\nuser_url : Website, shop-name" )->get_fields()
		);
	}

	public function test_keys_that_could_leak_secrets_never_show(): void {
		self::assertSame(
			array( array( 'key' => 'phone', 'label' => 'Phone' ) ),
			$this->create_details( "user_pass\nuser_activation_key\nsession_tokens\nwp_capabilities\nwp_user_level\n_acf_ref\nphone" )->get_fields()
		);
	}

	public function test_values_come_from_user_meta_or_the_user_itself_as_text(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User(
				array(
					'ID'           => 7,
					'user_url'     => 'https://example.org',
					'phone'        => ' 555 0100 ',
					'committees'   => array( 'Safety', 'Training' ),
					'volunteer'    => true,
				)
			),
		);

		self::assertSame(
			array(
				array( 'label' => 'Website', 'value' => 'https://example.org' ),
				array( 'label' => 'Phone', 'value' => '555 0100' ),
				array( 'label' => 'Committees', 'value' => 'Safety, Training' ),
				array( 'label' => 'Volunteer', 'value' => 'Yes' ),
				array( 'label' => 'Missing', 'value' => '' ),
			),
			$this->create_details( "user_url : Website\nphone : Phone\ncommittees\nvolunteer\nmissing" )->get_values( $GLOBALS['mac_members_test_users'][0] )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_acf_values_are_formatted_by_acf_and_labels_come_from_acf(): void {
		// Stand-ins for ACF's functions: a date field with its label, and a select field that returns both value and label.
		eval(
			'function acf_get_field( $key ) { return "joined" === $key ? array( "label" => "Joined the union" ) : false; }
			function get_field( $key, $post_id ) {
				return array( "joined" => "September 28, 2026", "local" => array( "value" => "350", "label" => "Local 350" ) )[ $key ] ?? null;
			}'
		);

		$GLOBALS['mac_members_test_users'] = array( new \WP_User( array( 'ID' => 7 ) ) );

		self::assertSame(
			array(
				array( 'label' => 'Joined the union', 'value' => 'September 28, 2026' ),
				array( 'label' => 'Local', 'value' => 'Local 350' ),
			),
			$this->create_details( "joined\nlocal" )->get_values( $GLOBALS['mac_members_test_users'][0] )
		);
	}

	private function create_details( string $fields ): MemberDetails {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'detail_fields' => $fields );

		return new MemberDetails( new WordPressSettingsRepository( new SettingsSchema() ) );
	}
}
