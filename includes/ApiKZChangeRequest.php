<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChangeRequest extends ApiBase {
	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/** @inheritDoc */
	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
		$this->logger = LoggerFactory::getInstance( 'KZChangeRequest' );
	}

	/** @inheritDoc */
	public function execute() {
		// $this->dieWithError( 'just because');
		// Validate request
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'request' );

		// Validate reCAPTCHA
		$recaptchaScore = $this->validateRecaptcha( $params['g-recaptcha-response'] );
		if ( $recaptchaScore === false ) {
			$this->dieWithError( 'kzchangerequest-captcha-fail' );
		}

		// Get page info
		$page = WikiPage::newFromID( $params['articleId'] );
		if ( !$page ) {
			$this->dieWithError( 'kzchangerequest-invalid-page' );
		}

		try {
			// Create Jira ticket
			$this->createJiraTicket( $params, $page, $recaptchaScore );

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
	protected function getAllowedParams() {
		return [
			'articleId' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'request' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'contactName' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'contactEmail' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'g-recaptcha-response' => [
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
	private function createJiraTicket( array $params, WikiPage $page ) {
		$config = $this->getConfig()->get( 'KZChangeRequestJiraServiceDeskApi' );

		// Validate Jira config
		if ( empty( $config ) || empty( $config['user'] ) || empty( $config['password'] )
			|| empty( $config['serviceDeskId'] ) || empty( $config['requestTypeId'] )
		) {
			$this->logger->error(
				"Missing Jira configuration: "
				. "user={user}, password={password}, serviceDeskId={serviceDeskId}, requestTypeId={requestTypeId}",
				[
					'user' => $config['user'] ?? '',
					'password' => $config['password'] ?? '',
					'serviceDeskId' => $config['serviceDeskId'] ?? '',
					'requestTypeId' => $config['requestTypeId'] ?? ''
				]
			);
			throw new RuntimeException( 'Invalid Jira configuration' );
		}

		// Check for existing customer if email provided
		$customerId = null;
		$email = $params['contactEmail'] ?? '';
		if ( !empty( $email ) && \Sanitizer::validateEmail( $email ) ) {
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
	private function jiraGetCustomer( string $email, array $jiraConfig ) {
		$calloutUrl = $jiraConfig['server']
			. "/rest/servicedeskapi/servicedesk/projectKey:{$jiraConfig['project']}/customer";
		$queryData = [
			'limit' => '1',
			'query' => $email,
		];

		$url = wfAppendQuery( $calloutUrl, $queryData );
		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [ 'username' => $jiraConfig['user'], 'password' => $jiraConfig['password'] ] );

		$httpRequest->setHeader( 'Accept', 'application/json' );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );
		$httpRequest->setHeader( 'X-ExperimentalApi', 'opt-in' );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Jira customer query callout failed with message: {errorMsg}, email={email}",
					[ 'errorMsg' => $status->getMessage()->toString(), 'email' => $email ]
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
				'password' => $jiraConfig['password']
			] );

		$httpRequest->setHeader( 'Accept', 'application/json' );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Jira ticket creation failed with message: {errorMsg}, issueData={issueData}",
					[ 'errorMsg' => $status->getMessage()->toString(), 'issueData' => $postJson ]
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
	 * Validate reCAPTCHA response
	 * @param string $response reCAPTCHA response token
	 * @return float|bool Score on success, false on failure
	 */
	private function validateRecaptcha( $response ) {
		// Get configuration
		$config = $this->getConfig();
		$secret = $config->get( 'KZChangeRequestRecaptchaV3Secret' );
		if ( empty( $secret ) ) {
			$this->logger->warning( "Missing KZChangeRequestRecaptchaV3Secret configuration" );
			return false;
		}

		$data = [
			'response' => $response,
			'secret' => $secret,
			'remoteip' => $this->getRequest()->getIP(),
		];

		$url = 'https://www.google.com/recaptcha/api/siteverify';
		$url = wfAppendQuery( $url, $data );

		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [ 'method' => 'POST' ] );

		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"ReCAPTCHA validation failed with message: {errorMsg}",
					[ 'errorMsg' => $status->getMessage()->toString() ]
				);
				return false;
			}

			$json = $httpRequest->getContent();
			$response = FormatJson::decode( $json, true );

			if ( !$response ) {
				$this->logger->error( "Failed to parse reCAPTCHA response" );
				return false;
			}

			if ( isset( $response['error-codes'] ) ) {
				$this->logger->error(
					"ReCAPTCHA validation failed with error: {errorMsg}",
					[ 'errorMsg' => implode( ',', (array)$response['error-codes'] ) ]
				);
				return false;
			}

			return $response['score'];

		} catch ( Exception $e ) {
			$this->logger->error(
				"ReCAPTCHA validation threw exception: {exceptionMsg}",
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
			$dbr = wfGetDB( DB_REPLICA );
			$res = $dbr->select(
				'langlinks',
				[ 'll_lang', 'll_title' ],
				[ 'll_from' => $articleId ],
				__METHOD__
			);

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

		public function needsToken() {
			return 'csrf';
		}
}
