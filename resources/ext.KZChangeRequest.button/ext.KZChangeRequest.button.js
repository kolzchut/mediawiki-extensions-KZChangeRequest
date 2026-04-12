'use strict';

/**
 * Small module that just handles the initial button click
 */
( function () {
	function bindButton( button ) {
		if ( button.dataset.kzChangeRequestBound === '1' ) {
			return;
		}

		button.dataset.kzChangeRequestBound = '1';
		button.addEventListener( 'click', ( e ) => {
			e.preventDefault();

			// Load the main form module
			mw.loader.using( 'ext.KZChangeRequest.form' ).then( () => {
				mw.kzChangeRequest.showForm();
			} );
		} );
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.changerequest-btn' ),
			bindButton
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}() );
