<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class ApiKZChangeRequestModal extends \ApiBase {
	private SpecialKZChangeRequest $page;

	/**
	 * Define form parameters and default values
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			// this param is for GET requests
			'articleId' => '',
			// this and following are for POST submissions
			'g-recaptcha-response' => '',
			'title' => '',
			'wpEditToken' => '',
			'wpFormIdentifier' => '',
			'wpkzcrArticleId' => '',
			'wpkzcrContactEmail' => '',
			'wpkzcrContactName' => '',
			'wpkzcrRequest' => '',
		];
	}

  /**
   * Programmatically execute Special:KZChangeRequest page and return
   * the data necessary to load it dynamically in a modal.
   *
   * Handles GET requests (display the form) and POST requests (submit the form).
   */
  public function execute() {
		$logger = LoggerFactory::getInstance( 'KZChangeRequest' );

		// Build the page as though the user had requested it by URL.
		$specialPageFactory = MediaWikiServices::getInstance()->getSpecialPageFactory();
		$this->page = $specialPageFactory->getPage( 'KZChangeRequest' );
		$context = \RequestContext::getMain();
		$output = new \OutputPage( $context );
		$context->setOutput( $output );
		$this->page->setContext( $context );
		$output->setTitle( $this->page->getPageTitle() );

		// Execute the page. Log and return error message if this fails.
		$result = $this->getResult();
		try {
			$this->page->execute( '' );
		} catch ( \Exception $e ) {
			$logger->error(
				'Load API could not execute new KZChangeRequest form special page. Exception message: {msg}',
				[ 'msg' => $e->getMessage() ]
			);
			$result->addValue( null, 'error', $this->msg( 'kzchangerequest-modal-load-error' ) );
			return;
		}

		// Return HTML to display in the modal.
		$result->addValue( null, 'title', $this->page->getDescription() );
		$result->addValue( null, 'html', $output->getHTML() );

		if ( $this->page->submissionSuccessful ) {
			// Confirmation page. All we need to add is a "finish" button.
			$result->addValue( null, 'finishMsg', $this->msg( 'kzchangerequest-finish' ) );
		} else {
			// Return everything via JSON that our modal js needs to display the form dynamically.
			$result->addValue( null, 'config', $output->getJsConfigVars() );
			// @TODO: is there a more elegant way to ensure all form-related modules are loaded
			// without pulling in modules the form doesn't need?
			$result->addValue( null, 'modules', $output->getModules() + [
				'mediawiki.widgets.styles', 'oojs-ui.styles.indicators', 'mediawiki.htmlform.ooui.styles'
			] );
			$result->addValue( null, 'bottomScripts', $output->getBottomScripts() );
			$result->addValue( null, 'cancelMsg', $this->msg( 'kzchangerequest-cancel' ) );
		}
  }
}
