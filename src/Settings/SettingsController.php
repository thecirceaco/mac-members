<?php
/**
 * Settings admin controller.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Settings;

use MacMembers\Admin\MenuIcon;
use MacMembers\Contracts\Service;
use MacMembers\Security\Capabilities;
use MacMembers\Security\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class SettingsController implements Service
{
	public const NONCE_ACTION = 'mac_members_save_settings';
	public const NONCE_NAME   = 'mac_members_settings_nonce';

	private const ACTION_SAVE_SETTINGS = 'save_settings';

	/**
	 * The setting that decides whether deleting the plugin removes its data. The page shows how many users
	 * hold each member role next to it, because roles in use are kept.
	 */
	private const DELETE_DATA_SETTING = 'delete_data_on_uninstall';

	/**
	 * Whether this request registered the page as a top-level menu item, or under Settings.
	 */
	private ?bool $top_level = null;

	/**
	 * @param \Closure|null $end_request Runs instead of exiting after the redirect that follows a save. Tests
	 *                                   pass a closure that throws, to see where the request ended.
	 */
	public function __construct(
		private readonly SettingsRepositoryInterface $settings,
		private readonly SettingsSchema $schema,
		private readonly ?\Closure $end_request = null
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

	/**
	 * Adds the settings page under Settings, or as a top-level menu item when that setting is on.
	 */
	public function register_settings_page(): void
	{
		$this->top_level = $this->wants_top_level();

		if ( $this->top_level ) {
			\add_menu_page(
				__( 'MAC Members', 'mac-members' ),
				__( 'MAC Members', 'mac-members' ),
				'manage_options',
				MAC_MEMBERS_ADMIN_SLUG,
				array( $this, 'render_settings_page' ),
				MenuIcon::url(),
				null
			);

			return;
		}

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

		$this->settings->save( $submitted );

		\add_settings_error(
			MAC_MEMBERS_SETTINGS_OPTION,
			'settings_saved',
			__( 'MAC Members settings saved.', 'mac-members' ),
			'success'
		);

		$this->redirect_to_settings_page();
	}

	public function render_settings_page(): void
	{
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MAC Members', 'mac-members' ) . '</h1>';

		// Under Settings, WordPress already shows settings notices on its own (options-head.php).
		if ( $this->is_top_level() ) {
			\settings_errors( MAC_MEMBERS_SETTINGS_OPTION );
		}

		echo '<form method="post" action="">';
		echo '<input type="hidden" name="mac_members_action" value="' . esc_attr( self::ACTION_SAVE_SETTINGS ) . '">';
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $this->schema->get_fields() as $key => $field ) {
			echo '<tr>';
			// A group of checkboxes has its own legend instead of a label.
			echo SettingsSchema::TYPE_ROLES === $field['type']
				? '<th scope="row">' . esc_html( $field['label'] ) . '</th>'
				: '<th scope="row"><label for="mac-members-' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns escaped admin form controls.
			echo '<td>' . $this->render_field( $key, $field, $settings[ $key ] ?? null ) . $this->render_description( $key, $field ) . ( self::DELETE_DATA_SETTING === $key ? $this->render_member_role_usage() : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		\submit_button( __( 'Save Settings', 'mac-members' ) );
		echo '</form>';
		echo '</div>';
	}

	public function render_missing_roles_warning(): void
	{
		if ( ! $this->is_settings_page() || ! \current_user_can( 'manage_options' ) || array() === Roles::missing() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'MAC Members: One or more member roles do not exist. Deactivate and activate MAC Members to create them again.', 'mac-members' );
		echo '</p></div>';
	}

	/**
	 * Sends the browser back to the settings page after a save, as WordPress does for its own settings, so a
	 * reload does not post the form again. The notices travel in the settings_errors transient, which
	 * settings_errors() reads on the next request. When the save moved the page between Settings and the
	 * top level, this also sends the browser to the page's new address.
	 */
	private function redirect_to_settings_page(): void
	{
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );
		\wp_safe_redirect( \add_query_arg( array( 'settings-updated' => 'true' ), $this->get_page_url( $this->wants_top_level() ) ) );

		if ( null !== $this->end_request ) {
			( $this->end_request )();
		}

		exit;
	}

	private function get_page_url( bool $top_level ): string
	{
		return \admin_url( ( $top_level ? 'admin.php' : 'options-general.php' ) . '?page=' . MAC_MEMBERS_ADMIN_SLUG );
	}

	private function wants_top_level(): bool
	{
		return true === (bool) $this->settings->get( 'top_level_menu', false );
	}

	private function is_top_level(): bool
	{
		return $this->top_level ?? $this->wants_top_level();
	}

	private function render_field( string $key, array $field, mixed $value ): string
	{
		return match ( $field['type'] ) {
			SettingsSchema::TYPE_EMAIL  => $this->render_email_input( $key, (string) $value ),
			SettingsSchema::TYPE_TOGGLE => $this->render_toggle( $key, (bool) $value, (string) $field['label'], isset( $field['description'] ) ),
			SettingsSchema::TYPE_ROLES  => $this->render_role_checkboxes( $key, (array) $value, (string) $field['label'], isset( $field['description'] ) ),
			SettingsSchema::TYPE_CHOICE => $this->render_choice_select( $key, (string) $value, $field['choices'] ?? array(), isset( $field['description'] ) ),
			SettingsSchema::TYPE_FIELDS => $this->render_textarea( $key, (string) $value, (int) ( $field['rows'] ?? 6 ), isset( $field['description'] ) ),
			default                     => '',
		};
	}

	/**
	 * How many users hold each member role, so the admin sees which roles deleting the plugin would keep.
	 */
	private function render_member_role_usage(): string
	{
		$usage = Roles::usage();

		if ( array() === $usage ) {
			return '';
		}

		$counts = array();
		$held   = false;

		foreach ( $usage as $role ) {
			/* translators: 1: role name, 2: number of users. */
			$counts[] = sprintf( __( '%1$s: %2$s', 'mac-members' ), $role['name'], \number_format_i18n( $role['users'] ) );
			$held     = $held || 0 < $role['users'];
		}

		$outcome = $held
			? __( 'The roles that users hold stay when the plugin is deleted.', 'mac-members' )
			: __( 'No user holds a member role, so deleting the plugin with this setting on removes them all.', 'mac-members' );

		/* translators: %s: comma-separated list of member roles and their number of users. */
		$summary = sprintf( __( 'Users per member role: %s.', 'mac-members' ), implode( ', ', $counts ) );

		return '<p class="description mac-members-role-usage">' . esc_html( $summary . ' ' . $outcome ) . '</p>';
	}

	private function render_description( string $key, array $field ): string
	{
		if ( ! isset( $field['description'] ) || '' === $field['description'] ) {
			return '';
		}

		return '<p class="description" id="mac-members-' . esc_attr( $key ) . '-description">' . esc_html( (string) $field['description'] ) . '</p>';
	}

	/**
	 * @param array<string,string> $choices Choice labels keyed by value.
	 */
	private function render_choice_select( string $key, string $value, array $choices, bool $described ): string
	{
		$describedby = $described ? ' aria-describedby="mac-members-' . esc_attr( $key ) . '-description"' : '';
		$output      = '<select id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']"' . $describedby . '>';

		foreach ( $choices as $choice => $label ) {
			$output .= '<option value="' . esc_attr( (string) $choice ) . '"' . $this->selected_attr( (string) $choice === $value ) . '>' . esc_html( $label ) . '</option>';
		}

		return $output . '</select>';
	}

	private function render_textarea( string $key, string $value, int $rows, bool $described ): string
	{
		$describedby = $described ? ' aria-describedby="mac-members-' . esc_attr( $key ) . '-description"' : '';

		return '<textarea class="large-text code" id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']" rows="' . esc_attr( (string) $rows ) . '"' . $describedby . '>' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * A checkbox for each role that can be hidden. The status roles cannot, so they are left out. Roles with
	 * administrative capabilities are always hidden, so their checkboxes show checked and disabled.
	 *
	 * @param array<int,string> $value The checked role slugs.
	 */
	private function render_role_checkboxes( string $key, array $value, string $label, bool $described ): string
	{
		$describedby  = $described ? ' aria-describedby="mac-members-' . esc_attr( $key ) . '-description"' : '';
		$status_roles = array_keys( Roles::defaults() );
		$output       = '<fieldset id="mac-members-' . esc_attr( $key ) . '"' . $describedby . '><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>';

		foreach ( $this->settings->get_available_roles() as $slug => $name ) {
			if ( in_array( $slug, $status_roles, true ) ) {
				continue;
			}

			$always  = array() !== Capabilities::sensitive_capabilities_of_role( $slug );
			$output .= '<label><input type="checkbox" name="mac_members_settings[' . esc_attr( $key ) . '][]" value="' . esc_attr( $slug ) . '"' . $this->checked_attr( $always || in_array( $slug, $value, true ) ) . ( $always ? ' disabled' : '' ) . '> ' . esc_html( \translate_user_role( $name ) ) . '</label>';
			$output .= $always ? ' <span class="description">' . esc_html__( '(always hidden)', 'mac-members' ) . '</span>' : '';
			$output .= '<br>';
		}

		return $output . '</fieldset>';
	}

	private function render_email_input( string $key, string $value ): string
	{
		return '<input type="email" class="regular-text" id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
	}

	private function render_toggle( string $key, bool $value, string $label, bool $described = false ): string
	{
		$describedby = $described ? ' aria-describedby="mac-members-' . esc_attr( $key ) . '-description"' : '';

		return '<label><input type="checkbox" id="mac-members-' . esc_attr( $key ) . '" name="mac_members_settings[' . esc_attr( $key ) . ']" value="1"' . $this->checked_attr( $value ) . $describedby . '> ' . esc_html( $label ) . '</label>';
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
