<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class SpecialKZChangeRequest extends UnlistedSpecialPage
{
  private \Psr\Log\LoggerInterface $logger;

  function __construct()
  {
    parent::__construct('KZChangeRequest');
    $this->logger = LoggerFactory::getInstance('KZChangeRequest');
  }

  /**
   * @inheritDoc
   */
  public function getDescription()
  {
    return $this->msg('kzchangerequest')->text();
  }

  /**
   * Special page: Kol-Zchut change request form
   */
  public function execute($par)
  {
    $request = $this->getRequest();
    $postValues = $request->getPostValues();
    $output = $this->getOutput();
    $this->setHeaders();

    // Load form structure
    $modal = !empty($request->getText('modal')) || !empty($postValues['wpkzcrModal']);
    $articleId = $postValues['wpkzcrArticleId'] ?? $request->getText('articleId');
    $page = $this->getPage($articleId ?? 0);
    $pageTitle = !empty($page) ? $page->getTitle()->getText() : 'unknown';
    $form = $this->getFormStructure($pageTitle);
    if (!empty($articleId)) {
      $form['kzcrArticleId']['default'] = $articleId;
    }
    if ($modal) {
      $form['kzcrModal'] = [
        'type' => 'hidden',
        'default' => '1',
      ];
    }

    // Include reCAPTCHA
    $config = $this->getConfig();
    $reCaptchaSitekey = $config->get('ReCaptchaV3SiteKey');
    if (empty($reCaptchaSitekey)) {
      // Log warning.
      $this->logger->warning("Missing ReCaptchaV3SiteKey configuration");
    } else {
      $output->addJsConfigVars(['reCaptchaV3SiteKey' => $reCaptchaSitekey]);
      $output->addScript(
        '<script src="https://www.google.com/recaptcha/api.js?render=' . $reCaptchaSitekey . '"></script>'
      );
      $output->addScript('<script>grecaptchaOnJs();</script>');
    }

    // ResourceLoader modules: load the form's JS and CSS
    $output->addModules('ext.KZChangeRequest');
    if ($modal) {
      $output->addModuleStyles('ext.KZChangeRequest.modal');
    }

    // Build the form
    $htmlForm = HTMLForm::factory('ooui', $form, $this->getContext());
    $htmlForm->setId("kzcrChangeRequestForm")
      ->setFormIdentifier('kzcrChangeRequestForm')
      ->setSubmitID("kzcrButton")
      ->setSubmitTextMsg('kzchangerequest-submit')
      ->setSubmitCallback([$this, 'handleSubmit'])
      ->show();
  }

  /**
   * Handle form submission
   */
  public function handleSubmit($postData)
  {
    // Verify valid article and get categories.
    $page = $this->getPage($postData['kzcrArticleId']);
    if (!$page)
      return $this->msg('kzchangerequest-submission-error')->text();
    $pageTitle = $page->getTitle()->getText();
    $pageCategories = [];
    foreach ($page->getCategories() as $category) {
      $pageCategories[] = $category->getText();
    }

    // Get reCAPTCHA v3 score.
    $recaptchaScore = $this->validateRecaptcha();
    if ($recaptchaScore === false)
      $recaptchaScore = -1; // Fail silently if something's wrong with reCAPTCHA.

    // Find or create Jira Service Desk "customer" for the current user.
    $config = $this->getConfig();
    $jiraConfig = $config->get('JiraServiceDeskApi');
    $serviceDeskId = $jiraConfig['serviceDeskId'];
    $requestTypeId = $jiraConfig['requestTypeId'];
    if (empty($jiraConfig) || empty($jiraConfig['user']) || empty($jiraConfig['password']) || empty($jiraConfig['serviceDeskId']) || empty($jiraConfig['requestTypeId'])) {
      $this->logger->error(
        "Missing Jira configuration: user={user}, password={password}, serviceDeskId={serviceDeskId}, requestTypeId={requestTypeId}",
        ['user' => $jiraConfig['user'] ?? '', 'password' => $jiraConfig['password'] ?? '', 'serviceDeskId' => $jiraConfig['serviceDeskId'] ?? '', 'requestTypeId' => $jiraConfig['requestTypeId'] ?? '']
      );
      return $this->msg('kzchangerequest-submission-error')->text();
    }
    $customerId = $this->jiraGetCustomer($postData['kzcrContactEmail'], $jiraConfig);
    if ($customerId === false)
      return $this->msg('kzchangerequest-submission-error')->text();

    // Open Jira Service Desk ticket.
    $language = $this->getLanguage();
    $languageName = $this->getLanguageName($language->mCode);
    $issueData = [
      'serviceDeskId' => $serviceDeskId,
      'requestTypeId' => $requestTypeId,
      'raiseOnBehalfOf' => $customerId ?? null,
      'requestFieldValues' => [
        'summary' => $pageTitle,
        'description' => $postData['kzcrRequest'],
        'customfield_10305' => ['value' => $languageName], // "Language"
        'customfield_10201' => $pageTitle, // "Page Title"
        'customfield_10202' => $postData['kzcrContactName'], // "Contact Name"
        'customfield_10203' => $postData['kzcrContactEmail'], // "Contact Email"
        'customfield_11691' => '', // "content_area" @TODO: is this deprecated?
        'customfield_10800' => $pageCategories, // "wikipage_categories"
        'customfield_11714' => ($recaptchaScore === false) ? -1 : $recaptchaScore, // "ReCAPTCHA Score"
      ],
    ];
    $linkFormat = $jiraConfig['shortLinkFormat'];
    if (!empty($linkFormat) && !empty($postData['kzcrArticleId'])) {
      $link = str_replace(['$articleId', '$lang'], [$postData['kzcrArticleId'], $language->mCode], $linkFormat);
      $issueData['requestFieldValues']['customfield_11689'] = $link; // "Link"
    }
    $success = $this->jiraOpenTicket($issueData, $jiraConfig);
    if (!$success)
      return $this->msg('kzchangerequest-submission-error')->text();

    // Post-submission confirmation.
    $output = $this->getOutput();
    $output->addHTML("<p class='kzcr-confirmation'>" . $this->msg('kzchangerequest-confirmation-message')->text() . "</p>");
    return true;
  }

  /**
   * Utility method to load page info, with error logging.
   * 
   * @return WikiPage|false
   */
  private function getPage($articleId)
  {
    $page = WikiPage::newFromID($articleId);
    if (!$page) {
      $this->logger->alert(
        "Failed to find page corresponding to articleId {articleId}",
        ['articleId' => $articleId]
      );
      return false;
    }
    return $page;
  }

  /**
   * Define form structure
   */
  private function getFormStructure($pageTitle = '')
  {
    return array(
      'kzcrIntro' => [
        'type' => 'info',
        'cssclass' => 'kzcr-intro',
        'default' => '<h4>' . $this->msg('kzchangerequest-intro-1')->text() . '</h4>'
          . '<p>' . $this->msg('kzchangerequest-intro-2')->text() . '</p>',
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
        'default' => '',  // This should be set by execute() according to the query parameter.
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
        'default' => '<h4>' . $this->msg('kzchangerequest-contact-intro-1')->text() . '</h4>'
          . '<p>' . $this->msg('kzchangerequest-contact-intro-2')->text() . '</p>',
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
        'required' => true,
      ],
      'kzcrNotice' => [
        'type' => 'info',
        'cssclass' => 'kzcr-notice',
        'default' => '<p>' . $this->msg('kzchangerequest-notice')->text() . '</p>',
        'raw' => true,
      ],
    );
  }

  /**
   * Utility to return English-language names corresponding to select language codes.
   */
  private function getLanguageName($mCode)
  {
    switch ($mCode) {
      case 'ar':
        return 'Arabic';
      case 'en':
        return 'English';
      case 'he':
        return 'Hebrew';
      case 'ru':
        return 'Russian';
      default:
        return 'Other';
    }
  }

  /**
   * reCAPTCHA validation
   */
  private function validateRecaptcha()
  {
    // Get configuration.
    $config = $this->getConfig();
    $secret = $config->get('RecaptchaV3Secret');
    if (empty($secret)) {
      // Log warning.
      $this->logger->warning("Missing RecaptchaV3Secret configuration");
      return false;
    }

    // Get response token from POST data.
    // Note this is added to the submission directly by reCAPTCHA js and is not processed by mediawiki as part of the HTMLForm.
    if (empty($_POST['g-recaptcha-response'])) {
      $this->logger->error("ReCAPTCHA didn't return response from client side");
      return false;
    }

    // Callout to reCAPTCHA v3 to validate response from the client side.
    $request = $this->getRequest();
    $data = [
      'response' => $_POST['g-recaptcha-response'],
      'secret' => $secret,
      'remoteip' => $request->getIP(),
    ];
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $url = wfAppendQuery($url, $data);
    $httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
      ->create($url, ['method' => 'POST'], __METHOD__);
    try {
      $status = $httpRequest->execute();
      if (!$status->isOK()) {
        $this->logger->error(
          "ReCAPTCHA validation callout failed with message: {errorMsg}",
          ['errorMsg' => $status->getMessage()->toString()]
        );
        return false;
      }
    } catch (Exception $e) {
      $this->logger->error(
        "ReCAPTCHA validation callout threw exception with message: {exceptionMsg}",
        ['exceptionMsg' => $e->getMessage()]
      );
      return false;
    }
    $json = $httpRequest->getContent();
    $response = FormatJson::decode($json, true);
    if (!$response) {
      $this->logger->error(
        "ReCAPTCHA validation failed to parse JSON: {json}",
        ['json' => $json]
      );
      return false;
    }
    if (isset($response['error-codes'])) {
      $this->logger->error(
        "ReCAPTCHA validation failed to parse JSON with error: {errorMsg}",
        ['errorMsg' => is_array($response['error-codes']) ? implode(',', $response['error-codes']) : $response['error-codes']]
      );
      return false;
    }

    // Success! Return the reCAPTHCA v3 score.
    return $response['score'];
  }

  /**
   * Callout to query preexisting Jira customer with the given email.
   */
  private function jiraGetCustomer($email, $jiraConfig)
  {
    if (empty($email)) return false;

    $calloutUrl = $jiraConfig['server'] . "/rest/servicedeskapi/servicedesk/projectKey:{$jiraConfig['project']}/customer";
    $queryData = [
      'limit' => '1',
      'query' => $email,
    ];
    $url = wfAppendQuery($calloutUrl, $queryData);
    $httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
      ->create($url, ['username' => $jiraConfig['user'], 'password' => $jiraConfig['password']], __METHOD__);
    $httpRequest->setHeader('Accept', 'application/json');
    $httpRequest->setHeader('Content-Type', 'application/json');
    $httpRequest->setHeader('X-ExperimentalApi', 'opt-in');
    try {
      $status = $httpRequest->execute();
      if (!$status->isOK()) {
        $this->logger->error(
          "Jira customer query callout failed with message: {errorMsg}, email={email}",
          ['errorMsg' => $status->getMessage()->toString(), 'email' => $email]
        );
        return false;
      }
    } catch (Exception $e) {
      $this->logger->error(
        "Jira customer query callout threw exception with message: {exceptionMsg}",
        ['exceptionMsg' => $e->getMessage()]
      );
      return false;
    }
    $json = $httpRequest->getContent();
    $response = FormatJson::decode($json, true);
    if (!$response) {
      $this->logger->error(
        "Jira customer query failed to parse JSON: {json}",
        ['json' => $json]
      );
      return false;
    }

    return ($response['size'] === 0) ? null : $response['values'][0]['accountId'];
  }

  /**
   * Callout to open new Jira Service Desk ticket.
   */
  private function jiraOpenTicket($issueData, $jiraConfig)
  {
    $calloutUrl = $jiraConfig['server'] . '/rest/servicedeskapi/request';
    $postJson = FormatJson::encode($issueData);
    $httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
      ->create($calloutUrl, ['method' => 'POST', 'postData' => $postJson, 'username' => $jiraConfig['user'], 'password' => $jiraConfig['password']], __METHOD__);
    $httpRequest->setHeader('Accept', 'application/json');
    $httpRequest->setHeader('Content-Type', 'application/json');
    try {
      $status = $httpRequest->execute();
      if (!$status->isOK()) {
        $this->logger->error(
          "Jira open ticket callout failed with message: {errorMsg}, issueData={issueData}",
          ['errorMsg' => $status->getMessage()->toString(), 'issueData' => json_encode($issueData)]
        );
        return false;
      }
    } catch (Exception $e) {
      $this->logger->error(
        "Jira open ticket callout threw exception with message: {exceptionMsg}",
        ['exceptionMsg' => $e->getMessage()]
      );
      return false;
    }
    $json = $httpRequest->getContent();
    $response = FormatJson::decode($json, true);
    if (!$response) {
      $this->logger->error(
        "Jira open ticket callout failed to parse JSON: {json}",
        ['json' => $json]
      );
      return false;
    }
    return $response;
  }
}
