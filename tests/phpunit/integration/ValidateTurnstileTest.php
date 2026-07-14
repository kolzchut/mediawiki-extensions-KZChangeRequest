<?php

namespace MediaWiki\Extension\KZChangeRequest\Tests\Integration;

use ApiMain;
use FauxRequest;
use MediaWiki\Extension\KZChangeRequest\ApiKZChangeRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiIntegrationTestCase;
use MWHttpRequest;
use ReflectionMethod;
use Status;

/**
 * @covers \MediaWiki\Extension\KZChangeRequest\ApiKZChangeRequest
 * @group KZChangeRequest
 */
class ValidateTurnstileTest extends MediaWikiIntegrationTestCase {

	/**
	 * Stub the Turnstile siteverify HTTP call with a canned response.
	 *
	 * @param string $body Response body returned by getContent()
	 * @param bool $httpOk Whether the request Status is OK (false = transport error)
	 */
	private function stubSiteverify( string $body, bool $httpOk = true ): void {
		$req = $this->createMock( MWHttpRequest::class );
		$req->method( 'execute' )
			->willReturn( $httpOk ? Status::newGood() : Status::newFatal( 'http-error' ) );
		$req->method( 'getContent' )->willReturn( $body );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $req );
		$this->setService( 'HttpRequestFactory', $factory );
	}

	/**
	 * Invoke the private validateTurnstile() on a fresh API module instance.
	 */
	private function callValidate( string $token ): bool {
		$api = new ApiKZChangeRequest( new ApiMain( new FauxRequest() ), 'kzchangerequest' );
		$method = new ReflectionMethod( $api, 'validateTurnstile' );
		$method->setAccessible( true );
		return $method->invoke( $api, $token );
	}

	public function testMissingSecretFails(): void {
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', '' );
		// Empty secret short-circuits to false without any siteverify call.
		$this->assertFalse( $this->callValidate( 'tok' ) );
	}

	public function testValidTokenPasses(): void {
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->overrideConfigValue( 'Server', 'https://kolzchut.org.il' );
		$this->stubSiteverify( json_encode( [
			'success' => true,
			'cdata' => 'kzchangerequest',
			'hostname' => 'kolzchut.org.il',
		] ) );
		$this->assertTrue( $this->callValidate( 'good-token' ) );
	}

	public function testMissingHostnameStillPasses(): void {
		// Cloudflare's always-pass test keys omit hostname; a valid cData is enough.
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->stubSiteverify( json_encode( [
			'success' => true,
			'cdata' => 'kzchangerequest',
		] ) );
		$this->assertTrue( $this->callValidate( 'good-token' ) );
	}

	public function testWrongCdataFails(): void {
		// A token minted for another consumer of the shared platform widget.
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->stubSiteverify( json_encode( [
			'success' => true,
			'cdata' => 'articleranking',
			'hostname' => 'kolzchut.org.il',
		] ) );
		$this->assertFalse( $this->callValidate( 'foreign-token' ) );
	}

	public function testWrongHostnameFails(): void {
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->overrideConfigValue( 'Server', 'https://kolzchut.org.il' );
		$this->stubSiteverify( json_encode( [
			'success' => true,
			'cdata' => 'kzchangerequest',
			'hostname' => 'evil.example.com',
		] ) );
		$this->assertFalse( $this->callValidate( 'replayed-token' ) );
	}

	public function testUnsuccessfulVerificationFails(): void {
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->stubSiteverify( json_encode( [
			'success' => false,
			'error-codes' => [ 'invalid-input-response' ],
		] ) );
		$this->assertFalse( $this->callValidate( 'bad-token' ) );
	}

	public function testTransportErrorFails(): void {
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->stubSiteverify( '', false );
		$this->assertFalse( $this->callValidate( 'token' ) );
	}
}
