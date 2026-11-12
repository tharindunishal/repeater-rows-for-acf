<?php
/**
 * The Repeater Rows field type.
 *
 * @package RepeaterRowsForACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects rows to ACF's field type lifecycle.
 *
 * Row storage is in RRFA_Row_Store and markup is in RRFA_Row_Renderer.
 */
class RRFA_Field_Repeater extends acf_field {

	/**
	 * Sets up the field type.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->name          = 'rrfa_repeater';
		$this->label         = __( 'Repeater Rows', 'repeater-rows-for-acf' );
		$this->category      = 'layout';
		$this->description   = __( 'A set of sub-fields that can be repeated as many rows as needed, shown as collapsible cards or a compact table.', 'repeater-rows-for-acf' );
		$this->preview_image = RRFA_URL . 'assets/images/field-preview.svg';
		$this->have_rows     = 'multiple';
		$this->supports      = array_merge( $this->supports, array( 'bindings' => false ) );
		$this->defaults      = array(
			'sub_fields'      => array(),
			'min'             => 0,
			'max'             => 0,
			'layout'          => 'block',
			'collapsed'       => '',
			'start_collapsed' => 1,
			'button_label'    => __( 'Add Row', 'repeater-rows-for-acf' ),
		);

		$this->add_field_filter( 'acf/prepare_field_for_export', array( $this, 'prepare_field_for_export' ) );
		$this->add_field_filter( 'acf/prepare_field_for_import', array( $this, 'prepare_field_for_import' ) );
	}

	/**
	 * Loads the assets used on screens with ACF forms.
	 *
	 * @return void
	 */
	public function input_admin_enqueue_scripts() {
		wp_enqueue_style( 'rrfa-repeater-rows', RRFA_URL . 'assets/css/repeater-rows.css', array( 'acf-input', 'dashicons' ), RRFA_VERSION );
		wp_enqueue_script( 'rrfa-repeater-rows', RRFA_URL . 'assets/js/repeater-rows.js', array( 'acf-input', 'jquery-ui-sortable', 'jquery-touch-punch', 'wp-i18n' ), RRFA_VERSION, true );
		wp_set_script_translations( 'rrfa-repeater-rows', 'repeater-rows-for-acf' );
	}

	/**
	 * Loads the script for the field group editor.
	 *
	 * @return void
	 */
	public function field_group_admin_enqueue_scripts() {
		wp_enqueue_script( 'rrfa-field-settings', RRFA_URL . 'assets/js/field-settings.js', array( 'acf-field-group' ), RRFA_VERSION, true );
	}

	/**
	 * Prints the General tab settings.
	 *
	 * @param array $field The field being edited.
	 * @return void
	 */
	public function render_field_settings( $field ) {
		?>
		<div class="acf-field acf-field-setting-sub_fields" data-setting="rrfa_repeater" data-name="sub_fields">
			<div class="acf-label">
				<label><?php esc_html_e( 'Sub Fields', 'repeater-rows-for-acf' ); ?></label>
			</div>
			<div class="acf-input acf-input-sub">
				<?php
				acf_get_view(
					'acf-field-group/fields',
					array(
						'fields'      => $field['sub_fields'],
						'parent'      => $field['ID'],
						'is_subfield' => true,
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the Validation tab settings.
	 *
	 * @param array $field The field being edited.
	 * @return void
	 */
	public function render_field_validation_settings( $field ) {
		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Minimum Rows', 'repeater-rows-for-acf' ),
				'instructions' => __( 'Leave at 0 for no minimum.', 'repeater-rows-for-acf' ),
				'type'         => 'number',
				'name'         => 'min',
				'min'          => 0,
				'placeholder'  => '0',
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Maximum Rows', 'repeater-rows-for-acf' ),
				'instructions' => __( 'Leave at 0 for no maximum.', 'repeater-rows-for-acf' ),
				'type'         => 'number',
				'name'         => 'max',
				'min'          => 0,
				'placeholder'  => '0',
			)
		);
	}

	/**
	 * Prints the Presentation tab settings.
	 *
	 * @param array $field The field being edited.
	 * @return void
	 */
	public function render_field_presentation_settings( $field ) {
		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Layout', 'repeater-rows-for-acf' ),
				'instructions' => __( 'Cards suit most fields. Use Table only for short fields; large ones such as WYSIWYG or image have little room in a table.', 'repeater-rows-for-acf' ),
				'type'         => 'radio',
				'name'         => 'layout',
				'layout'       => 'horizontal',
				'choices'      => array(
					'block' => __( 'Cards', 'repeater-rows-for-acf' ),
					'table' => __( 'Table', 'repeater-rows-for-acf' ),
				),
			)
		);

