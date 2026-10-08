<?php

  namespace MundoProgra\JsonApi\Exceptions;

  use InvalidArgumentException;

  /**
   * Se lanza al arrancar cuando config/json-api.php tiene valores inválidos. Reúne todos los problemas encontrados.
   * Thrown at boot when config/json-api.php has invalid values. It gathers every problem found.
   */
  class InvalidConfigurationException extends InvalidArgumentException {
    /**
     * @param  list<string>  $errors
     */
    public function __construct(private readonly array $errors) {
      parent::__construct("Invalid json-api configuration:\n- " . implode("\n- ", $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array {
      return $this->errors;
    }
  }
