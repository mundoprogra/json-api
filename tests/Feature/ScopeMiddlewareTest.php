<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;
  use Symfony\Component\Console\Output\BufferedOutput;

  /**
   * El middleware del paquete: se aplica solo dentro del alcance (modo, prefijo y `except`) y, ahí, hace que la petición pida JSON:API
   * para que `expectsJson()` sea verdadero y el middleware `auth` no calcule la ruta `login`. Guarda el `Accept` original en la petición.
   * También se puede poner a mano en una ruta con el alias `json-api.apply`. Se usa el modo `prefix` con el prefijo por defecto (`api`).
   * The package middleware: it applies only inside the scope (mode, prefix and `except`) and, there, makes the request ask for JSON:API
   * so `expectsJson()` is true and the `auth` middleware does not work out the `login` route. It keeps the original `Accept` on the request.
   * It can also be put by hand on a route with the `json-api.apply` alias. The `prefix` mode with the default prefix (`api`) is used.
   */
  class ScopeMiddlewareTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      // Cada ruta de eco cuenta lo que la app recibe: el Accept, el original que guardó el paquete y qué piensa Laravel.
      // Each echo route reports what the app receives: the Accept, the original one the package kept and what Laravel thinks.
      $echo = fn(Request $request) => response()->json([
        'accept' => $request->header('Accept'),
        'original' => $request->attributes->get('json-api.original_accept'),
        'expectsJson' => $request->expectsJson(),
      ]);

      foreach (['api/echo', 'web/echo', 'up'] as $path) {
        $router->get($path, $echo);
      }

      $router->get('web/marked', $echo)->middleware('json-api.apply');
      $router->get('up/marked', $echo)->middleware('json-api.apply');
      $router->get('api/double', $echo)->middleware('json-api.apply');
      $router->get('web/marked-boom', fn() => throw new RuntimeException('boom'))->middleware('json-api.apply');

      $router->get('api/auth', fn() => 'secret')->middleware(AuthenticateLikeTheSkeleton::class);
      $router->get('web/auth', fn() => 'secret')->middleware(AuthenticateLikeTheSkeleton::class);
      $router->get('web/marked-auth', fn() => 'secret')->middleware(['json-api.apply', AuthenticateLikeTheSkeleton::class]);
    }

    // ---------------------------------------------------------------------------------------------
    // El Accept dentro del alcance / The Accept inside the scope
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function under_the_prefix_a_request_that_asks_for_html_reaches_the_app_asking_for_json_api(): void {
      $response = $this->get('api/echo', ['Accept' => 'text/html']);

      $this->assertSame(self::MEDIA_TYPE, $response->json('accept'));
      $this->assertTrue($response->json('expectsJson'));
    }

    #[Test]
    public function under_the_prefix_a_request_without_a_specific_accept_reaches_the_app_asking_for_json_api(): void {
      $response = $this->get('api/echo', ['Accept' => '*/*']);

      $this->assertSame(self::MEDIA_TYPE, $response->json('accept'));
      $this->assertTrue($response->json('expectsJson'));
    }

    #[Test]
    public function under_the_prefix_an_accept_that_already_expects_json_is_left_as_it_is(): void {
      $response = $this->get('api/echo', ['Accept' => 'application/json']);

      $this->assertSame('application/json', $response->json('accept'));
      $this->assertTrue($response->json('expectsJson'));
    }

    #[Test]
    public function under_the_prefix_a_request_that_already_asks_for_the_media_type_is_left_as_it_is(): void {
      $response = $this->get('api/echo', ['Accept' => self::MEDIA_TYPE . '; profile="https://example.com/p"']);

      $this->assertSame(self::MEDIA_TYPE . '; profile="https://example.com/p"', $response->json('accept'));
    }

    #[Test]
    public function the_original_accept_is_kept_on_the_request_for_the_content_negotiation(): void {
      $this->assertSame('text/html', $this->get('api/echo', ['Accept' => 'text/html'])->json('original'));
      $this->assertSame('application/json', $this->get('api/echo', ['Accept' => 'application/json'])->json('original'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance / Outside the scope
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_prefix_the_request_is_left_untouched(): void {
      $response = $this->get('web/echo', ['Accept' => 'text/html']);

      $this->assertSame('text/html', $response->json('accept'));
      $this->assertNull($response->json('original'));
      $this->assertFalse($response->json('expectsJson'));
    }

    #[Test]
    public function an_excepted_path_is_left_untouched(): void {
      $response = $this->get('up', ['Accept' => 'text/html']);

      $this->assertSame('text/html', $response->json('accept'));
      $this->assertNull($response->json('original'));
    }

    #[Test]
    public function in_global_mode_every_request_is_fixed(): void {
      config()->set('json-api.mode', 'global');

      $response = $this->get('web/echo', ['Accept' => 'text/html']);

      $this->assertSame(self::MEDIA_TYPE, $response->json('accept'));
      $this->assertSame('text/html', $response->json('original'));
    }

    #[Test]
    public function in_global_mode_an_excepted_path_is_still_left_untouched(): void {
      config()->set('json-api.mode', 'global');

      $this->assertSame('text/html', $this->get('up', ['Accept' => 'text/html'])->json('accept'));
    }

    // ---------------------------------------------------------------------------------------------
    // El middleware `auth` (pendiente heredado de la Fase 3) / The `auth` middleware (pending item inherited from Phase 3)
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function under_the_prefix_a_request_without_json_that_fails_auth_is_a_401_json_api_error(): void {
      $response = $this->get('api/auth', ['Accept' => 'text/html']);

      $response->assertStatus(401);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function outside_the_prefix_the_auth_middleware_behaves_as_it_always_did(): void {
      // Control: sin el paquete, esta ruta falla porque la app no tiene la ruta `login`. Fuera del alcance eso no cambia.
      // Control: without the package, this route fails because the app has no `login` route. Outside the scope that does not change.
      $response = $this->get('web/auth', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------------------------------------
    // A mano, con el alias `json-api.apply` / By hand, with the `json-api.apply` alias
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_middleware_has_the_apply_alias(): void {
      $this->assertSame(ApplyJsonApi::class, $this->app->make('router')->getMiddleware()['json-api.apply'] ?? null);
    }

    #[Test]
    public function a_route_marked_by_hand_outside_the_prefix_is_fixed(): void {
      $response = $this->get('web/marked', ['Accept' => 'text/html']);

      $this->assertSame(self::MEDIA_TYPE, $response->json('accept'));
      $this->assertSame('text/html', $response->json('original'));
    }

    #[Test]
    public function a_route_marked_by_hand_answers_its_errors_as_json_api(): void {
      $response = $this->get('web/marked-boom', ['Accept' => 'text/html']);

      $response->assertStatus(500);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_route_marked_by_hand_before_auth_answers_401_json_api(): void {
      $response = $this->get('web/marked-auth', ['Accept' => 'text/html']);

      $response->assertStatus(401);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_route_marked_by_hand_on_an_excepted_path_is_fixed(): void {
      config()->set('json-api.except', ['up/*']);

      $this->assertSame(self::MEDIA_TYPE, $this->get('up/marked', ['Accept' => 'text/html'])->json('accept'));
    }

    #[Test]
    public function applying_it_twice_keeps_the_first_original_accept(): void {
      // Bajo el prefijo ya se aplicó solo; el de la ruta no debe tomar el Accept ya corregido como si fuera el original.
      // Under the prefix it was already applied on its own; the route one must not take the already fixed Accept as the original.
      $response = $this->get('api/double', ['Accept' => 'text/html']);

      $this->assertSame(self::MEDIA_TYPE, $response->json('accept'));
      $this->assertSame('text/html', $response->json('original'));
    }

    // ---------------------------------------------------------------------------------------------
    // Consola y colas / Console and queues
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_console_keeps_laravels_own_rendering(): void {
      // Control: el paquete solo toca peticiones HTTP; un error de consola sale como siempre, no como documento de JSON:API.
      // Control: the package only touches HTTP requests; a console error comes out as usual, not as a JSON:API document.
      config()->set('json-api.mode', 'global');
      $output = new BufferedOutput();

      $this->app->make(ExceptionHandler::class)->renderForConsole($output, new RuntimeException('console boom'));

      $this->assertStringContainsString('console boom', $output->fetch());
    }
  }
