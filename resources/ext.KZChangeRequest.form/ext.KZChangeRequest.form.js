( function () {
	'use strict';

	const config = require( './config.json' );

	// Store reference to active window manager and dialog
	let activeWindowManager;
	let activeDialog;
	let needsReset = false;

	function ChangeRequestDialog( config ) {
		ChangeRequestDialog.super.call( this, config );
		this.pageTitle = config.pageTitle || '';
		this.$element.addClass( 'kzchangerequest-dialog' );
	}

	OO.inheritClass( ChangeRequestDialog, OO.ui.ProcessDialog );

	// Static properties
	ChangeRequestDialog.static.name = 'changeRequestModal';
	ChangeRequestDialog.static.title = OO.ui.deferMsg( 'kzchangerequest' );
	ChangeRequestDialog.static.className = 'kzchangerequest-dialog';
	ChangeRequestDialog.static.size = 'medium';

	ChangeRequestDialog.static.modes = ['form', 'confirmation'];

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
			value: mw.config.get('wgUserEmail') || ''
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

		this.setupReCaptcha();
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
		this.requestField.setValue('');

		// Clear any errors
		this.clearErrors();

		// Reset to form mode
		this.setMode('form');

		// Reset size
		this.updateSize('medium');
	};

	/**
	 * Set up reCAPTCHA and fallback
	 */
	ChangeRequestDialog.prototype.setupReCaptcha = function () {
		const siteKey = config.KZChangeRequestReCaptchaV3SiteKey;
		if (!siteKey) {
			this.showError(mw.msg('kzchangerequest-recaptcha-load-error'));
			return;
		}

		// Load reCAPTCHA script
		mw.loader.load('https://www.google.com/recaptcha/api.js?render=' + siteKey);

		// Set up global reCAPTCHA callback
		window.kzcrGetToken = function() {
			return new Promise(function(resolve, reject) {
				if (!window.grecaptcha) {
					reject(new Error('reCAPTCHA not loaded'));
					return;
				}

				grecaptcha.ready(function() {
					grecaptcha.execute(siteKey, {action: 'change_request'})
						.then(resolve)
						.catch(reject);
				});
			});
		};

		// Set timeout for reCAPTCHA loading
		setTimeout(() => {
			if (!window.grecaptcha) {
				this.showError(mw.msg('kzchangerequest-recaptcha-load-error'));
			}
		}, 10000);  // 10 second timeout
	};

	/**
	 * Show fallback email information
	 */
	ChangeRequestDialog.prototype.getFallbackEmailMessage = function () {
		const fallbackEmail = config.KZChangeRequestFallbackEmail;
		if (!fallbackEmail) {
			return;
		}

		// Build email body
		const emailBody = [
			mw.msg('kzchangerequest-fallback-email-body'),
			'',
			this.requestField.getValue() || '',
			'',
			this.nameField.getValue() || ''
		].join('\n');

		const emailTitle = mw.msg('kzchangerequest-fallback-email-title', this.pageTitle);

		// Create mailto URL
		const mailtoUrl = 'mailto:' + encodeURIComponent(fallbackEmail) +
			'?subject=' + encodeURIComponent( emailTitle ) +
			'&body=' + encodeURIComponent( emailBody );

		// Add fallback message with email link
		return $('<div>')
			.addClass('kzchangerequest-fallback')
			.append(
				mw.msg('kzchangerequest-fallback-message') + ' ',
				$('<a>').attr( 'href', mailtoUrl ).text( fallbackEmail )
			);
	};

	/**
	 * Handle form changes
	 */
	ChangeRequestDialog.prototype.onFormChange = function () {
		const validText = this.requestField.getValue().length > 0;
		const email = this.emailField.getValue();

		if (email) {
			this.emailField.getValidity()
				.then(() => {
					// Promise resolved means valid
					this.actions.setAbilities({
						submit: validText
					});
				})
				.catch(() => {
					// Promise rejected means invalid
					this.actions.setAbilities({
						submit: false
					});
				});
		} else {
			this.actions.setAbilities({
				submit: validText
			});
		}
	};

	/**
	 * Get ready process
	 */
	ChangeRequestDialog.prototype.getSetupProcess = function ( data ) {
		return ChangeRequestDialog.super.prototype.getSetupProcess.call( this, data )
			.next( function () {
				this.actions.setMode( 'form' );
				this.onFormChange();

				// Add custom classes to buttons
				const actions = this.actions.get();
				actions.forEach( function ( action ) {
					if ( action.getAction() === 'submit' ) {
						action.$element.addClass( 'kzchangerequest-submit' );
					} else if ( action.getAction() === 'cancel' ) {
						action.$element.addClass( 'kzchangerequest-cancel' );
					} else if ( action.getAction() === 'cancel-footer' ) {
						action.toggleFramed( false )
						action.$element.addClass( 'kzchangerequest-cancel-footer' );
					}

				} );
			}, this );
	};

	/**
	 * Handle dialog actions
	 */
	ChangeRequestDialog.prototype.getActionProcess = function ( action ) {
		let dialog = this;
		if ( action === 'submit' ) {
			return new OO.ui.Process( function () {
				return dialog.submit();
			});
		} else {
			// Handle all closing actions (cancel, cancel-footer, close, escape key, etc.)
			return new OO.ui.Process(function () {
				if (dialog.getMode() === 'confirmation') {
					// If we're in confirmation mode, this means a successful submission occurred
					dialog.emit('submit'); // Trigger cleanup
				}
				dialog.close({action: action});
			});
		}
		return ChangeRequestDialog.super.prototype.getActionProcess.call( this, action );
	};

	/**
	 * Submit form data
	 */
	ChangeRequestDialog.prototype.submit = async function () {
		this.pushPending();
		this.clearErrors();

		try {
			// Get reCAPTCHA token
			let token;
			try {
				token = await window.kzcrGetToken();
				if (!token) {
					throw new Error('No token received');
				}
			} catch (e) {
				this.showError(mw.msg('kzchangerequest-captcha-fail'));
				return;
			}

			const api = new mw.Api();
			const result = await api.postWithToken( 'csrf', {
				action: 'kzchangerequest',
				articleId: mw.config.get( 'wgArticleId' ),
				request: this.requestField.getValue(),
				contactName: this.nameField.getValue(),
				contactEmail: this.emailField.getValue(),
				'g-recaptcha-response': token
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
			this.popPending();
		}
	};

	/**
	 * Show error message
	 */
	ChangeRequestDialog.prototype.showError = function ( message ) {
		const error = new OO.ui.MessageWidget( {
			type: 'error',
			label: message,
			classes: [ 'kzchangerequest-error' ]
		} );

		error.$label.append( this.getFallbackEmailMessage() );
		this.$body.prepend( error.$element );
	};

	/**
	 * Clear error messages
	 */
	ChangeRequestDialog.prototype.clearErrors = function () {
		this.$body.find( '.kzchangerequest-error' ).remove();
	};

	/**
	 * Export public interface
	 */
	mw.kzChangeRequest = {
		showForm: function () {
			// If there's already a dialog open, just focus it unless it needs reset
			if (activeWindowManager && activeDialog) {
				if (needsReset) {
					// Clear form and reset to initial state
					activeDialog.reset();
					needsReset = false;
				} else {
					// Reuse existing validation logic
					activeDialog.onFormChange();
				}
				activeWindowManager.openWindow(activeDialog);
				return;
			}

			// Create new window manager and dialog
			activeWindowManager = new OO.ui.WindowManager();
			$( document.body ).append( activeWindowManager.$element );

			activeDialog = new ChangeRequestDialog( {
				pageTitle: mw.config.get( 'wgPageName' ).replace( /_/g, ' ' )
			} );

			activeWindowManager.addWindows( [ activeDialog ] );
			activeWindowManager.openWindow( activeDialog );

			// Only clean up when dialog is submitted successfully
			activeDialog.on('submit', function() {
				activeDialog.$element.remove();
				activeWindowManager.$element.remove();
				activeDialog = null;
				activeWindowManager = null;
			});
		}
	};

}() );
