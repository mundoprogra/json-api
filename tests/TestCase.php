<?php

  namespace MundoProgra\JsonApi\Tests;

  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use Orchestra\Testbench\TestCase as Orchestra;

  abstract class TestCase extends Orchestra {
    /**
     * La negociación de contenido corre sola en cada petición dentro del alcance. Las pruebas que no son de negociación la apagan,
     * para que ejerciten solo lo suyo con cualquier cabecera; las que sí lo son ponen esto en `true`.
     * Content negotiation runs on its own on every request inside the scope. The tests that are not about negotiation switch it off,
     * so they exercise only their own subject with any header; those that are set this to `true`.
     */
    protected bool $negotiates = false;

    protected function setUp(): void {
      parent::setUp();

      if (!$this->negotiates) {
        $this->withoutMiddleware(NegotiateContent::class);
      }
    }

    protected function getPackageProviders($app): array {
      return [
        JsonApiServiceProvider::class,
      ];
    }

    protected function defineEnvironment($app): void {
      $app['config']->set('database.default', 'testing');
    }
  }
