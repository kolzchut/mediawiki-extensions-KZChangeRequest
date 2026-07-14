<?php

namespace MediaWiki\Extension\KZChangeRequest;

use Exception;
use MediaWiki\Api\ApiBase;
use MediaWiki\Json\FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;
use WikiPage;

class ApiKZChangeRequest extends ApiBase {
	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/** @inheritDoc */
	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
		$this->logger = LoggerFactory::getInstance( 'KZChangeRequest' );
	}

	/** @inheritDoc */
	public function execute(): void {
		// $this->dieWithError( 'just because');
		// Validate request
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'request' );

		// Throttle before doing any work: each accepted call opens a Jira
		// ticket, and Turnstile tokens (single-use, but mintable in bulk by a
		// solver) do not by themselves cap volume. A default limit is seeded in
		// Hooks::onRegistration so this is active out of the box.
		//
		// pingLimiter() stores its counters in the main object cache. When that
		// is CACHE_NONE the limiter cannot persist a count and (via WRStats over
		// EmptyBagOStuff) fails *closed*, blocking every request — which would
		// take the whole form offline. Skip the throttle in that case, but log
		// loudly so a production cache outage is visible rather than silent.
		// Turnstile still gates submissions regardless. Staging/prod run a Redis
		// main cache, so the throttle is active there.
		if ( $this->getConfig()->get( MainConfigNames::MainCacheType ) === CACHE_NONE ) {
			$this->logger->warning(
				'KZChangeRequest rate limiting is inactive: $wgMainCacheType is CACHE_NONE'
			);
		} elseif ( $this->getUser()->pingLimiter( 'kzchangerequest' ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		// Validate the Cloudflare Turnstile token
		if ( !$this->validateTurnstile( $params['cf-turnstile-response'] ) ) {
			$this->dieWithError( 'kzchangerequest-captcha-fail' );
		}

		// Get page info. WikiPage::newFromID() was removed in MediaWiki 1.43;
		// use the WikiPageFactory service instead.
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromID( $params['articleId'] );
		if ( !$page ) {
			$this->dieWithError( 'kzchangerequest-invalid-page' );
		}

		try {
			// Create Jira ticket
			$this->createJiraTicket( $params, $page );

			$this->getResult()->addValue( null, 'success', 1 );
		} catch ( Exception $e ) {
			$this->logger->error( 'Failed to create Jira ticket', [
				'exception' => $e,
				'articleId' => $params['articleId']
			] );
			$this->dieWithError( 'kzchangerequest-submission-error' );
		}
	}

	/** @inheritDoc */
	protected function getAllowedParams(): array {
		return [
			'articleId' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'request' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				StringDef::PARAM_MAX_CHARS => 5000,
			],
			'contactName' => [
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_CHARS => 200,
			],
			'contactEmail' => [
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_CHARS => 254,
			],
			'cf-turnstile-response' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * Create Jira ticket for the given page
	 * @param array $params
	 * @param WikiPage $page
	 * @return bool
	 */
	private function createJiraTicket( array $params, WikiPage $page ): bool {
		$config = $this->getConfig()->get( 'KZChangeRequestJiraServiceDeskApi' );

		// Validate Jira config
		if ( empty( $config ) || empty( $config['user'] ) || empty( $config['password'] )
			|| empty( $config['serviceDeskId'] ) || empty( $config['requestTypeId'] )
		) {
			// Log only which fields are present/absent — never the secret
			// values themselves. The KZChangeRequest log channel is a
			// bind-mounted file on staging/prod, so dumping the service-desk
			// password here would leak a live credential to anyone with log
			// access.
			$this->logger->error(
				"Missing Jira configuration: "
				. "user={user}, password={password}, serviceDeskId={serviceDeskId}, requestTypeId={requestTypeId}",
				[
					'user' => empty( $config['user'] ) ? 'unset' : 'set',
					'password' => empty( $config['password'] ) ? 'unset' : 'set',
					'serviceDeskId' => empty( $config['serviceDeskId'] ) ? 'unset' : 'set',
					'requestTypeId' => empty( $config['requestTypeId'] ) ? 'unset' : 'set'
				]
			);
			throw new RuntimeException( 'Invalid Jira configuration' );
		}

		// Only trust the contact email once it validates. The same validated
		// value is used both for the customer lookup and for the Jira field
		// below — an address that fails validation is dropped, not stored.
		$customerId = null;
		$rawEmail = $params['contactEmail'] ?? '';
		$email = ( !empty( $rawEmail ) && Sanitizer::validateEmail( $rawEmail ) ) ? $rawEmail : '';
		if ( $email !== '' ) {
			$customerId = $this->jiraGetCustomer( $email, $config );
		}

		// Get page categories
		$pageCategories = [];
		foreach ( $page->getCategories() as $category ) {
			$pageCategories[] = $category->getDBkey();
		}

		// Get page translations
		$translations = $this->getTranslationLanguages( $page->getId() );

		// Prepare ticket fields
		$fields = [
			'summary' => $page->getTitle()->getText(),
			'description' => $params['request'],
			// "Language"
			'customfield_10305' => [ 'value' => $this->getContentLanguageName() ],
			// "Page Title"
			'customfield_10201' => $page->getTitle()->getText(),
			// "Contact Name"
			'customfield_10202' => $params['contactName'] ?? '',
			// "Contact Email"
			'customfield_10203' => $email,
			// "wikipage_categories"
			'customfield_10800' => $pageCategories,
			// "article_translated_to"
			'customfield_11711' => $translations
		];

		// Add content area if extension is loaded
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$contentArea = \MediaWiki\Extension\ArticleContentArea\ArticleContentArea::getArticleContentArea(
				$page->getTitle()
			);
			if ( $contentArea ) {
				// "content_area"
				$fields['customfield_11691'] = $contentArea;
			}
		}

		// Add short link if format is configured
		$linkFormat = $config['shortLinkFormat'] ?? '';
		if ( !empty( $linkFormat ) ) {
			$link = str_replace(
				[ '$articleId', '$lang' ],
				[ $page->getId(), $this->getContentLanguageCode() ],
				$linkFormat
			);
			// "Link"
			$fields['customfield_11689'] = $link;
		}

		// Create ticket data
		$issueData = [
			'serviceDeskId' => $config['serviceDeskId'],
			'requestTypeId' => $config['requestTypeId'],
			'raiseOnBehalfOf' => $customerId,
			'requestFieldValues' => $fields
		];

		// Submit to Jira
		$response = $this->jiraOpenTicket( $issueData, $config );
		if ( !$response ) {
			throw new RuntimeException( 'Failed to create Jira ticket' );
		}

		return true;
	}

	/**
	 * Query existing Jira customer with the given email
	 * @param string $email End user email address
	 * @param array $jiraConfig
	 * @return string|null Customer ID if found
	 */
	private function jiraGetCustomer( string $email, array $jiraConfig ): ?string {
		$calloutUrl = $jiraConfig['server']
			. "/rest/servicedeskapi/servicedesk/projectKey:{$jiraConfig['project']}/customer";
		$queryData = [
			'limit' => '1',
			'query' => $email,
		];

		$url = wfAppendQuery( $calloutUrl, $queryData );
		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [
				'username' => $jiraConfig['user'],
				'password' => $jiraConfig['password'],
				'timeout' => 10,
				'connectTimeout' => 5,
			] );

		$httpRequest->setHeader( 'Accept', 'application/json' );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );
		$httpRequest->setHeader( 'X-ExperimentalApi', 'opt-in' );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Jira customer query callout failed with message: {errorMsg}",
					[ 'errorMsg' => $this->formatStatus( $status ) ]
				);
				return null;
			}

			$response = FormatJson::decode( $httpRequest->getContent(), true );
			if ( !$response ) {
				$this->logger->error( "Failed to parse Jira customer query response" );
				return null;
			}

			return ( $response['size'] === 0 ) ? null : $response['values'][0]['accountId'];

		} catch ( Exception $e ) {
			$this->logger->error(
				"Jira customer query threw exception: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return null;
		}
	}

	/**
	 * Format an HTTP-request Status into plain text for logging.
	 *
	 * MWHttpRequest::execute() returns a Status; reading it via the deprecated
	 * Status::getMessage()->toString() breaks under MediaWiki 1.42+ (getMessage
	 * is deprecated in favour of StatusFormatter, and Message::toString() now
	 * requires a format argument). Use the StatusFormatter service instead.
	 *
	 * @param \StatusValue $status
	 * @return string
	 */
	private function formatStatus( $status ): string {
		return MediaWikiServices::getInstance()->getFormatterFactory()
			->getStatusFormatter( $this )->getWikiText( $status );
	}

	/**
	 * Open new Jira Service Desk ticket
	 * @param array $issueData Issue parameters to send to Jira
	 * @param array $jiraConfig
	 * @return array|false Decoded JSON response from Jira on success, FALSE on failure
	 */
	private function jiraOpenTicket( array $issueData, array $jiraConfig ) {
		$calloutUrl = $jiraConfig['server'] . '/rest/servicedeskapi/request';
		$postJson = FormatJson::encode( $issueData );

		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $calloutUrl, [
				'method' => 'POST',
				'postData' => $postJson,
				'username' => $jiraConfig['user'],
				'password' => $jiraConfig['password'],
				'timeout' => 15,
				'connectTimeout' => 5,
			] );

		$httpRequest->setHeader( 'Accept', 'application/json' );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Jira ticket creation failed with message: {errorMsg}, issueData={issueData}",
					[ 'errorMsg' => $this->formatStatus( $status ), 'issueData' => $postJson ]
				);
				return false;
			}

			$response = FormatJson::decode( $httpRequest->getContent(), true );
			if ( !$response ) {
				$this->logger->error(
					"Failed to parse Jira ticket creation response: {json}",
					[ 'json' => $httpRequest->getContent() ]
				);
				return false;
			}

			return $response;

		} catch ( Exception $e ) {
			$this->logger->error(
				"Jira ticket creation threw exception: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return false;
		}
	}

	/**
	 * Validate a Cloudflare Turnstile token against the siteverify endpoint.
	 *
	 * Replaces the old reCAPTCHA v3 check. Turnstile is pass/fail (no score — the
	 * v3 score was fetched but never thresholded), and echoes the widget's cData
	 * and hostname, which we validate: cData is how this service is told apart
	 * from the other consumers of the one shared platform widget.
	 *
	 * @param string $response Turnstile response token from the client widget
	 * @return bool True if the token is valid for this service, false otherwise
	 */
	private function validateTurnstile( string $response ): bool {
		// Get configuration
		$config = $this->getConfig();
		$secret = $config->get( 'KZChangeRequestTurnstileSecretKey' );
		if ( empty( $secret ) ) {
			$this->logger->warning( "Missing KZChangeRequestTurnstileSecretKey configuration" );
			return false;
		}

		$data = [
			'response' => $response,
			'secret' => $secret,
			'remoteip' => $this->getRequest()->getIP(),
		];

		$url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [
				'method' => 'POST',
				'postData' => $data,
				'timeout' => 10,
				'connectTimeout' => 5,
			] );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Turnstile validation failed with message: {errorMsg}",
					[ 'errorMsg' => $this->formatStatus( $status ) ]
				);
				return false;
			}

			$json = $httpRequest->getContent();
			$result = FormatJson::decode( $json, true );

			if ( !$result ) {
				$this->logger->error( "Failed to parse Turnstile response" );
				return false;
			}

			if ( empty( $result['success'] ) ) {
				$this->logger->error(
					"Turnstile validation failed with error: {errorMsg}",
					[ 'errorMsg' => implode( ',', (array)( $result['error-codes'] ?? [] ) ) ]
				);
				return false;
			}

			// Confirm the token was minted for THIS service, not another consumer
			// of the shared platform widget.
			if ( ( $result['cdata'] ?? '' ) !== 'kzchangerequest' ) {
				$this->logger->error(
					"Turnstile cData mismatch: {cdata}",
					[ 'cdata' => $result['cdata'] ?? '(none)' ]
				);
				return false;
			}

			// Defence-in-depth on top of the widget's allowed-hostnames list: the
			// token must have been solved on this wiki's own host. Only enforced
			// when siteverify reports a hostname (the always-pass test keys omit it).
			$expectedHost = parse_url( (string)$config->get( 'Server' ), PHP_URL_HOST );
			$returnedHost = $result['hostname'] ?? '';
			if ( $expectedHost && $returnedHost && $returnedHost !== $expectedHost ) {
				$this->logger->error(
					"Turnstile hostname mismatch: {got} != {expected}",
					[ 'got' => $returnedHost, 'expected' => $expectedHost ]
				);
				return false;
			}

			return true;

		} catch ( Exception $e ) {
			$this->logger->error(
				"Turnstile validation threw exception: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return false;
		}
	}

		/**
		 * Get translation languages for Jira fields
		 * @param int $articleId
		 * @return array
		 */
	private function getTranslationLanguages( int $articleId ): array {
		$langLinks = $this->getPageLanguageLinks( $articleId );

		// To update a multi-select field by value and not id, we have to pass an
		// object with specific 'value' => $value
		$translations = [];
		foreach ( $langLinks as $key => $val ) {
			$translations[] = [ 'value' => $key ];
		}

		return $translations;
	}

		/**
		 * Get existing interlanguage links
		 * @param int $articleId
		 * @return array
		 */
	private function getPageLanguageLinks( int $articleId ): array {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'll_lang', 'll_title' ] )
			->from( 'langlinks' )
			->where( [ 'll_from' => $articleId ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $res as $row ) {
			$links[$row->ll_lang] = $row->ll_title;
		}

		return $links;
	}

		/**
		 * Get content language code
		 * @return string
		 */
	private function getContentLanguageCode(): string {
		return MediaWikiServices::getInstance()->getContentLanguage()->getCode();
	}

		/**
		 * Get content language name in English
		 * @return string
		 */
	private function getContentLanguageName(): string {
		$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		return $languageNameUtils->getLanguageName( $this->getContentLanguageCode(), 'en' );
	}

	public function needsToken(): string {
		return 'csrf';
	}
}
