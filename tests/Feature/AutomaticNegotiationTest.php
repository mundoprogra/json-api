<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Http\Kernel as HttpKernel;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La negociación de contenido se aplica sola a toda petición dentro del alcance del paquete (modo, prefijo y `except`),
   * sin poner nada en las rutas. Corre justo después de `ApplyJsonApi`, antes de buscar la ruta y de cualquier middleware de la app.
   * Fuera del alcance no se toca; para una ruta suelta se ponen a mano `json-api` y `json-api.negotiate`.
   * Content negotiation is applied on its own to every request inside the package scope (mode, prefix and `except`),
   * without putting anything on the routes. It runs right after `ApplyJsonApi`, before the route is looked up and before any app middleware.
   * Outside the scope it is left alone; for a single route `json-api` and `json-api.negotiate` are put by hand.
   */
  class AutomaticNegotiationTest extends TestCase {
    use AssertsJsonApi;

    protected bool $negotiates = true;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ok = fn() => response()->json(['ok' => true]);
      $methods = ['GET', 'POST', 'PATCH', 'DELETE'];

      // Ninguna lleva `json-api.negotiate`, salvo las que lo dicen en el nombre.
      // None carries `json-api.negotiate`, except those that say so in the name.
      foreach (['api/items', 'web/items', 'api/health', 'up'] as $path) {
        $router->match($methods, $path, $ok);
      }

      $router->match($methods, 'api/twice', $ok)->middleware('json-api.negotiate');
      $router->match($methods, 'web/marked', $ok)->middleware('json-api');
      $router->match($methods, 'web/marked-negotiated', $ok)->middleware(['json-api', 'json-api.negotiate']);
      $router->match($methods, 'api/auth', fn() => 'secret')->middleware(AuthenticateLikeTheSkeleton::class);
    }

    /**
     * Una petición con cuerpo y las cabeceras indicadas. Un Content-Type null no se envía.
     * A request with a body and the given headers. A null Content-Type is not sent.
     */
    private function send(string $method, string $uri, ?string $contentType = self::MEDIA_TYPE, string $accept = self::MEDIA_TYPE): TestResponse {
      $server = ['HTTP_ACCEPT' => $accept];

      if ($contentType !== null) {
        $server['CONTENT_TYPE'] = $contentType;
      }

      return $this->call($method, $uri, [], [], [], $server, $method === 'GET' ? '' : '{"data":{"type":"articles"}}');
    }

    private function assertRejected(TestResponse $response, int $status, string $header): void {
      $response->assertStatus($status);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame($header, $response->json('errors.0.source.header'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Dentro del alcance, sin poner nada en la ruta / Inside the scope, with nothing on the route
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function under_the_prefix_a_body_that_is_not_json_api_is_a_415(): void {
      $this->assertRejected($this->send('POST', 'api/items', contentType: 'application/json'), 415, 'Content-Type');
    }

    #[Test]
    public function under_the_prefix_an_accept_that_does_not_allow_json_api_is_a_406(): void {
      // El Accept que se juzga es el que mandó el cliente, no el que el paquete corrigió para la app.
      // The Accept that is judged is the one the client sent, not the one the package fixed for the app.
      $this->assertRejected($this->send('GET', 'api/items', accept: 'text/html'), 406, 'Accept');
    }

    #[Test]
    public function under_the_prefix_a_correct_request_goes_through(): void {
      $this->send('POST', 'api/items')->assertOk();
      $this->send('GET', 'api/items')->assertOk();
    }

    #[Test]
    public function under_the_prefix_an_accept_with_a_wildcard_goes_through(): void {
      $this->send('GET', 'api/items', accept: '*/*')->assertOk();
      $this->send('GET', 'api/items', accept: self::BROWSER)->assertOk();
    }

    #[Test]
    public function under_the_prefix_plain_json_is_a_406_in_strict_mode_and_goes_through_otherwise(): void {
      $this->assertRejected($this->send('GET', 'api/items', accept: 'application/json'), 406, 'Accept');

      config()->set('json-api.strict_headers', false);

      $this->send('GET', 'api/items', accept: 'application/json')->assertOk();
      $this->send('POST', 'api/items', contentType: 'application/json', accept: 'application/json')->assertOk();
    }

    #[Test]
    public function a_request_without_accept_goes_through(): void {
      $this->call('GET', 'api/items')->assertOk();
    }

    #[Test]
    public function the_rejection_comes_before_the_app_middleware(): void {
      // Sin esperar a `auth`: una petición mal negociada es un 415, no un 401 (ni un error por la ruta `login`).
      // Without waiting for `auth`: a badly negotiated request is a 415, not a 401 (nor an error about the `login` route).
      $this->assertRejected($this->send('POST', 'api/auth', contentType: 'application/json'), 415, 'Content-Type');
    }

    #[Test]
    public function a_route_that_also_has_the_alias_is_judged_once(): void {
      $response = $this->send('POST', 'api/twice', contentType: 'application/json');

      $this->assertRejected($response, 415, 'Content-Type');
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function a_request_that_asks_for_the_media_type_outside_the_prefix_is_negotiated(): void {
      // Pedir el media type de JSON:API la mete en el alcance, y entonces su Content-Type también se juzga.
      // Asking for the JSON:API media type puts it inside the scope, and then its Content-Type is judged too.
      $this->assertRejected($this->send('POST', 'web/items', contentType: 'application/json'), 415, 'Content-Type');
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance no se toca / Outside the scope it is left alone
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_prefix_the_request_is_not_negotiated(): void {
      $this->send('POST', 'web/items', contentType: 'application/json', accept: 'application/json')->assertOk();
      $this->send('GET', 'web/items', accept: 'text/html')->assertOk();
    }

    #[Test]
    public function an_excepted_path_is_not_negotiated(): void {
      config()->set('json-api.except', ['api/health']);

      $this->send('POST', 'api/health', contentType: 'application/json', accept: 'text/html')->assertOk();
    }

    #[Test]
    public function a_route_marked_by_hand_is_negotiated_only_if_it_also_has_the_negotiate_alias(): void {
      // `json-api` solo marca la petición: la negociación automática ya pasó antes de que se leyera la marca.
      // `json-api` only marks the request: the automatic negotiation was over before the mark was read.
      $this->send('POST', 'web/marked', contentType: 'application/json', accept: 'application/json')->assertOk();
      $this->assertRejected($this->send('POST', 'web/marked-negotiated', contentType: 'application/json'), 415, 'Content-Type');
    }

    // ---------------------------------------------------------------------------------------------
    // mode = global
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function in_global_mode_every_path_is_negotiated(): void {
      config()->set('json-api.mode', 'global');

      $this->assertRejected($this->send('POST', 'web/items', contentType: 'application/json'), 415, 'Content-Type');
      $this->assertRejected($this->send('GET', 'web/items', accept: 'text/html'), 406, 'Accept');
      $this->send('GET', 'web/items', accept: self::BROWSER)->assertOk();
    }

    #[Test]
    public function in_global_mode_an_excepted_path_is_not_negotiated(): void {
      config()->set('json-api.mode', 'global');

      $this->send('POST', 'up', contentType: 'application/json', accept: 'text/html')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // El registro / The registration
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_registered_right_after_apply_json_api_at_the_start_of_the_global_stack(): void {
      $global = $this->globalMiddleware();

      // Primero ApplyJsonApi (guarda el Accept original) y justo después la negociación, que lo lee.
      // ApplyJsonApi first (it keeps the original Accept) and right after it the negotiation, which reads it.
      $this->assertSame(ApplyJsonApi::class . ':scope', $global[0] ?? null);
      $this->assertSame(NegotiateContent::class . ':scope', $global[1] ?? null);
    }

    #[Test]
    public function it_is_registered_only_once_however_many_times_the_kernel_is_resolved(): void {
      $this->app->make(HttpKernel::class);
      $this->app->make(HttpKernel::class);

      $negotiating = array_filter($this->globalMiddleware(), fn($middleware) => str_starts_with((string) $middleware, NegotiateContent::class));

      $this->assertCount(1, $negotiating);
    }

    /**
     * La fila global del kernel. Se lee la propiedad porque `getGlobalMiddleware()` no existe en Laravel 10.
     * The kernel global stack. The property is read because `getGlobalMiddleware()` does not exist in Laravel 10.
     *
     * @return list<mixed>
     */
    private function globalMiddleware(): array {
      $kernel = $this->app->make(HttpKernel::class);

      return array_values((fn() => $this->middleware)->call($kernel));
    }
  }
