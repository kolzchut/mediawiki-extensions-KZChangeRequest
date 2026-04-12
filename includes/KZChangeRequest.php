<?php

namespace MediaWiki\Extension\KZChangeRequest;

use MediaWiki\Html\Html;

class KZChangeRequest {

	/**
	 * @param int|null $articleId
	 *
	 * @return string HTML markup for the Change Request button
	 * @throws \MWException
	 */
	public static function createChangeRequestButton( ?int $articleId = null ): string {
		return Html::element(
			'a',
			[ 'class' => 'btn btn-secondary ranking-btn changerequest-btn', 'href' => '#' ],
			wfMessage( 'kzchangerequest-button-label' )->text()
		);
	}
}
