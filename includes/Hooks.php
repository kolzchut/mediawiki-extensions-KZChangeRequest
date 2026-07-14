<?php

namespace MediaWiki\Extension\KZChangeRequest;

use MediaWiki\Output\Hook\BeforePageDisplayHook;

class Hooks implements BeforePageDisplayHook {

	/**
	 * Seed a conservative default rate limit for the change-request endpoint
	 * so the throttle is active out of the box. Operators can still override
	 * $wgRateLimits['kzchangerequest'] in LocalSettings.
	 *
	 * Wired via the "callback" key in extension.json.
	 */
	public static function onRegistration(): void {
		global $wgRateLimits;
		if ( !isset( $wgRateLimits['kzchangerequest'] ) ) {
			$wgRateLimits['kzchangerequest'] = [
				'anon' => [ 3, 600 ],
				'ip' => [ 5, 3600 ],
				'newbie' => [ 5, 3600 ],
				'user' => [ 10, 3600 ],
			];
		}
	}

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
