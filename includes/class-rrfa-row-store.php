<?php
/**
 * Reads and writes row data.
 *
 * @package RepeaterRowsForACF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps rows in meta, one meta row per cell.
 *
 * The field's own meta holds the row count. Each cell is stored as
 * `{field}_{row}_{sub_field}` with a `_{field}_{row}_{sub_field}` key reference.
 * Every cell goes through ACF's value functions, so each sub-field type applies
 * its own load, update and delete behaviour, and nesting works by recursion.
 */
final class RRFA_Row_Store {

	/**
	 * Start of the row ID used in the new-row template.
	 */
	const TEMPLATE_ROW_PREFIX = 'rrfaclone_';

	/**
	 * Key of the hidden input printed after a repeater's rows.
	 *
	 * PHP drops every input past max_input_vars, so a form submission without this
	 * key may have lost rows.
	 */
	const COMPLETE_KEY = 'rrfa_complete';

	/**
	 * How many repeaters are being saved, counting the outermost one.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Whether submitted row IDs can be read as saved positions.
	 *
	 * A saved row ID in a new or duplicated parent row refers to the row it was
	 * copied from, not to anything at the parent's new position.
	 *
	 * @var bool
	 */
	private static $trust_row_ids = true;

	/**
	 * Returns the placeholder row ID for a field's new-row template.
	 *
	 * It is built from the field key so that a nested repeater's template keeps
	 * its own placeholder when its parent's placeholder is replaced.
	 *
	 * @param array $field The repeater field.
	 * @return string
	 */
	public static function template_row_id( array $field ) {
		return self::TEMPLATE_ROW_PREFIX . $field['key'] . '__';
	}

	/**
	 * Returns the input ID of a saved row.
	 *
	 * The field key keeps it distinct from row IDs at other nesting levels.
	 *
	 * @param array $field The repeater field.
	 * @param int   $index The row position.
	 * @return string
	 */
	public static function row_id( array $field, $index ) {
		return 'rrfarow-' . $field['key'] . '-' . $index;
	}

