<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;


class ApiKZChangeRequestLoad extends ApiBase
{
  protected function getAllowedParams()
  {
    return [
      'articleId' => '',
    ];
  }

  /**
   * Programmatically execute Special:KZChangeRequest page and return 
   * the data necessary to load it dynamically in a modal.
   */
  public function execute()
  {
    $logger = LoggerFactory::getInstance('KZChangeRequest');

    // Build the page as though the user had browsed to it by URL.
    $specialPageFactory = MediaWikiServices::getInstance()->getSpecialPageFactory();
    $page = $specialPageFactory->getPage('KZChangeRequest');
    $context = RequestContext::getMain();
    $output = new OutputPage($context);
    $context->setOutput($output);
    $page->setContext($context);
    $output->setTitle($page->getPageTitle());

    // Execute the page. Log and return error message if this fails.
    $result = $this->getResult();
    try {
      $page->execute('');
    } catch (Exception $e) {
      $logger->error(
        'Load API could not execute new KZChangeRequest form special page. Exception message: {msg}',
        ['msg' => $e->getMessage()]
      );
      $result->addValue(null, 'error', $this->msg('kzchangerequest-modal-load-error'));
      return;
    }

    // Return everything via JSON that our modal js needs to display the form dynamically.
    $result->addValue(null, 'title', $page->getDescription());
    $result->addValue(null, 'html', $output->getHTML());
    $result->addValue(null, 'config', $output->getJsConfigVars());
    $result->addValue(null, 'modules', $output->getModules() + [
      'mediawiki.widgets.styles', 'oojs-ui-core.icons', 'oojs-ui-core.styles',
      'oojs-ui.styles.indicators', 'mediawiki.htmlform.ooui.styles'
    ]);  //@TODO: is there a more elegant way to ensure all form-related modules are loaded without pulling in modules the form doesn't need?
    $result->addValue(null, 'bottomScripts', $output->getBottomScripts());
    $result->addValue(null, 'cancelMsg', $this->msg('kzchangerequest-cancel'));
  }
}
