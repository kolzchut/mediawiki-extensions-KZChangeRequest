<?php
class SpecialKZChangeRequest extends UnlistedSpecialPage
{
  function __construct()
  {
    parent::__construct('KZChangeRequest');
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
  function execute($par)
  {
    $request = $this->getRequest();
    $output = $this->getOutput();
    $this->setHeaders();

    // ResourceLoader modules: load the form's JS and CSS
    $output->addModules('ext.KZChangeRequest');
    if (!empty($request->getText('modal'))) {  // Use simple querystring switch for load-in-modal mode
      $output->addModuleStyles('ext.KZChangeRequest.modal');
    }

    // Build the form
    $form = $this->getFormStructure($request->getText('rpage'));
    $htmlForm = HTMLForm::factory('ooui', $form, $this->getContext());
    $htmlForm->setId("kzcrChangeRequestForm")
      ->setFormIdentifier('kzcrChangeRequestForm')
      ->setSubmitID("kzcrButton")
      ->setSubmitTextMsg('kzchangerequest-submit')
      ->show();
  }

  private function getFormStructure($relevantPage = '')
  {
    return array(
      'kzcrIntro' => array(
        'type' => 'info',
        'cssclass' => 'kzcr-intro',
        'default' => '<h4>' . $this->msg('kzchangerequest-intro-1')->text() . '</h4>'
          . '<p>' . $this->msg('kzchangerequest-intro-2')->text() . '</p>',
        'raw' => true,
      ),
      'kzcrRelevantPage' => array(
        'type' => 'info',
        'label-message' => 'kzchangerequest-relevantpage',
        'default' => $relevantPage,
        'raw' => true,
      ),
      'kzcrRequest' => array(
        'type' => 'textarea',
        'label-message' => 'kzchangerequest-request',
        'required' => true,
        'default' => '',
        'rows' => 4,
      ),
      'kzcrContactIntro' => array(
        'type' => 'info',
        'cssclass' => 'kzcr-contact-intro',
        'default' => '<h4>' . $this->msg('kzchangerequest-contact-intro-1')->text() . '</h4>'
          . '<p>' . $this->msg('kzchangerequest-contact-intro-2')->text() . '</p>',
        'raw' => true,
      ),
      'kzcrContactName' => array(
        'type' => 'text',
        'cssclass' => 'kzcr-name',
        'label-message' => 'kzchangerequest-contact-name',
      ),
      'kzcrContactEmail' => array(
        'type' => 'email',
        'label-message' => 'kzchangerequest-contact-email',
        'cssclass' => 'kzcr-email',
        'required' => true,
      ),
      'kzcrNotice' => array(
        'type' => 'info',
        'cssclass' => 'kzcr-notice',
        'default' => '<p>' . $this->msg('kzchangerequest-notice')->text() . '</p>',
        'raw' => true,
      ),
    );
  }
}
