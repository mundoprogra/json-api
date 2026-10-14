<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Exceptions;

  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;

  /**
   * Un ejemplo de excepción propia: define su error en un solo lugar.
   * An example of an own exception: it defines its error in one place.
   */
  class PaymentDeclined extends JsonApiException {
    public function __construct() {
      parent::__construct([
        Error::make(status: 402, title: 'Payment declined', detail: 'The card was declined.', code: 'PAYMENT-DECLINED'),
      ]);
    }
  }
