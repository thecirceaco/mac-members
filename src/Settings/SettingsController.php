<?php
/**
 * Settings admin controller.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

use MacMembers\Contracts\Service;

final class SettingsController implements Service
{
	public const NONCE_ACTION = 'mac_members_save_settings';
	public const NONCE_NAME   = 'mac_members_settings_nonce';

	private const ACTION_SAVE_SETTINGS = 'save_settings';

	public function __construct(
		private readonly SettingsRepositoryInterface $settings,
		private readonly SettingsSchema $schema
	) {}

	public function register(): void
	{
		\register_activation_hook( MAC_MEMBERS_PLUGIN_FILE, array( $this, 'activate' ) );
		\add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		\add_action( 'admin_init', array( $this, 'handle_save' ) );
		\add_action( 'admin_notices', array( $this, 'render_missing_roles_warning' ) );
	}

	public function activate(): void
	{
		$this->settings->ensure_defaults();
	}

	public function register_settings_page(): void
	{
		\add_options_page(
			__( 'MAC Members', 'mac-members' ),
			__( 'MAC Members', 'mac-members' ),
			'manage_options',
			MAC_MEMBERS_ADMIN_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function handle_save(): void
	{
		if ( ! $this->is_settings_save_request() ) {
			return;
		}

		if ( ! \current_user_can( 'manage_options' ) ) {
			$this->add_settings_error(
				'settings_permission_denied',
				__( 'You do not have permission to manage MAC Members settings.', 'mac-members' )
			);
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		if ( ! \wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->add_settings_error(
				'settings_nonce_failed',
				__( 'MAC Members settings could not be saved. Please try again.', 'mac-members' )
			);
			return;
		}

		$submitted = isset( $_POST['mac_members_settings'] ) && \is_array( $_POST['mac_members_settings'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings are unslashed here and sanitized by schema in WordPressSettingsRepository.
			? \wp_unslash( $_POST['mac_members_settings'] )
			: array();

		$role_errors = $this->settings->validate_roles( $submitted );

		if ( array() !== $role_errors ) {
			foreach ( $role_errors as $code => $message ) {
				$this->add_settings_error( $code, $message );
			}

			return;
		}

		$this->settings->save( $submitted );

		\add_settings_error(
			MAC_MEMBERS_SETTINGS_OPTION,
			'settings_saved',
			__( 'MAC Members settings saved.', 'mac-members' ),
			'success'
		);
	}

	public function render_settings_page(): void
	{
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAC Members', 'mac-members' ) . '</h1>';

		\settings_errors( MAC_MEMBERS_SETTINGS_OPTION );

		echo '<form method="post" action="">';
		echo '<input type="hidden" name="mac_members_action" value="' . esc_attr( self::ACTION_SAVE_SETTINGS ) . '">';
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $this->schema->get_fields() as $key => $field ) {
			echo '<tr>';
			echo '<th scope="row"><label for="mac-members-' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns escaped admin form controls.
			echo '<td>' . $this->render_field( $key, $field, $settings[ $key ] ?? null ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		\submit_button( __( 'Save MAC Members settings', 'mac-members' ) );
		echo '</form>';
		echo '</div>';
	}

	public function render_missing_roles_warning(): void
	{
		if ( ! $this->is_settings_page() || ! \current_user_can( 'manage_options' ) || ! $this->settings->has_missing_roles() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'MAC Members: One or more configured roles do not exist. Please review Settings > MAC Members.', 'mac-members' );
		echo '</p></div>';
	}

	private function render_field( string $key, array $field, mixed $value ): string
	{
		return match ( $field['type'] ) {
			SettingsSchema::TYPE_ROLE   => $this->render_role_select( $key, (string) $value ),
			SettingsSchema::TYPE_EMAIL  => $this->render_email_input( $key, (string) $value ),
			SettingsSchema::TYPE_TOGGLE => $this->render_toggle( $key, (bool) $value, (string) $field['label'] ),
			default                     => '',
		};
	}

	private function render_role_select( string $key, string $value ): string
	{
		$output = '<select id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']">';

		foreach ( $this->settings->get_available_roles() as $slug => $label ) {
			$output .= '<option value="' . esc_attr( $slug ) . '"' . $this->selected_attr( $slug === $value ) . '>';
			$output .= esc_html( $label ) . '</option>';
		}

		if ( '' !== $value && ! array_key_exists( $value, $this->settings->get_available_roles() ) ) {
			$output .= '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
		}

		$output .= '</select>';

		return $output;
	}

	private function render_email_input( string $key, string $value ): string
	{
		return '<input type="email" class="regular-text" id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
	}

	private function render_toggle( string $key, bool $value, string $label ): string
	{
		return '<label><input type="checkbox" id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']" value="1"' . $this->checked_attr( $value ) . '> ' . esc_html( $label ) . '</label>';
	}

	private function selected_attr( bool $selected ): string
	{
		return $selected ? ' selected="selected"' : '';
	}

	private function checked_attr( bool $checked ): string
	{
		return $checked ? ' checked="checked"' : '';
	}

	private function is_settings_save_request(): bool
	{
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
		$action         = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only scopes save handling before nonce verification in handle_save().
		if ( isset( $_POST['mac_members_action'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only scopes save handling before nonce verification in handle_save().
			$action = \sanitize_key( \wp_unslash( $_POST['mac_members_action'] ) );
		}

		return 'POST' === $request_method
			&& $this->is_settings_page()
			&& self::ACTION_SAVE_SETTINGS === $action;
	}

	private function is_settings_page(): bool
	{
		$page = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the admin page slug is safe and does not change state.
		if ( isset( $_GET['page'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the admin page slug is safe and does not change state.
			$page = \sanitize_key( \wp_unslash( $_GET['page'] ) );
		}

		return MAC_MEMBERS_ADMIN_SLUG === $page;
	}

	private function add_settings_error( string $code, string $message ): void
	{
		\add_settings_error( MAC_MEMBERS_SETTINGS_OPTION, $code, $message );
	}
}
