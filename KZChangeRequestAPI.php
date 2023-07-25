<?php
class KZChangeRequestAPI extends ApiBase
{
  public function execute()
  {
    $this->getResult()->addValue(null, $this->getModuleName(), "Hello World");
    //wfDebug(print_r($this->getResult(),true));
  }
}
