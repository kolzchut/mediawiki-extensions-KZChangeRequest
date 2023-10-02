<?php

class KZChangeRequestHooks {

	/**
	 * If change request button will be made available on the page,
	 * add the resource module.
	 *
	 * @param OutputPage &$out The OutputPage object
	 * @param Skin &$skin Skin object that will be used to generate the page
	 * @return bool true
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( [ 'ext.KZChangeRequest.button' ] );
		return true;
	}

}
