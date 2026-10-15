<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Auth\AuthenticationException;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

  class AuthenticationRenderingTest extends TestCase {
    use AssertsJsonApi;

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);

      // Estas pruebas ejercitan el renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');
    }

    protected function defineRoutes($router): void {
      $router->get('auth', fn() => throw new AuthenticationException());

      // Una excepción con datos que no deben llegar al cliente: el mensaje, los guards y la URL de redirección.
      // An exception with data that must not reach the client: the message, the guards and the redirect URL.
      $router->get('auth-details', fn() => throw new AuthenticationException('secret reason', ['sanctum', 'web'], 'https://example.com/login'));

      // Una ruta protegida de verdad, con el middleware de autenticación de Laravel.
      // A really protected route, with Laravel's authentication middleware.
      $router->get('protected', fn() => 'secret')->middleware('auth');

      // La app puede indicar cómo autenticarse lanzando la excepción de Symfony, que ya trae la cabecera.
      // The app can say how to authenticate by throwing the Symfony exception, which already carries the header.
      $router->get('bearer', fn() => throw new UnauthorizedHttpException('Bearer realm="api"', 'Token required'));
      $router->get('basic', fn() => throw new UnauthorizedHttpException('Basic realm="Admin"'));
    }

    #[Test]
    public function an_authentication_failure_becomes_a_401_json_api_error(): void {
      $response = $this->get('auth');

      $response->assertStatus(401);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'errors' => [[
          'status' => '401',
          'code' => 'mundoprogra.json-api.auth.unauthenticated',
          'title' => 'Unauthenticated',
          'detail' => Arr::get(require __DIR__ . '/../../lang/en/errors.php', 'auth.unauthenticated.detail'),
        ]],
      ], $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function acceptHeaders(): array {
      return [
        'no accept header' => [null],
        'a browser asking for html' => ['text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
        'plain json' => ['application/json'],
        'the json:api media type' => ['application/vnd.api+json'],
      ];
    }

    #[Test]
    #[DataProvider('acceptHeaders')]
    public function an_authentication_failure_answers_401_whatever_the_client_asks_for(?string $accept): void {
      // Laravel redirigiría al login a quien no pide JSON; el paquete nunca redirige.
      // Laravel would redirect to the login whoever does not ask for JSON; the package never redirects.
      $response = $accept === null ? $this->get('auth') : $this->get('auth', ['Accept' => $accept]);

      $response->assertStatus(401);
      $this->assertFalse($response->headers->has('Location'));
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jsonAcceptHeaders(): array {
      // Con el middleware `auth` de Laravel, una petición que NO pide JSON hace que el propio middleware intente calcular la URL de login
      // (route('login')) antes de lanzar la excepción, y falla si la app no tiene esa ruta. Eso ocurre antes de que el paquete intervenga;
      // la Fase 4 lo resuelve forzando Accept, por eso aquí solo se prueban las peticiones que piden JSON.
      // With Laravel's `auth` middleware, a request that does NOT ask for JSON makes the middleware itself try to compute the login URL
      // (route('login')) before throwing the exception, and it fails if the app has no such route. That happens before the package acts;
      // Phase 4 solves it by forcing Accept, which is why only requests that ask for JSON are tested here.
      return [
        'plain json' => ['application/json'],
        'the json:api media type' => ['application/vnd.api+json'],
      ];
    }

    #[Test]
    #[DataProvider('jsonAcceptHeaders')]
    public function a_protected_route_answers_401_when_the_client_asks_for_json(string $accept): void {
      $response = $this->get('protected', ['Accept' => $accept]);

      $response->assertStatus(401);
      $this->assertFalse($response->headers->has('Location'));
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
      $this->assertStringNotContainsString('secret', (string) $response->getContent());
    }

    #[Test]
    public function an_exception_that_carries_a_redirect_url_still_answers_401_without_redirecting(): void {
      $response = $this->get('auth-details', ['Accept' => 'text/html']);

      $response->assertStatus(401);
      $this->assertFalse($response->headers->has('Location'));
    }

    #[Test]
    public function the_message_the_guards_and_the_redirect_url_never_reach_the_client(): void {
      $body = (string) $this->get('auth-details')->getContent();

      $this->assertStringNotContainsString('secret reason', $body);
      $this->assertStringNotContainsString('sanctum', $body);
      $this->assertStringNotContainsString('example.com/login', $body);
    }

    #[Test]
    public function does_not_invent_a_www_authenticate_header(): void {
      // El paquete no sabe qué esquema de autenticación usa la app: no envía un desafío que podría ser el equivocado.
      // The package does not know which authentication scheme the app uses: it sends no challenge that could be the wrong one.
      foreach (['auth', 'protected'] as $path) {
        $response = $this->get($path, ['Accept' => 'application/json']);

        // Primero se exige el 401, para que la prueba no pase por una respuesta que nunca tuvo la cabecera.
        // The 401 is required first, so the test does not pass because of a response that never had the header.
        $response->assertStatus(401);
        $this->assertFalse($response->headers->has('WWW-Authenticate'), $path);
      }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function challenges(): array {
      return [
        'a bearer token' => ['bearer', 'Bearer realm="api"'],
        'basic authentication' => ['basic', 'Basic realm="Admin"'],
      ];
    }

    #[Test]
    #[DataProvider('challenges')]
    public function keeps_the_www_authenticate_header_when_the_app_throws_it(string $path, string $challenge): void {
      $response = $this->get($path);

      $response->assertStatus(401);
      $this->assertSame($challenge, $response->headers->get('WWW-Authenticate'));
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
    }

    #[Test]
    public function the_texts_follow_the_language_of_the_application(): void {
      $this->app->setLocale('es');

      $this->assertSame('No autenticado', $this->get('auth')->json('errors.0.title'));
    }

    #[Test]
    public function the_configuration_is_applied(): void {
      config()->set('json-api.errors.code_prefix', 'acme');
      $this->assertSame('acme.auth.unauthenticated', $this->get('auth')->json('errors.0.code'));

      config()->set('json-api.errors.codes', ['auth.unauthenticated' => 'ERR-401']);
      $this->assertSame('ERR-401', $this->get('auth')->json('errors.0.code'));

      config()->set('json-api.errors.include_code', false);
      $this->assertArrayNotHasKey('code', $this->get('auth')->json('errors.0'));

      config()->set('json-api.jsonapi_object', false);
      $response = $this->get('auth');
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertArrayNotHasKey('jsonapi', $response->json());
    }
  }
