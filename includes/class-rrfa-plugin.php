<?php
/**
 * Plugin bootstrap.
 *
 * @package RepeaterRowsForACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks for ACF and registers the field type.
 *
 * The dependency is checked at runtime rather than with a "Requires Plugins"
 * header, because that header names one slug and ACF Pro uses a different one.
 */
final class RRFA_Plugin {

	/**
	 * Lowest ACF version this plugin supports.
	 *
	 * It is also the first to flag submitted values while saving, which the REST
	 * read-only guard and the check for forms cut off at max_input_vars rely on.
	 */
	const MIN_ACF_VERSION = '6.8.11';

	/**
	 * Wires the plugin up once ACF can be detected.
	 *
	 * ACF may be bundled in a theme, which loads after all plugins.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! self::has_supported_acf() ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_acf_notice' ) );
			return;
		}

		add_action( 'acf/include_field_types', array( __CLASS__, 'register_field_type' ) );
		add_filter( 'acf/rest/format_value_for_rest', array( __CLASS__, 'keep_rest_output_light' ), 20, 5 );
	}

	/**
	 * Whether a compatible version of ACF is active.
	 *
	 * @return bool
	 */
	private static function has_supported_acf() {
		return defined( 'ACF_VERSION' )
			&& function_exists( 'acf_register_field_type' )
			&& version_compare( ACF_VERSION, self::MIN_ACF_VERSION, '>=' );
	}

	/**
	 * Registers the field type with ACF.
	 *
	 * @return void
	 */
	public static function register_field_type() {
		require_once RRFA_PATH . 'includes/class-rrfa-row-store.php';
		require_once RRFA_PATH . 'includes/class-rrfa-row-renderer.php';
		require_once RRFA_PATH . 'includes/class-rrfa-field-repeater.php';

		acf_register_field_type( 'RRFA_Field_Repeater' );
	}

	/**
	 * Tells administrators why the field type is unavailable.
	 *
	 * @return void
	 */
	public static function render_acf_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum Advanced Custom Fields version. */
					__( 'Repeater Rows for ACF needs Advanced Custom Fields %s or newer to be active.', 'repeater-rows-for-acf' ),
					self::MIN_ACF_VERSION
				)
			)
		);
	}

	/**
	 * Returns light REST output for any field that holds a Repeater Rows field.
	 *
	 * ACF removes private user and post data from `?acf_format=standard` responses,
	 * but only inside the container types it lists by name. Rows of this field type
	 * would pass through unfiltered, so those responses use IDs instead of expanded
	 * objects. That applies to every requester, which keeps the response shape stable.
	 *
	 * @param mixed      $formatted The value ACF formatted.
	 * @param int|string $post_id   The ACF post ID.
	 * @param array      $field     The field being output.
	 * @param mixed      $raw       The raw value.
	 * @param string     $format    The requested format.
	 * @return mixed
	 */
	public static function keep_rest_output_light( $formatted, $post_id, $field, $raw, $format ) {
		if ( 'standard' !== $format || ! self::holds_repeater_rows( $field ) ) {
			return $formatted;
		}

		$type = acf_get_field_type( $field['type'] );

		return $type ? $type->format_value_for_rest( $raw, $post_id, $field ) : $formatted;
	}

	/**
	 * Whether a field is, or contains at any depth, a Repeater Rows field.
	 *
	 * @param array $field The field to inspect.
	 * @return bool
	 */
	private static function holds_repeater_rows( array $field ) {
		if ( 'rrfa_repeater' === ( $field['type'] ?? '' ) ) {
			return true;
		}

		$children = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();

		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( is_array( $layout['sub_fields'] ?? null ) ) {
				$children = array_merge( $children, $layout['sub_fields'] );
			}
		}

		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::holds_repeater_rows( $child ) ) {
				return true;
			}
		}

		return false;
	}
}
