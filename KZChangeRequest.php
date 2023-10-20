<?php

class KZChangeRequest {

	/**
	 * @return string HTML markup for the Change Request button
	 */
	public static function createChangeRequestButton( $articleId = null ): string {
		$urlParams = $articleId ? [ 'articleId' => $articleId ] : "";
		$url = SpecialPage::getTitleFor( 'KZChangeRequest' )->getLocalURL( $urlParams );
		return Html::element( 'a',
			[ 'class' => 'btn ranking-btn changerequest', 'href' => $url ],
			wfMessage( 'kzchangerequest-button-label' )
		);
	}

}
