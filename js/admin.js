/**
 * Stock & Price Sync for WooCommerce - admin screen behavior.
 * Plain JavaScript, no dependencies.
 */
( function () {
	'use strict';

	// Settings: show only the fields that apply to the selected feed format.
	var format = document.getElementById( 'wsps-source_type' );

	function toggleFormatRows() {
		var value = format.value;
		document.querySelectorAll( '.wsps-only-csv' ).forEach( function ( row ) {
			row.hidden = 'csv' !== value;
		} );
		document.querySelectorAll( '.wsps-only-json' ).forEach( function ( row ) {
			row.hidden = 'json' !== value;
		} );
	}

	if ( format ) {
		format.addEventListener( 'change', toggleFormatRows );
		toggleFormatRows();
	}

	// Log: expand and collapse the details of a run.
	document.querySelectorAll( '.wsps-toggle' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var target = document.getElementById( button.getAttribute( 'aria-controls' ) );
			var expanded = 'true' === button.getAttribute( 'aria-expanded' );

			if ( ! target ) {
				return;
			}
			target.hidden = expanded;
			button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
			button.textContent = expanded ? button.dataset.show : button.dataset.hide;
		} );
	} );
}() );
