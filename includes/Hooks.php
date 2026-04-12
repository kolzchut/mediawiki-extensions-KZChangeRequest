<?php

namespace MediaWiki\Extension\KZChangeRequest;

use MediaWiki\Output\Hook\BeforePageDisplayHook;

class Hooks implements BeforePageDisplayHook {

	/**
	 * Add the resource loader module for the Change Request button
	 * Pass the user email to the client side
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param \OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules( [ 'ext.KZChangeRequest.button' ] );

		$email = $out->getUser()->getEmail();
		if ( $email ) {
			$out->addJsConfigVars( [
				'wgUserEmail' => $email,
			] );
		}
	}
}
