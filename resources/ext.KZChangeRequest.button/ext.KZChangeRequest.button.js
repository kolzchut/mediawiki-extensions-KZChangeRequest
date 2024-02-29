$( function () {
	// When the Change Request button is pressed open the form in a modal dialog.
	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.changerequest-btn' ).on( 'click', function ( e ) {
		e.preventDefault();

		mw.loader.using( [ 'mediawiki.api', 'oojs-ui-widgets', 'oojs-ui-windows', 'mediawiki.jqueryMsg' ], function () {

			// Subclass OOUI's ProcessDialog for our modal.
			function ModalDialog( config ) {
				ModalDialog.super.call( this, config );
			}
			OO.inheritClass( ModalDialog, OO.ui.ProcessDialog );
			ModalDialog.static.name = 'changeRequestModal';

			// Minimal dialog chrome, just a close button.
			// Title and submit button will appear (in the user's language) in the content area.
			ModalDialog.static.title = mw.msg( 'kzchangerequest' );
			ModalDialog.static.actions = [
				{
					label: mw.msg( 'kzchangerequest-cancel' ),
					flags: [ 'safe', 'close' ]
				}
			];

			// Initialize the modal's content with a spinning hourglass.
			ModalDialog.prototype.initialize = function () {
				ModalDialog.super.prototype.initialize.apply( this, arguments );
				this.panel = new OO.ui.PanelLayout( { padded: true, expanded: false } );
				this.panel.$element.append(
					'<div id="changeRequestModalBody">' +
					'  <div class="kzcr-spinner"><span class="kzcr-spin">&#9203;</span></div>' +
					'</div>'
				);
				this.$body.append( this.panel.$element );
			};

			// Get modal height.
			ModalDialog.prototype.getBodyHeight = function () {
				return this.panel.$element.outerHeight( true );
			};

			// Instantiate and append the window manager.
			var windowManager = new OO.ui.WindowManager();
			$( document.body ).append( windowManager.$element );

			// Instantiate the modal dialog and add in to the window manager.
			var modalDialog = new ModalDialog( {
				size: 'medium'
			} );
			windowManager.addWindows( [ modalDialog ] );

			// Handle window onClosing.
			windowManager.on( 'closing', function ( win, closed ) {
				closed.done( function () {
					// Clean up the DOM. If the form is re-opened we'll start from the beginning.
					windowManager.destroy();
				} );
			} );

			// Handle window onOpening.
			windowManager.on( 'opening', function ( win, opening ) {
				// If the form loaded during the window-opening transition, restore
				// focus to the first element.
				opening.done( function () {
					$( 'textarea[name=wpkzcrRequest]' ).trigger( 'focus' );
				} );
			} );

			// Cancel from within the form closes the modal.
			var onClose = function () {
				modalDialog.close();
			};

			// Resize the modal when content is updated.
			var onReady = function () {
				modalDialog.updateSize();
				// Scroll to the top of form content.
				$( '#changeRequestModalBody' ).parents( '.oo-ui-window-body' ).scrollTop( 0 );
			};

			// Open the modal.
			windowManager.openWindow( modalDialog );

			// Load the form into the modal.
			mw.loader.using( [ 'ext.KZChangeRequest.form', 'ext.KZChangeRequest.modal' ], function () {
				window.kzcrAjax( $( '#changeRequestModalBody' ), onClose, onReady );
			} );
		} );
	} );
} );
