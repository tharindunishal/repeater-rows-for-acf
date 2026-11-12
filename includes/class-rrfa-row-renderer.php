<?php
/**
 * Edit screen markup.
 *
 * @package RepeaterRowsForACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the repeater's card and table layouts.
 */
final class RRFA_Row_Renderer {

	/**
	 * Sub-field types that can supply a row title.
	 */
	const TITLE_FIELD_TYPES = array( 'text', 'textarea', 'wysiwyg', 'email', 'url', 'number', 'select', 'radio', 'button_group', 'date_picker', 'date_time_picker', 'time_picker' );

	/**
	 * Title types whose stored value is what the title shows, before the script runs.
	 */
	const PLAIN_TITLE_FIELD_TYPES = array( 'text', 'textarea', 'wysiwyg', 'email', 'url', 'number' );

	/**
	 * Prints the whole field.
	 *
	 * @param array $field The prepared repeater field.
	 * @return void
	 */
	public static function render( array $field ) {
		$sub_fields = RRFA_Row_Store::sub_fields( $field );

		if ( ! $sub_fields ) {
			printf(
				'<p class="rrfa-setup-hint">%s</p>',
				esc_html__( 'Add sub-fields to this repeater in its field group to start using it.', 'repeater-rows-for-acf' )
			);
			return;
		}

		$is_table    = 'table' === $field['layout'];
		$saved_count = count( RRFA_Row_Store::usable_rows( $field['value'] ) );
		$rows        = self::rows_for_display( $field );
		$title_field = self::title_field( $field, $sub_fields );

		$attributes = array(
			'class'            => 'rrfa-repeater ' . ( $is_table ? '-table' : '-block' ),
			'data-min'         => max( 0, (int) $field['min'] ),
			'data-max'         => max( 0, (int) $field['max'] ),
			'data-title-key'   => $title_field ? $title_field['key'] : '',
			'data-placeholder' => RRFA_Row_Store::template_row_id( $field ),
			'data-input-limit' => (int) ini_get( 'max_input_vars' ),
		);

		echo '<div ' . acf_esc_attrs( $attributes ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- acf_esc_attrs() escapes the output.

		// An empty value must still post, or ACF skips validation when there are no rows.
		acf_hidden_input(
			array(
				'name'  => $field['name'],
				'value' => '',
			)
		);

		self::render_toolbar( $is_table );

		echo '<p class="rrfa-limit-warning" role="alert" hidden></p>';

		if ( $is_table ) {
			self::render_table( $field, $sub_fields, $rows );
		} else {
			echo '<div class="rrfa-rows" data-rrfa-list>';
			foreach ( $rows as $index => $row ) {
				// Blank rows added to reach the minimum need filling in, so they stay open.
				$collapsed = ! empty( $field['start_collapsed'] ) && $index < $saved_count;

				self::render_card( $field, $sub_fields, RRFA_Row_Store::row_id( $field, $index ), $row, $index, $collapsed, $title_field );
			}
			echo '</div>';
		}

		// Anything cut off by max_input_vars comes after this, so its absence reveals the loss.
		acf_hidden_input(
			array(
				'name'  => $field['name'] . '[' . RRFA_Row_Store::COMPLETE_KEY . ']',
				'value' => '1',
			)
		);

		printf( '<p class="rrfa-empty">%s</p>', esc_html__( 'No rows yet. Use the button below to add the first one.', 'repeater-rows-for-acf' ) );

		printf(
			'<div class="rrfa-footer"><button type="button" class="rrfa-add" data-rrfa-action="add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>%s</button></div>',
			esc_html( $field['button_label'] ? $field['button_label'] : __( 'Add Row', 'repeater-rows-for-acf' ) )
		);

		echo '<template class="rrfa-template">';
		$template_id = RRFA_Row_Store::template_row_id( $field );
		if ( $is_table ) {
			self::render_table_row( $field, $sub_fields, $template_id, array(), 0 );
		} else {
			self::render_card( $field, $sub_fields, $template_id, array(), 0, false, $title_field );
		}
		echo '</template>';

		echo '</div>';
	}

	/**
	 * Returns saved rows, padded with blank rows up to the minimum.
	 *
	 * @param array $field The repeater field.
	 * @return array
	 */
	private static function rows_for_display( array $field ) {
		$rows = array_values( RRFA_Row_Store::usable_rows( $field['value'] ) );

		return array_pad( $rows, max( 0, (int) $field['min'] ), array() );
	}

	/**
	 * Chooses the sub-field that supplies row titles.
	 *
	 * A password never qualifies, and neither does a sub-field that `acf/prepare_field`
	 * hides from this user, because the title would reveal its value.
	 *
	 * @param array $field      The repeater field.
	 * @param array $sub_fields The sub-fields.
	 * @return array|null The sub-field, or null if none qualifies.
	 */
	private static function title_field( array $field, array $sub_fields ) {
		$fallback = null;

		foreach ( $sub_fields as $sub_field ) {
			if ( ! in_array( $sub_field['type'], self::TITLE_FIELD_TYPES, true ) || ! acf_prepare_field( $sub_field ) ) {
				continue;
			}

			if ( $field['collapsed'] === $sub_field['key'] ) {
				return $sub_field;
			}

			if ( null === $fallback ) {
				$fallback = $sub_field;
			}
		}

		return $fallback;
	}

	/**
	 * Prints the row count and the expand and collapse controls.
	 *
	 * @param bool $is_table Whether the table layout is in use.
	 * @return void
	 */
	private static function render_toolbar( $is_table ) {
		echo '<div class="rrfa-toolbar"><span class="rrfa-status" aria-live="polite"></span>';

		if ( ! $is_table ) {
			printf(
				'<span class="rrfa-bulk"><button type="button" data-rrfa-action="expand-all">%s</button><button type="button" data-rrfa-action="collapse-all">%s</button></span>',
				esc_html__( 'Expand all', 'repeater-rows-for-acf' ),
				esc_html__( 'Collapse all', 'repeater-rows-for-acf' )
			);
		}

		echo '</div>';
	}

	/**
	 * Prints one row as a card.
	 *
	 * @param array      $field      The repeater field.
	 * @param array      $sub_fields The sub-fields.
	 * @param string     $row_id     The row's input ID.
	 * @param array      $row        The row's raw values.
	 * @param int        $index      The row position.
	 * @param bool       $collapsed  Whether the row starts collapsed.
	 * @param array|null $title_field The sub-field that supplies the title.
	 * @return void
	 */
	private static function render_card( array $field, array $sub_fields, $row_id, array $row, $index, $collapsed, $title_field ) {
		$prefix  = $field['name'] . '[' . $row_id . ']';
		$body_id = acf_idify( $prefix ) . '-body';

		printf(
			'<div class="rrfa-row%s" data-id="%s"><div class="rrfa-row__header">',
			$collapsed ? ' -collapsed' : '',
			esc_attr( $row_id )
		);

		self::render_handle();

		printf(
			'<button type="button" class="rrfa-row__toggle" data-rrfa-action="toggle" aria-expanded="%s" aria-controls="%s"><span class="rrfa-row__index">%d</span><span class="rrfa-row__title">%s</span></button>',
			$collapsed ? 'false' : 'true',
			esc_attr( $body_id ),
			(int) $index + 1,
			esc_html( self::initial_title( $title_field, $row, $index ) )
		);

		self::render_row_actions();

		printf(
			'<span class="rrfa-icon-button rrfa-row__chevron" data-rrfa-action="toggle" aria-hidden="true"><span class="dashicons dashicons-arrow-down-alt2"></span></span></div><div class="rrfa-row__body acf-fields -top" id="%s">',
			esc_attr( $body_id )
		);

		foreach ( $sub_fields as $sub_field ) {
			acf_render_field_wrap( self::prepare_cell( $sub_field, $row, $prefix ) );
		}

		echo '</div></div>';
	}

	/**
	 * Prints the table layout.
	 *
	 * @param array $field      The repeater field.
	 * @param array $sub_fields The sub-fields.
	 * @param array $rows       The rows to show.
	 * @return void
	 */
	private static function render_table( array $field, array $sub_fields, array $rows ) {
		echo '<table class="acf-table rrfa-table"><thead><tr>';
		printf( '<th class="rrfa-cell--handle"><span class="screen-reader-text">%s</span></th>', esc_html__( 'Row', 'repeater-rows-for-acf' ) );

		foreach ( $sub_fields as $sub_field ) {
			$prepared = acf_prepare_field( $sub_field );

			if ( ! $prepared ) {
				continue;
			}

			$attributes = array(
				'class'     => 'acf-th',
				'data-name' => $prepared['_name'],
				'data-type' => $prepared['type'],
				'data-key'  => $prepared['key'],
			);

			if ( $prepared['wrapper']['width'] ) {
				$attributes['data-width'] = $prepared['wrapper']['width'];
				$attributes['style']      = 'width: ' . $prepared['wrapper']['width'] . '%;';
			}

			echo '<th ' . acf_esc_attrs( $attributes ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- acf_esc_attrs() escapes the output.
			acf_render_field_label( $prepared );
			acf_render_field_instructions( $prepared );
			echo '</th>';
		}

		printf( '<th class="rrfa-cell--actions"><span class="screen-reader-text">%s</span></th>', esc_html__( 'Actions', 'repeater-rows-for-acf' ) );
		echo '</tr></thead><tbody data-rrfa-list>';

		foreach ( $rows as $index => $row ) {
			self::render_table_row( $field, $sub_fields, RRFA_Row_Store::row_id( $field, $index ), $row, $index );
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints one row of the table layout.
	 *
	 * @param array  $field      The repeater field.
	 * @param array  $sub_fields The sub-fields.
	 * @param string $row_id     The row's input ID.
	 * @param array  $row        The row's raw values.
	 * @param int    $index      The row position.
	 * @return void
	 */
	private static function render_table_row( array $field, array $sub_fields, $row_id, array $row, $index ) {
		$prefix = $field['name'] . '[' . $row_id . ']';

		printf( '<tr class="rrfa-row" data-id="%s"><td class="rrfa-cell--handle">', esc_attr( $row_id ) );
		self::render_handle();
		printf( '<span class="rrfa-row__index">%d</span></td>', (int) $index + 1 );

		foreach ( $sub_fields as $sub_field ) {
			$cell = self::prepare_cell( $sub_field, $row, $prefix );

			// Narrow containers hide the table header and show this label instead.
			$cell['wrapper']['data-rrfa-label'] = $sub_field['label'];

			acf_render_field_wrap( $cell, 'td' );
		}

		echo '<td class="rrfa-cell--actions">';
		self::render_row_actions();
		echo '</td></tr>';
	}

	/**
	 * Prepares a sub-field to render inside one row.
	 *
	 * @param array  $sub_field The sub-field.
	 * @param array  $row       The row's raw values.
	 * @param string $prefix    The row's input name.
	 * @return array
	 */
	private static function prepare_cell( array $sub_field, array $row, $prefix ) {
		$sub_field['prefix'] = $prefix;
		$sub_field['value']  = RRFA_Row_Store::row_has_cell( $row, $sub_field )
			? RRFA_Row_Store::read_cell( $row, $sub_field )
			: ( $sub_field['default_value'] ?? null );

		return $sub_field;
	}

	/**
	 * Returns the title shown before the script takes over.
	 *
	 * The script reads what the form shows, such as a choice's label. Here, only values
	 * stored as they are shown can be used, so other types start with the row number.
	 *
	 * @param array|null $title_field The sub-field that supplies the title.
	 * @param array      $row         The row's raw values.
	 * @param int        $index       The row position.
	 * @return string
	 */
	private static function initial_title( $title_field, array $row, $index ) {
		$title = '';

		if ( $title_field && in_array( $title_field['type'], self::PLAIN_TITLE_FIELD_TYPES, true ) ) {
			$value = RRFA_Row_Store::read_cell( $row, $title_field );

			if ( is_scalar( $value ) ) {
				$title = trim( 'wysiwyg' === $title_field['type'] ? wp_strip_all_tags( (string) $value ) : (string) $value );
			}
		}

		if ( '' !== $title ) {
			return $title;
		}

		/* translators: %d: row number. */
		return sprintf( __( 'Row %d', 'repeater-rows-for-acf' ), (int) $index + 1 );
	}

	/**
	 * Prints the drag handle.
	 *
	 * Assistive technology and keyboards use the move buttons instead.
	 *
	 * @return void
	 */
	private static function render_handle() {
		printf(
			'<span class="rrfa-row__handle dashicons dashicons-menu" title="%s" aria-hidden="true"></span>',
			esc_attr__( 'Drag to reorder', 'repeater-rows-for-acf' )
		);
	}

	/**
	 * Prints the move, duplicate and delete buttons.
	 *
	 * @return void
	 */
	private static function render_row_actions() {
		$actions = array(
			'up'        => array( 'dashicons-arrow-up-alt', __( 'Move row up', 'repeater-rows-for-acf' ) ),
			'down'      => array( 'dashicons-arrow-down-alt', __( 'Move row down', 'repeater-rows-for-acf' ) ),
			'duplicate' => array( 'dashicons-admin-page', __( 'Duplicate row', 'repeater-rows-for-acf' ) ),
			'remove'    => array( 'dashicons-trash', __( 'Delete row', 'repeater-rows-for-acf' ) ),
		);

		echo '<span class="rrfa-row__actions">';

		foreach ( $actions as $action => $details ) {
			printf(
				'<button type="button" class="rrfa-icon-button" data-rrfa-action="%1$s" aria-label="%3$s" title="%3$s"><span class="dashicons %2$s" aria-hidden="true"></span></button>',
				esc_attr( $action ),
				esc_attr( $details[0] ),
				esc_attr( $details[1] )
			);
		}

		echo '</span>';
	}
}
