/**
 * Small module that just handles the initial button click
 */
( function () {
	'use strict';

	$( function () {
		$( '.changerequest-btn' ).on( 'click', function ( e ) {
			e.preventDefault();

			// Load the main form module
			mw.loader.using( 'ext.KZChangeRequest.form' ).then( function () {
				mw.kzChangeRequest.showForm();
			} );
		} );
	} );
}() );
