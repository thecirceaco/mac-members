<?php
/**
 * The extra fields of the member details.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Settings\SettingsRepositoryInterface;
use MacMembers\Settings\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The fields that the "Member details fields" setting lists, in its order, with their values for a member. A
 * field is a user meta key, or one of the user's own fields such as user_url. When ACF is active, the values go
 * through ACF, so dates and choices come out formatted. Keys that could leak secrets never show.
 */
final class MemberDetails
{
	/**
	 * Fields of the user itself rather than user meta.
	 */
	private const USER_FIELDS = array( 'user_login', 'user_email', 'user_url', 'user_registered', 'display_name', 'user_nicename' );

	/**
	 * Keys that hold secrets or permissions.
	 */
	private const BLOCKED_KEYS = array( 'user_pass', 'user_activation_key', 'session_tokens' );

	/**
	 * The fields, read once per request.
	 *
	 * @var array<int,array{key:string,label:string}>|null
	 */
	private ?array $fields = null;

	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	/**
	 * @return array<int,array{key:string,label:string}> The listed fields that may show, with their labels.
	 */
	public function get_fields(): array
	{
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		$fields = array();

		foreach ( SettingsSchema::parse_fields( (string) $this->settings->get( 'detail_fields', '' ) ) as $field ) {
			if ( $this->is_blocked( $field['key'] ) ) {
				continue;
			}

			$fields[] = array(
				'key'   => $field['key'],
				'label' => '' !== $field['label'] ? $field['label'] : $this->get_default_label( $field['key'] ),
			);
		}

		$this->fields = $fields;

		return $fields;
	}

	/**
	 * @return array<int,array{label:string,value:string}> Each field's label and value as text, empty when unset.
	 */
	public function get_values( object $user ): array
	{
		$values = array();

		foreach ( $this->get_fields() as $field ) {
			$values[] = array(
				'label' => $field['label'],
				'value' => $this->to_text( $this->read( $user, $field['key'] ) ),
			);
		}

		return $values;
	}

	private function is_blocked( string $key ): bool
	{
		$key = strtolower( $key );

		return str_starts_with( $key, '_' )
			|| in_array( $key, self::BLOCKED_KEYS, true )
			|| str_ends_with( $key, 'capabilities' )
			|| str_ends_with( $key, 'user_level' );
	}

	/**
	 * The ACF field label when ACF knows the key, or else the key made readable: local_number reads Local number.
	 */
	private function get_default_label( string $key ): string
	{
		if ( \function_exists( 'acf_get_field' ) ) {
			$field = \acf_get_field( $key );

			if ( \is_array( $field ) && isset( $field['label'] ) && '' !== (string) $field['label'] ) {
				return (string) $field['label'];
			}
		}

		return ucfirst( strtolower( trim( (string) preg_replace( '/[_\-]+/', ' ', $key ) ) ) );
	}

	private function read( object $user, string $key ): mixed
	{
		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;

		if ( in_array( $key, self::USER_FIELDS, true ) ) {
			return method_exists( $user, 'get' ) ? ( $user->get( $key ) ?? '' ) : ( $user->{$key} ?? '' );
		}

		if ( 1 > $user_id ) {
			return '';
		}

		if ( \function_exists( 'get_field' ) ) {
			return \get_field( $key, 'user_' . $user_id );
		}

		return \get_user_meta( $user_id, $key, true );
	}

	/**
	 * A value as text: scalars as they are, true and false as Yes and No, lists joined with commas, and ACF's
	 * choices, posts, terms, users and files by their label, title or name.
	 */
	private function to_text( mixed $value ): string
	{
		if ( \is_bool( $value ) ) {
			return $value ? __( 'Yes', 'mac-members' ) : __( 'No', 'mac-members' );
		}

		if ( \is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		if ( $value instanceof \WP_Post ) {
			return (string) $value->post_title;
		}

		if ( $value instanceof \WP_Term ) {
			return (string) $value->name;
		}

		if ( $value instanceof \WP_User ) {
			return (string) $value->display_name;
		}

		if ( ! \is_array( $value ) ) {
			return '';
		}

		foreach ( array( 'label', 'title', 'name', 'url' ) as $key ) {
			if ( isset( $value[ $key ] ) && \is_scalar( $value[ $key ] ) ) {
				return trim( (string) $value[ $key ] );
			}
		}

		$parts = array_filter(
			array_map( fn ( mixed $item ): string => $this->to_text( $item ), $value ),
			static fn ( string $part ): bool => '' !== $part
		);

		return implode( ', ', $parts );
	}
}
