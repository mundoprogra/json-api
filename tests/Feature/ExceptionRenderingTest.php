<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Auth\Access\AuthorizationException;
  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Database\Eloquent\ModelNotFoundException;
  use Illuminate\Http\Exceptions\HttpResponseException;
  use Illuminate\Http\Exceptions\PostTooLargeException;
  use Illuminate\Http\Exceptions\ThrottleRequestsException;
  use Illuminate\Routing\Exceptions\InvalidSignatureException;
  use Illuminate\Session\TokenMismatchException;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\MinimalHandler;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\PaymentDeclined;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;

  class ExceptionRenderingTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);

      // Estas pruebas ejercitan el renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');
    }

    protected function defineRoutes($router): void {
      $router->get('model', fn() => throw (new ModelNotFoundException())->setModel('App\\Models\\User', [1]));
      $router->post('post-only', fn() => 'ok');
      $router->get('authz', fn() => throw new AuthorizationException('nope'));
      $router->get('csrf', fn() => throw new TokenMismatchException('expired'));
      $router->get('throttle', fn() => throw new ThrottleRequestsException('slow', null, ['Retry-After' => 60]));
      $router->get('signature', fn() => throw new InvalidSignatureException());
      $router->get('toolarge', fn() => throw new PostTooLargeException());
      foreach ([400, 401, 402, 403, 404, 409, 502, 503] as $status) {
        $router->get("abort$status", fn() => abort($status));
      }
      $router->get('abort403-secret', fn() => abort(403, 'secret internal reason'));
      $router->get('abort503-message', fn() => abort(503, 'database maintenance'));
      $router->get('boom', fn() => throw new RuntimeException('boom'));
      $router->get('response-exception', fn() => throw new HttpResponseException(response('custom', 202)));
      $router->get('jsonapi-single', fn() => throw JsonApiException::make(
        status: 402,
        title: 'Payment declined',
        detail: 'The card was declined.',
        code: 'PAYMENT-DECLINED',
        pointer: '/data/attributes/card',
        meta: ['attempt' => 2],
        headers: ['X-Test' => '1'],
      ));
      $router->get('jsonapi-bare', fn() => throw JsonApiException::make(status: 404));
      $router->get('jsonapi-many', fn() => throw new JsonApiException([Error::make(status: 403), Error::make(status: 404)]));
      $router->get('jsonapi-server', fn() => throw JsonApiException::make(status: 500, detail: 'Custom text'));
      $router->get('jsonapi-subclass', fn() => throw new PaymentDeclined());
    }

    // ---------------------------------------------------------------------------------------------
    // La forma de la respuesta / The shape of the response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_model_becomes_a_complete_json_api_error_document(): void {
      $response = $this->get('model');

      $response->assertStatus(404);
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'errors' => [[
          'status' => '404',
          'code' => 'mundoprogra.json-api.resource.not_found',
          'title' => 'Resource not found',
          'detail' => $this->english('resource.not_found.detail'),
        ]],
      ], $response->json());
    }

    #[Test]
    public function the_response_uses_the_json_api_media_type(): void {
      $this->assertSame('application/vnd.api+json', $this->get('model')->headers->get('Content-Type'));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function acceptHeaders(): array {
      // El paquete actúa en todas las rutas y con cualquier Accept: el filtro por prefijo llega en la Fase 4.
      // The package acts on every route and with any Accept: the prefix filter arrives in Phase 4.
      return [
        'no accept header' => [null],
        'a browser asking for html' => ['text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
        'plain json' => ['application/json'],
        'the json:api media type' => ['application/vnd.api+json'],
      ];
    }

    #[Test]
    #[DataProvider('acceptHeaders')]
    public function acts_whatever_the_client_asks_for(?string $accept): void {
      $response = $accept === null ? $this->get('model') : $this->get('model', ['Accept' => $accept]);

      $response->assertStatus(404);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Excepciones con estado HTTP / Exceptions with an HTTP status
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function statusExceptions(): array {
      // [ruta, estado, código base, título en inglés] / [route, status, base code, English title]
      return [
        'a missing model' => ['model', 404, 'resource.not_found', 'Resource not found'],
        'a route that does not exist' => ['nope', 404, 'route.not_found', 'Route not found'],
        'abort 404 inside an existing route' => ['abort404', 404, 'resource.not_found', 'Resource not found'],
        'a method that is not allowed' => ['post-only', 405, 'request.method_not_allowed', 'Method not allowed'],
        'an authorization failure' => ['authz', 403, 'auth.forbidden', 'Forbidden'],
        'abort 403' => ['abort403', 403, 'auth.forbidden', 'Forbidden'],
        'abort 401' => ['abort401', 401, 'auth.unauthenticated', 'Unauthenticated'],
        'abort 400' => ['abort400', 400, 'request.bad_request', 'Bad request'],
        'abort 409' => ['abort409', 409, 'resource.conflict', 'Conflict'],
        'an expired token' => ['csrf', 419, 'request.token_mismatch', 'Page expired'],
        'too many requests' => ['throttle', 429, 'request.too_many_requests', 'Too many requests'],
        'an invalid signature' => ['signature', 403, 'auth.invalid_signature', 'Invalid signature'],
        'a body that is too large' => ['toolarge', 413, 'request.payload_too_large', 'Payload too large'],
        'abort 503' => ['abort503', 503, 'server.unavailable', 'Service unavailable'],
        'abort 402, which has no code of its own' => ['abort402', 402, 'http.402', 'Request failed'],
        'abort 502, which has no code of its own' => ['abort502', 502, 'http.502', 'Request failed'],
        'an unexpected exception' => ['boom', 500, 'server.error', 'Server error'],
      ];
    }

    #[Test]
    #[DataProvider('statusExceptions')]
    public function converts_the_exception_into_an_error_with_its_status_code_and_title(string $path, int $status, string $base, string $title): void {
      $response = $this->get($path);

      $response->assertStatus($status);
      $error = $response->json('errors.0');

      $this->assertSame((string) $status, $error['status']);
      $this->assertSame('mundoprogra.json-api.' . $base, $error['code']);
      $this->assertSame($title, $error['title']);
      $this->assertNotSame('', $error['detail']);
      $this->assertCount(1, $response->json('errors'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_route_that_does_not_exist_is_told_apart_from_a_missing_resource(): void {
      $this->assertSame('mundoprogra.json-api.route.not_found', $this->get('nope')->json('errors.0.code'));
      $this->assertSame('mundoprogra.json-api.resource.not_found', $this->get('abort404')->json('errors.0.code'));
    }

    #[Test]
    public function a_method_that_is_not_allowed_keeps_the_allow_header_and_names_the_methods(): void {
      $response = $this->get('post-only');

      $response->assertStatus(405);
      $this->assertStringContainsString('POST', (string) $response->headers->get('Allow'));
      $this->assertStringContainsString('GET', $response->json('errors.0.detail'));
      $this->assertStringContainsString('POST', $response->json('errors.0.detail'));
    }

    #[Test]
    public function too_many_requests_keeps_the_retry_after_header(): void {
      $this->assertSame('60', $this->get('throttle')->headers->get('Retry-After'));
    }

    #[Test]
    public function the_texts_follow_the_language_of_the_application(): void {
      $this->app->setLocale('es');

      $this->assertSame('Recurso no encontrado', $this->get('model')->json('errors.0.title'));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no se debe filtrar / What must not leak
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_client_error_never_leaks_the_name_of_the_model_or_the_message_of_the_exception(): void {
      config()->set('json-api.errors.expose_details', true);

      $body = (string) $this->get('model')->getContent();
      $secret = (string) $this->get('abort403-secret')->getContent();

      $this->assertStringNotContainsString('App\\\\Models\\\\User', $body);
      $this->assertStringNotContainsString('No query results', $body);
      $this->assertStringNotContainsString('secret internal reason', $secret);
    }

    #[Test]
    public function an_unexpected_exception_hides_its_details_when_they_are_not_exposed(): void {
      $body = (string) $this->get('boom')->getContent();

      $this->assertSame($this->english('server.error.detail'), $this->get('boom')->json('errors.0.detail'));
      $this->assertStringNotContainsString('boom', $body);
      $this->assertStringNotContainsString('RuntimeException', $body);
      $this->assertArrayNotHasKey('meta', $this->get('boom')->json('errors.0'));
    }

    #[Test]
    public function an_unexpected_exception_shows_its_details_when_app_debug_is_on(): void {
      config()->set('app.debug', true);

      $error = $this->get('boom')->json('errors.0');

      $this->assertSame('boom', $error['detail']);
      $this->assertSame(RuntimeException::class, $error['meta']['exception']);
      $this->assertArrayHasKey('file', $error['meta']);
      $this->assertArrayHasKey('line', $error['meta']);
    }

    #[Test]
    public function expose_details_true_shows_the_details_even_with_app_debug_off(): void {
      config()->set('json-api.errors.expose_details', true);

      $this->assertSame('boom', $this->get('boom')->json('errors.0.detail'));
    }

    #[Test]
    public function expose_details_false_hides_the_details_even_with_app_debug_on(): void {
      config()->set('app.debug', true);
      config()->set('json-api.errors.expose_details', false);

      $this->assertStringNotContainsString('boom', (string) $this->get('boom')->getContent());
    }

    #[Test]
    public function a_server_error_with_an_explicit_message_shows_it_only_when_details_are_exposed(): void {
      $this->assertSame($this->english('server.unavailable.detail'), $this->get('abort503-message')->json('errors.0.detail'));

      config()->set('json-api.errors.expose_details', true);

      $this->assertSame('database maintenance', $this->get('abort503-message')->json('errors.0.detail'));
    }

    // ---------------------------------------------------------------------------------------------
    // La configuración / The configuration
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_jsonapi_member_can_be_turned_off(): void {
      config()->set('json-api.jsonapi_object', false);

      $this->assertArrayNotHasKey('jsonapi', $this->get('model')->json());
    }

    #[Test]
    public function the_code_prefix_the_replacements_and_the_switch_are_applied(): void {
      config()->set('json-api.errors.code_prefix', 'acme');
      $this->assertSame('acme.resource.not_found', $this->get('model')->json('errors.0.code'));

      config()->set('json-api.errors.codes', ['resource.not_found' => 'ERR-4040']);
      $this->assertSame('ERR-4040', $this->get('model')->json('errors.0.code'));
      $this->assertSame('acme.auth.forbidden', $this->get('authz')->json('errors.0.code'));

      config()->set('json-api.errors.include_code', false);
      $this->assertArrayNotHasKey('code', $this->get('model')->json('errors.0'));
    }

    // ---------------------------------------------------------------------------------------------
    // JsonApiException
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_json_api_exception_keeps_everything_it_was_given(): void {
      $response = $this->get('jsonapi-single');

      $response->assertStatus(402);
      $this->assertSame('1', $response->headers->get('X-Test'));
      $this->assertSame([
        'status' => '402',
        'code' => 'PAYMENT-DECLINED',
        'title' => 'Payment declined',
        'detail' => 'The card was declined.',
        'source' => ['pointer' => '/data/attributes/card'],
        'meta' => ['attempt' => 2],
      ], $response->json('errors.0'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_json_api_exception_without_texts_or_code_gets_them_from_its_status(): void {
      $error = $this->get('jsonapi-bare')->json('errors.0');

      $this->assertSame('404', $error['status']);
      $this->assertSame('Resource not found', $error['title']);
      $this->assertSame($this->english('resource.not_found.detail'), $error['detail']);
      $this->assertSame('mundoprogra.json-api.resource.not_found', $error['code']);
    }

    #[Test]
    public function a_json_api_exception_with_several_errors_renders_each_one_and_uses_the_general_status(): void {
      $response = $this->get('jsonapi-many');

      $response->assertStatus(400);
      $this->assertSame(['403', '404'], array_column($response->json('errors'), 'status'));
      $this->assertSame(
        ['mundoprogra.json-api.auth.forbidden', 'mundoprogra.json-api.resource.not_found'],
        array_column($response->json('errors'), 'code')
      );
    }

    #[Test]
    public function the_text_written_by_the_developer_in_a_server_error_is_never_hidden(): void {
      $this->assertSame('Custom text', $this->get('jsonapi-server')->json('errors.0.detail'));
    }

    #[Test]
    public function an_explicit_code_is_not_given_a_prefix_but_the_switch_still_removes_it(): void {
      $this->assertSame('PAYMENT-DECLINED', $this->get('jsonapi-subclass')->json('errors.0.code'));

      config()->set('json-api.errors.include_code', false);

      $this->assertArrayNotHasKey('code', $this->get('jsonapi-subclass')->json('errors.0'));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que se deja intacto / What is left untouched
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_exception_that_already_carries_its_response_is_left_untouched(): void {
      $response = $this->get('response-exception');

      $response->assertStatus(202);
      $this->assertSame('custom', $response->getContent());
    }

    #[Test]
    public function does_not_break_an_exception_handler_that_has_no_renderable_method(): void {
      $this->app->singleton(ExceptionHandler::class, fn() => new MinimalHandler());
      $this->app->register(JsonApiServiceProvider::class, true);

      $this->assertInstanceOf(MinimalHandler::class, $this->app->make(ExceptionHandler::class));
    }
  }
