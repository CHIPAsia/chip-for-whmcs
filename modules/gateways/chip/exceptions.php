<?php

declare(strict_types=1);

class ChipAPIException extends Exception
{
  protected $response;

  public function __construct($message, $code = 0, $response = null, Throwable $previous = null)
  {
    $this->response = $response;
    parent::__construct($message, $code, $previous);
  }

  public function getResponse()
  {
    return $this->response;
  }
}
