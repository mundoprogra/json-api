<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Exceptions;

  use RuntimeException;

  /**
   * Una excepción como la de un paquete externo: no se puede modificar, así que se convierte con map() de Laravel.
   * An exception like one from an external package: it cannot be modified, so it is converted with Laravel's map().
   */
  class ThirdPartyPaymentFailed extends RuntimeException {
  }
