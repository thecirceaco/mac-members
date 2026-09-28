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

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID;
		public string $post_content;

		/**
		 * @param array<string,mixed> $data Post data.
		 */
		public function __construct( array $data ) {
			$this->ID           = (int) ( $data['ID'] ?? 0 );
			$this->post_content = (string) ( $data['post_content'] ?? '' );
		}
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	final class WP_Role {
		public string $name;

		/**
		 * @var array<string,bool>
		 */
		public array $capabilities;

		/**
		 * @param array<string,bool> $capabilities Capabilities.
		 */
		public function __construct( string $role, array $capabilities ) {
			$this->name         = $role;
			$this->capabilities = $capabilities;
		}

		public function add_cap( string $cap, bool $grant = true ): void {
			$this->capabilities[ $cap ] = $grant;

			$GLOBALS['mac_members_test_roles'][ $this->name ]['capabilities'][ $cap ] = $grant;
		}

		public function remove_cap( string $cap ): void {
			unset( $this->capabilities[ $cap ], $GLOBALS['mac_members_test_roles'][ $this->name ]['capabilities'][ $cap ] );
		}

		public function has_cap( string $cap ): bool {
			return ! empty( $this->capabilities[ $cap ] );
		}
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	final class WP_Roles {
		/**
		 * Registered roles.
		 *
		 * @var array<string,array<string,mixed>>
		 */
		public array $roles = array();

		/**
		 * @var array<string,WP_Role>
		 */
		public array $role_objects = array();

		/**
		 * Create the roles object.
		 *
		 * @param array<string,array<string,mixed>> $roles Roles.
		 */
		public function __construct( array $roles ) {
			$this->roles = $roles;

			foreach ( $roles as $slug => $role ) {
				$this->role_objects[ $slug ] = new WP_Role( (string) $slug, (array) ( $role['capabilities'] ?? array() ) );
			}
		}

		public function is_role( string $role ): bool {
			return isset( $this->roles[ $role ] );
		}

		public function get_role( string $role ): ?WP_Role {
			return $this->role_objects[ $role ] ?? null;
		}
	}
}

if ( ! class_exists( 'MacMembers_Test_Ajax_Exit' ) ) {
	/**
	 * Thrown by the wp_send_json_*() stubs, where WordPress would exit.
	 */
	final class MacMembers_Test_Ajax_Exit extends RuntimeException {}
}

if ( ! class_exists( 'MacMembers_Test_Request_Ended' ) ) {
	/**
	 * Thrown by the end_request closure that tests give the action controller.
	 */
	final class MacMembers_Test_Request_Ended extends RuntimeException {}
}

