<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * @package MacMembers\Tests\Stubs
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! class_exists( 'WP_Roles' ) ) {
	final class WP_Roles {
		/**
		 * Registered roles.
		 *
		 * @var array<string,array<string,string>>
		 */
		public array $roles = array();

		/**
		 * Create the roles object.
		 *
		 * @param array<string,array<string,string>> $roles Roles.
		 */
		public function __construct( array $roles ) {
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	final class WP_User {
		public int $ID;
		public string $user_email;
		public string $user_login;
		public string $user_registered;

		/**
		 * @var array<string,mixed>
		 */
		private array $data;

		/**
		 * @param array<string,mixed> $data User data.
		 */
		public function __construct( array $data ) {
			$this->ID              = (int) ( $data['ID'] ?? 0 );
			$this->user_email      = (string) ( $data['user_email'] ?? '' );
			$this->user_login      = (string) ( $data['user_login'] ?? '' );
			$this->user_registered = (string) ( $data['user_registered'] ?? '' );
			$this->data            = $data;
		}

		public function get( string $key ): mixed {
			return $this->data[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	final class WP_User_Query {
		/**
		 * @var array<string,mixed>
		 */
		private array $args;

		/**
		 * @param array<string,mixed> $args Query arguments.
		 */
		public function __construct( array $args ) {
			$this->args                                  = $args;
			$GLOBALS['mac_members_test_last_user_query'] = $args;
		}

		/**
		 * @return array<int,WP_User>
		 */
		public function get_results(): array {
			return $GLOBALS['mac_members_test_users'] ?? array();
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_args(): array {
			return $this->args;
		}
	}
}

function mac_members_tests_reset_wp_state(): void {
	$GLOBALS['mac_members_test_actions']          = array();
	$GLOBALS['mac_members_test_filters']          = array();
	$GLOBALS['mac_members_test_activation_hooks'] = array();
	$GLOBALS['mac_members_test_options']          = array(
		'admin_email' => 'admin@example.test',
	);
	$GLOBALS['mac_members_test_bloginfo']         = array(
		'name' => 'Example Site',
	);
	$GLOBALS['mac_members_test_roles']            = array(
		'administrator'  => array( 'name' => 'Administrator' ),
		'member-pending' => array( 'name' => 'Member Pending' ),
		'member'         => array( 'name' => 'Member' ),
		'member-invalid' => array( 'name' => 'Member Invalid' ),
		'subscriber'     => array( 'name' => 'Subscriber' ),
	);
	$GLOBALS['mac_members_test_current_user_caps'] = array(
		'manage_options' => true,
		'promote_users'  => true,
	);
	$GLOBALS['mac_members_test_options_pages']     = array();
	$GLOBALS['mac_members_test_settings_errors']   = array();
	$GLOBALS['mac_members_test_nonces']            = array();
	$GLOBALS['mac_members_test_logged_in']         = true;
	$GLOBALS['mac_members_test_shortcodes']        = array();
	$GLOBALS['mac_members_test_users']             = array();
	$GLOBALS['mac_members_test_last_user_query']   = null;
	$GLOBALS['mac_members_test_registered_styles'] = array();
	$GLOBALS['mac_members_test_registered_scripts'] = array();
	$GLOBALS['mac_members_test_enqueued_styles']    = array();
	$GLOBALS['mac_members_test_enqueued_scripts']   = array();
	$GLOBALS['mac_members_test_inline_scripts']     = array();

	$_GET    = array();
	$_POST   = array();
	$_SERVER = array(
		'REQUEST_METHOD' => 'GET',
	);
}

function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['mac_members_test_actions'][ $hook_name ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['mac_members_test_filters'][ $hook_name ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_shortcode( string $tag, callable $callback ): void {
	$GLOBALS['mac_members_test_shortcodes'][ $tag ] = $callback;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['mac_members_test_filters'][ $hook_name ] ?? array() as $filter ) {
		$value = ( $filter['callback'] )( $value, ...$args );
	}

	return $value;
}

function register_activation_hook( string $file, callable $callback ): void {
	$GLOBALS['mac_members_test_activation_hooks'][ $file ] = $callback;
}

function get_option( string $option, mixed $default_value = false ): mixed {
	return array_key_exists( $option, $GLOBALS['mac_members_test_options'] )
		? $GLOBALS['mac_members_test_options'][ $option ]
		: $default_value;
}

function update_option( string $option, mixed $value ): bool {
	$GLOBALS['mac_members_test_options'][ $option ] = $value;

	return true;
}

function delete_option( string $option ): bool {
	unset( $GLOBALS['mac_members_test_options'][ $option ] );

	return true;
}

function get_bloginfo( string $show = '' ): string {
	return (string) ( $GLOBALS['mac_members_test_bloginfo'][ $show ] ?? '' );
}

function wp_roles(): WP_Roles {
	return new WP_Roles( $GLOBALS['mac_members_test_roles'] ?? array() );
}

function sanitize_key( string $key ): string {
	$key = strtolower( $key );

	return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
}

function sanitize_text_field( mixed $value ): string {
	$value = (string) $value;
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '';
	$value = trim( strip_tags( $value ) );

	return $value;
}

function sanitize_email( mixed $email ): string {
	return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}

function is_email( mixed $email ): string|false {
	$email = (string) $email;

	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

function esc_html( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( __( $text, $domain ) );
}

function esc_attr__( string $text, string $domain = 'default' ): string {
	return esc_attr( __( $text, $domain ) );
}

function current_user_can( string $capability ): bool {
	return (bool) ( $GLOBALS['mac_members_test_current_user_caps'][ $capability ] ?? false );
}

function is_user_logged_in(): bool {
	return (bool) ( $GLOBALS['mac_members_test_logged_in'] ?? false );
}

function add_options_page(
	string $page_title,
	string $menu_title,
	string $capability,
	string $menu_slug,
	callable $callback
): string {
	$GLOBALS['mac_members_test_options_pages'][ $menu_slug ] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
		'callback'   => $callback,
		'parent'     => 'options-general.php',
	);

	return 'settings_page_' . $menu_slug;
}

function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['mac_members_test_settings_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

function settings_errors( string $setting = '' ): void {
	foreach ( $GLOBALS['mac_members_test_settings_errors'] ?? array() as $error ) {
		if ( '' !== $setting && $setting !== $error['setting'] ) {
			continue;
		}

		echo '<div class="' . esc_attr( $error['type'] ) . '"><p>' . esc_html( $error['message'] ) . '</p></div>';
	}
}

function wp_create_nonce( string $action ): string {
	$nonce = 'nonce-' . $action;
	$GLOBALS['mac_members_test_nonces'][ $nonce ] = $action;

	return $nonce;
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	return ( $GLOBALS['mac_members_test_nonces'][ $nonce ] ?? null ) === $action ? 1 : false;
}

function wp_nonce_field( string $action, string $name ): void {
	$nonce = wp_create_nonce( $action );

	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $nonce ) . '">';
}

function submit_button( string $text = 'Save Changes' ): void {
	echo '<button type="submit" class="button button-primary">' . esc_html( $text ) . '</button>';
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function esc_url( mixed $url ): string {
	return esc_attr( (string) $url );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

function wp_register_style(
	string $handle,
	string|false $src = false,
	array $deps = array(),
	string|bool|null $ver = false,
	string $media = 'all'
): bool {
	$GLOBALS['mac_members_test_registered_styles'][ $handle ] = array(
		'src'   => $src,
		'deps'  => $deps,
		'ver'   => $ver,
		'media' => $media,
	);

	return true;
}

function wp_register_script(
	string $handle,
	string|false $src = false,
	array $deps = array(),
	string|bool|null $ver = false,
	bool|array $args = false
): bool {
	$GLOBALS['mac_members_test_registered_scripts'][ $handle ] = array(
		'src'  => $src,
		'deps' => $deps,
		'ver'  => $ver,
		'args' => $args,
	);

	return true;
}

function wp_enqueue_style( string $handle ): void {
	$GLOBALS['mac_members_test_enqueued_styles'][] = $handle;
}

function wp_enqueue_script( string $handle ): void {
	$GLOBALS['mac_members_test_enqueued_scripts'][] = $handle;
}

function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	$GLOBALS['mac_members_test_inline_scripts'][ $handle ][] = array(
		'data'     => $data,
		'position' => $position,
	);

	return true;
}

function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
	return json_encode( $data, $options, $depth );
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
