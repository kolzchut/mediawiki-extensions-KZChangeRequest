<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

class SpecialKZChangeRequest extends \UnlistedSpecialPage {
	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/**
	 * @var bool Expose success state
	 */
  public bool $submissionSuccessful = false;

	public function __construct() {
		parent::__construct( 'KZChangeRequest' );
		$this->logger = LoggerFactory::getInstance( 'KZChangeRequest' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kzchangerequest' )->text();
	}

	/**
	 * Special page: Kol-Zchut change request form
	 * @param string|null $par Parameters passed to the page
	 */
	public function execute( $par ) {
		$request = $this->getRequest();
		$postValues = $request->getPostValues();
		$output = $this->getOutput();
		$this->setHeaders();

		// Load form structure
		$articleId = $postValues['wpkzcrArticleId'] ?? $request->getText( 'articleId' );
		$page = $this->getPage( $articleId ?? 0 );
		$pageTitleText = !empty( $page ) ? $page->getTitle()->getText()
			: $this->msg( 'kzchangerequest-pageunknown' )->text();
		$form = $this->getFormStructure( $pageTitleText );
		if ( !empty( $articleId ) ) {
			$form['kzcrArticleId']['default'] = $articleId;
		}

		// Include reCAPTCHA
		$config = $this->getConfig();
		$reCaptchaSitekey = $config->get( 'KZChangeRequestReCaptchaV3SiteKey' );
		if ( empty( $reCaptchaSitekey ) ) {
			// Log warning
			$this->logger->warning( "Missing KZChangeRequestReCaptchaV3SiteKey configuration" );
		} else {
			$output->addJsConfigVars( [
				'KZChangeRequestReCaptchaV3SiteKey' => $reCaptchaSitekey,
			] );
			$output->addScript(
				'<script src="https://www.google.com/recaptcha/api.js?render=' . $reCaptchaSitekey . '"></script>'
			);
			$output->addScript(
				'<script>if (window.grecaptchaOnJs !== undefined) { window.grecaptchaOnJs(); }'
				. ' else { window.grecaptchaOnJsReady=true; }</script>'
			);
		}

		// ResourceLoader modules: load the form's JS and CSS
		$output->addModules( 'ext.KZChangeRequest.form' );

		// Build the form
		$htmlForm = \HTMLForm::factory( 'ooui', $form, $this->getContext() );
		$htmlForm->setId( "kzcrChangeRequestForm" )
			->setFormIdentifier( 'kzcrChangeRequestForm' )
			->setSubmitID( "kzcrButton" )
			->setSubmitName( "kzcrSubmit" )
			->setSubmitTextMsg( 'kzchangerequest-submit' )
			->setSubmitCallback( [ $this, 'handleSubmit' ] )
			->show();
	}

	/**
	 * Handle form submission
	 * @param array $postData Form submission data
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleSubmit( $postData ) {
		// Verify valid article and get categories
		$page = $this->getPage( $postData['kzcrArticleId'] );
		if ( !$page ) {
			return $this->msg( 'kzchangerequest-submission-error' )->escaped();
		}
		$pageTitle = $page->getTitle()->getText();
		$pageCategories = [];
		// use getDBkey(), because Jira labels can't contain spaces
		foreach ( $page->getCategories() as $category ) {
			$pageCategories[] = $category->getDBkey();
		}

		// Get reCAPTCHA v3 score
		$recaptchaScore = $this->validateRecaptcha();
		if ( $recaptchaScore === false ) {
			// Fail silently if something's wrong with reCAPTCHA
			$recaptchaScore = -1;
		}

		// Find or create Jira Service Desk "customer" for the current user
		$config = $this->getConfig();
		$jiraConfig = $config->get( 'KZChangeRequestJiraServiceDeskApi' );
		$serviceDeskId = $jiraConfig['serviceDeskId'];
		$requestTypeId = $jiraConfig['requestTypeId'];
		if ( empty( $jiraConfig ) || empty( $jiraConfig['user'] ) || empty( $jiraConfig['password'] )
			|| empty( $jiraConfig['serviceDeskId'] ) || empty( $jiraConfig['requestTypeId'] )
		) {
			$this->logger->error(
				"Missing Jira configuration: "
				. "user={user}, password={password}, serviceDeskId={serviceDeskId}, requestTypeId={requestTypeId}",
				[
					'user' => $jiraConfig['user'] ?? '',
					'password' => $jiraConfig['password'] ?? '',
					'serviceDeskId' => $jiraConfig['serviceDeskId'] ?? '',
					'requestTypeId' => $jiraConfig['requestTypeId'] ?? ''
				]
			);
			return $this->msg( 'kzchangerequest-submission-error' )->escaped();
		}
		// Check for an existing customer ID.
		// We can't create new customers from here - it requires extensive permissions, so we do
		// that with a Jira automation. However, the automation fails if we pass it the email of an
		// existing company.
		// Therefore, we check if the customer already exists and set the customer ID in the request
		$email = $postData['kzcrContactEmail'];
		if ( !empty( $email ) && \Sanitizer::validateEmail( $email ) ) {
			$customerId = $this->jiraGetCustomer( $postData[ 'kzcrContactEmail' ], $jiraConfig );
		} else {
			$email = '';
		}
		// Open Jira Service Desk ticket
		$language = $this->getLanguage();

		$fields = [
			'summary' => $pageTitle,
			'description' => $postData['kzcrRequest'],
			// "Language"
			'customfield_10305' => [ 'value' => self::getContentLanguageName() ],
			// "Page Title"
			'customfield_10201' => $pageTitle,
			// "Contact Name"
			'customfield_10202' => $postData['kzcrContactName'],
			// "Contact Email"
			'customfield_10203' => $email,
			// "wikipage_categories"
			'customfield_10800' => $pageCategories,
			// "article_translated_to"
			'customfield_11711' => $this->getTranslationLanguagesForJira( $page->getId() ),

			// "ReCAPTCHA Score"
			'customfield_11714' => ( $recaptchaScore === false ) ? -1 : $recaptchaScore,
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$contentArea = \MediaWiki\Extension\ArticleContentArea\ArticleContentArea::getArticleContentArea(
				$page->getTitle()
			);
			// customfield_11691 "content_area"
			$fields['customfield_11691'] = $contentArea;
		}

		$linkFormat = $jiraConfig['shortLinkFormat'];
		if ( !empty( $linkFormat ) && !empty( $postData['kzcrArticleId'] ) ) {
			$link = str_replace(
				[ '$articleId', '$lang' ],
				[ $postData['kzcrArticleId'], self::getContentLanguageCode() ],
				$linkFormat
			);
			// "Link"
			$fields['customfield_11689'] = $link;
		}

		$issueData = [
			'serviceDeskId' => $serviceDeskId,
			'requestTypeId' => $requestTypeId,
			'raiseOnBehalfOf' => $customerId ?? null,
			'requestFieldValues' => $fields
		];

		$success = $this->jiraOpenTicket( $issueData, $jiraConfig );
		if ( !$success ) {
			return $this->msg( 'kzchangerequest-submission-error' )->escaped();
		}

		// Post-submission confirmation
		$this->submissionSuccessful = true;
		$output = $this->getOutput();
		$output->addHTML( Html::element( 'p',
			[ 'class' => 'kzcr-confirmation' ],
			$this->msg( 'kzchangerequest-confirmation-message' )->text()
		) );
		return true;
	}

	/**
	 * @param int $articleId
	 *
	 * @return array
	 */
	private function getTranslationLanguagesForJira( int $articleId ): array {
		$langLinks = $this->getPageLankLinks( $articleId );
		// To update a multi-select field by value and not id, we have to pass an
		// object with specific 'value' => $value
		$translations = [];
		foreach ( $langLinks as $key => $val ) {
			$translations[] = [ 'value' => $key ];
		}

		return $translations;
	}

	/**
	 * @return string
	 */
	private static function getContentLanguageCode(): string {
		return MediaWikiServices::getInstance()->getContentLanguage()->getCode();
	}

	/**
	 * @return string
	 */
	private static function getContentLanguageName(): string {
		$languageNameUtils = MediaWikiServices::getInstance()->getLanguageNameUtils();
		return $languageNameUtils->getLanguageName( self::getContentLanguageCode(), 'en' );
	}

	/**
	 * Get an array of existing interlanguage links, with the language code in the key and the
	 * title in the value.
	 *
	 * Taken from Core's LinksUpdate::getExistingInterlangs() [includes/deferred/LinksUpdate.php]
	 *
	 * @param int $articleId
	 *
	 * @return array
	 */
	private function getPageLankLinks( int $articleId ): array {
		if ( isset( $this->langLinks ) ) {
			return $this->langLinks;
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'langlinks', [ 'll_lang', 'll_title' ],
			[ 'll_from' => $articleId ], __METHOD__
		);
		$arr = [];
		foreach ( $res as $row ) {
			$arr[$row->ll_lang] = $row->ll_title;
		}

		$this->langLinks = $arr;
		return $arr;
	}

	/**
	 * Utility method to load page info, with error logging.
	 * @param string|int $articleId Article ID for the relevant wiki page
	 * @return WikiPage|false
	 */
	private function getPage( $articleId ) {
		$page = WikiPage::newFromID( $articleId );
		if ( !$page ) {
			$this->logger->alert(
				"Failed to find page corresponding to articleId {articleId}",
				[ 'articleId' => $articleId ]
			);
			return false;
		}
		return $page;
	}

	/**
	 * Define form structure
	 * @param string|null $pageTitle Article title of the relevant wiki page
	 * @return array
	 */
	private function getFormStructure( $pageTitle = '' ): array {
		return [
			'kzcrIntro' => [
				'type' => 'info',
				'cssclass' => 'kzcr-intro',
				'default' => Html::element( 'h4', [], $this->msg( 'kzchangerequest-intro-1' )->text() )
					. Html::element( 'p', [], $this->msg( 'kzchangerequest-intro-2' )->text() ),
				'raw' => true,
			],
			'kzcrPageTitleInfo' => [
				'type' => 'info',
				'label-message' => 'kzchangerequest-relevantpage',
				'default' => $pageTitle,
				'raw' => true,
			],
			'kzcrArticleId' => [
				'type' => 'hidden',
				// This should be set by execute() according to the query parameter
				'default' => '',
			],
			'kzcrRequest' => [
				'type' => 'textarea',
				'label-message' => 'kzchangerequest-request',
				'required' => true,
				'default' => '',
				'rows' => 4,
			],
			'kzcrContactIntro' => [
				'type' => 'info',
				'cssclass' => 'kzcr-contact-intro',
				'default' => Html::element( 'h4', [], $this->msg( 'kzchangerequest-contact-intro-1' )->text() )
					. Html::element( 'p', [], $this->msg( 'kzchangerequest-contact-intro-2' )->text() ),
				'raw' => true,
			],
			'kzcrContactName' => [
				'type' => 'text',
				'cssclass' => 'kzcr-name',
				'label-message' => 'kzchangerequest-contact-name',
			],
			'kzcrContactEmail' => [
				'type' => 'email',
				'label-message' => 'kzchangerequest-contact-email',
				'cssclass' => 'kzcr-email',
				'required' => false,
			],
			'kzcrNotice' => [
				'type' => 'info',
				'cssclass' => 'kzcr-notice',
				'default' => Html::element( 'p', [], $this->msg( 'kzchangerequest-notice' )->text() ),
				'raw' => true,
			],
		];
	}

	/**
	 * reCAPTCHA validation
	 * @return int|bool Return ReCAPTCHA score on success, FALSE on failure
	 */
	private function validateRecaptcha() {
		// Get configuration
		$config = $this->getConfig();
		$secret = $config->get( 'KZChangeRequestRecaptchaV3Secret' );
		if ( empty( $secret ) ) {
			// Log warning
			$this->logger->warning( "Missing KZChangeRequestRecaptchaV3Secret configuration" );
			return false;
		}

		// Get response token from POST data
		// Note this is added to the submission directly by reCAPTCHA js and is not processed by
		// mediawiki as part of the HTMLForm
		$request = $this->getRequest();
		$recaptchaResponse = $request->getRawVal( 'g-recaptcha-response' );
		if ( empty( $recaptchaResponse ) ) {
			$this->logger->error( "ReCAPTCHA didn't return response from client side" );
			return false;
		}

		// Callout to reCAPTCHA v3 to validate response from the client side
		$data = [
			'response' => $recaptchaResponse,
			'secret' => $secret,
			'remoteip' => $request->getIP(),
		];
		$url = 'https://www.google.com/recaptcha/api/siteverify';
		$url = wfAppendQuery( $url, $data );
		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [ 'method' => 'POST' ] );
		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"ReCAPTCHA validation callout failed with message: {errorMsg}",
					[ 'errorMsg' => $status->getMessage()->toString() ]
				);
				return false;
			}
		} catch ( Exception $e ) {
			$this->logger->error(
				"ReCAPTCHA validation callout threw exception with message: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return false;
		}
		$json = $httpRequest->getContent();
		$response = FormatJson::decode( $json, true );
		if ( !$response ) {
			$this->logger->error(
				"ReCAPTCHA validation failed to parse JSON: {json}",
				[ 'json' => $json ]
			);
			return false;
		}
		if ( isset( $response['error-codes'] ) ) {
			$this->logger->error(
				"ReCAPTCHA validation failed to parse JSON with error: {errorMsg}",
				[ 'errorMsg' => is_array( $response['error-codes'] )
					? implode( ',', $response['error-codes'] ) : $response['error-codes']
				]
			);
			return false;
		}

		// Success! Return the reCAPTHCA v3 score
		return $response['score'];
	}

	/**
	 * Callout to query preexisting Jira customer with the given email.
	 * @param string $email End user email address
	 * @param array $jiraConfig
	 * @return bool|string Return Jira Customer ID on success, FALSE on failure
	 */
	private function jiraGetCustomer( $email, $jiraConfig ) {
		if ( empty( $email ) ) {
			return false;
		}

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
				return false;
			}
		} catch ( Exception $e ) {
			$this->logger->error(
				"Jira customer query callout threw exception with message: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return false;
		}
		$json = $httpRequest->getContent();
		$response = FormatJson::decode( $json, true );
		if ( !$response ) {
			$this->logger->error(
				"Jira customer query failed to parse JSON: {json}",
				[ 'json' => $json ]
			);
			return false;
		}

		return ( $response['size'] === 0 ) ? null : $response['values'][0]['accountId'];
	}

	/**
	 * Callout to open new Jira Service Desk ticket.
	 * @param array $issueData Issue parameters to send to Jira
	 * @param array $jiraConfig
	 * @return mixed Return the JSON-decoded response from Jira on success, FALSE on failure
	 */
	private function jiraOpenTicket( $issueData, $jiraConfig ) {
		$calloutUrl = $jiraConfig['server'] . '/rest/servicedeskapi/request';
		$postJson = FormatJson::encode( $issueData );
		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $calloutUrl, [
				'method' => 'POST',
				'postData' => $postJson,
				'username' => $jiraConfig['user'],
				'password' => $jiraConfig['password']
			]
		);
		$httpRequest->setHeader( 'Accept', 'application/json' );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );
		try {
			$status = $httpRequest->execute();
			if ( !$status->isOK() ) {
				$this->logger->error(
					"Jira open ticket callout failed with message: {errorMsg}, issueData={issueData}",
					[ 'errorMsg' => $status->getMessage()->toString(), 'issueData' => json_encode( $issueData ) ]
				);
				return false;
			}
		} catch ( Exception $e ) {
			$this->logger->error(
				"Jira open ticket callout threw exception with message: {exceptionMsg}",
				[ 'exceptionMsg' => $e->getMessage() ]
			);
			return false;
		}
		$json = $httpRequest->getContent();
		$response = FormatJson::decode( $json, true );
		if ( !$response ) {
			$this->logger->error(
				"Jira open ticket callout failed to parse JSON: {json}",
				[ 'json' => $json ]
			);
			return false;
		}
		return $response;
	}
}
