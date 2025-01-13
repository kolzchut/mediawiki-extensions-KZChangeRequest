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
		return Html::element( 'a',
			[ 'class' => 'btn btn-secondary ranking-btn changerequest-btn', 'href' => '#' ],
			wfMessage( 'kzchangerequest-button-label' )->text()
		);
	}

}