	/**
	 * Returns usable rows from a submitted or API value, with their keys intact.
	 *
	 * @param mixed $value The value to read.
	 * @return array
	 */
	public static function usable_rows( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();

		foreach ( $value as $row_id => $row ) {
			$is_template = 'acfcloneindex' === $row_id || 0 === strpos( (string) $row_id, self::TEMPLATE_ROW_PREFIX );

			if ( is_array( $row ) && ! $is_template ) {
				$rows[ $row_id ] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Returns a copy of a sub-field named for one row.
	 *
	 * ACF caches values by post ID and field name, so a shared name would make
	 * every row read the first row's cached value.
	 *
	 * @param array $field     The repeater field.
	 * @param array $sub_field The sub-field.
	 * @param int   $index     The row position.
	 * @return array
	 */
	public static function sub_field_for_row( array $field, array $sub_field, $index ) {
		$sub_field['name'] = $field['name'] . '_' . $index . '_' . self::base_name( $sub_field );

		return $sub_field;
	}

	/**
	 * Returns the sub-field's own name, without any row prefix.
	 *
	 * @param array $sub_field The sub-field.
	 * @return string
	 */
	public static function base_name( array $sub_field ) {
		return ! empty( $sub_field['_name'] ) ? $sub_field['_name'] : $sub_field['name'];
	}

	/**
	 * Returns the sub-fields of a repeater.
	 *
	 * @param array $field The repeater field.
	 * @return array
	 */
	public static function sub_fields( array $field ) {
		return ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
	}

	/**
	 * Returns the sub-fields that hold values.
	 *
	 * Tabs and accordions have no name and store nothing.
	 *
	 * @param array $field The repeater field.
	 * @return array
	 */
	public static function value_sub_fields( array $field ) {
		return array_filter(
			self::sub_fields( $field ),
			static function ( $sub_field ) {
				return '' !== (string) self::base_name( $sub_field );
			}
		);
	}

	/**
	 * Reads the row count saved for a field.
	 *
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @return int
	 */
	public static function saved_count( $post_id, array $field ) {
		$count = acf_get_metadata_by_field( $post_id, $field );

		return is_numeric( $count ) ? max( 0, (int) $count ) : 0;
	}

	/**
	 * Loads raw rows, each keyed by sub-field key.
	 *
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @param int        $count   The number of rows to load.
	 * @return array
	 */
	public static function load_rows( $post_id, array $field, $count ) {
		$rows = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$row = array();

			foreach ( self::value_sub_fields( $field ) as $sub_field ) {
				$row[ $sub_field['key'] ] = acf_get_value( $post_id, self::sub_field_for_row( $field, $sub_field, $index ) );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Whether this value comes from a form that PHP cut off at max_input_vars.
	 *
	 * Saving it would delete every row past the cut-off. Only an outermost repeater
	 * needs the check, because its marker comes after any nested rows. Requiring a
	 * large form leaves code that supplies its own values in a small form alone.
	 *
	 * @param mixed $value The submitted value.
	 * @return bool
	 */
	public static function is_cut_off_submission( $value ) {
		if ( self::$depth > 0 || ! acf_get_data( 'acf_saving_submitted_values' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ACF verified the nonce before saving.
		if ( ! isset( $_POST['_acf_nonce'] ) || ( is_array( $value ) && isset( $value[ self::COMPLETE_KEY ] ) ) ) {
			return false;
		}

		$limit = (int) ini_get( 'max_input_vars' );

		return $limit > 0 && count( $_POST, COUNT_RECURSIVE ) >= $limit / 2;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Saves rows in the given order and removes anything left past the new end.
	 *
	 * A saved row can arrive without some of its sub-fields, because conditional logic
	 * or `acf/prepare_field` kept them off the form. Those values stay with the row: left
	 * alone if the row stayed in place, and copied across if it moved. A new row has no
	 * earlier values, so anything at its position belongs to another row and is deleted.
	 *
	 * @param array      $rows    The rows to save.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @return int The number of rows saved.
	 */
	public static function save_rows( array $rows, $post_id, array $field ) {
		$previous_count = self::saved_count( $post_id, $field );
		$was_trusted    = self::$trust_row_ids;
		$prepared       = array();
		$claimed        = array();

		// The whole submitted tree is checked once, before any value is completed from storage.
		if ( 0 === self::$depth && acf_get_data( 'acf_saving_submitted_values' ) ) {
			$rows = self::drop_hidden_rows( $rows, $post_id, $field, $field );
		}

		// Moved rows read their old values here, before any position is overwritten.
		foreach ( $rows as $row_id => $row ) {
			$origin = $was_trusted ? self::claim_origin( $field, $row_id, $previous_count, $claimed ) : null;

			if ( null === $origin ) {
				$row = self::clear_missing_group_cells( $row, $field );
			} elseif ( count( $prepared ) !== $origin ) {
				$row = self::complete_moved_row( $row, $post_id, $field, $origin );
			}

			$prepared[] = array(
				'row'    => $row,
				'origin' => $origin,
			);
		}

		++self::$depth;

		try {
			foreach ( $prepared as $index => $item ) {
				self::$trust_row_ids = $was_trusted && null !== $item['origin'];
				self::write_row( $item['row'], $index === $item['origin'], $post_id, $field, $index );
			}
		} finally {
			--self::$depth;
			self::$trust_row_ids = $was_trusted;
		}

		self::delete_rows( $post_id, $field, count( $prepared ), $previous_count );

		return count( $prepared );
	}

	/**
	 * Deletes every saved row of a field.
	 *
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @return void
	 */
	public static function delete_all_rows( $post_id, array $field ) {
		self::delete_rows( $post_id, $field, 0, self::saved_count( $post_id, $field ) );
	}

	/**
	 * Whether a row holds a value for a sub-field, by key or by name.
	 *
	 * @param array $row       The row.
	 * @param array $sub_field The sub-field.
	 * @return bool
	 */
	public static function row_has_cell( array $row, array $sub_field ) {
		return array_key_exists( $sub_field['key'], $row ) || array_key_exists( self::base_name( $sub_field ), $row );
	}

	/**
	 * Reads a row's value for a sub-field, by key or by name.
	 *
	 * @param array $row       The row.
	 * @param array $sub_field The sub-field.
	 * @return mixed
	 */
	public static function read_cell( array $row, array $sub_field ) {
		if ( array_key_exists( $sub_field['key'], $row ) ) {
			return $row[ $sub_field['key'] ];
		}

		return $row[ self::base_name( $sub_field ) ] ?? null;
	}

	/**
	 * Returns the saved position a submitted row ID refers to.
	 *
	 * @param array      $field          The repeater field.
	 * @param int|string $row_id         The submitted row ID.
	 * @param int        $previous_count The saved row count.
	 * @return int|null The position, or null if the row wasn't saved at one.
	 */
	private static function origin_index( array $field, $row_id, $previous_count ) {
		// Only the form row_id() prints, so "-00" can't be a second name for row 0.
		$pattern = '/^' . preg_quote( self::row_id( $field, '' ), '/' ) . '(0|[1-9]\d*)$/';

		if ( ! preg_match( $pattern, (string) $row_id, $matches ) ) {
			return null;
		}

		$origin = (int) $matches[1];

		return $origin < $previous_count ? $origin : null;
	}

	/**
	 * Returns the saved position a row refers to, if no earlier row has claimed it.
	 *
	 * The form never submits one saved row twice. A repeated ID is treated as a new row,
	 * so it can't copy the first row's values that were kept off the form.
	 *
	 * @param array      $field          The repeater field.
	 * @param int|string $row_id         The submitted row ID.
	 * @param int        $previous_count The saved row count.
	 * @param array      $claimed        Positions claimed so far, keyed by position. Updated.
	 * @return int|null The position, or null if the row is new.
	 */
	private static function claim_origin( array $field, $row_id, $previous_count, array &$claimed ) {
		$origin = self::origin_index( $field, $row_id, $previous_count );

		if ( null === $origin || isset( $claimed[ $origin ] ) ) {
			return null;
		}

		$claimed[ $origin ] = true;

		return $origin;
	}

	/**
	 * Removes submitted values of sub-fields that the edit screen hides from this user.
	 *
	 * A sub-field that `acf/prepare_field` removes is never on the form, so a value for it
	 * can only come from a forged request. Dropping it means it is handled as a value
	 * kept off the form: left alone, or moved with its row. Each check sees the saved value,
	 * as the edit screen does, or the default value for a row that wasn't saved.
	 *
	 * @param array      $rows        The submitted rows.
	 * @param int|string $post_id     The ACF post ID.
	 * @param array      $field       The repeater field.
	 * @param array|null $saved_field The repeater named where its rows are saved, or null if they aren't.
	 * @return array
	 */
	private static function drop_hidden_rows( array $rows, $post_id, array $field, $saved_field ) {
		$previous_count = $saved_field ? self::saved_count( $post_id, $saved_field ) : 0;
		$claimed        = array();

		foreach ( $rows as $row_id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$origin = $saved_field ? self::claim_origin( $saved_field, $row_id, $previous_count, $claimed ) : null;
			$prefix = null === $origin ? null : $saved_field['name'] . '_' . $origin . '_';

			$rows[ $row_id ] = self::drop_hidden_cells( $row, self::value_sub_fields( $field ), $post_id, $prefix );
		}

		return $rows;
	}

	/**
	 * Removes hidden sub-fields from one row or Group value, and checks inside nested ones.
	 *
	 * @param array       $values       The row, or a Group's value.
	 * @param array       $sub_fields   The sub-fields holding those values.
	 * @param int|string  $post_id      The ACF post ID.
	 * @param string|null $saved_prefix The meta name prefix of the saved values, or null if there are none.
	 * @return array
	 */
	private static function drop_hidden_cells( array $values, array $sub_fields, $post_id, $saved_prefix ) {
		foreach ( $sub_fields as $sub_field ) {
			if ( ! self::row_has_cell( $values, $sub_field ) ) {
				continue;
			}

			$saved_cell = null;

			if ( null !== $saved_prefix ) {
				$saved_cell         = $sub_field;
				$saved_cell['name'] = $saved_prefix . self::base_name( $sub_field );
			}

			$check          = $sub_field;
			$check['value'] = $saved_cell ? acf_get_value( $post_id, $saved_cell ) : ( $sub_field['default_value'] ?? null );

			if ( ! acf_prepare_field( $check ) ) {
				unset( $values[ $sub_field['key'] ], $values[ self::base_name( $sub_field ) ] );
				continue;
			}

			$cell = self::read_cell( $values, $sub_field );

			if ( ! is_array( $cell ) ) {
				continue;
			}

			if ( 'rrfa_repeater' === $sub_field['type'] ) {
				$values[ $sub_field['key'] ] = self::drop_hidden_rows( $cell, $post_id, $sub_field, $saved_cell );
			} elseif ( 'group' === $sub_field['type'] ) {
				$group_prefix = $saved_cell ? $saved_cell['name'] . '_' : null;
				$group        = self::drop_hidden_cells( $cell, self::value_sub_fields( $sub_field ), $post_id, $group_prefix );

				// A Group deletes its values when given an empty one, so leave it off instead.
				unset( $values[ $sub_field['key'] ], $values[ self::base_name( $sub_field ) ] );

				if ( $group ) {
					$values[ $sub_field['key'] ] = $group;
				}
			}
		}

		return $values;
	}

	/**
	 * Fills in the values a moved row didn't submit, at any depth, from its old position.
	 *
	 * @param array      $row     The submitted row.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field, named for the row's old parent.
	 * @param int        $origin  The row's saved position.
	 * @return array
	 */
	private static function complete_moved_row( array $row, $post_id, array $field, $origin ) {
		foreach ( self::value_sub_fields( $field ) as $sub_field ) {
			$old_cell = self::sub_field_for_row( $field, $sub_field, $origin );

			$row[ $sub_field['key'] ] = self::row_has_cell( $row, $sub_field )
				? self::complete_moved_value( self::read_cell( $row, $sub_field ), $post_id, $old_cell )
				: acf_get_value( $post_id, $old_cell );
		}

		return $row;
	}

	/**
	 * Fills in unsubmitted values inside a nested repeater or Group of a moved row.
	 *
	 * Nested rows lose their IDs here. Those IDs refer to the old parent position, and
	 * these rows are now complete, so nothing later needs them.
	 *
	 * @param mixed      $value    The submitted value.
	 * @param int|string $post_id  The ACF post ID.
	 * @param array      $old_cell The sub-field, named for its old position.
	 * @return mixed
	 */
	private static function complete_moved_value( $value, $post_id, array $old_cell ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( 'rrfa_repeater' === $old_cell['type'] ) {
			$previous_count = self::saved_count( $post_id, $old_cell );
			$rows           = array();
			$claimed        = array();

			foreach ( self::usable_rows( $value ) as $row_id => $row ) {
				$origin = self::claim_origin( $old_cell, $row_id, $previous_count, $claimed );
				$rows[] = null === $origin ? $row : self::complete_moved_row( $row, $post_id, $old_cell, $origin );
			}

			return $rows;
		}

		if ( 'group' === $old_cell['type'] ) {
			foreach ( self::value_sub_fields( $old_cell ) as $sub_field ) {
				$old_sub_field         = $sub_field;
				$old_sub_field['name'] = $old_cell['name'] . '_' . self::base_name( $sub_field );

				// A Group skips null, which would leave another row's value in place.
				$value[ $sub_field['key'] ] = self::row_has_cell( $value, $sub_field )
					? self::complete_moved_value( self::read_cell( $value, $sub_field ), $post_id, $old_sub_field )
					: ( acf_get_value( $post_id, $old_sub_field ) ?? '' );
			}
		}

		return $value;
	}

	/**
	 * Blanks the sub-fields a new row's Groups didn't submit.
	 *
	 * A Group leaves missing sub-fields untouched, which here would hand the new row
	 * values belonging to whichever row used to be at its position.
	 *
	 * @param array $values The row, or a Group's value.
	 * @param array $field  The repeater or Group holding those values.
	 * @return array
	 */
	private static function clear_missing_group_cells( array $values, array $field ) {
		foreach ( self::value_sub_fields( $field ) as $sub_field ) {
			if ( 'group' !== $sub_field['type'] || ! self::row_has_cell( $values, $sub_field ) ) {
				continue;
			}

			$group = self::read_cell( $values, $sub_field );

			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( self::value_sub_fields( $sub_field ) as $group_sub_field ) {
				if ( ! self::row_has_cell( $group, $group_sub_field ) ) {
					$group[ $group_sub_field['key'] ] = '';
				}
			}

			$values[ $sub_field['key'] ] = self::clear_missing_group_cells( $group, $sub_field );
		}

		return $values;
	}

	/**
	 * Writes one row's cells at a position.
	 *
	 * @param array      $row     The row.
	 * @param bool       $stayed  Whether the row was already saved at this position.
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @param int        $index   The row position.
	 * @return void
	 */
	private static function write_row( array $row, $stayed, $post_id, array $field, $index ) {
		foreach ( self::value_sub_fields( $field ) as $sub_field ) {
			$cell = self::sub_field_for_row( $field, $sub_field, $index );

			if ( self::row_has_cell( $row, $sub_field ) ) {
				acf_update_value( self::read_cell( $row, $sub_field ), $post_id, $cell );
			} elseif ( ! $stayed ) {
				acf_delete_value( $post_id, $cell );
			}
		}
	}

	/**
	 * Deletes the cells of a range of rows.
	 *
	 * @param int|string $post_id The ACF post ID.
	 * @param array      $field   The repeater field.
	 * @param int        $from    First row position to delete.
	 * @param int        $until   Position after the last row to delete.
	 * @return void
	 */
	private static function delete_rows( $post_id, array $field, $from, $until ) {
		for ( $index = $from; $index < $until; $index++ ) {
			foreach ( self::value_sub_fields( $field ) as $sub_field ) {
				acf_delete_value( $post_id, self::sub_field_for_row( $field, $sub_field, $index ) );
			}
		}
	}
}
