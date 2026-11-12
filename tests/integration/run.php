<?php
/**
 * Integration checks. They need a WordPress site with ACF and this plugin active.
 *
 * From the WordPress root, or the Docker container's /var/www/html:
 *   php wp-content/plugins/repeater-rows-for-acf/tests/integration/run.php
 *
 * The script finds wp-load.php in a parent folder, or in a parent's wp/ folder
 * (Bedrock and similar layouts), so it can be run from any working directory.
 *
 * The checks use a temporary post, user and field groups. All of them are removed
 * afterward, even after a failure. Exit code 0 means every check passed.
 *
 * @package RepeaterRowsForACF
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals -- Command line test script.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Look in each parent folder, and in its wp/ subfolder for layouts that keep WordPress core there, such as Bedrock.
$rrfa_wp_load   = '';
$rrfa_directory = __DIR__;
while ( '' === $rrfa_wp_load ) {
	foreach ( array( '/wp-load.php', '/wp/wp-load.php' ) as $rrfa_candidate ) {
		if ( file_exists( $rrfa_directory . $rrfa_candidate ) ) {
			$rrfa_wp_load = $rrfa_directory . $rrfa_candidate;
			break;
		}
	}
	if ( '' !== $rrfa_wp_load ) {
		break;
	}
	$rrfa_parent = dirname( $rrfa_directory );
	if ( $rrfa_parent === $rrfa_directory ) {
		fwrite( STDERR, "Could not find wp-load.php.\n" );
		exit( 1 );
	}
	$rrfa_directory = $rrfa_parent;
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
define( 'WP_ADMIN', true );
require $rrfa_wp_load;

if ( ! class_exists( 'RRFA_Field_Repeater' ) ) {
	fwrite( STDERR, "The Repeater Rows field type is not registered. Is the plugin active, along with ACF?\n" );
	exit( 1 );
}

/**
 * Counts and reports check results.
 */
final class RRFA_Test {

	/**
	 * Number of passed checks.
	 *
	 * @var int
	 */
	public static $passed = 0;

	/**
	 * Failure messages.
	 *
	 * @var string[]
	 */
	public static $failed = array();

	/**
	 * Records whether two values are identical.
	 *
	 * @param mixed  $expected The expected value.
	 * @param mixed  $actual   The actual value.
	 * @param string $message  What is being checked.
	 * @return void
	 */
	public static function same( $expected, $actual, $message ) {
		if ( $expected === $actual ) {
			++self::$passed;
			return;
		}

		self::$failed[] = sprintf( "%s\n    expected: %s\n    actual:   %s", $message, wp_json_encode( $expected ), wp_json_encode( $actual ) );
	}

	/**
	 * Records whether a condition holds.
	 *
	 * @param bool   $condition The condition.
	 * @param string $message   What is being checked.
	 * @return void
	 */
	public static function ok( $condition, $message ) {
		self::same( true, (bool) $condition, $message );
	}
}

/**
 * Registers a local field group for the temporary post.
 *
 * @return void
 */
function rrfa_test_register_group() {
	acf_add_local_field_group(
		array(
			'key'      => 'group_rrfatest',
			'title'    => 'RRFA test',
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'post',
					),
				),
			),
			'fields'   => array(
				array(
					'key'        => 'field_rrfat_faq',
					'name'       => 'rrfat_faq',
					'label'      => 'FAQ',
					'type'       => 'rrfa_repeater',
					'min'        => 0,
					'max'        => 0,
					'sub_fields' => array(
						array(
							'key'      => 'field_rrfat_title',
							'name'     => 'title',
							'label'    => 'Title',
							'type'     => 'text',
							'required' => 1,
						),
						array(
							'key'   => 'field_rrfat_email',
							'name'  => 'email',
							'label' => 'Email',
							'type'  => 'email',
						),
						array(
							'key'           => 'field_rrfat_when',
							'name'          => 'when',
							'label'         => 'When',
							'type'          => 'date_picker',
							'return_format' => 'd/m/Y',
						),
						array(
							'key'           => 'field_rrfat_page',
							'name'          => 'page',
							'label'         => 'Page',
							'type'          => 'post_object',
							'return_format' => 'object',
						),
						array(
							'key'           => 'field_rrfat_person',
							'name'          => 'person',
							'label'         => 'Person',
							'type'          => 'user',
							'return_format' => 'array',
						),
						array(
							'key'        => 'field_rrfat_group',
							'name'       => 'details',
							'label'      => 'Details',
							'type'       => 'group',
							'sub_fields' => array(
								array(
									'key'   => 'field_rrfat_group_note',
									'name'  => 'note',
									'label' => 'Note',
									'type'  => 'text',
								),
							),
						),
						array(
							'key'        => 'field_rrfat_nested',
							'name'       => 'steps',
							'label'      => 'Steps',
							'type'       => 'rrfa_repeater',
							'sub_fields' => array(
								array(
									'key'   => 'field_rrfat_step',
									'name'  => 'step',
									'label' => 'Step',
									'type'  => 'text',
								),
							),
						),
					),
				),
			),
		)
	);
}

/**
 * Lists a post's meta keys that belong to the test repeater.
 *
 * @param int $post_id The post ID.
 * @return string[]
 */
function rrfa_test_meta_keys( $post_id ) {
	global $wpdb;

	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )", $post_id, 'rrfat\_faq%', '\_rrfat\_faq%' ) );
	sort( $keys );

	return $keys;
}

/**
 * Removes a stored field group.
 *
 * @param string $key The field group key.
 * @return void
 */
function rrfa_test_delete_stored_group( $key ) {
	$group = acf_get_field_group( $key );

	if ( $group && ! empty( $group['ID'] ) ) {
		acf_delete_field_group( $group['ID'] );
	}
}

/**
 * Lists a group's fields as "name:type" with nesting, for comparing structure.
 *
 * @param array $fields Nested fields.
 * @return array
 */
function rrfa_test_outline( array $fields ) {
	$outline = array();

	foreach ( $fields as $field ) {
		$entry = array(
			'key'  => $field['key'],
			'type' => $field['type'],
		);

		if ( ! empty( $field['sub_fields'] ) ) {
			$entry['sub_fields'] = rrfa_test_outline( $field['sub_fields'] );
		}

		$outline[] = $entry;
	}

	return $outline;
}

/**
 * Returns a field group's nested export array, as ACF's export tool builds it.
 *
 * @param string $key The field group key.
 * @return array
 */
function rrfa_test_export( $key ) {
	$group           = acf_get_internal_post_type( $key, 'acf-field-group' );
	$group['fields'] = acf_get_fields( $group );

	return acf_prepare_internal_post_type_for_export( $group, 'acf-field-group' );
}

rrfa_test_register_group();

// A warning raised from this plugin, or an array used as a string anywhere, fails the run.
set_error_handler(
	static function ( $level, $message, $file, $line ) {
		if ( false !== strpos( $file, 'repeater-rows-for-acf' ) || false !== strpos( $message, 'Array to string' ) ) {
			RRFA_Test::$failed[] = "PHP notice: {$message} at {$file}:{$line}";
		}
		return false;
	}
);

