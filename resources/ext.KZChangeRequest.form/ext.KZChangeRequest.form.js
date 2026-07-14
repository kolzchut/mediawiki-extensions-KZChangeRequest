'use strict';

( function () {
	const config = require( './config.json' );

	// Store reference to active window manager and dialog
	let activeWindowManager;
	let activeDialog;
	let needsReset = false;

	function ChangeRequestDialog( dialogConfig ) {
		ChangeRequestDialog.super.call( this, dialogConfig );
		this.pageTitle = dialogConfig.pageTitle || '';
		this.$element.addClass( 'kzchangerequest-dialog' );
	}

	OO.inheritClass( ChangeRequestDialog, OO.ui.ProcessDialog );

	// Static properties
	ChangeRequestDialog.static.name = 'changeRequestModal';
	ChangeRequestDialog.static.title = OO.ui.deferMsg( 'kzchangerequest' );
	ChangeRequestDialog.static.className = 'kzchangerequest-dialog';
	ChangeRequestDialog.static.size = 'medium';

	ChangeRequestDialog.static.modes = [ 'form', 'confirmation' ];

	ChangeRequestDialog.static.actions = [
		{
			flags: [ 'progressive' ],
			action: 'submit',
			label: mw.msg( 'kzchangerequest-submit' ),
			disabled: true,
			modes: 'form'
		},
		{
			flags: [ 'progressive', 'close', 'safe' ],
			action: 'cancel',
			label: mw.msg( 'kzchangerequest-cancel' ),
			modes: [ 'form', 'confirmation' ]
		},
		// A secondary close button at the footer
		{
			flags: [ 'safe' ],
			action: 'cancel-footer',
			label: mw.msg( 'kzchangerequest-cancel' ),
			modes: 'form'
		},
		{
			flags: [ 'safe' ],
			action: 'close',
			label: mw.msg( 'ooui-dialog-message-accept' ),
			modes: 'confirmation'
		}
	];

	ChangeRequestDialog.prototype.initialize = function () {
		ChangeRequestDialog.super.prototype.initialize.apply( this, arguments );

		// Create form panel
		this.formPanel = new OO.ui.PanelLayout( {
			padded: true,
			expanded: false
		} );

		// Create confirmation panel
		this.confirmationPanel = new OO.ui.PanelLayout( {
			padded: true,
			expanded: false
		} );

		this.introText = new OO.ui.Widget( {
			$element: $( '<div>' )
				.addClass( 'kzcr-intro' )
				.append(
					$( '<h4>' ).text( mw.msg( 'kzchangerequest-intro-1' ) ),
					$( '<p>' ).text( mw.msg( 'kzchangerequest-intro-2' ) )
				)
		} );

		this.pageTitleLabel = new OO.ui.LabelWidget( {
			label: this.pageTitle,
			classes: [ 'kzcr-page-title' ]
		} );

		this.requestField = new OO.ui.MultilineTextInputWidget( {
			rows: 4,
			required: true,
			placeholder: mw.msg( 'kzchangerequest-request' )
		} );

		this.contactIntro = new OO.ui.Widget( {
			$element: $( '<div>' )
				.addClass( 'kzcr-contact-intro' )
				.append(
					$( '<h4>' ).text( mw.msg( 'kzchangerequest-contact-intro-1' ) ),
					$( '<p>' ).text( mw.msg( 'kzchangerequest-contact-intro-2' ) )
				)
		} );

		this.nameField = new OO.ui.TextInputWidget( {
			value: mw.config.get( 'wgUserName' ) || ''
		} );

		this.emailField = new OO.ui.TextInputWidget( {
			type: 'email',
			validate: 'email',
			value: mw.config.get( 'wgUserEmail' ) || ''
		} );

		this.noticeText = new OO.ui.LabelWidget( {
			label: mw.msg( 'kzchangerequest-notice' ),
			classes: [ 'kzcr-notice' ]
		} );

		// Build form layout
		this.fieldset = new OO.ui.FieldsetLayout( {
			classes: [ 'kzcr-form' ],
			items: [
				this.introText,
				new OO.ui.FieldLayout( this.pageTitleLabel, {
					align: 'top',
					label: mw.msg( 'kzchangerequest-relevantpage' )
				} ),
				new OO.ui.FieldLayout( this.requestField, {
					align: 'top',
					label: mw.msg( 'kzchangerequest-request' )
				} ),
				this.contactIntro,
				new OO.ui.HorizontalLayout( {
					classes: [ 'kzcr-contact-fields' ],
					items: [
						new OO.ui.FieldLayout( this.nameField, {
							align: 'top',
							label: mw.msg( 'kzchangerequest-contact-name' )
						} ),
						new OO.ui.FieldLayout( this.emailField, {
							align: 'top',
							label: mw.msg( 'kzchangerequest-contact-email' )
						} )
					]
				} ),
				this.noticeText
			]
		} );

		// Container the Cloudflare Turnstile widget renders into (managed mode
		// shows an interactive checkbox to suspicious clients, unlike the old
		// fully-invisible reCAPTCHA v3).
		this.turnstileContainer = new OO.ui.Widget( {
			$element: $( '<div>' ).addClass( 'kzcr-turnstile' )
		} );
		this.fieldset.addItems( [ this.turnstileContainer ] );

		// Add fieldset to form panel
		this.formPanel.$element.append( this.fieldset.$element );

		// Create success message for confirmation panel
		this.successMessage = new OO.ui.MessageWidget( {
			type: 'success',
			label: mw.msg( 'kzchangerequest-confirmation-message' ),
			classes: [ 'kzchangerequest-success' ]
		} );
		this.confirmationPanel.$element.append( this.successMessage.$element );

		// Add panels to dialog
		this.$body.append(
			this.formPanel.$element,
			this.confirmationPanel.$element
		);

		// Events
		this.requestField.connect( this, { change: 'onFormChange' } );
		this.nameField.connect( this, { change: 'onFormChange' } );
		this.emailField.connect( this, { change: 'onFormChange' } );

		this.setMode( 'form' );
		this.onFormChange();

		this.setupTurnstile();
	};

	// Add mode handling
	ChangeRequestDialog.prototype.getMode = function () {
		return this.mode;
	};

	ChangeRequestDialog.prototype.setMode = function ( mode ) {
		if ( this.mode === mode ) {
			return;
		}

		this.mode = mode;

		// Show/hide panels based on mode
		this.formPanel.toggle( mode === 'form' );
		this.confirmationPanel.toggle( mode === 'confirmation' );

		// Update actions
		this.actions.setMode( mode );

		// Adjust size for confirmation mode
		if ( mode === 'confirmation' ) {
			this.updateSize( 'small' );
		}
	};

	/**
	 * Reset the dialog to its initial state
	 */
	ChangeRequestDialog.prototype.reset = function () {
		// Clear form fields
		this.requestField.setValue( '' );

		// Clear any errors
		this.clearErrors();

		// Mint a fresh Turnstile token for the next submission.
		this.resetTurnstile();

		// Reset to form mode
		this.setMode( 'form' );

		// Reset size
		this.updateSize( 'medium' );
	};

	/**
	 * Set up the Cloudflare Turnstile widget and fallback.
	 *
	 * Replaces the old invisible reCAPTCHA v3. Turnstile in managed mode renders
	 * a widget that solves ahead of time, so the token is read synchronously via
	 * turnstile.getResponse() at submit; the whole platform shares one widget,
	 * this service being identified by cData 'kzchangerequest'.
	 */
	ChangeRequestDialog.prototype.setupTurnstile = function () {
		const siteKey = config.KZChangeRequestTurnstileSiteKey;
		if ( !siteKey ) {
			this.showError( mw.msg( 'kzchangerequest-captcha-load-error' ) );
			return;
		}

		const dialog = this;
		const container = this.turnstileContainer.$element[ 0 ];
		// Explicit interface language (he/ar/ru natively supported) rather than
		// Turnstile's browser auto-detect, so the widget matches the wiki UI.
		const language = mw.config.get( 'wgUserLanguage' ) ||
			mw.config.get( 'wgContentLanguage' ) || 'auto';

		function renderWidget() {
			// Guard against a double render if the dialog is reused.
			if ( dialog.turnstileWidgetId !== undefined && dialog.turnstileWidgetId !== null ) {
				return;
			}
			dialog.turnstileWidgetId = window.turnstile.render( container, {
				sitekey: siteKey,
				cData: 'kzchangerequest',
				language: language
			} );
		}

		// Turnstile invokes this global once api.js has finished loading.
		window.kzcrOnloadTurnstile = renderWidget;

		if ( window.turnstile ) {
			// Script already present (dialog reopened in the same page view).
			renderWidget();
		} else {
			mw.loader.load(
				'https://challenges.cloudflare.com/turnstile/v0/api.js' +
				'?onload=kzcrOnloadTurnstile&render=explicit'
			);
		}

		// Global token getter used by submit(). getResponse() returns the solved
		// token, or '' if the widget has not completed yet.
		window.kzcrGetToken = function () {
			return new Promise( ( resolve, reject ) => {
				if ( !window.turnstile || dialog.turnstileWidgetId === undefined ||
					dialog.turnstileWidgetId === null ) {
					reject( new Error( 'Turnstile not loaded' ) );
					return;
				}
				const token = window.turnstile.getResponse( dialog.turnstileWidgetId );
				if ( token ) {
					resolve( token );
				} else {
					reject( new Error( 'Turnstile not solved' ) );
				}
			} );
		};

		// Fallback if the script never loads.
		setTimeout( () => {
			if ( !window.turnstile ) {
				dialog.showError( mw.msg( 'kzchangerequest-captcha-load-error' ) );
			}
		}, 10000 ); // 10 second timeout
	};

	/**
	 * Reset the Turnstile widget so a fresh token is minted. Turnstile tokens are
	 * single-use (consumed server-side by siteverify), so this must run after any
	 * submit attempt before the form can be submitted again.
	 */
	ChangeRequestDialog.prototype.resetTurnstile = function () {
		if ( window.turnstile && this.turnstileWidgetId !== undefined &&
			this.turnstileWidgetId !== null ) {
			window.turnstile.reset( this.turnstileWidgetId );
		}
	};

	/**
	 * Show fallback email information
	 *
	 * @return {jQuery|undefined}
	 */
	ChangeRequestDialog.prototype.getFallbackEmailMessage = function () {
		const fallbackEmail = config.KZChangeRequestFallbackEmail;
		if ( !fallbackEmail ) {
			return;
		}

		// Build email body
		const emailBody = [
			mw.msg( 'kzchangerequest-fallback-email-body' ),
			'',
			this.requestField.getValue() || '',
			'',
			this.nameField.getValue() || ''
		].join( '\n' );

		const emailTitle = mw.msg( 'kzchangerequest-fallback-email-title', this.pageTitle );

		// Create mailto URL
		const mailtoUrl = 'mailto:' + encodeURIComponent( fallbackEmail ) +
			'?subject=' + encodeURIComponent( emailTitle ) +
			'&body=' + encodeURIComponent( emailBody );

		// Add fallback message with email link
		return $( '<div>' )
			.addClass( 'kzchangerequest-fallback' )
			.append(
				mw.msg( 'kzchangerequest-fallback-message' ) + ' ',
				$( '<a>' ).attr( 'href', mailtoUrl ).text( fallbackEmail )
			);
	};

	/**
	 * Handle form changes
	 */
	ChangeRequestDialog.prototype.onFormChange = function () {
		const validText = this.requestField.getValue().length > 0;
		const email = this.emailField.getValue();

		if ( email ) {
			this.emailField.getValidity()
				.then( () => {
					// Promise resolved means valid
					this.actions.setAbilities( {
						submit: validText
					} );
				} )
				.catch( () => {
					// Promise rejected means invalid
					this.actions.setAbilities( {
						submit: false
					} );
				} );
		} else {
			this.actions.setAbilities( {
				submit: validText
			} );
		}
	};

	/**
	 * Get ready process
	 *
	 * @param {Object} data
	 * @return {OO.ui.Process}
	 */
	ChangeRequestDialog.prototype.getSetupProcess = function ( data ) {
		return ChangeRequestDialog.super.prototype.getSetupProcess.call( this, data )
			.next( function () {
				this.actions.setMode( 'form' );
				this.onFormChange();

				// Add custom classes to buttons
				const actions = this.actions.get();
				actions.forEach( ( action ) => {
					if ( action.getAction() === 'submit' ) {
						action.$element.addClass( 'kzchangerequest-submit' );
					} else if ( action.getAction() === 'cancel' ) {
						action.$element.addClass( 'kzchangerequest-cancel' );
					} else if ( action.getAction() === 'cancel-footer' ) {
						action.toggleFramed( false );
						action.$element.addClass( 'kzchangerequest-cancel-footer' );
					}

				} );
			}, this );
	};

	/**
	 * Handle dialog actions
	 *
	 * @param {string} action
	 * @return {OO.ui.Process}
	 */
	ChangeRequestDialog.prototype.getActionProcess = function ( action ) {
		const dialog = this;
		if ( action === 'submit' ) {
			return new OO.ui.Process( () => dialog.submit() );
		}

		// Handle all closing actions (cancel, cancel-footer, close, escape key, etc.)
		return new OO.ui.Process( () => {
			if ( dialog.getMode() === 'confirmation' ) {
				// If we're in confirmation mode, this means a successful submission occurred
				dialog.emit( 'submit' ); // Trigger cleanup
			}
			dialog.close( { action: action } );
		} );
	};

	/**
	 * Submit form data
	 */
	ChangeRequestDialog.prototype.submit = async function () {
		this.pushPending();
		this.clearErrors();

		try {
			// Get the Turnstile token
			let token;
			try {
				token = await window.kzcrGetToken();
				if ( !token ) {
					throw new Error( 'No token received' );
				}
			} catch ( e ) {
				this.showError( mw.msg( 'kzchangerequest-captcha-fail' ) );
				return;
			}

			const api = new mw.Api();
			const result = await api.postWithToken( 'csrf', {
				action: 'kzchangerequest',
				articleId: mw.config.get( 'wgArticleId' ),
				request: this.requestField.getValue(),
				contactName: this.nameField.getValue(),
				contactEmail: this.emailField.getValue(),
				'cf-turnstile-response': token
			} );

			if ( result.success ) {
				needsReset = true;
				this.setMode( 'confirmation' );
				// Mark for cleanup on next close instead of immediate cleanup
				this.emit( 'submitted' );
			} else {
				throw new Error( result.error );
			}
		} catch ( err ) {
			this.showError( mw.msg( 'kzchangerequest-submission-error' ) );
		} finally {
			// The token just posted is now single-use-consumed server-side; mint a
			// fresh one in case the user submits again.
			this.resetTurnstile();
			this.popPending();
		}
	};

	/**
	 * Show error message
	 *
	 * @param {string} message
	 */
	ChangeRequestDialog.prototype.showError = function ( message ) {
		const error = new OO.ui.MessageWidget( {
			type: 'error',
			label: message,
			classes: [ 'kzchangerequest-error' ]
		} );

		error.$label.append( this.getFallbackEmailMessage() );
		this.$body[ 0 ].prepend( error.$element[ 0 ] );
	};

	/**
	 * Clear error messages
	 */
	ChangeRequestDialog.prototype.clearErrors = function () {
		Array.prototype.forEach.call(
			this.$body[ 0 ].querySelectorAll( '.kzchangerequest-error' ),
			( element ) => {
				element.remove();
			}
		);
	};

	/**
	 * Export public interface
	 */
	mw.kzChangeRequest = {
		showForm: function () {
			// If there's already a dialog open, just focus it unless it needs reset
			if ( activeWindowManager && activeDialog ) {
				if ( needsReset ) {
					// Clear form and reset to initial state
					activeDialog.reset();
					needsReset = false;
				} else {
					// Reuse existing validation logic
					activeDialog.onFormChange();
				}
				activeWindowManager.openWindow( activeDialog );
				return;
			}

			// Create new window manager and dialog
			activeWindowManager = new OO.ui.WindowManager();
			document.body.appendChild( activeWindowManager.$element[ 0 ] );

			activeDialog = new ChangeRequestDialog( {
				pageTitle: mw.config.get( 'wgPageName' ).replace( /_/g, ' ' )
			} );

			activeWindowManager.addWindows( [ activeDialog ] );
			activeWindowManager.openWindow( activeDialog );

			// Only clean up when dialog is submitted successfully
			activeDialog.on( 'submit', () => {
				activeDialog.$element[ 0 ].remove();
				activeWindowManager.$element[ 0 ].remove();
				activeDialog = null;
				activeWindowManager = null;
			} );
		}
	};

}() );
