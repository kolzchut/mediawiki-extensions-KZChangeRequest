<?php

class KZChangeRequest {

	/**
	 * @param int|null $articleId
	 *
	 * @return string HTML markup for the Change Request button
	 * @throws MWException
	 */
	public static function createChangeRequestButton( ?int $articleId = null ): string {
		$urlParams = $articleId ? [ 'articleId' => $articleId ] : "";
		$url = SpecialPage::getTitleFor( 'KZChangeRequest' )->getLocalURL( $urlParams );
		return Html::element( 'a',
			[ 'class' => 'btn ranking-btn changerequest', 'href' => $url ],
			wfMessage( 'kzchangerequest-button-label' )
		);
	}

}
