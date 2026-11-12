/**
 * Field group editor behaviour for the Repeater Rows field.
 *
 * Keeps the Row Title Field choices in step with sub-fields added or renamed
 * since the page loaded.
 *
 * @package RepeaterRowsForACF
 */

/* global acf, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof acf === 'undefined' ) {
		return;
	}

	const SELECT = '.acf-field-setting-collapsed[data-setting="rrfa_repeater"] select';

	// Matches RRFA_Row_Renderer::TITLE_FIELD_TYPES.
	const TITLE_FIELD_TYPES = [ 'text', 'textarea', 'wysiwyg', 'email', 'url', 'number', 'select', 'radio', 'button_group', 'date_picker', 'date_time_picker', 'time_picker' ];

	const refreshChoices = ( $select ) => {
		const choices = [];

		$select
			.closest( '.acf-field-settings' )
			.find( '.acf-field-setting-sub_fields' )
			.first()
			.find( '.acf-field-list' )
			.first()
			.children( '.acf-field-object' )
			.each( function () {
				const subField = acf.getFieldObject( $( this ) );

				if ( TITLE_FIELD_TYPES.includes( subField.prop( 'type' ) ) ) {
					choices.push( {
						key: subField.prop( 'key' ),
						label: subField.prop( 'label' ) || subField.prop( 'key' ),
					} );
				}
			} );

		const $emptyChoice = $select.find( 'option[value=""]' ).first();
		const $current = $select.find( 'option' ).not( $emptyChoice );
		const unchanged =
			$current.length === choices.length &&
			choices.every(
				( choice, index ) =>
					$current[ index ].value === choice.key && $current[ index ].text === choice.label
			);

		// Rebuilding a list as it opens can close it, so only do so when something changed.
		if ( unchanged ) {
			return;
		}

		const selected = $select.val();

		$current.remove();
		choices.forEach( ( choice ) => {
			$select.append( $( '<option>' ).val( choice.key ).text( choice.label ) );
		} );
		$select.val( selected );
	};

	$( document ).on( 'mousedown focus', SELECT, function () {
		refreshChoices( $( this ) );
	} );
}( jQuery ) );
