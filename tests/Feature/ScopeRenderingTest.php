<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Auth\AuthenticationException;
  use Illuminate\Support\Facades\Log;
  use Monolog\Handler\TestHandler;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;

  /**
   * Fuera de su alcance el paquete no actúa: el renderizador cede a Laravel (devuelve null) y el id del log tampoco se anota.
   * La única excepción es un JsonApiException lanzado a mano: ese siempre sale en JSON:API.
   * Aquí se usa el modo `prefix` con el prefijo por defecto (`api`), salvo donde se indica.
   * Outside its scope the package does not act: the renderer yields to Laravel (returns null) and the log id is not noted either.
   * The only exception is a JsonApiException thrown by hand: that one always comes out as JSON:API.
   * Here the `prefix` mode is used with the default prefix (`api`), except where stated.
   */
  class ScopeRenderingTest extends TestCase {
    use AssertsJsonApi;

    protected function setUp(): void {
      parent::setUp();

      config()->set('app.debug', false);
    }

    protected function defineEnvironment($app): void {
      parent::defineEnvironment($app);

      // Un canal de log real, para comprobar también lo que se anota (o no) en el contexto del log.
      // A real log channel, to also check what is (or is not) noted in the log context.
      $app['config']->set('logging.default', 'spike');
      $app['config']->set('logging.channels.spike', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    protected function defineRoutes($router): void {
      $router->get('api/boom', fn() => throw new RuntimeException('boom'));
      $router->get('web/boom', fn() => throw new RuntimeException('boom'));
      $router->get('api/health', fn() => throw new RuntimeException('boom'));
      $router->get('up', fn() => throw new RuntimeException('boom'));
      $router->get('web/auth', fn() => throw new AuthenticationException());
      $router->get('api/auth', fn() => throw new AuthenticationException());
      $router->get('web/own', fn() => throw JsonApiException::make(status: 409, detail: 'Already taken.'));
      $router->get('web/own-server-error', fn() => throw JsonApiException::make(status: 503, detail: 'Try later.'));
    }

    // ---------------------------------------------------------------------------------------------
    // mode = prefix
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function under_the_prefix_the_error_is_a_json_api_document(): void {
      $response = $this->get('api/boom');

      $response->assertStatus(500);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function outside_the_prefix_laravel_answers_as_usual(): void {
      $response = $this->get('web/boom', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
      $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function outside_the_prefix_a_missing_page_is_the_usual_404(): void {
      $response = $this->get('web/no-such-page', ['Accept' => 'text/html']);

      $response->assertStatus(404);
      $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function outside_the_prefix_a_client_that_asks_for_json_gets_the_usual_laravel_json(): void {
      // Laravel responde su JSON de siempre ({"message": ...}), no el documento de errores de JSON:API.
      // Laravel answers its usual JSON ({"message": ...}), not the JSON:API errors document.
      $response = $this->get('web/auth', ['Accept' => 'application/json']);

      $response->assertStatus(401);
      $this->assertSame('Unauthenticated.', $response->json('message'));
      $this->assertArrayNotHasKey('errors', $response->json());
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function under_the_prefix_the_same_exception_is_a_json_api_error(): void {
      // La misma excepción que en la prueba anterior, ahora bajo el prefijo.
      // The same exception as in the previous test, now under the prefix.
      $response = $this->get('api/auth', ['Accept' => 'application/json']);

      $response->assertStatus(401);
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
    }

    #[Test]
    public function outside_the_prefix_a_request_that_asks_for_the_media_type_is_answered_as_json_api(): void {
      $response = $this->get('web/boom', ['Accept' => 'application/vnd.api+json']);

      $response->assertStatus(500);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // except
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_excepted_path_under_the_prefix_is_left_to_laravel(): void {
      config()->set('json-api.except', ['api/health']);

      $response = $this->get('api/health', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------------------------------------
    // mode = global
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function in_global_mode_a_page_outside_the_prefix_is_answered_as_json_api_even_if_the_client_asks_for_html(): void {
      config()->set('json-api.mode', 'global');

      $response = $this->get('web/boom', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function in_global_mode_a_route_that_does_not_exist_is_a_json_api_404(): void {
      config()->set('json-api.mode', 'global');

      $response = $this->get('no/such/route', ['Accept' => 'text/html']);

      $response->assertStatus(404);
      $this->assertSame('mundoprogra.json-api.route.not_found', $response->json('errors.0.code'));
    }

    #[Test]
    public function in_global_mode_an_excepted_path_is_left_to_laravel(): void {
      config()->set('json-api.mode', 'global');

      $response = $this->get('up', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_mode_can_change_while_the_app_runs(): void {
      $this->assertStringNotContainsString('vnd.api+json', (string) $this->get('web/boom', ['Accept' => 'text/html'])->headers->get('Content-Type'));

      config()->set('json-api.mode', 'global');

      $this->assertSame('application/vnd.api+json', $this->get('web/boom', ['Accept' => 'text/html'])->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------------------------------------
    // Un JsonApiException lanzado a mano siempre sale en JSON:API: quien lo lanza ya eligió ese formato
    // A JsonApiException thrown by hand always comes out as JSON:API: whoever throws it already chose that format
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_json_api_exception_is_answered_as_json_api_even_outside_the_prefix(): void {
      $response = $this->get('web/own', ['Accept' => 'text/html']);

      $response->assertStatus(409);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('Already taken.', $response->json('errors.0.detail'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_json_api_exception_is_answered_as_json_api_even_on_an_excepted_path(): void {
      config()->set('json-api.except', ['web/own']);

      $response = $this->get('web/own', ['Accept' => 'text/html']);

      $response->assertStatus(409);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_error_id_of_a_json_api_exception_outside_the_prefix_is_in_the_response_and_in_the_log(): void {
      config()->set('json-api.errors.include_id', true);

      $response = $this->get('web/own-server-error', ['Accept' => 'text/html']);

      $response->assertStatus(503);
      $this->assertNotNull($response->json('errors.0.id'));
      $this->assertCount(1, $this->records());
      $this->assertSame($response->json('errors.0.id'), $this->records()[0]['error_id'] ?? null);
    }

    // ---------------------------------------------------------------------------------------------
    // El id del log respeta el mismo alcance / The log id follows the same scope
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function under_the_prefix_the_error_id_is_noted_in_the_log(): void {
      config()->set('json-api.errors.include_id', true);

      $response = $this->get('api/boom');

      $this->assertCount(1, $this->records());
      $this->assertSame($response->json('errors.0.id'), $this->records()[0]['error_id'] ?? null);
      $this->assertNotNull($response->json('errors.0.id'));
    }

    #[Test]
    public function outside_the_scope_no_error_id_is_noted_in_the_log(): void {
      config()->set('json-api.errors.include_id', true);

      $this->get('web/boom', ['Accept' => 'text/html']);

      // El log sí recibió la excepción: sin esto, "no hay error_id" podría significar que el log no funciona.
      // The log did receive the exception: without this, "no error_id" could just mean the log does not work.
      $this->assertCount(1, $this->records());
      $this->assertArrayNotHasKey('error_id', $this->records()[0]);
    }

    /**
     * @return list<array<string, mixed>> El contexto de cada línea del log. / The context of each log line.
     */
    private function records(): array {
      /** @var TestHandler $handler */
      $handler = Log::channel('spike')->getLogger()->getHandlers()[0];

      return array_map(fn($record) => (array) $record['context'], $handler->getRecords());
    }
  }
