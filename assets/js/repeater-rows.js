/**
 * Edit screen behaviour for the Repeater Rows field.
 *
 * One model runs per repeater. Every lookup stays within that repeater's own
 * rows, so nested repeaters never act on each other.
 *
 * @package RepeaterRowsForACF
 */

/* global acf, jQuery, wp */
( function ( $ ) {
	'use strict';

	if ( typeof acf === 'undefined' || ! acf.Field ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;

	const prefersReducedMotion = () =>
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Replaces a row ID without touching longer IDs that start the same way,
	 * so "...-1" leaves "...-10" alone.
	 *
	 * @param {string} text The text to change.
	 * @param {string} from The row ID to find.
	 * @param {string} to   The new row ID.
	 * @return {string} The changed text.
	 */
	const replaceRowId = ( text, from, to ) => {
		const escaped = from.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

		return text.replace(
			new RegExp( escaped + '(?![0-9A-Za-z])', 'g' ),
			to
		);
	};

	/**
	 * Returns text with any HTML removed. DOMParser never loads or runs the content.
	 *
	 * @param {string} html The HTML.
	 * @return {string} The text.
	 */
	const stripHtml = ( html ) =>
		new DOMParser().parseFromString( html, 'text/html' ).body.textContent;

	const RepeaterRows = acf.Field.extend( {
		type: 'rrfa_repeater',

		$control() {
			return this.$( '.rrfa-repeater:first' );
		},

		$list() {
			return this.$control().find( '[data-rrfa-list]' ).first();
		},

		$rows() {
			return this.$list().children( '.rrfa-row' );
		},

		$rowActions( $row ) {
			return $row
				.children( '.rrfa-row__header, .rrfa-cell--actions' )
				.children( '.rrfa-row__actions' );
		},

		$rowToggle( $row ) {
			return $row.children( '.rrfa-row__header' ).children( '.rrfa-row__toggle' );
		},

		minRows() {
			return parseInt( this.$control().attr( 'data-min' ), 10 ) || 0;
		},

		maxRows() {
			return parseInt( this.$control().attr( 'data-max' ), 10 ) || 0;
		},

		isCards() {
			return this.$control().hasClass( '-block' );
		},

		initialize() {
			const $control = this.$control();

			// Repeaters with no sub-fields only print a setup hint.
			if ( ! $control.length ) {
				return;
			}

			$control.on(
				'click.rrfa',
				'[data-rrfa-action]',
				this.proxy( this.onActionClick )
			);
			$control.on(
				'input.rrfa change.rrfa',
				':input',
				this.proxy( this.onValueChange )
			);

			this.setupSorting();
			this.restoreOpenRows();
			this.syncRows();
		},

		setupSorting() {
			if ( ! $.fn.sortable ) {
				return;
			}

			this.$list().sortable( {
				items: '> .rrfa-row',
				handle: '.rrfa-row__handle',
				axis: 'y',
				tolerance: 'pointer',
				forcePlaceholderSize: true,
				placeholder: 'rrfa-row-placeholder',
				start: ( event, ui ) =>
					acf.doAction( 'sortstart', ui.item, ui.placeholder ),
				update: () => this.markChanged(),
				stop: ( event, ui ) => {
					acf.doAction( 'sortstop', ui.item, ui.placeholder );
					this.syncRows();
					this.saveOpenRows();
				},
			} );
		},

		onActionClick( event ) {
			const $button = $( event.currentTarget );

			// Clicks in a nested repeater also bubble up to this one.
			if ( $button.closest( '.rrfa-repeater' )[ 0 ] !== this.$control()[ 0 ] ) {
				return;
			}

			event.preventDefault();

			const $row = $button.closest( '.rrfa-row' );

			switch ( $button.attr( 'data-rrfa-action' ) ) {
				case 'add':
					this.addRow();
					break;
				case 'toggle':
					this.setRowOpen( $row, $row.hasClass( '-collapsed' ) );
					this.saveOpenRows();
					break;
				case 'expand-all':
					this.setAllRowsOpen( true );
					break;
				case 'collapse-all':
					this.setAllRowsOpen( false );
					break;
				case 'up':
					this.moveRow( $row, $button, -1 );
					break;
				case 'down':
					this.moveRow( $row, $button, 1 );
					break;
				case 'duplicate':
					this.duplicateRow( $row );
					break;
				case 'remove':
					this.confirmRemove( $row, $button );
					break;
			}
		},

		onValueChange( event ) {
			const $row = $( event.target ).closest( '.rrfa-row' );

			if ( $row.closest( '.rrfa-repeater' )[ 0 ] === this.$control()[ 0 ] ) {
				this.syncRowTitle( $row, this.$rows().index( $row ) );
			}
		},

		addRow() {
			const max = this.maxRows();

			if ( max && this.$rows().length >= max ) {
				return;
			}

			const html = this.$control()
				.children( '.rrfa-template' )
				.html()
				.split( this.$control().attr( 'data-placeholder' ) )
				.join( acf.uniqid( 'rrfarow-' ) );

			const $row = $( $.parseHTML( html, document, true ) ).filter( '.rrfa-row' );

			this.$list().append( $row );
			acf.doAction( 'append', $row );

			this.syncRows();
			this.saveOpenRows();
			this.markChanged();
			this.focusNewRow( $row );
		},

		duplicateRow( $row ) {
			const max = this.maxRows();

			if ( max && this.$rows().length >= max ) {
				return;
			}

			const from = $row.attr( 'data-id' );
			const to = acf.uniqid( 'rrfarow-' );

			const $copy = acf.duplicate( {
				target: $row,
				search: from,
				replace: to,
				rename: ( name, value ) => replaceRowId( value, from, to ),
				// Unmounting saves each editor's content back to its textarea.
				before: ( $source ) => acf.doAction( 'unmount', $source ),
				after: ( $source, $clone ) => {
					this.copyTextareas( $source, $clone );
					this.renameNestedTemplates( $clone, from, to );
					acf.doAction( 'remount', $source );
				},
				append: ( $source, $clone ) => $source.after( $clone ),
			} );

			this.setRowOpen( $copy, true );
			this.syncRows();
			this.saveOpenRows();
			this.markChanged();
			this.focusNewRow( $copy );
		},

		/**
		 * ACF only learns of edits through change events. It uses them to enable Update
		 * in the block editor, warn about unsaved changes, and decide on a new revision.
		 */
		markChanged() {
			this.$control().children( 'input[type="hidden"]' ).first().trigger( 'change' );
		},

		copyTextareas( $source, $clone ) {
			const $textareas = $clone.find( 'textarea' );

			$source.find( 'textarea' ).each( function ( index ) {
				$textareas.eq( index ).val( $( this ).val() );
			} );
		},

		// Template content is not part of the document, so ACF's rename can't reach it.
		renameNestedTemplates( $clone, from, to ) {
			$clone.find( 'template.rrfa-template' ).each( function () {
				this.innerHTML = replaceRowId( this.innerHTML, from, to );
			} );
		},

		confirmRemove( $row, $button ) {
			const tooltip = acf.newTooltip( {
				confirmRemove: true,
				target: $button,
				context: this,
				confirm() {
					this.removeRow( $row );
				},
				cancel( event ) {
					// A click elsewhere cancels too, and that click keeps its own focus.
					if ( $( event.target ).closest( '.acf-tooltip' ).length ) {
						$button.trigger( 'focus' );
					}
				},
			} );

			tooltip.$el.on( 'keydown', ( event ) => {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					tooltip.$el.find( '[data-event="cancel"]' ).trigger( 'click' );
				}
			} );
			tooltip.$el.find( '[data-event="confirm"]' ).trigger( 'focus' );
		},

		removeRow( $row ) {
			const $next = $row.next( '.rrfa-row' );
			const $previous = $row.prev( '.rrfa-row' );

			acf.doAction( 'remove', $row );
			$row.remove();

			this.syncRows();
			this.saveOpenRows();
			this.markChanged();
			acf.doAction( 'refresh' );

			// Keep keyboard users near where they were.
			const $neighbour = $next.length ? $next : $previous;
			const $focus = $neighbour.length
				? this.$rowActions( $neighbour ).find( 'button:enabled' ).last()
				: this.$control().find( '> .rrfa-footer .rrfa-add' );

			$focus.trigger( 'focus' );
		},

		moveRow( $row, $button, direction ) {
			const $target = direction < 0 ? $row.prev( '.rrfa-row' ) : $row.next( '.rrfa-row' );

			if ( ! $target.length ) {
				return;
			}

			// Moving an editor in the DOM breaks it, so take it down first.
			acf.doAction( 'unmount', $row );

			if ( direction < 0 ) {
				$row.insertBefore( $target );
			} else {
				$row.insertAfter( $target );
			}

			acf.doAction( 'remount', $row );

			this.syncRows();
			this.saveOpenRows();
			this.markChanged();

			// At the top or bottom this button is now disabled, so use the other one.
			const opposite = direction < 0 ? 'down' : 'up';
			const $focus = $button.prop( 'disabled' )
				? this.$rowActions( $row ).find( `[data-rrfa-action="${ opposite }"]` )
				: $button;

			this.focusAfterRemount( $row, $focus );
		},

		/**
		 * Restoring an editor moves focus into it a moment later. Focus is taken back
		 * for up to a second, unless the person clicks or presses a key first.
		 *
		 * @param {jQuery} $row   The row that was remounted.
		 * @param {jQuery} $focus The element that should hold focus.
		 */
		focusAfterRemount( $row, $focus ) {
			const element = $focus[ 0 ];
			const events = `mousedown.rrfa${ this.cid } keydown.rrfa${ this.cid }`;

			clearInterval( this.focusTimer );
			$( document ).off( events );
			element.focus();

			if ( ! $row.find( '.wp-editor-area' ).length ) {
				return;
			}

			let attempts = 0;

			const stop = () => {
				clearInterval( this.focusTimer );
				$( document ).off( events );
			};

			$( document ).on( events, stop );

			this.focusTimer = setInterval( () => {
				attempts++;

				if ( document.activeElement !== element ) {
					element.focus();
				}

				if ( attempts >= 20 ) {
					stop();
				}
			}, 50 );
		},

		setRowOpen( $row, open ) {
			$row.toggleClass( '-collapsed', ! open );
			this.$rowToggle( $row ).attr( 'aria-expanded', open ? 'true' : 'false' );

			// Lets fields that measure themselves, such as maps and widths, update.
			acf.doAction( open ? 'show' : 'hide', $row.children( '.rrfa-row__body' ), 'collapse' );
			acf.doAction( 'refresh' );
		},

		setAllRowsOpen( open ) {
			this.$rows().each( ( index, row ) => this.setRowOpen( $( row ), open ) );
			this.saveOpenRows();
		},

		focusNewRow( $row ) {
			$row[ 0 ].scrollIntoView( {
				block: 'nearest',
				behavior: prefersReducedMotion() ? 'auto' : 'smooth',
			} );
			$row
				.find( '.acf-field :input:visible:not([type="hidden"]):not(button)' )
				.first()
				.trigger( 'focus' );
		},

		/**
		 * Open rows are remembered by position, per post, in this browser. Nested
		 * repeaters are left out because their field key repeats across parent rows.
		 *
		 * @return {string|false} The preference name, if this repeater can use one.
		 */
		preferenceName() {
			if ( ! this.isCards() || this.$el.parents( '.rrfa-row' ).length ) {
				return false;
			}

			return `this.rrfa-open-${ this.get( 'key' ) }`;
		},

		restoreOpenRows() {
			const name = this.preferenceName();
			const saved = name ? acf.getPreference( name ) : null;

			if ( ! Array.isArray( saved ) ) {
				return;
			}

			this.$rows().each( ( index, row ) => {
				const $row = $( row );
				const open = saved.includes( index );

				$row.toggleClass( '-collapsed', ! open );
				this.$rowToggle( $row ).attr( 'aria-expanded', open ? 'true' : 'false' );
			} );
		},

		saveOpenRows() {
			const name = this.preferenceName();

			if ( ! name ) {
				return;
			}

			const open = [];

			this.$rows().each( ( index, row ) => {
				if ( ! $( row ).hasClass( '-collapsed' ) ) {
					open.push( index );
				}
			} );

			acf.setPreference( name, open );
		},

		syncRows() {
			const $rows = this.$rows();
			const count = $rows.length;
			const min = this.minRows();
			const max = this.maxRows();
			const atMax = max > 0 && count >= max;
			const atMin = min > 0 && count <= min;
			const maxReason = sprintf(
				/* translators: %d: maximum number of rows. */
				_n( 'This field allows %d row at most.', 'This field allows %d rows at most.', max, 'repeater-rows-for-acf' ),
				max
			);
			const minReason = sprintf(
				/* translators: %d: minimum number of rows. */
				_n( 'This field needs at least %d row.', 'This field needs at least %d rows.', min, 'repeater-rows-for-acf' ),
				min
			);

			$rows.each( ( index, row ) =>
				this.syncRow( $( row ), index, count, {
					atMax,
					atMin,
					maxReason,
					minReason,
				} )
			);

			const $control = this.$control();

			$control.toggleClass( '-empty', 0 === count );
			$control
				.find( '> .rrfa-footer .rrfa-add' )
				.prop( 'disabled', atMax )
				.attr( 'title', atMax ? maxReason : null );
			$control.find( '> .rrfa-toolbar .rrfa-status' ).text( this.statusText( count, max ) );

			this.syncInputLimit();
		},

		/**
		 * PHP drops every input past max_input_vars. The server refuses to save a repeater
		 * that was cut off this way, so warn before work is lost. A nested repeater
		 * leaves this to its outermost parent.
		 */
		syncInputLimit() {
			const $control = this.$control();
			const limit = parseInt( $control.attr( 'data-input-limit' ), 10 ) || 0;
			const form = $control.closest( 'form' )[ 0 ];

			if ( ! limit || ! form || this.$el.parents( '.rrfa-row' ).length ) {
				return;
			}

			const count = form.elements.length;
			const $warning = $control.children( '.rrfa-limit-warning' );

			if ( count < limit * 0.9 ) {
				$warning.prop( 'hidden', true );
				return;
			}

			$warning.prop( 'hidden', false ).text(
				sprintf(
					/* translators: 1: number of inputs in the form, 2: number of inputs the server accepts. */
					__( 'This form has about %1$d inputs, and the server accepts %2$d. Past that limit, changes to this field will not be saved. Remove some rows, or ask your host to raise the PHP setting max_input_vars.', 'repeater-rows-for-acf' ),
					count,
					limit
				)
			);
		},

		statusText( count, max ) {
			if ( max ) {
				return sprintf(
					/* translators: 1: number of rows in use, 2: maximum number of rows. */
					_n( '%1$d of %2$d row', '%1$d of %2$d rows', max, 'repeater-rows-for-acf' ),
					count,
					max
				);
			}

			return sprintf(
				/* translators: %d: number of rows. */
				_n( '%d row', '%d rows', count, 'repeater-rows-for-acf' ),
				count
			);
		},

		syncRow( $row, index, count, limits ) {
			const number = index + 1;
			const $actions = this.$rowActions( $row );
			const setButton = ( action, label, disabled, reason ) => {
				$actions
					.children( `[data-rrfa-action="${ action }"]` )
					.prop( 'disabled', disabled )
					.attr( 'aria-label', label )
					.attr( 'title', disabled && reason ? reason : label );
			};

			$row
				.children( '.rrfa-row__header, .rrfa-cell--handle' )
				.find( '> .rrfa-row__index, > .rrfa-row__toggle > .rrfa-row__index' )
				.text( number );

			setButton(
				'up',
				/* translators: %d: row number. */
				sprintf( __( 'Move row %d up', 'repeater-rows-for-acf' ), number ),
				0 === index
			);
			setButton(
				'down',
				/* translators: %d: row number. */
				sprintf( __( 'Move row %d down', 'repeater-rows-for-acf' ), number ),
				index === count - 1
			);
			setButton(
				'duplicate',
				/* translators: %d: row number. */
				sprintf( __( 'Duplicate row %d', 'repeater-rows-for-acf' ), number ),
				limits.atMax,
				limits.maxReason
			);
			setButton(
				'remove',
				/* translators: %d: row number. */
				sprintf( __( 'Delete row %d', 'repeater-rows-for-acf' ), number ),
				limits.atMin,
				limits.minReason
			);

			const $toggle = this.$rowToggle( $row );

			// ACF's rename updates the body ID but not this reference to it.
			$toggle.attr( 'aria-controls', $row.children( '.rrfa-row__body' ).attr( 'id' ) );

			this.syncRowTitle( $row, index );
		},

		syncRowTitle( $row, index ) {
			const $title = this.$rowToggle( $row ).children( '.rrfa-row__title' );

			if ( ! $title.length ) {
				return;
			}

			const title = this.readRowTitle( $row );

			$title.text(
				title ||
					/* translators: %d: row number. */
					sprintf( __( 'Row %d', 'repeater-rows-for-acf' ), index + 1 )
			);
		},

		readRowTitle( $row ) {
			const key = this.$control().attr( 'data-title-key' );

			if ( ! key ) {
				return '';
			}

			const $cell = $row
				.children( '.rrfa-row__body' )
				.children( `.acf-field[data-key="${ $.escapeSelector( key ) }"]` );

			switch ( $cell.attr( 'data-type' ) ) {
				case 'select':
					return $cell
						.find( 'select' )
						.first()
						.find( 'option:selected' )
						.map( ( index, option ) => ( $( option ).val() ? $( option ).text() : null ) )
						.get()
						.join( ', ' );
				case 'radio':
				case 'button_group':
					return $cell.find( 'input:checked' ).first().closest( 'label' ).text().trim();
				case 'wysiwyg':
					return stripHtml( String( $cell.find( 'textarea' ).first().val() || '' ) ).trim();
			}

			return String( $cell.find( 'textarea, input:not([type="hidden"])' ).first().val() || '' ).trim();
		},
	} );

	acf.registerFieldType( RepeaterRows );

	// Errors can't be seen in a closed row, so open it and any rows around it.
	acf.addAction( 'invalid_field', ( field ) => {
		field.$el.parents( '.rrfa-row.-collapsed' ).each( function () {
			const $row = $( this );

			acf.getField( $row.closest( '.acf-field[data-type="rrfa_repeater"]' ) ).setRowOpen( $row, true );
		} );
	} );
}( jQuery ) );
