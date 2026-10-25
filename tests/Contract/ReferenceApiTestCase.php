<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\Routes;
  use MundoProgra\JsonApi\Tests\Support\MakesJsonApiRequests;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;

  /**
   * La base de las pruebas de la API de referencia: las tablas de prueba con 40 artículos (los pares publicados) y las relaciones de los primeros 20,
   * las rutas de `Routes`, y el paquete entero encendido (negociación de contenido y validación del documento incluidas). Cada respuesta se valida
   * contra el schema oficial con `jsonApi()`.
   *
   * The base of the reference API tests: the test tables with 40 articles (even ones published) and the relationships of the first 20, the routes of
   * `Routes`, and the whole package on (content negotiation and document validation included). Every response is validated against the official
   * schema with `jsonApi()`.
   */
  abstract class ReferenceApiTestCase extends TestCase {
    use MakesJsonApiRequests;
    use UsesArticleTables;

    protected bool $negotiates = true;

    protected bool $validatesDocuments = true;

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->seedRelations(20);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      Routes::register($router);
    }
  }
