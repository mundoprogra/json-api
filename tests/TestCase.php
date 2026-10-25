<?php

  namespace MundoProgra\JsonApi\Tests;

  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Http\Middleware\ValidateDocument;
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

    /**
     * Lo mismo con la validación del documento de entrada: corre sola en cada `POST` o `PATCH` con cuerpo dentro del alcance, y rechazaría
     * los cuerpos de ejemplo de las pruebas que no son de documentos (por ejemplo, `data.attributes` sin `type`). Las que sí lo son ponen esto en `true`.
     * The same with the input document validation: it runs on its own on every `POST` or `PATCH` with a body inside the scope, and it would reject
     * the sample bodies of tests that are not about documents (for example, `data.attributes` without `type`). Those that are set this to `true`.
     */
    protected bool $validatesDocuments = false;

    protected function setUp(): void {
      parent::setUp();

      if (!$this->negotiates) {
        $this->withoutMiddleware(NegotiateContent::class);
      }

      if (!$this->validatesDocuments) {
        $this->withoutMiddleware(ValidateDocument::class);
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
