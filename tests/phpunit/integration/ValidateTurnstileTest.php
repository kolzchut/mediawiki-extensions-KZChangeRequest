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

	/** @var string|false MW_ENVIRONMENT before the test */
	private $savedEnvironment;

	protected function setUp(): void {
		parent::setUp();
		$this->savedEnvironment = getenv( 'MW_ENVIRONMENT' );
	}

	protected function tearDown(): void {
		putenv( $this->savedEnvironment === false
			? 'MW_ENVIRONMENT'
			: 'MW_ENVIRONMENT=' . $this->savedEnvironment );
		parent::tearDown();
	}

	private function useTestSecret(): void {
		$this->overrideConfigValue(
			'KZChangeRequestTurnstileSecretKey',
			ApiKZChangeRequest::TURNSTILE_TEST_SECRET
		);
		$this->overrideConfigValue( 'Server', 'https://kolzchut.org.il' );
	}

	/** What siteverify answers for Cloudflare's always-pass test secret. */
	private function stubTestSecretSiteverify(): void {
		$this->stubSiteverify( json_encode( [
			'success' => true,
			'hostname' => 'example.com',
			'error-codes' => [],
		] ) );
	}

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
		// The hostname check applies only when siteverify reports one.
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

	public function testTestSecretPassesInDevelopment(): void {
		putenv( 'MW_ENVIRONMENT=development' );
		$this->useTestSecret();
		$this->stubTestSecretSiteverify();
		$this->assertTrue( $this->callValidate( 'XXXX.DUMMY.TOKEN.XXXX' ) );
	}

	public function testTestSecretFailsOutsideDevelopment(): void {
		foreach ( [ 'MW_ENVIRONMENT=staging', 'MW_ENVIRONMENT=production', 'MW_ENVIRONMENT' ] as $env ) {
			putenv( $env );
			$this->useTestSecret();
			$this->stubTestSecretSiteverify();
			$this->assertFalse( $this->callValidate( 'XXXX.DUMMY.TOKEN.XXXX' ), $env );
		}
	}

	public function testRealSecretKeepsChecksInDevelopment(): void {
		// Only the test secret skips the cData and hostname checks.
		putenv( 'MW_ENVIRONMENT=development' );
		$this->overrideConfigValue( 'KZChangeRequestTurnstileSecretKey', 'se' );
		$this->overrideConfigValue( 'Server', 'https://kolzchut.org.il' );
		$this->stubTestSecretSiteverify();
		$this->assertFalse( $this->callValidate( 'token' ) );
	}

	public function testUnsuccessfulTestSecretFailsInDevelopment(): void {
		putenv( 'MW_ENVIRONMENT=development' );
		$this->useTestSecret();
		$this->stubSiteverify( json_encode( [
			'success' => false,
			'error-codes' => [ 'invalid-input-response' ],
		] ) );
		$this->assertFalse( $this->callValidate( 'bad-token' ) );
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
