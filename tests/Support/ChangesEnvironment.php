<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  /**
   * Permite definir variables de entorno dentro de un test y las elimina al terminar para que no se filtren a otros.
   * Lets a test define environment variables and removes them afterwards so they do not leak into other tests.
   */
  trait ChangesEnvironment {
    /**
     * @var list<string>
     */
    private array $touchedEnvironment = [];

    protected function setEnvironmentVariable(string $variable, string $value): void {
      $_ENV[$variable] = $value;
      $_SERVER[$variable] = $value;
      putenv("$variable=$value");

      $this->touchedEnvironment[] = $variable;
    }

    protected function tearDown(): void {
      foreach ($this->touchedEnvironment as $variable) {
        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);
      }

      parent::tearDown();
    }
  }
