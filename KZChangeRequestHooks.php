<?php

use MediaWiki\Hook\BeforePageDisplayHook;

class KZChangeRequestHooks implements BeforePageDisplayHook {
	/**
	 * Add the resource loader module for the Change Request button
	 * Pass the user email to the client side
	 * add the resource module.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules( [ 'ext.KZChangeRequest.button' ] );
		$email = $out->getUser()->getEmail();
		if ( $email ) {
			$out->addJsConfigVars( [
				'wgUserEmail' => $email
			] );
		}
	}
}
