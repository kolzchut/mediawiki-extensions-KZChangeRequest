<?php

class KZChangeRequest {

	/**
	 * @return Markup for the Change Request button with intro text.
	 */
	public static function createChangeRequestButton() {
		$templateParser = new TemplateParser( __DIR__ . '/templates' );

		return $templateParser->processTemplate( 'change-request-button', [
			'buttonIntro'  => wfMessage( 'kzchangerequest-button-intro' ),
			'buttonLabel' => wfMessage( 'kzchangerequest-button-label' ),
		] );
	}

}
