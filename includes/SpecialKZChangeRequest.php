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
    $output = $this->getOutput();
    $this->setHeaders();

    //wfDebug('execute(): ' . print_r($request, true));

    // Load form structure
    $rpage = $request->getText('rpage') ?? 'unknown';
    $modal = !empty($request->getText('modal')) || !empty($request->getPostValues()['wpkzcrModal']);
    $form = $this->getFormStructure($rpage);
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
      $output->addHeadItem(
        'recaptchaV3',
        '<script src="https://www.google.com/recaptcha/api.js?onload=onLoadRecaptcha&render=' . $reCaptchaSitekey . '" async defer></script>'
      );
      $output->addJsConfigVars(['reCaptchaV3SiteKey' => $reCaptchaSitekey]);
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
    // Get reCAPTCHA v3 score.
    $recaptchaScore = $this->validateRecaptcha();

    // Open Jira ticket.
    //@TODO

    $output = $this->getOutput();
    $output->addHTML("<p class='kzcr-confirmation'>" . $this->msg('kzchangerequest-confirmation-message')->text() . "</p>");
    return true;
  }

  /**
   * Define form structure
   */
  private function getFormStructure($relevantPage = '')
  {
    return array(
      'kzcrIntro' => [
        'type' => 'info',
        'cssclass' => 'kzcr-intro',
        'default' => '<h4>' . $this->msg('kzchangerequest-intro-1')->text() . '</h4>'
          . '<p>' . $this->msg('kzchangerequest-intro-2')->text() . '</p>',
        'raw' => true,
      ],
      'kzcrRelevantPageInfo' => [
        'type' => 'info',
        'label-message' => 'kzchangerequest-relevantpage',
        'default' => $relevantPage,
        'raw' => true,
      ],
      'kzcrRelevantPage' => [
        'type' => 'hidden',
        'default' => $relevantPage,
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
   * reCAPTCHA validation
   */
  private function validateRecaptcha()
  {
    $request = $this->getRequest();

    // Get configuration.
    $config = $this->getConfig();
    $secret = $config->get('RecaptchaV3Secret');
    if (empty($secret)) {
      // Log warning.
      $this->logger->warning("Missing RecaptchaV3Secret configuration");
      return false;
    }

    // Get response token from POST submission.
    $postValues = $request->getPostValues();
    if (empty($postValues['g-recaptcha-response'])) {
      $this->logger->error("ReCAPTCHA didn't return response from client side");
      return false;
    }

    $url = 'https://www.google.com/recaptcha/api/siteverify';
    // Build data to append to request
    $data = [
      'response' => $postValues['g-recaptcha-response'],
      'secret' => $secret,
      'remoteip' => $request->getIP(),
    ];
    $url = wfAppendQuery($url, $data);
    $httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()
      ->create($url, ['method' => 'POST'], __METHOD__);
    $status = $httpRequest->execute();
    if (!$status->isOK()) {
      $this->logger->error(
        "ReCAPTCHA validation callout failed with message: {errorMsg}",
        ['errorMsg' => $status->getMessage()->toString()]
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
}