$rrfa_post_id  = wp_insert_post(
	array(
		'post_title'  => 'RRFA integration test',
		'post_status' => 'draft',
		'post_type'   => 'post',
	)
);
$rrfa_page_id  = wp_insert_post(
	array(
		'post_title'  => 'RRFA private target',
		'post_status' => 'private',
		'post_type'   => 'page',
	)
);
$rrfa_user_id  = wp_insert_user(
	array(
		'user_login' => 'rrfa_test_' . wp_generate_password( 8, false ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'rrfa-private@example.invalid',
		'role'       => 'subscriber',
	)
);
$rrfa_temp_dir = trailingslashit( get_temp_dir() ) . 'rrfa-json-' . wp_generate_password( 8, false );
$rrfa_stored   = array();

try {
	// Row values stay separate. The reference plugin returned row one for every row.
	update_field(
		'rrfat_faq',
		array(
			array(
				'title' => 'First',
				'when'  => '20260102',
			),
			array(
				'title' => 'Second',
				'when'  => '20260304',
			),
		),
		$rrfa_post_id
	);

	$rows = get_field( 'rrfat_faq', $rrfa_post_id );
	RRFA_Test::same( array( 'First', 'Second' ), wp_list_pluck( $rows, 'title' ), 'get_field() returns a distinct value per row' );
	RRFA_Test::same( '04/03/2026', $rows[1]['when'], 'get_field() applies the sub-field return format' );

	$titles = array();
	$dates  = array();
	while ( have_rows( 'rrfat_faq', $rrfa_post_id ) ) {
		the_row();
		$titles[] = get_sub_field( 'title' );
		$dates[]  = get_sub_field( 'when' );
	}
	RRFA_Test::same( array( 'First', 'Second' ), $titles, 'have_rows() and get_sub_field() read every row' );
	RRFA_Test::same( array( '02/01/2026', '04/03/2026' ), $dates, 'get_sub_field() applies the sub-field return format' );

	// Storage layout.
	RRFA_Test::same( '2', get_post_meta( $rrfa_post_id, 'rrfat_faq', true ), 'The field meta holds the row count' );
	RRFA_Test::same( 'field_rrfat_faq', get_post_meta( $rrfa_post_id, '_rrfat_faq', true ), 'The field reference is stored' );
	RRFA_Test::same( 'Second', get_post_meta( $rrfa_post_id, 'rrfat_faq_1_title', true ), 'Cells have their own meta row' );
	RRFA_Test::same( 'field_rrfat_title', get_post_meta( $rrfa_post_id, '_rrfat_faq_1_title', true ), 'Cells have a field reference' );

	// Return shapes match the same sub-field type used outside a repeater.
	update_field(
		'rrfat_faq',
		array(
			array(
				'title'  => 'Shapes',
				'page'   => $rrfa_page_id,
				'person' => $rrfa_user_id,
			),
		),
		$rrfa_post_id
	);
	$rows = get_field( 'rrfat_faq', $rrfa_post_id );
	RRFA_Test::ok( $rows[0]['page'] instanceof WP_Post && $rows[0]['page']->ID === $rrfa_page_id, 'Post object sub-field returns a WP_Post' );
	RRFA_Test::same( $rrfa_user_id, (int) $rows[0]['person']['ID'], 'User sub-field returns its array format' );

	// Row functions, by name and by key.
	update_field( 'rrfat_faq', array( array( 'title' => 'A' ) ), $rrfa_post_id );
	RRFA_Test::same( 2, add_row( 'rrfat_faq', array( 'title' => 'B' ), $rrfa_post_id ), 'add_row() works by name' );
	RRFA_Test::same( 3, add_row( 'field_rrfat_faq', array( 'field_rrfat_title' => 'C' ), $rrfa_post_id ), 'add_row() works by key' );
	update_row( 'rrfat_faq', 2, array( 'title' => 'B2' ), $rrfa_post_id );
	RRFA_Test::same( array( 'A', 'B2', 'C' ), wp_list_pluck( get_field( 'rrfat_faq', $rrfa_post_id ), 'title' ), 'update_row() changes one row' );
	delete_row( 'rrfat_faq', 1, $rrfa_post_id );
	RRFA_Test::same( array( 'B2', 'C' ), wp_list_pluck( get_field( 'rrfat_faq', $rrfa_post_id ), 'title' ), 'delete_row() removes the row and closes the gap' );
	update_sub_field( array( 'rrfat_faq', 2, 'title' ), 'C2', $rrfa_post_id );
	RRFA_Test::same( 'C2', get_post_meta( $rrfa_post_id, 'rrfat_faq_1_title', true ), 'update_sub_field() writes the cell' );

	// A value left out of a row at a reorder is cleared, not kept from the old row.
	update_field(
		'rrfat_faq',
		array(
			array(
				'field_rrfat_title' => 'One',
				'field_rrfat_email' => 'one@example.invalid',
			),
			array( 'field_rrfat_title' => 'Two' ),
		),
		$rrfa_post_id
	);
	acf_update_value(
		array(
			'rrfarow-b' => array( 'field_rrfat_title' => 'Two' ),
			'rrfarow-a' => array(
				'field_rrfat_title' => 'One',
				'field_rrfat_email' => 'one@example.invalid',
			),
		),
		$rrfa_post_id,
		acf_get_field( 'field_rrfat_faq' )
	);
	RRFA_Test::same( '', get_post_meta( $rrfa_post_id, 'rrfat_faq_0_email', true ), 'Reorder leaves no stale value for a hidden sub-field' );
	RRFA_Test::same( 'one@example.invalid', get_post_meta( $rrfa_post_id, 'rrfat_faq_1_email', true ), 'Reorder moves values with their row' );

	// Template rows and unknown keys never reach the database.
	$rrfa_placeholder = RRFA_Row_Store::template_row_id( acf_get_field( 'field_rrfat_faq' ) );
	acf_update_value(
		array(
			'acfcloneindex'   => array( 'field_rrfat_title' => 'Template A' ),
			$rrfa_placeholder => array( 'field_rrfat_title' => 'Template B' ),
			'rrfarow-a'       => array(
				'field_rrfat_title' => 'Kept',
				'field_unknown'     => 'Injected',
				'unknown_name'      => 'Injected',
			),
		),
		$rrfa_post_id,
		acf_get_field( 'field_rrfat_faq' )
	);
	RRFA_Test::same( '1', get_post_meta( $rrfa_post_id, 'rrfat_faq', true ), 'Template rows are not counted' );
	$rrfa_all_values = implode( ' ', array_map( 'maybe_serialize', array_map( 'current', get_post_meta( $rrfa_post_id ) ) ) );
	RRFA_Test::ok( false === strpos( $rrfa_all_values, 'Injected' ) && false === strpos( $rrfa_all_values, 'Template' ), 'Template rows and unknown keys are not stored' );

	// Nesting, and cleanup of rows past the new end.
	$rrfa_rows = array();
	for ( $rrfa_i = 0; $rrfa_i < 5; $rrfa_i++ ) {
		$rrfa_rows[] = array(
			'title'   => "Row {$rrfa_i}",
			'details' => array( 'note' => "Note {$rrfa_i}" ),
			'steps'   => array(
				array( 'step' => "Step {$rrfa_i}.0" ),
				array( 'step' => "Step {$rrfa_i}.1" ),
			),
		);
	}
	update_field( 'rrfat_faq', $rrfa_rows, $rrfa_post_id );

	$rows = get_field( 'rrfat_faq', $rrfa_post_id );
	RRFA_Test::same( 'Step 3.1', $rows[3]['steps'][1]['step'], 'A nested repeater round-trips through get_field()' );
	RRFA_Test::same( 'Note 4', $rows[4]['details']['note'], 'A Group in a row round-trips through get_field()' );

	$rrfa_seen = array();
	while ( have_rows( 'rrfat_faq', $rrfa_post_id ) ) {
		the_row();
		while ( have_rows( 'steps' ) ) {
			the_row();
			$rrfa_seen[] = get_sub_field( 'step' );
		}
	}
	RRFA_Test::same( 'Step 4.1', end( $rrfa_seen ), 'Nested have_rows() loops read the inner rows' );
	RRFA_Test::same( 10, count( $rrfa_seen ), 'Nested have_rows() loops visit every inner row' );

	update_field( 'rrfat_faq', array_slice( $rrfa_rows, 0, 2 ), $rrfa_post_id );
	$rrfa_leftovers = preg_grep( '/^_?rrfat_faq_[2-4]_/', rrfa_test_meta_keys( $rrfa_post_id ) );
	RRFA_Test::same( array(), array_values( $rrfa_leftovers ), 'Shrinking from 5 rows to 2 leaves no orphan meta, including nested rows' );

	$rrfa_shrunk                          = get_field( 'rrfat_faq', $rrfa_post_id, false );
	$rrfa_shrunk[0]['field_rrfat_nested'] = array( array( 'field_rrfat_step' => 'Only step' ) );
	update_field( 'rrfat_faq', $rrfa_shrunk, $rrfa_post_id );
	RRFA_Test::same( '', get_post_meta( $rrfa_post_id, 'rrfat_faq_0_steps_1_step', true ), 'Shrinking a nested repeater removes its extra rows' );

	delete_field( 'rrfat_faq', $rrfa_post_id );
	RRFA_Test::same( array(), rrfa_test_meta_keys( $rrfa_post_id ), 'delete_field() removes all row meta' );
	RRFA_Test::ok( ! get_field( 'rrfat_faq', $rrfa_post_id ), 'get_field() is empty after delete_field()' );
	update_field( 'rrfat_faq', array(), $rrfa_post_id );
	RRFA_Test::same( false, get_field( 'rrfat_faq', $rrfa_post_id ), 'get_field() returns false with zero rows saved' );
	RRFA_Test::ok( ! have_rows( 'rrfat_faq', $rrfa_post_id ), 'have_rows() is false with no rows' );

	// Validation.
	$rrfa_field     = acf_get_field( 'field_rrfat_faq' );
	$rrfa_validate  = static function ( $value, array $field ) {
		acf_reset_validation_errors();
		acf_validate_value( $value, $field, 'acf[' . $field['key'] . ']' );
		return wp_list_pluck( acf_get_validation_errors(), 'input' );
	};
	$rrfa_row_input = 'acf[field_rrfat_faq][r1]';
	$rrfa_filled    = array(
		'field_rrfat_title' => 'Filled',
		'field_rrfat_email' => 'ok@example.invalid',
	);

	RRFA_Test::same(
		array( "{$rrfa_row_input}[field_rrfat_title]" ),
		$rrfa_validate( array( 'r1' => array( 'field_rrfat_title' => '' ) ), $rrfa_field ),
		'A required sub-field fails validation at its own input'
	);
	RRFA_Test::same(
		array( "{$rrfa_row_input}[field_rrfat_email]" ),
		$rrfa_validate(
			array(
				'r1' => array(
					'field_rrfat_title' => 'Title',
					'field_rrfat_email' => 'not-an-email',
				),
			),
			$rrfa_field
		),
		'An invalid email sub-field fails validation'
	);
	RRFA_Test::same( array(), $rrfa_validate( array( 'r1' => $rrfa_filled ), $rrfa_field ), 'A valid row passes validation' );
	RRFA_Test::same(
		array(),
		$rrfa_validate( array( 'r1' => array( 'field_rrfat_email' => 'ok@example.invalid' ) ), $rrfa_field ),
		'A required sub-field hidden by conditional logic is not validated'
	);

	$rrfa_limited        = $rrfa_field;
	$rrfa_limited['min'] = 2;
	$rrfa_limited['max'] = 3;
	$rrfa_with_rows      = static function ( $count ) use ( $rrfa_filled ) {
		$rows = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[ "r{$i}" ] = $rrfa_filled;
		}
		return $rows;
	};
	RRFA_Test::same( array( 'acf[field_rrfat_faq]' ), $rrfa_validate( $rrfa_with_rows( 1 ), $rrfa_limited ), 'Fewer rows than the minimum fails' );
	RRFA_Test::same( array( 'acf[field_rrfat_faq]' ), $rrfa_validate( '', $rrfa_limited ), 'No rows fails a minimum' );
	RRFA_Test::same( array( 'acf[field_rrfat_faq]' ), $rrfa_validate( $rrfa_with_rows( 4 ), $rrfa_limited ), 'More rows than the maximum fails' );
	RRFA_Test::same( array(), $rrfa_validate( $rrfa_with_rows( 3 ), $rrfa_limited ), 'A row count within the limits passes' );

	$rrfa_blank = array_map(
		static function () {
			return array( 'field_rrfat_email' => '' );
		},
		array_flip( array( 'r1', 'r2' ) )
	);
	RRFA_Test::same( array(), $rrfa_validate( $rrfa_blank, $rrfa_limited ), 'Blank rows count toward the minimum' );
	acf_update_value( $rrfa_blank, $rrfa_post_id, $rrfa_limited );
	RRFA_Test::same( '2', get_post_meta( $rrfa_post_id, 'rrfat_faq', true ), 'Blank rows are stored, so validated and stored counts match' );

	acf_reset_validation_errors();

	// The rendered form has the expected structure, and a template that holds no live inputs.
	ob_start();
	$rrfa_render_field          = acf_get_field( 'field_rrfat_faq' );
	$rrfa_render_field['value'] = array( array( 'field_rrfat_title' => '<b>Bold</b> title' ) );
	acf_render_field_wrap( $rrfa_render_field );
	$rrfa_html = ob_get_clean();
	RRFA_Test::ok( false !== strpos( $rrfa_html, 'name="acf[field_rrfat_faq]" value=""' ), 'An empty input always posts, so validation runs with no rows' );
	RRFA_Test::ok( false !== strpos( $rrfa_html, 'name="acf[field_rrfat_faq][rrfarow-field_rrfat_faq-0][field_rrfat_title]"' ), 'Row inputs use the row ID' );
	RRFA_Test::ok( false !== strpos( $rrfa_html, '<template class="rrfa-template">' ), 'The new-row template is inert' );
	RRFA_Test::ok( false !== strpos( $rrfa_html, 'rrfaclone_field_rrfat_nested__' ), 'A nested repeater keeps its own template placeholder' );
	RRFA_Test::ok( false === strpos( $rrfa_html, '<span class="rrfa-row__title"><b>' ), 'Row titles are escaped' );

	$rrfa_table_field           = $rrfa_render_field;
	$rrfa_table_field['layout'] = 'table';
	ob_start();
	acf_render_field_wrap( $rrfa_table_field );
	$rrfa_table_html = ob_get_clean();
	RRFA_Test::ok( false !== strpos( $rrfa_table_html, '<table class="acf-table rrfa-table">' ) && false !== strpos( $rrfa_table_html, 'data-rrfa-label="Title"' ), 'The table layout renders with labels for narrow screens' );

	// REST output stays light, with no expanded user or private post data.
	update_field(
		'rrfat_faq',
		array(
			array(
				'title'  => 'REST',
				'page'   => $rrfa_page_id,
				'person' => $rrfa_user_id,
			),
		),
		$rrfa_post_id
	);
	wp_set_current_user( 0 );
	$rrfa_field = acf_get_field( 'field_rrfat_faq' );
	$rrfa_raw   = acf_get_value( $rrfa_post_id, $rrfa_field );

	foreach ( array( 'light', 'standard' ) as $rrfa_format ) {
		$rrfa_rest = acf_format_value_for_rest( $rrfa_raw, $rrfa_post_id, $rrfa_field, $rrfa_format );
		$rrfa_json = wp_json_encode( $rrfa_rest );
		RRFA_Test::same( 'REST', $rrfa_rest[0]['title'], "REST {$rrfa_format} output is keyed by sub-field name" );
		RRFA_Test::ok( false === strpos( $rrfa_json, 'rrfa-private@example.invalid' ), "REST {$rrfa_format} output has no user email" );
		RRFA_Test::ok( false === strpos( $rrfa_json, 'RRFA private target' ), "REST {$rrfa_format} output has no private post title" );
	}

	$rrfa_wrapped = acf_get_valid_field(
		array(
			'key'        => 'field_rrfat_wrapper',
			'name'       => 'rrfat_wrapper',
			'type'       => 'group',
			'sub_fields' => array( $rrfa_field ),
		)
	);
	$rrfa_json    = wp_json_encode( acf_format_value_for_rest( array( 'field_rrfat_faq' => $rrfa_raw ), $rrfa_post_id, $rrfa_wrapped, 'standard' ) );
	RRFA_Test::ok( false === strpos( $rrfa_json, 'rrfa-private@example.invalid' ), 'REST standard output of a repeater in a Group has no user email' );

	$rrfa_schema = acf_get_field_rest_schema( $rrfa_field );
	RRFA_Test::ok( ! empty( $rrfa_schema['items']['properties']['title'] ), 'The REST schema lists sub-fields' );
	RRFA_Test::ok( is_wp_error( apply_filters( 'acf/validate_rest_value/type=rrfa_repeater', true, array(), $rrfa_field ) ), 'REST writes to the field are refused' );

	// JSON export includes every child field, nested at each level.
	$rrfa_definition = array(
		'key'      => 'group_rrfatjson',
		'title'    => 'RRFA JSON test',
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'page',
				),
			),
		),
		'fields'   => array(
			array(
				'key'        => 'field_rrfatj_outer',
				'name'       => 'rrfatj_outer',
				'label'      => 'Outer',
				'type'       => 'rrfa_repeater',
				'layout'     => 'table',
				'collapsed'  => 'field_rrfatj_title',
				'min'        => 1,
				'max'        => 9,
				'sub_fields' => array(
					array(
						'key'   => 'field_rrfatj_title',
						'name'  => 'title',
						'label' => 'Title',
						'type'  => 'text',
					),
					array(
						'key'        => 'field_rrfatj_inner',
						'name'       => 'inner',
						'label'      => 'Inner',
						'type'       => 'rrfa_repeater',
						'sub_fields' => array(
							array(
								'key'   => 'field_rrfatj_leaf',
								'name'  => 'leaf',
								'label' => 'Leaf',
								'type'  => 'text',
							),
						),
					),
				),
			),
		),
	);
	$rrfa_stored[]   = 'group_rrfatjson';
	acf_import_internal_post_type( $rrfa_definition, 'acf-field-group' );
	$rrfa_export = rrfa_test_export( 'group_rrfatjson' );
	$rrfa_json   = acf_json_encode( array( $rrfa_export ) );
	$rrfa_parsed = json_decode( $rrfa_json, true );
	$rrfa_expect = rrfa_test_outline( $rrfa_definition['fields'] );
	RRFA_Test::same( JSON_ERROR_NONE, json_last_error(), 'JSON export is valid JSON' );
	RRFA_Test::same( $rrfa_expect, rrfa_test_outline( $rrfa_parsed[0]['fields'] ), 'JSON export has every child field, nested correctly' );
	RRFA_Test::same( 'table', $rrfa_parsed[0]['fields'][0]['layout'], 'JSON export keeps the field settings' );
	RRFA_Test::same( 9, (int) $rrfa_parsed[0]['fields'][0]['max'], 'JSON export keeps row limits' );

	// JSON import round trip, and re-import behaviour.
	$rrfa_copy        = $rrfa_parsed[0];
	$rrfa_copy['key'] = 'group_rrfatcopy';
	array_walk_recursive(
		$rrfa_copy,
		static function ( &$value, $name ) {
			if ( 'key' === $name || 'collapsed' === $name ) {
				$value = str_replace( 'rrfatj_', 'rrfatc_', $value );
			}
		}
	);
	$rrfa_stored[]  = 'group_rrfatcopy';
	$rrfa_imported  = acf_import_internal_post_type( $rrfa_copy, 'acf-field-group' );
	$rrfa_reexport  = rrfa_test_export( 'group_rrfatcopy' );
	$rrfa_copy_keys = rrfa_test_outline( $rrfa_copy['fields'] );
	RRFA_Test::same( $rrfa_copy_keys, rrfa_test_outline( $rrfa_reexport['fields'] ), 'Importing JSON recreates the same structure' );

	// Re-import over the same group, as ACF's import tool does.
	$rrfa_copy['ID'] = $rrfa_imported['ID'];
	acf_import_internal_post_type( $rrfa_copy, 'acf-field-group' );
	$rrfa_reexport = rrfa_test_export( 'group_rrfatcopy' );
	RRFA_Test::same( $rrfa_copy_keys, rrfa_test_outline( $rrfa_reexport['fields'] ), 'Re-importing does not duplicate fields' );

	$rrfa_trimmed = $rrfa_copy;
	array_pop( $rrfa_trimmed['fields'][0]['sub_fields'] );
	acf_import_internal_post_type( $rrfa_trimmed, 'acf-field-group' );
	$rrfa_reexport = rrfa_test_export( 'group_rrfatcopy' );
	RRFA_Test::same( rrfa_test_outline( $rrfa_trimmed['fields'] ), rrfa_test_outline( $rrfa_reexport['fields'] ), 'A child field removed from the file is removed from the group' );
	RRFA_Test::same( false, acf_get_field_post( 'field_rrfatc_leaf' ) ? true : false, 'Removing a nested repeater also removes its child fields' );

	// PHP export output, registered as ACF's "Generate PHP" code would be.
	$rrfa_php_code = html_entity_decode( acf_export_internal_post_type_as_php( $rrfa_export, 'acf-field-group' ), ENT_QUOTES );
	RRFA_Test::ok( false !== strpos( $rrfa_php_code, 'field_rrfatj_leaf' ), 'PHP export includes nested child fields' );
	$rrfa_local        = $rrfa_export;
	$rrfa_local['key'] = 'group_rrfatlocal';
	array_walk_recursive(
		$rrfa_local,
		static function ( &$value, $name ) {
			if ( 'key' === $name || 'collapsed' === $name ) {
				$value = str_replace( 'rrfatj_', 'rrfatl_', $value );
			}
		}
	);
	$rrfa_local['fields'][0]['name'] = 'rrfatl_outer';
	acf_add_local_field_group( $rrfa_local );
	$rrfa_local_field = acf_get_field( 'field_rrfatl_outer' );
	RRFA_Test::same( rrfa_test_outline( $rrfa_local['fields'] ), rrfa_test_outline( array( $rrfa_local_field ) ), 'A locally registered group has every child field' );
	update_field(
		'field_rrfatl_outer',
		array(
			array(
				'title' => 'Local',
				'inner' => array( array( 'leaf' => 'Local leaf' ) ),
			),
		),
		$rrfa_post_id
	);
	$rows = get_field( 'field_rrfatl_outer', $rrfa_post_id );
	RRFA_Test::same( 'Local leaf', $rows[0]['inner'][0]['leaf'], 'Rows save and load through a locally registered group' );
	delete_field( 'field_rrfatl_outer', $rrfa_post_id );

	// Local JSON: a save writes nested sub-fields to the file ACF loads back.
	wp_mkdir_p( $rrfa_temp_dir );
	$rrfa_save_path = static function () use ( $rrfa_temp_dir ) {
		return $rrfa_temp_dir;
	};
	add_filter( 'acf/settings/save_json', $rrfa_save_path, 99 );
	acf_update_setting( 'json', true );
	$rrfa_group = acf_get_field_group( 'group_rrfatjson' );
	acf_update_field_group( $rrfa_group );
	remove_filter( 'acf/settings/save_json', $rrfa_save_path, 99 );
	$rrfa_file = $rrfa_temp_dir . '/group_rrfatjson.json';
	RRFA_Test::ok( file_exists( $rrfa_file ), 'Saving a group writes a Local JSON file' );
	if ( file_exists( $rrfa_file ) ) {
		$rrfa_file_data = json_decode( file_get_contents( $rrfa_file ), true );
		RRFA_Test::same( $rrfa_expect, rrfa_test_outline( $rrfa_file_data['fields'] ), 'The Local JSON file has nested child fields' );

		$rrfa_file_data['key'] = 'group_rrfatsync';
		array_walk_recursive(
			$rrfa_file_data,
			static function ( &$value, $name ) {
				if ( 'key' === $name || 'collapsed' === $name ) {
					$value = str_replace( 'rrfatj_', 'rrfats_', $value );
				}
			}
		);
		$rrfa_file_data['fields'][0]['name'] = 'rrfats_outer';
		acf_add_local_field_group( $rrfa_file_data );
		RRFA_Test::same( rrfa_test_outline( $rrfa_file_data['fields'] ), rrfa_test_outline( array( acf_get_field( 'field_rrfats_outer' ) ) ), 'A group loaded from Local JSON keeps its child fields' );
	}

	// Duplicating a repeater copies its child fields under the new parent.
	$rrfa_stored_outer = acf_get_field( 'field_rrfatj_outer' );
	$rrfa_duplicate    = acf_duplicate_field( $rrfa_stored_outer['ID'], acf_get_field_group( 'group_rrfatjson' )['ID'] );
	$rrfa_dup_reloaded = acf_get_field( $rrfa_duplicate['ID'] );
	RRFA_Test::same( 2, count( $rrfa_dup_reloaded['sub_fields'] ), 'A duplicated repeater has its own copies of the child fields' );
	RRFA_Test::ok( $rrfa_dup_reloaded['sub_fields'][0]['key'] !== 'field_rrfatj_title', 'Copied child fields get new keys' );
	RRFA_Test::same( $rrfa_dup_reloaded['sub_fields'][0]['key'], $rrfa_dup_reloaded['collapsed'], 'A duplicate\'s Row Title Field points at the copied sub-field' );
	RRFA_Test::same( 1, count( acf_get_field( 'field_rrfatj_outer' )['sub_fields'][1]['sub_fields'] ), 'Duplicating leaves the original child fields in place' );
	RRFA_Test::same( 2, count( acf_get_field( 'field_rrfatj_outer' )['sub_fields'] ), 'Duplicating adds nothing to the original repeater' );

	// Group-level actions keep child fields.
	$rrfa_source_group = acf_get_field_group( 'group_rrfatjson' );
	$rrfa_group_copy   = acf_duplicate_field_group( $rrfa_source_group['ID'] );
	$rrfa_stored[]     = $rrfa_group_copy['key'];
	$rrfa_copy_fields  = acf_get_fields( $rrfa_group_copy );
	RRFA_Test::same( 'rrfa_repeater', $rrfa_copy_fields[0]['sub_fields'][1]['type'], 'Duplicating a field group copies a nested repeater' );
	RRFA_Test::ok( 'field_rrfatj_leaf' !== $rrfa_copy_fields[0]['sub_fields'][1]['sub_fields'][0]['key'], 'A duplicated field group has its own nested child fields' );
	$rrfa_before_trash = rrfa_test_outline( acf_get_fields( $rrfa_group_copy ) );
	acf_trash_field_group( $rrfa_group_copy['ID'] );
	acf_untrash_field_group( $rrfa_group_copy['ID'] );
	RRFA_Test::same( $rrfa_before_trash, rrfa_test_outline( acf_get_fields( acf_get_field_group( $rrfa_group_copy['ID'] ) ) ), 'Trashing and restoring a field group keeps its child fields' );

	// Deleting a repeater removes its stored child fields.
	$rrfa_copy_title_id = acf_get_field_post( $rrfa_dup_reloaded['sub_fields'][0]['key'] )->ID;
	acf_delete_field( $rrfa_duplicate['ID'] );
	RRFA_Test::same( null, get_post( $rrfa_copy_title_id ), 'Deleting a repeater deletes its stored child fields' );

	// Values kept off the form stay with their row. Row IDs below are what the form submits.
	$rrfa_faq    = acf_get_field( 'field_rrfat_faq' );
	$rrfa_row_id = static function ( $index ) use ( $rrfa_faq ) {
		return RRFA_Row_Store::row_id( $rrfa_faq, $index );
	};
	$rrfa_seed   = static function () use ( $rrfa_post_id ) {
		update_field(
			'rrfat_faq',
			array(
				array(
					'title'   => 'A',
					'email'   => 'a@example.invalid',
					'details' => array( 'note' => 'note A' ),
					'steps'   => array(
						array( 'step' => 'A0' ),
						array( 'step' => 'A1' ),
					),
				),
				array(
					'title'   => 'B',
					'email'   => 'b@example.invalid',
					'details' => array( 'note' => 'note B' ),
					'steps'   => array( array( 'step' => 'B0' ) ),
				),
			),
			$rrfa_post_id
		);
	};
	$rrfa_cell   = static function ( $key ) use ( $rrfa_post_id ) {
		return get_post_meta( $rrfa_post_id, $key, true );
	};

	// A sub-field left out of a row that stayed put is untouched, even on a large number of saves.
	$rrfa_seed();
	acf_update_value(
		array(
			$rrfa_row_id( 0 ) => array( 'field_rrfat_title' => 'A' ),
			$rrfa_row_id( 1 ) => array( 'field_rrfat_title' => 'B' ),
		),
		$rrfa_post_id,
		$rrfa_faq
	);
	RRFA_Test::same( 'a@example.invalid', $rrfa_cell( 'rrfat_faq_0_email' ), 'A sub-field kept off the form keeps its value' );
	RRFA_Test::same( 'A1', $rrfa_cell( 'rrfat_faq_0_steps_1_step' ), 'A nested repeater kept off the form keeps its rows' );
	RRFA_Test::same( 'note B', $rrfa_cell( 'rrfat_faq_1_details_note' ), 'A Group kept off the form keeps its value' );

	// Saved rows swap places: kept-off values must follow them, at every depth.
	$rrfa_seed();
	acf_update_value(
		array(
			$rrfa_row_id( 1 ) => array( 'field_rrfat_title' => 'B' ),
			$rrfa_row_id( 0 ) => array( 'field_rrfat_title' => 'A' ),
		),
		$rrfa_post_id,
		$rrfa_faq
	);
	RRFA_Test::same( array( 'B', 'b@example.invalid', 'A', 'a@example.invalid' ), array( $rrfa_cell( 'rrfat_faq_0_title' ), $rrfa_cell( 'rrfat_faq_0_email' ), $rrfa_cell( 'rrfat_faq_1_title' ), $rrfa_cell( 'rrfat_faq_1_email' ) ), 'A kept-off value moves with its row' );
	RRFA_Test::same( array( 'B0', 'A0', 'A1' ), array( $rrfa_cell( 'rrfat_faq_0_steps_0_step' ), $rrfa_cell( 'rrfat_faq_1_steps_0_step' ), $rrfa_cell( 'rrfat_faq_1_steps_1_step' ) ), 'A kept-off nested repeater moves with its row' );
	RRFA_Test::same( '', $rrfa_cell( 'rrfat_faq_0_steps_1_step' ), 'A moved nested repeater leaves no extra rows behind' );
	RRFA_Test::same( array( 'note B', 'note A' ), array( $rrfa_cell( 'rrfat_faq_0_details_note' ), $rrfa_cell( 'rrfat_faq_1_details_note' ) ), 'A kept-off Group moves with its row' );

	// A submitted nested repeater inside a moved parent keeps its own kept-off values.
	$rrfa_steps = RRFA_Row_Store::sub_field_for_row( $rrfa_faq, acf_get_sub_field( 'field_rrfat_nested', $rrfa_faq ), 0 );
	$rrfa_seed();
	wp_cache_flush();
	update_post_meta( $rrfa_post_id, 'rrfat_faq_0_steps_1_step', 'A1' );
	acf_update_value(
		array(
			$rrfa_row_id( 1 ) => array(
				'field_rrfat_title'  => 'B',
				'field_rrfat_nested' => array( RRFA_Row_Store::row_id( $rrfa_steps, 0 ) => array( 'field_rrfat_step' => 'B0 edited' ) ),
			),
			$rrfa_row_id( 0 ) => array(
				'field_rrfat_title'  => 'A',
				'field_rrfat_nested' => array(
					RRFA_Row_Store::row_id( $rrfa_steps, 1 ) => array(),
					RRFA_Row_Store::row_id( $rrfa_steps, 0 ) => array( 'field_rrfat_step' => 'A0 edited' ),
				),
			),
		),
		$rrfa_post_id,
		$rrfa_faq
	);
	RRFA_Test::same( array( 'B0 edited', 'A1', 'A0 edited' ), array( $rrfa_cell( 'rrfat_faq_0_steps_0_step' ), $rrfa_cell( 'rrfat_faq_1_steps_0_step' ), $rrfa_cell( 'rrfat_faq_1_steps_1_step' ) ), 'Nested rows in a moved parent read kept-off values from the old parent' );

	// A new row takes over a position: nothing there belongs to it.
	$rrfa_seed();
	acf_update_value(
		array(
			$rrfa_row_id( 0 )  => array( 'field_rrfat_title' => 'A' ),
			'rrfarow-brandnew' => array(
				'field_rrfat_title'  => 'New',
				'field_rrfat_nested' => array( RRFA_Row_Store::row_id( $rrfa_steps, 0 ) => array() ),
			),
			$rrfa_row_id( 1 )  => array( 'field_rrfat_title' => 'B' ),
		),
		$rrfa_post_id,
		$rrfa_faq
	);
	RRFA_Test::same( array( '', 'b@example.invalid' ), array( $rrfa_cell( 'rrfat_faq_1_email' ), $rrfa_cell( 'rrfat_faq_2_email' ) ), 'A new row inherits nothing, and the moved row keeps its value' );
	RRFA_Test::same( array( '', 'B0' ), array( $rrfa_cell( 'rrfat_faq_1_steps_0_step' ), $rrfa_cell( 'rrfat_faq_2_steps_0_step' ) ), 'A nested row copied into a new parent inherits nothing from that position' );

	// A forged position beyond the saved rows is treated as a new row.
	$rrfa_seed();
	acf_update_value( array( $rrfa_row_id( 99 ) => array( 'field_rrfat_title' => 'Forged' ) ), $rrfa_post_id, $rrfa_faq );
	RRFA_Test::same( '', $rrfa_cell( 'rrfat_faq_0_email' ), 'A row ID past the saved rows has no earlier values' );

	// A second name for a saved row is a new row, so it can't copy that row's kept-off values.
	$rrfa_seed();
	acf_update_value(
		array(
			$rrfa_row_id( 0 )       => array( 'field_rrfat_title' => 'A' ),
			$rrfa_row_id( 0 ) . '0' => array( 'field_rrfat_title' => 'Copy' ),
			$rrfa_row_id( 1 )       => array( 'field_rrfat_title' => 'B' ),
		),
		$rrfa_post_id,
		$rrfa_faq
	);
	RRFA_Test::same( array( 'a@example.invalid', '', 'b@example.invalid' ), array( $rrfa_cell( 'rrfat_faq_0_email' ), $rrfa_cell( 'rrfat_faq_1_email' ), $rrfa_cell( 'rrfat_faq_2_email' ) ), 'A row ID with a leading zero copies nothing' );
	RRFA_Test::same( array( 'A0', '' ), array( $rrfa_cell( 'rrfat_faq_0_steps_0_step' ), $rrfa_cell( 'rrfat_faq_1_steps_0_step' ) ), 'A row ID with a leading zero copies no nested rows' );

	// Two rows claiming one saved position: only the first gets its kept-off values.
	$rrfa_claimed = array();
	$rrfa_claim   = new ReflectionMethod( 'RRFA_Row_Store', 'claim_origin' );
	if ( PHP_VERSION_ID < 80100 ) {
		$rrfa_claim->setAccessible( true );
	}
	RRFA_Test::same(
		array( 1, null, null ),
		array(
			$rrfa_claim->invokeArgs( null, array( $rrfa_faq, $rrfa_row_id( 1 ), 2, &$rrfa_claimed ) ),
			$rrfa_claim->invokeArgs( null, array( $rrfa_faq, $rrfa_row_id( 1 ), 2, &$rrfa_claimed ) ),
			$rrfa_claim->invokeArgs( null, array( $rrfa_faq, $rrfa_row_id( 0 ) . '0', 2, &$rrfa_claimed ) ),
		),
		'A saved position can be claimed once, and only by its exact row ID'
	);

	// A form cut off at max_input_vars must not delete the rows it lost.
	$rrfa_submit = static function ( array $value, $inputs ) use ( $rrfa_post_id, $rrfa_faq ) {
		$_POST = array(
			'_acf_nonce' => 'x',
			'padding'    => $inputs ? array_fill( 0, $inputs, 'x' ) : array(),
		);
		_acf_save_submitted_values(
			static function () use ( $value, $rrfa_post_id, $rrfa_faq ) {
				acf_update_value( $value, $rrfa_post_id, $rrfa_faq );
			}
		);
		$_POST = array();
	};
	$rrfa_marker = array( RRFA_Row_Store::COMPLETE_KEY => '1' );
	$rrfa_big    = (int) ini_get( 'max_input_vars' );
	$rrfa_seed();
	$rrfa_submit( array( $rrfa_row_id( 0 ) => array( 'field_rrfat_title' => 'Cut off' ) ), $rrfa_big );
	RRFA_Test::same( array( '2', 'A', 'B' ), array( $rrfa_cell( 'rrfat_faq' ), $rrfa_cell( 'rrfat_faq_0_title' ), $rrfa_cell( 'rrfat_faq_1_title' ) ), 'A form cut off before the marker changes nothing' );
	$rrfa_submit( array( $rrfa_row_id( 0 ) => array( 'field_rrfat_title' => 'Whole' ) ) + $rrfa_marker, $rrfa_big );
	RRFA_Test::same( array( '1', 'Whole' ), array( $rrfa_cell( 'rrfat_faq' ), $rrfa_cell( 'rrfat_faq_0_title' ) ), 'A large form with its marker saves' );
	$rrfa_seed();
	$rrfa_submit( array( array( 'field_rrfat_title' => 'From code' ) ), 0 );
	RRFA_Test::same( array( '1', 'From code' ), array( $rrfa_cell( 'rrfat_faq' ), $rrfa_cell( 'rrfat_faq_0_title' ) ), 'A small form whose value code replaced still saves' );

	// A submitted value for a sub-field hidden by prepare_field is ignored, at every depth.
	$rrfa_hidden = array( 'field_rrfat_email', 'field_rrfat_group_note', 'field_rrfat_step' );
	foreach ( $rrfa_hidden as $rrfa_hidden_key ) {
		add_filter( "acf/prepare_field/key={$rrfa_hidden_key}", '__return_false' );
	}
	$rrfa_seed();
	$rrfa_submit(
		array(
			$rrfa_row_id( 0 )  => array(
				'field_rrfat_title'  => 'A edited',
				'field_rrfat_email'  => 'forged@example.invalid',
				'field_rrfat_group'  => array( 'field_rrfat_group_note' => 'forged note' ),
				'field_rrfat_nested' => array(
					RRFA_Row_Store::row_id( $rrfa_steps, 0 ) => array( 'field_rrfat_step' => 'forged step' ),
					RRFA_Row_Store::row_id( $rrfa_steps, 1 ) => array(),
				),
			),
			$rrfa_row_id( 1 )  => array( 'field_rrfat_title' => 'B' ),
			'rrfarow-brandnew' => array(
				'field_rrfat_title' => 'New',
				'field_rrfat_email' => 'forged@example.invalid',
			),
		) + $rrfa_marker,
		0
	);
	RRFA_Test::same( array( 'A edited', 'a@example.invalid' ), array( $rrfa_cell( 'rrfat_faq_0_title' ), $rrfa_cell( 'rrfat_faq_0_email' ) ), 'A forged value for a hidden sub-field is ignored, and visible ones save' );
	RRFA_Test::same( '', $rrfa_cell( 'rrfat_faq_2_email' ), 'A new row gets no forged value for a hidden sub-field' );
	RRFA_Test::same( 'note A', $rrfa_cell( 'rrfat_faq_0_details_note' ), 'A forged value for a hidden sub-field in a Group is ignored' );
	RRFA_Test::same( array( 'A0', 'A1' ), array( $rrfa_cell( 'rrfat_faq_0_steps_0_step' ), $rrfa_cell( 'rrfat_faq_0_steps_1_step' ) ), 'A forged value for a hidden sub-field in a nested repeater is ignored' );

	// Hidden values still move with their row in a submitted form.
	$rrfa_seed();
	$rrfa_submit(
		array(
			$rrfa_row_id( 1 ) => array( 'field_rrfat_title' => 'B' ),
			$rrfa_row_id( 0 ) => array( 'field_rrfat_title' => 'A' ),
		) + $rrfa_marker,
		0
	);
	RRFA_Test::same( array( 'b@example.invalid', 'a@example.invalid' ), array( $rrfa_cell( 'rrfat_faq_0_email' ), $rrfa_cell( 'rrfat_faq_1_email' ) ), 'A hidden value moves with its row in a submitted form' );

	// Code that saves values isn't limited by what the edit screen shows.
	$rrfa_seed();
	update_field(
		'rrfat_faq',
		array(
			array(
				'title' => 'C',
				'email' => 'code@example.invalid',
			),
		),
		$rrfa_post_id
	);
	RRFA_Test::same( 'code@example.invalid', $rrfa_cell( 'rrfat_faq_0_email' ), 'update_field() still writes a hidden sub-field' );

	foreach ( $rrfa_hidden as $rrfa_hidden_key ) {
		remove_filter( "acf/prepare_field/key={$rrfa_hidden_key}", '__return_false' );
	}

	// Sub-fields with no name store nothing and don't appear in rows.
	$rrfa_with_tab                 = acf_get_field( 'field_rrfat_faq' );
	$rrfa_with_tab['sub_fields'][] = acf_get_valid_field(
		array(
			'key'  => 'field_rrfat_tab',
			'name' => '',
			'type' => 'tab',
		)
	);
	RRFA_Test::ok( ! array_key_exists( '', acf_format_value( array( array( 'field_rrfat_title' => 'T' ) ), $rrfa_post_id, $rrfa_with_tab )[0] ), 'A tab adds no empty key to rows' );

	// Field settings stay usable.
	$rrfa_type  = acf_get_field_type( 'rrfa_repeater' );
	$rrfa_clean = $rrfa_type->update_field(
		array(
			'min' => 5,
			'max' => 3,
		)
	);
	RRFA_Test::same( array( 5, 5 ), array( $rrfa_clean['min'], $rrfa_clean['max'] ), 'A maximum below the minimum is raised to it' );
	$rrfa_clean = $rrfa_type->update_field(
		array(
			'min' => -2,
			'max' => '',
		)
	);
	RRFA_Test::same( array( 0, 0 ), array( $rrfa_clean['min'], $rrfa_clean['max'] ), 'Negative and empty limits become 0' );
	RRFA_Test::same( array(), $rrfa_type->load_value( '1e9', $rrfa_post_id, $rrfa_faq ), 'A stored value that is not a row count loads no rows' );

	// Requiring rows still works, with the marker input in the submitted value.
	acf_reset_validation_errors();
	$rrfa_required             = $rrfa_faq;
	$rrfa_required['required'] = 1;
	RRFA_Test::same( array( 'acf[field_rrfat_faq]' ), $rrfa_validate( $rrfa_marker, $rrfa_required ), 'A required repeater with no rows fails validation' );
	acf_reset_validation_errors();

	// Rendering: blank rows stay open, and titles reveal nothing they shouldn't.
	$rrfa_render                    = static function ( array $field ) {
		ob_start();
		acf_render_field_wrap( $field );
		return ob_get_clean();
	};
	$rrfa_padded                    = $rrfa_faq;
	$rrfa_padded['min']             = 2;
	$rrfa_padded['start_collapsed'] = 1;
	$rrfa_padded['value']           = array( array( 'field_rrfat_title' => 'Saved' ) );
	$rrfa_html                      = $rrfa_render( $rrfa_padded );
	RRFA_Test::same( 1, substr_count( $rrfa_html, 'class="rrfa-row -collapsed"' ), 'Only saved rows start collapsed, not blank rows added for the minimum' );
	RRFA_Test::ok( false !== strpos( $rrfa_html, 'name="acf[field_rrfat_faq][' . RRFA_Row_Store::COMPLETE_KEY . ']"' ), 'The form includes the marker input' );
	RRFA_Test::ok( strpos( $rrfa_html, RRFA_Row_Store::COMPLETE_KEY ) > strrpos( substr( $rrfa_html, 0, strpos( $rrfa_html, '<template' ) ), 'rrfa-row__body' ), 'The marker input comes after the rows' );

	$rrfa_negative        = $rrfa_faq;
	$rrfa_negative['min'] = -3;
	RRFA_Test::same( 0, substr_count( $rrfa_render( $rrfa_negative ), 'data-id="rrfarow-field_rrfat_faq-' ), 'A negative minimum adds no blank rows' );

	$rrfa_password = acf_get_valid_field(
		array(
			'key'        => 'field_rrfat_pw_parent',
			'name'       => 'rrfat_pw_parent',
			'type'       => 'rrfa_repeater',
			'collapsed'  => 'field_rrfat_pw',
			'value'      => array( array( 'field_rrfat_pw' => 'hunter2' ) ),
			'sub_fields' => array(
				acf_get_valid_field(
					array(
						'key'  => 'field_rrfat_pw',
						'name' => 'pw',
						'type' => 'password',
					)
				),
			),
		)
	);
	RRFA_Test::ok( false === strpos( $rrfa_render( $rrfa_password ), '<span class="rrfa-row__title">hunter2' ), 'A password is never a row title' );

	$rrfa_hidden_title              = $rrfa_faq;
	$rrfa_hidden_title['collapsed'] = 'field_rrfat_title';
	$rrfa_hidden_title['value']     = array(
		array(
			'field_rrfat_title' => 'TOPSECRET',
			'field_rrfat_email' => 'visible@example.invalid',
		),
	);
	add_filter( 'acf/prepare_field/key=field_rrfat_title', '__return_false' );
	$rrfa_html = $rrfa_render( $rrfa_hidden_title );
	remove_filter( 'acf/prepare_field/key=field_rrfat_title', '__return_false' );
	RRFA_Test::ok( false === strpos( $rrfa_html, 'TOPSECRET' ), 'A sub-field hidden by prepare_field never reaches the page through its title' );

	// Other ACF functions.
	update_field(
		'rrfat_faq',
		array(
			array(
				'title' => 'Fn one',
				'steps' => array( array( 'step' => 'Fn step' ) ),
			),
		),
		$rrfa_post_id
	);
	RRFA_Test::same( 'Fn one', get_field( 'rrfat_faq_0_title', $rrfa_post_id ), 'A cell can be read directly by its full name' );
	RRFA_Test::ok( ! array_key_exists( 'rrfat_faq_0_title', get_fields( $rrfa_post_id ) ), 'get_fields() lists the repeater, not its cells' );
	RRFA_Test::same( 'Fn one', get_fields( $rrfa_post_id )['rrfat_faq'][0]['title'], 'get_fields() returns formatted rows' );
	RRFA_Test::same( 2, add_sub_row( array( 'rrfat_faq', 1, 'steps' ), array( 'step' => 'Added' ), $rrfa_post_id ), 'add_sub_row() adds to a nested repeater' );
	update_sub_row( array( 'rrfat_faq', 1, 'steps' ), 1, array( 'step' => 'Changed' ), $rrfa_post_id );
	RRFA_Test::same( 'Changed', get_post_meta( $rrfa_post_id, 'rrfat_faq_0_steps_0_step', true ), 'update_sub_row() changes a nested row' );
	delete_sub_row( array( 'rrfat_faq', 1, 'steps' ), 1, $rrfa_post_id );
	RRFA_Test::same( 'Added', get_post_meta( $rrfa_post_id, 'rrfat_faq_0_steps_0_step', true ), 'delete_sub_row() removes a nested row' );

	$rrfa_seen = array();
	while ( have_rows( 'rrfat_faq', $rrfa_post_id ) ) {
		$rrfa_seen[] = the_row( true );
		$rrfa_seen[] = get_row_index();
		$rrfa_seen[] = get_row_sub_value( 'field_rrfat_title' );
	}
	RRFA_Test::same( 'Fn one', $rrfa_seen[0]['title'], 'the_row( true ) returns a formatted row' );
	RRFA_Test::same( 1, $rrfa_seen[1], 'get_row_index() counts from 1' );
	RRFA_Test::same( 'Fn one', $rrfa_seen[2], 'get_row_sub_value() reads a raw cell' );

	$rrfa_filtered = array();
	$rrfa_filter   = static function ( $value, $post_id, $field ) use ( &$rrfa_filtered ) {
		$rrfa_filtered[] = $field['name'];
		return $value . ' (filtered)';
	};
	add_filter( 'acf/update_value/key=field_rrfat_title', $rrfa_filter, 10, 3 );
	update_field( 'rrfat_faq', array( array( 'title' => 'One' ), array( 'title' => 'Two' ) ), $rrfa_post_id );
	remove_filter( 'acf/update_value/key=field_rrfat_title', $rrfa_filter, 10 );
	RRFA_Test::same( array( 'rrfat_faq_0_title', 'rrfat_faq_1_title' ), $rrfa_filtered, 'acf/update_value filters run per cell with the row-specific name' );
	RRFA_Test::same( 'Two (filtered)', get_post_meta( $rrfa_post_id, 'rrfat_faq_1_title', true ), 'A sub-field filter can change the stored value' );

	$rrfa_escaped = get_field( 'rrfat_faq', $rrfa_post_id, true, true );
	RRFA_Test::same( 'One (filtered)', $rrfa_escaped[0]['title'], 'get_field() with escaping returns rows' );
	RRFA_Test::same( '', do_shortcode( '[acf field="rrfat_faq" post_id="' . $rrfa_post_id . '"]' ), 'The ACF shortcode prints nothing for a repeater' );

	// Rows work on objects other than posts.
	foreach ( array( 'user_' . $rrfa_user_id, 'options' ) as $rrfa_object ) {
		update_field(
			'field_rrfat_faq',
			array(
				array( 'title' => 'Object one' ),
				array( 'title' => 'Object two' ),
			),
			$rrfa_object
		);
		RRFA_Test::same( array( 'Object one', 'Object two' ), wp_list_pluck( get_field( 'field_rrfat_faq', $rrfa_object ), 'title' ), "Rows round-trip on {$rrfa_object}" );
		delete_field( 'field_rrfat_faq', $rrfa_object );
		RRFA_Test::ok( ! get_field( 'field_rrfat_faq', $rrfa_object ), "delete_field() clears rows on {$rrfa_object}" );
	}
	RRFA_Test::same( false, get_option( 'options_rrfat_faq_0_title' ), 'delete_field() leaves no row options behind' );

	// Revisions restore rows, and the compare screen gets a plain value.
	update_field(
		'rrfat_faq',
		array(
			array( 'title' => 'Original one' ),
			array( 'title' => 'Original two' ),
		),
		$rrfa_post_id
	);
	$rrfa_revision_id = _wp_put_post_revision( get_post( $rrfa_post_id ) );
	acf_save_post_revision( $rrfa_post_id );
	RRFA_Test::same( '2', get_post_meta( $rrfa_revision_id, 'rrfat_faq', true ), 'A revision stores the row count' );
	RRFA_Test::same( 'Original two', get_post_meta( $rrfa_revision_id, 'rrfat_faq_1_title', true ), 'A revision stores the cells' );

	update_field( 'rrfat_faq', array( array( 'title' => 'Changed' ) ), $rrfa_post_id );
	wp_restore_post_revision( $rrfa_revision_id );
	RRFA_Test::same( array( 'Original one', 'Original two' ), wp_list_pluck( get_field( 'rrfat_faq', $rrfa_post_id ), 'title' ), 'Restoring a revision restores its rows' );

	$rrfa_compare = acf()->revisions->wp_post_revision_field( get_post_meta( $rrfa_revision_id, 'rrfat_faq', true ), 'rrfat_faq', get_post( $rrfa_revision_id ), 'to' );
	RRFA_Test::ok( is_scalar( $rrfa_compare ), 'The revision compare screen gets a plain value for the repeater' );
} catch ( Throwable $rrfa_error ) {
	RRFA_Test::$failed[] = 'Uncaught ' . get_class( $rrfa_error ) . ': ' . $rrfa_error->getMessage() . ' at ' . $rrfa_error->getFile() . ':' . $rrfa_error->getLine();
} finally {
	wp_delete_post( $rrfa_post_id, true );
	wp_delete_post( $rrfa_page_id, true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $rrfa_user_id );
	foreach ( $rrfa_stored as $rrfa_key ) {
		rrfa_test_delete_stored_group( $rrfa_key );
	}
	foreach ( (array) glob( $rrfa_temp_dir . '/*.json' ) as $rrfa_file_path ) {
		unlink( $rrfa_file_path );
	}
	if ( is_dir( $rrfa_temp_dir ) ) {
		rmdir( $rrfa_temp_dir );
	}
}

echo RRFA_Test::$passed . " checks passed\n";

if ( RRFA_Test::$failed ) {
	echo count( RRFA_Test::$failed ) . " failed:\n";
	foreach ( RRFA_Test::$failed as $rrfa_message ) {
		echo "  - {$rrfa_message}\n";
	}
	exit( 1 );
}