if ( ! class_exists( 'WP_User' ) ) {
	final class WP_User {
		public int $ID;
		public string $user_email;
		public string $user_login;
		public string $user_registered;
		public string $display_name;

		/**
		 * @var array<int,string>
		 */
		public array $roles;

		/**
		 * @var array<string,mixed>
		 */
		private array $data;

		/**
		 * @var array<string,bool>
		 */
		private array $caps;

		private bool $can_update_roles;

		/**
		 * Roles add_role() silently fails to add, like a write that did not happen.
		 *
		 * @var array<int,string>
		 */
		private array $blocked_roles;

		/**
		 * Whether role changes reach the stored roles that get_user_by() reads.
		 */
		private bool $persist_roles;

		/**
		 * @param array<string,mixed> $data User data.
		 */
		public function __construct( array $data ) {
			$this->ID              = (int) ( $data['ID'] ?? 0 );
			$this->user_email      = (string) ( $data['user_email'] ?? '' );
			$this->user_login      = (string) ( $data['user_login'] ?? '' );
			$this->user_registered = (string) ( $data['user_registered'] ?? '' );
			$this->display_name    = (string) ( $data['display_name'] ?? $this->user_login );
			$this->roles           = array_values( array_map( 'strval', $data['roles'] ?? array() ) );
			$this->data            = $data;
			$this->caps            = array_map( 'boolval', $data['caps'] ?? array() );
			$this->can_update_roles = (bool) ( $data['can_update_roles'] ?? true );
			$this->blocked_roles    = array_values( array_map( 'strval', $data['blocked_roles'] ?? array() ) );
			$this->persist_roles    = (bool) ( $data['persist_roles'] ?? true );

			if ( 0 < $this->ID ) {
				$GLOBALS['mac_members_test_user_roles'][ $this->ID ] = $this->roles;
			}
		}

		public function get( string $key ): mixed {
			return $this->data[ $key ] ?? null;
		}

		public function add_role( string $role ): void {
			$GLOBALS['mac_members_test_role_changes'][] = array( 'add', $this->ID, $role );

			if ( ! $this->can_update_roles || in_array( $role, $this->roles, true ) || in_array( $role, $this->blocked_roles, true ) ) {
				return;
			}

			$this->roles[] = $role;
			$this->store_roles();
		}

		public function remove_role( string $role ): void {
			$GLOBALS['mac_members_test_role_changes'][] = array( 'remove', $this->ID, $role );

			if ( ! $this->can_update_roles ) {
				return;
			}

			$this->roles = array_values(
				array_filter(
					$this->roles,
					static fn ( string $current_role ): bool => $current_role !== $role
				)
			);
			$this->store_roles();
		}

		/**
		 * Replaces the roles in memory with the stored roles, as a fresh read from the database would.
		 */
		public function reload_roles(): void {
			if ( isset( $GLOBALS['mac_members_test_user_roles'][ $this->ID ] ) ) {
				$this->roles = $GLOBALS['mac_members_test_user_roles'][ $this->ID ];
			}
		}

		private function store_roles(): void {
			if ( $this->persist_roles ) {
				$GLOBALS['mac_members_test_user_roles'][ $this->ID ] = $this->roles;
			}
		}

		public function has_cap( string $capability ): bool {
			return (bool) ( $this->caps[ $capability ] ?? false );
		}
	}
}