		$title_choices = array();
		foreach ( RRFA_Row_Store::sub_fields( $field ) as $sub_field ) {
			if ( in_array( $sub_field['type'], RRFA_Row_Renderer::TITLE_FIELD_TYPES, true ) ) {
				$title_choices[ $sub_field['key'] ] = $sub_field['label'];
			}
		}

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Row Title Field', 'repeater-rows-for-acf' ),
				'instructions' => __( 'The sub-field shown in each card header. By default the first text-like sub-field is used.', 'repeater-rows-for-acf' ),
				'type'         => 'select',
				'name'         => 'collapsed',
				'allow_null'   => 1,
				'placeholder'  => __( 'Automatic', 'repeater-rows-for-acf' ),
				'choices'      => $title_choices,
				'ui'           => 0,
				'conditions'   => array(
					'field'    => 'layout',
					'operator' => '==',
					'value'    => 'block',
				),
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Start Collapsed', 'repeater-rows-for-acf' ),
				'instructions' => __( 'Show saved rows as titles until they are opened. New rows always start open.', 'repeater-rows-for-acf' ),
				'type'         => 'true_false',
				'name'         => 'start_collapsed',
				'ui'           => 1,
				'conditions'   => array(
					'field'    => 'layout',
					'operator' => '==',
					'value'    => 'block',
				),
			)
		);

		acf_render_field_setting(
			$field,
			array(
				'label'       => __( 'Add Row Button Label', 'repeater-rows-for-acf' ),
				'type'        => 'text',
				'name'        => 'button_label',
				'placeholder' => __( 'Add Row', 'repeater-rows-for-acf' ),
			)
		);
	}

	/**
	 * Attaches sub-fields whenever the field is loaded.
	 *
	 * @param array $field The field.
	 * @return array
	 */
	public function load_field( $field ) {
		$sub_fields = acf_get_fields( $field );

		if ( $sub_fields ) {
			$field['sub_fields'] = $sub_fields;
		}

		return $field;
	}

	/**
	 * Keeps sub-fields out of the parent's stored settings.
	 *
	 * Each sub-field is stored as its own record.
	 *
	 * @param array $field The field.
	 * @return array
	 */
	public function update_field( $field ) {
		unset( $field['sub_fields'] );

		$field['min'] = max( 0, (int) $field['min'] );
		$field['max'] = max( 0, (int) $field['max'] );

		// A maximum below the minimum would make the field impossible to save.
		if ( $field['max'] && $field['max'] < $field['min'] ) {
			$field['max'] = $field['min'];
		}

		return $field;
	}

	/**
	 * Copies sub-fields when the repeater is duplicated.
	 *
	 * The new parent has no ID when this filter runs, so it is saved first.
	 *
	 * @param array $field The field copy.
	 * @return array
	 */
	public function duplicate_field( $field ) {
		$sub_fields = RRFA_Row_Store::sub_fields( $field );
		unset( $field['sub_fields'] );

		$field  = acf_update_field( $field );
		$copies = acf_duplicate_fields( $sub_fields, $field['ID'] );

		foreach ( $sub_fields as $position => $sub_field ) {
			if ( $field['collapsed'] === $sub_field['key'] && isset( $copies[ $position ]['key'] ) ) {
				$field['collapsed'] = $copies[ $position ]['key'];
				$field              = acf_update_field( $field );
				break;
			}
		}

		return $field;
	}

	/**
	 * Deletes stored sub-fields along with the repeater.
	 *
	 * @param array $field The field being deleted.
	 * @return void
	 */
	public function delete_field( $field ) {
		foreach ( RRFA_Row_Store::sub_fields( $field ) as $sub_field ) {
			// Fields registered in PHP or Local JSON have no database record.
			if ( ! empty( $sub_field['ID'] ) ) {
				acf_delete_field( $sub_field['ID'] );
			}
		}
	}

	/**
	 * Keeps sub-fields nested in JSON and PHP exports.
	 *
	 * @param array $field The field being exported.
	 * @return array
	 */
	public function prepare_field_for_export( $field ) {
		if ( ! empty( $field['sub_fields'] ) ) {
			$field['sub_fields'] = acf_prepare_fields_for_export( $field['sub_fields'] );
		}

		return $field;
	}

	/**
	 * Turns nested sub-fields into the flat list ACF imports.
	 *
	 * ACF runs each returned field through this filter again, so deeper levels of
	 * nesting are handled as they come up.
	 *
	 * @param array $field The field being imported.
	 * @return array
	 */
	public function prepare_field_for_import( $field ) {
		$sub_fields = RRFA_Row_Store::sub_fields( $field );

		if ( ! $sub_fields ) {
			return $field;
		}

		unset( $field['sub_fields'] );

		foreach ( array_keys( $sub_fields ) as $position ) {
			$sub_fields[ $position ]['parent']     = $field['key'];
			$sub_fields[ $position ]['menu_order'] = $position;
		}

		return array_merge( array( $field ), $sub_fields );
	}

	/**
	 * Prints the field on the edit screen.
	 *
	 * @param array $field The prepared field.
	 * @return void
	 */
	public function render_field( $field ) {
		RRFA_Row_Renderer::render( $field );
	}

	/**
	 * Turns the stored row count into raw rows.
	 *
	 * @param mixed      $value   The stored value.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The field.
	 * @return array
	 */
	public function load_value( $value, $post_id, $field ) {
		if ( ! ctype_digit( (string) $value ) ) {
			return array();
		}

		return RRFA_Row_Store::load_rows( $post_id, $field, (int) $value );
	}

	/**
	 * Saves rows and returns the count ACF stores as the field's own value.
	 *
	 * @param mixed      $value   The value being saved.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The field.
	 * @return int
	 */
	public function update_value( $value, $post_id, $field ) {
		// REST output is read-only, including for a repeater nested in a Group.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && acf_get_data( 'acf_saving_submitted_values' ) ) {
			return RRFA_Row_Store::saved_count( $post_id, $field );
		}

		if ( RRFA_Row_Store::is_cut_off_submission( $value ) ) {
			return RRFA_Row_Store::saved_count( $post_id, $field );
		}

		return RRFA_Row_Store::save_rows( RRFA_Row_Store::usable_rows( $value ), $post_id, $field );
	}

	/**
	 * Deletes all rows when the field's value is deleted.
	 *
	 * ACF fires this before removing the row count, which is still needed here.
	 *
	 * @param int|string $post_id The ACF post ID.
	 * @param string     $key     The meta key.
	 * @param array      $field   The field.
	 * @return void
	 */
	public function delete_value( $post_id, $key, $field ) {
		RRFA_Row_Store::delete_all_rows( $post_id, $field );
	}

	/**
	 * Formats rows for templates, keyed by sub-field name.
	 *
	 * @param mixed      $value       The raw rows.
	 * @param int|string $post_id     The ACF post ID.
	 * @param array      $field       The field.
	 * @param bool       $escape_html Whether to return HTML-safe values.
	 * @return array|false
	 */
	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return false;
		}

		$rows = array();

		foreach ( array_values( $value ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$formatted = array();

			foreach ( RRFA_Row_Store::value_sub_fields( $field ) as $sub_field ) {
				$formatted[ RRFA_Row_Store::base_name( $sub_field ) ] = acf_format_value(
					RRFA_Row_Store::read_cell( $row, $sub_field ),
					$post_id,
					RRFA_Row_Store::sub_field_for_row( $field, $sub_field, $index ),
					$escape_html
				);
			}

			$rows[] = $formatted;
		}

		return $rows;
	}

	/**
	 * Checks row limits and validates every submitted sub-field.
	 *
	 * A sub-field absent from a row was kept off the form, so it is skipped.
	 *
	 * @param bool|string $valid The current status.
	 * @param mixed       $value The submitted value.
	 * @param array       $field The field.
	 * @param string      $input The input name.
	 * @return bool|string
	 */
	public function validate_value( $valid, $value, $field, $input ) {
		$rows  = RRFA_Row_Store::usable_rows( $value );
		$count = count( $rows );
		$min   = (int) $field['min'];
		$max   = (int) $field['max'];

		// ACF's own check sees the hidden marker input as a value, even with no rows.
		if ( ! $count && ! empty( $field['required'] ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s needs at least one row.', 'repeater-rows-for-acf' ), $field['label'] );
		}

		if ( $min && $count < $min ) {
			return sprintf(
				/* translators: %d: minimum number of rows. */
				_n( 'Add at least %d row.', 'Add at least %d rows.', $min, 'repeater-rows-for-acf' ),
				$min
			);
		}

		if ( $max && $count > $max ) {
			return sprintf(
				/* translators: %d: maximum number of rows. */
				_n( 'Use no more than %d row.', 'Use no more than %d rows.', $max, 'repeater-rows-for-acf' ),
				$max
			);
		}

		foreach ( $rows as $row_id => $row ) {
			foreach ( RRFA_Row_Store::value_sub_fields( $field ) as $sub_field ) {
				if ( array_key_exists( $sub_field['key'], $row ) ) {
					acf_validate_value( $row[ $sub_field['key'] ], $sub_field, "{$input}[{$row_id}][{$sub_field['key']}]" );
				}
			}
		}

		return $valid;
	}

	/**
	 * Refuses REST writes with a clear error.
	 *
	 * @param bool|WP_Error $valid The current status.
	 * @param mixed         $value The submitted value.
	 * @param array         $field The field.
	 * @return bool|WP_Error
	 */
	public function validate_rest_value( $valid, $value, $field ) {
		return new WP_Error(
			'rrfa_rest_read_only',
			/* translators: %s: field name. */
			sprintf( __( 'The %s field is read-only in the REST API.', 'repeater-rows-for-acf' ), $field['name'] ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Describes the REST output.
	 *
	 * @param array $field The field.
	 * @return array
	 */
	public function get_rest_schema( array $field ) {
		$properties = array();

		foreach ( RRFA_Row_Store::value_sub_fields( $field ) as $sub_field ) {
			$schema = acf_get_field_rest_schema( $sub_field );

			if ( $schema ) {
				$properties[ RRFA_Row_Store::base_name( $sub_field ) ] = $schema;
			}
		}

		return array(
			'type'     => array( 'array', 'null' ),
			'readonly' => true,
			'items'    => array(
				'type'       => 'object',
				'properties' => $properties,
			),
		);
	}

	/**
	 * Prepares rows for light REST output, keyed by sub-field name.
	 *
	 * @param mixed      $value   The raw rows.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The field.
	 * @return array
	 */
	public function format_value_for_rest( $value, $post_id, array $field ) {
		$rows = array();

		foreach ( RRFA_Row_Store::usable_rows( $value ) as $row ) {
			$output = array();

			foreach ( RRFA_Row_Store::value_sub_fields( $field ) as $sub_field ) {
				$output[ RRFA_Row_Store::base_name( $sub_field ) ] = acf_format_value_for_rest(
					RRFA_Row_Store::read_cell( $row, $sub_field ),
					$post_id,
					$sub_field
				);
			}

			$rows[] = $output;
		}

		return $rows;
	}
}