if ( ! class_exists( 'MacMembers_Test_Wpdb' ) ) {
	/**
	 * Enough of wpdb for the member lock: an options table whose option_name is unique, as in MySQL.
	 * Any other query throws, so a test notices when the plugin sends SQL this fake does not know.
	 */
	final class MacMembers_Test_Wpdb {
		public string $options = 'wp_options';

		/**
		 * Rows of the options table written through queries, by option_name.
		 *
		 * @var array<string,string>
		 */
		public array $rows = array();

		/**
		 * @var array<int,string>
		 */
		public array $queries = array();

		/**
		 * When true, the next query fails like a database error.
		 */
		public bool $fail_next_query = false;

		public function prepare( string $query, mixed ...$args ): string {
			return vsprintf(
				$query,
				array_map(
					static fn ( mixed $arg ): string => "'" . addslashes( (string) $arg ) . "'",
					$args
				)
			);
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function query( string $query ): int|false {
			$this->queries[] = $query;

			if ( $this->fail_next_query ) {
				$this->fail_next_query = false;

				return false;
			}

			$values = $this->get_quoted_values( $query );

			if ( str_starts_with( $query, "INSERT IGNORE INTO {$this->options} (option_name, option_value, autoload) VALUES (" ) ) {
				if ( isset( $this->rows[ $values[0] ] ) ) {
					return 0;
				}

				$this->rows[ $values[0] ] = $values[1];

				// Lets a test change data at the moment a lock is taken, as a concurrent request could.
				if ( is_callable( $GLOBALS['mac_members_test_after_lock_insert'] ?? null ) ) {
					( $GLOBALS['mac_members_test_after_lock_insert'] )( $values[0] );
				}

				return 1;
			}

			if ( str_starts_with( $query, "DELETE FROM {$this->options} WHERE option_name = " ) && str_contains( $query, ' AND option_value = ' ) ) {
				if ( ( $this->rows[ $values[0] ] ?? null ) !== $values[1] ) {
					return 0;
				}

				unset( $this->rows[ $values[0] ] );

				return 1;
			}

			if ( str_starts_with( $query, "DELETE FROM {$this->options} WHERE option_name LIKE " ) ) {
				$pattern = $this->like_to_regex( $values[0] );
				$deleted = 0;

				foreach ( array_keys( $this->rows ) as $name ) {
					if ( 1 === preg_match( $pattern, $name ) ) {
						unset( $this->rows[ $name ] );
						++$deleted;
					}
				}

				return $deleted;
			}

			throw new RuntimeException( 'Unexpected query: ' . $query );
		}

		public function get_var( string $query ): ?string {
			$this->queries[] = $query;

			if ( str_starts_with( $query, "SELECT option_value FROM {$this->options} WHERE option_name = " ) ) {
				return $this->rows[ $this->get_quoted_values( $query )[0] ] ?? null;
			}

			throw new RuntimeException( 'Unexpected query: ' . $query );
		}

		/**
		 * @return array<int,string>
		 */
		private function get_quoted_values( string $query ): array {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $query, $matches );

			return array_map( 'stripslashes', $matches[1] );
		}

		private function like_to_regex( string $pattern ): string {
			$regex  = '';
			$length = strlen( $pattern );

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $pattern[ $i ];

				if ( '\\' === $char && $i + 1 < $length ) {
					$regex .= preg_quote( $pattern[ ++$i ], '/' );
				} elseif ( '%' === $char ) {
					$regex .= '.*';
				} elseif ( '_' === $char ) {
					$regex .= '.';
				} else {
					$regex .= preg_quote( $char, '/' );
				}
			}

			return '/^' . $regex . '$/s';
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
			$GLOBALS['mac_members_test_user_queries'][]  = $args;
		}

		private int $total = 0;

		/**
		 * The users in $GLOBALS['mac_members_test_users'] that match the arguments, paged like WordPress: every
		 * role in `role`, one role in `role__in`, an ID in `include`, `search` in one of `search_columns` (with
		 * * as the wildcard) and the `meta_query` clauses, compared with LIKE or =.
		 *
		 * @return array<int,WP_User|int>
		 */
		public function get_results(): array {
			$users = array_values( array_filter( $GLOBALS['mac_members_test_users'] ?? array(), array( $this, 'matches' ) ) );

			$this->total = count( $users );
			$number      = (int) ( $this->args['number'] ?? -1 );

			if ( 0 < $number ) {
				$page  = max( 1, (int) ( $this->args['paged'] ?? 1 ) );
				$users = array_slice( $users, ( $page - 1 ) * $number, $number );
			}

			if ( 'ID' === ( $this->args['fields'] ?? 'all' ) ) {
				return array_map( static fn ( WP_User $user ): int => $user->ID, $users );
			}

			return $users;
		}

		public function get_total(): int {
			$this->get_results();

			return $this->total;
		}

		private function matches( WP_User $user ): bool {
			$all = array_filter( array_map( 'strval', (array) ( $this->args['role'] ?? array() ) ) );
			$any = array_filter( array_map( 'strval', (array) ( $this->args['role__in'] ?? array() ) ) );

			if ( array() !== array_diff( $all, $user->roles ) ) {
				return false;
			}

			if ( array() !== $any && array() === array_intersect( $any, $user->roles ) ) {
				return false;
			}

			if ( ! empty( $this->args['include'] ) && ! in_array( $user->ID, array_map( 'intval', (array) $this->args['include'] ), true ) ) {
				return false;
			}

			if ( isset( $this->args['search'] ) && '' !== $this->args['search'] && ! $this->matches_search( $user ) ) {
				return false;
			}

			return ! isset( $this->args['meta_query'] ) || $this->matches_meta_query( $user, (array) $this->args['meta_query'] );
		}

		private function matches_search( WP_User $user ): bool {
			$search  = (string) $this->args['search'];
			$leading = str_starts_with( $search, '*' );
			$ending  = str_ends_with( $search, '*' );
			$term    = strtolower( trim( $search, '*' ) );

			foreach ( (array) ( $this->args['search_columns'] ?? array( 'user_login', 'user_email', 'display_name' ) ) as $column ) {
				$value = strtolower( 'ID' === $column ? (string) $user->ID : (string) ( $user->{$column} ?? $user->get( (string) $column ) ?? '' ) );

				if ( 'ID' === $column ) {
					if ( $value === $term ) {
						return true;
					}

					continue;
				}

				$found = match ( true ) {
					$leading && $ending => str_contains( $value, $term ),
					$leading            => str_ends_with( $value, $term ),
					$ending             => str_starts_with( $value, $term ),
					default             => $value === $term,
				};

				if ( $found ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * @param array<int|string,mixed> $meta_query Meta query clauses and their relation.
		 */
		private function matches_meta_query( WP_User $user, array $meta_query ): bool {
			$relation = strtoupper( (string) ( $meta_query['relation'] ?? 'AND' ) );
			$results  = array();

			foreach ( $meta_query as $key => $clause ) {
				if ( 'relation' === $key || ! is_array( $clause ) ) {
					continue;
				}

				$value     = strtolower( (string) ( $user->get( (string) $clause['key'] ) ?? '' ) );
				$expected  = strtolower( (string) ( $clause['value'] ?? '' ) );
				$results[] = 'LIKE' === strtoupper( (string) ( $clause['compare'] ?? '=' ) )
					? str_contains( $value, $expected )
					: $value === $expected;
			}

			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
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
		'administrator'  => array(
			'name'         => 'Administrator',
			'capabilities' => array(
				'read'            => true,
				'manage_options'  => true,
				'edit_users'      => true,
				'promote_users'   => true,
				'unfiltered_html' => true,
			),
		),
		'mac_members_pending'  => array(
			'name'         => 'Member (Pending)',
			'capabilities' => array( 'read' => true ),
		),
		'mac_members_approved' => array(
			'name'         => 'Member',
			'capabilities' => array( 'read' => true ),
		),
		'mac_members_inactive' => array(
			'name'         => 'Member (Inactive)',
			'capabilities' => array( 'read' => true ),
		),
		'mac_members_denied'   => array(
			'name'         => 'Member (Denied)',
			'capabilities' => array( 'read' => true ),
		),
		'subscriber'     => array(
			'name'         => 'Subscriber',
			'capabilities' => array( 'read' => true ),
		),
	);
	$GLOBALS['mac_members_test_current_user_caps'] = array(
		'manage_options'     => true,
		'promote_users'      => true,
		'mac_members_review' => true,
	);
	// Capabilities checked for one object, such as current_user_can( 'promote_user', 12 ), keyed by capability and ID.
	$GLOBALS['mac_members_test_current_user_object_caps'] = array();
	$GLOBALS['mac_members_test_uninstall_hooks']          = array();
	$GLOBALS['mac_members_test_doing_it_wrong']           = array();
	$GLOBALS['mac_members_test_session_token']            = 'session-one';
	$GLOBALS['mac_members_test_is_singular']              = false;
	$GLOBALS['mac_members_test_queried_object']           = null;
	$GLOBALS['mac_members_test_nocache_headers_calls']    = 0;
	$GLOBALS['mac_members_test_after_lock_insert']        = null;
	$GLOBALS['wpdb']                                      = new MacMembers_Test_Wpdb();
	$GLOBALS['mac_members_test_options_pages']     = array();
	$GLOBALS['mac_members_test_menu_pages']        = array();
	$GLOBALS['mac_members_test_transients']        = array();
	$GLOBALS['mac_members_test_redirect']          = null;
	$GLOBALS['mac_members_test_settings_errors']   = array();
	$GLOBALS['mac_members_test_nonces']            = array();
	$GLOBALS['mac_members_test_logged_in']         = true;
	$GLOBALS['mac_members_test_shortcodes']        = array();
	$GLOBALS['mac_members_test_users']             = array();
	$GLOBALS['mac_members_test_users_by_id']       = array();
	$GLOBALS['mac_members_test_user_roles']        = array();
	$GLOBALS['mac_members_test_cleaned_user_cache'] = array();
	$GLOBALS['mac_members_test_last_user_query']   = null;
	$GLOBALS['mac_members_test_user_queries']      = array();
	$GLOBALS['mac_members_test_role_translations'] = array();
	$GLOBALS['mac_members_test_unique_id']         = 0;
	$GLOBALS['mac_members_test_registered_styles'] = array();
	$GLOBALS['mac_members_test_registered_scripts'] = array();
	$GLOBALS['mac_members_test_enqueued_styles']    = array();
	$GLOBALS['mac_members_test_enqueued_scripts']   = array();
	$GLOBALS['mac_members_test_inline_scripts']     = array();
	$GLOBALS['mac_members_test_current_user_id']    = 1;
	$GLOBALS['mac_members_test_ajax_response']      = null;
	$GLOBALS['mac_members_test_ajax_responses']     = array();
	$GLOBALS['mac_members_test_ajax_send_exits']    = true;
	$GLOBALS['mac_members_test_ajax_referer_checks'] = array();
	$GLOBALS['mac_members_test_role_changes']       = array();
	$GLOBALS['mac_members_test_mail']               = array();
	$GLOBALS['mac_members_test_mail_fail_next']     = 0;

	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_SERVER  = array(
		'REQUEST_METHOD' => 'GET',
	);

	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mac-members-test-error.log' );
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

/**
 * @param array<string,mixed>        $pairs Supported attributes and their defaults.
 * @param array<string,mixed>|string $atts  Attributes from the shortcode tag.
 *
 * @return array<string,mixed>
 */
function shortcode_atts( array $pairs, array|string $atts, string $shortcode = '' ): array {
	unset( $shortcode );

	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

/**
 * Array form only: add_query_arg( array $args, string $url ). A false value removes the argument.
 *
 * @param array<string,mixed> $args Query arguments.
 */
/**
 * Like WordPress: the URL's own arguments are encoded again, and new values are added as they are, so callers
 * encode them.
 */
function add_query_arg( array $args, ?string $url = null ): string {
	$url   = $url ?? (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
	$parts = explode( '?', $url, 2 );
	$query = array();

	parse_str( $parts[1] ?? '', $query );

	$query = array_map( static fn ( mixed $value ): mixed => is_string( $value ) ? urlencode( $value ) : $value, $query );

	foreach ( $args as $key => $value ) {
		if ( false === $value ) {
			unset( $query[ $key ] );
		} else {
			$query[ $key ] = $value;
		}
	}

	$pairs = array();

	foreach ( $query as $key => $value ) {
		$pairs[] = $key . '=' . ( is_array( $value ) ? http_build_query( $value ) : (string) $value );
	}

	return $parts[0] . ( array() === $pairs ? '' : '?' . implode( '&', $pairs ) );
}

/**
 * @param string|array<int,string> $key Arguments to remove.
 */
function remove_query_arg( string|array $key, ?string $url = null ): string {
	return add_query_arg( array_fill_keys( (array) $key, false ), $url );
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['mac_members_test_filters'][ $hook_name ] ?? array() as $filter ) {
		$value = ( $filter['callback'] )( $value, ...$args );
	}

	return $value;
}

function register_activation_hook( string $file, callable $callback ): void {
	$GLOBALS['mac_members_test_activation_hooks'][ $file ][] = $callback;
}

function register_uninstall_hook( string $file, callable $callback ): void {
	// Like WordPress, which stores the callback in an option: object methods are refused.
	if ( is_array( $callback ) && is_object( $callback[0] ) ) {
		$GLOBALS['mac_members_test_doing_it_wrong'][] = 'register_uninstall_hook';

		return;
	}

	$GLOBALS['mac_members_test_uninstall_hooks'][ $file ] = $callback;
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

function translate_user_role( string $name, string $domain = 'default' ): string {
	unset( $domain );

	return $GLOBALS['mac_members_test_role_translations'][ $name ] ?? $name;
}

function get_role( string $role ): ?WP_Role {
	return wp_roles()->get_role( $role );
}

function add_role( string $role, string $display_name, array $capabilities = array() ): ?WP_Role {
	if ( isset( $GLOBALS['mac_members_test_roles'][ $role ] ) ) {
		return null;
	}

	$GLOBALS['mac_members_test_roles'][ $role ] = array(
		'name'         => $display_name,
		'capabilities' => $capabilities,
	);

	return new WP_Role( $role, $capabilities );
}

function remove_role( string $role ): void {
	unset( $GLOBALS['mac_members_test_roles'][ $role ] );
}

/**
 * @return array<string,array<string,mixed>>
 */
function get_editable_roles(): array {
	return apply_filters( 'editable_roles', $GLOBALS['mac_members_test_roles'] ?? array() );
}

function sanitize_key( string $key ): string {
	$key = strtolower( $key );

	return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
}

function sanitize_text_field( mixed $value ): string {
	$original = (string) $value;
	$value    = strip_tags( $original );
	// Like WordPress: line breaks, tabs and runs of spaces become one space.
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '';

	return (string) apply_filters( 'sanitize_text_field', trim( $value ), $original );
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

function number_format_i18n( float|int $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals );
}

/**
 * Like WordPress: the prefix and a number that grows with each call in the request.
 */
function wp_unique_id( string $prefix = '' ): string {
	$GLOBALS['mac_members_test_unique_id'] = ( $GLOBALS['mac_members_test_unique_id'] ?? 0 ) + 1;

	return $prefix . $GLOBALS['mac_members_test_unique_id'];
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

function current_user_can( string $capability, mixed ...$args ): bool {
	if ( isset( $args[0] ) && isset( $GLOBALS['mac_members_test_current_user_object_caps'][ $capability ][ (int) $args[0] ] ) ) {
		return (bool) $GLOBALS['mac_members_test_current_user_object_caps'][ $capability ][ (int) $args[0] ];
	}

	// map_meta_cap() maps promote_user to promote_users.
	if ( 'promote_user' === $capability ) {
		$capability = 'promote_users';
	}

	return (bool) ( $GLOBALS['mac_members_test_current_user_caps'][ $capability ] ?? false );
}

function is_user_logged_in(): bool {
	return (bool) ( $GLOBALS['mac_members_test_logged_in'] ?? false );
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['mac_members_test_current_user_id'] ?? 0 );
}

function get_user_by( string $field, mixed $value ): WP_User|false {
	if ( 'id' !== $field && 'ID' !== $field ) {
		return false;
	}

	$user = $GLOBALS['mac_members_test_users_by_id'][ (int) $value ] ?? false;

	if ( $user instanceof WP_User ) {
		$user->reload_roles();
	}

	return $user;
}

function clean_user_cache( WP_User|int $user ): void {
	$GLOBALS['mac_members_test_cleaned_user_cache'][] = $user instanceof WP_User ? $user->ID : $user;
}

function wp_cache_delete( int|string $key, string $group = '' ): bool {
	unset( $key, $group );

	return true;
}

function user_can( mixed $user, string $capability ): bool {
	if ( is_int( $user ) ) {
		$user = get_user_by( 'id', $user );
	}

	if ( ! $user instanceof WP_User ) {
		return false;
	}

	return $user->has_cap( $capability );
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

function add_menu_page(
	string $page_title,
	string $menu_title,
	string $capability,
	string $menu_slug,
	callable $callback,
	string $icon_url = '',
	int|float|null $position = null
): string {
	$GLOBALS['mac_members_test_menu_pages'][ $menu_slug ] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'capability' => $capability,
		'callback'   => $callback,
		'icon_url'   => $icon_url,
		'position'   => $position,
	);

	return 'toplevel_page_' . $menu_slug;
}

/**
 * @return array<int,array<string,string>>
 */
function get_settings_errors( string $setting = '' ): array {
	return array_values(
		array_filter(
			$GLOBALS['mac_members_test_settings_errors'] ?? array(),
			static fn ( array $error ): bool => '' === $setting || $setting === $error['setting']
		)
	);
}

function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
	unset( $expiration );

	$GLOBALS['mac_members_test_transients'][ $transient ] = $value;

	return true;
}

function wp_safe_redirect( string $location, int $status = 302 ): bool {
	$GLOBALS['mac_members_test_redirect'] = array(
		'location' => $location,
		'status'   => $status,
	);

	return true;
}

function home_url( string $path = '' ): string {
	return 'https://example.test/' . ltrim( $path, '/' );
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

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

function wp_get_session_token(): string {
	return (string) ( $GLOBALS['mac_members_test_session_token'] ?? '' );
}

function is_singular( string|array $post_types = '' ): bool {
	unset( $post_types );

	return (bool) ( $GLOBALS['mac_members_test_is_singular'] ?? false );
}

function get_queried_object(): ?object {
	return $GLOBALS['mac_members_test_queried_object'] ?? null;
}

function has_shortcode( string $content, string $tag ): bool {
	return 1 === preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', $content );
}

function nocache_headers(): void {
	++$GLOBALS['mac_members_test_nocache_headers_calls'];
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

function wp_login_url( string $redirect = '', bool $force_reauth = false ): string {
	unset( $redirect, $force_reauth );

	return 'https://example.test/wp-login.php';
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

/**
 * Records a JSON response. WordPress exits after sending it; the stub throws instead, or, when
 * $GLOBALS['mac_members_test_ajax_send_exits'] is false, returns like a wp_die handler that does not exit.
 *
 * @param array{success:bool,data:mixed,status:int} $response Response.
 */
function mac_members_tests_send_json( array $response ): void {
	$GLOBALS['mac_members_test_ajax_response']     = $response;
	$GLOBALS['mac_members_test_ajax_responses'][] = $response;

	if ( $GLOBALS['mac_members_test_ajax_send_exits'] ?? true ) {
		throw new MacMembers_Test_Ajax_Exit();
	}
}

function wp_send_json_success( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void {
	unset( $flags );

	mac_members_tests_send_json(
		array(
			'success' => true,
			'data'    => $data,
			'status'  => $status_code ?? 200,
		)
	);
}

function wp_send_json_error( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void {
	unset( $flags );

	mac_members_tests_send_json(
		array(
			'success' => false,
			'data'    => $data,
			'status'  => $status_code ?? 400,
		)
	);
}

function check_ajax_referer( int|string $action = -1, string|false $query_arg = false, bool $stop = true ): int|false {
	$GLOBALS['mac_members_test_ajax_referer_checks'][] = array(
		'action'    => $action,
		'query_arg' => $query_arg,
		'stop'      => $stop,
	);

	// Same lookup order as WordPress: the named argument, then _ajax_nonce, then _wpnonce, from $_REQUEST.
	$nonce = '';

	if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
		$nonce = (string) $_REQUEST[ $query_arg ];
	} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
		$nonce = (string) $_REQUEST['_ajax_nonce'];
	} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
		$nonce = (string) $_REQUEST['_wpnonce'];
	}

	$result = wp_verify_nonce( $nonce, (string) $action );

	if ( $stop && false === $result ) {
		// WordPress calls wp_die( -1, 403 ) here.
		throw new MacMembers_Test_Ajax_Exit();
	}

	return $result;
}

function wp_mail(
	string|array $to,
	string $subject,
	string $message,
	string|array $headers = '',
	array $attachments = array()
): bool {
	$GLOBALS['mac_members_test_mail'][] = array(
		'to'          => $to,
		'subject'     => $subject,
		'message'     => $message,
		'headers'     => $headers,
		'attachments' => $attachments,
	);

	if ( 0 < (int) $GLOBALS['mac_members_test_mail_fail_next'] ) {
		$GLOBALS['mac_members_test_mail_fail_next']--;

		return false;
	}

	return true;
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
