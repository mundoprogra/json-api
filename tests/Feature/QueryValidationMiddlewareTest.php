<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Http\Kernel as HttpKernel;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Http\Middleware\ValidateQuery;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Toda petición dentro del alcance del paquete tiene su consulta validada antes de llegar al controlador: un parámetro mal formado es un
   * `400` aunque el endpoint no lo use. El resultado queda en la petición y el controlador lo lee con `$request->jsonApiQuery()`, que
   * también funciona fuera del alcance (y entonces la analiza en ese momento). Corre justo después de la negociación de contenido.
   * Every request inside the package scope has its query validated before it reaches the controller: a malformed parameter is a `400`
   * even if the endpoint does not use it. The result is kept on the request and the controller reads it with `$request->jsonApiQuery()`,
   * which also works outside the scope (and then parses it right there). It runs right after the content negotiation.
   */
  class QueryValidationMiddlewareTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ok = fn() => response()->json(['ok' => true]);
      $read = fn(Request $request) => response()->json([
        'include' => $request->jsonApiQuery()->includes(),
        'fields' => $request->jsonApiQuery()->fields(),
        'sort' => $request->jsonApiQuery()->sort(),
        'filter' => $request->jsonApiQuery()->filter(),
        'page' => $request->jsonApiQuery()->page(),
      ]);

      foreach (['api/plain', 'web/plain', 'api/health'] as $path) {
        $router->match(['GET', 'POST', 'DELETE'], $path, $ok);
      }

      $router->get('web/validated', $ok)->middleware('json-api.query');
      $router->get('api/read', $read);
      $router->get('web/read', $read);
      $router->get('api/once', fn(Request $request) => response()->json(['same' => $request->jsonApiQuery() === $request->jsonApiQuery()]));
      $router->get('api/auth', fn() => 'secret')->middleware(AuthenticateLikeTheSkeleton::class);
    }

    // ---------------------------------------------------------------------------------------------
    // Dentro del alcance se valida aunque el endpoint no use la consulta / Inside the scope it is validated even if the endpoint ignores it
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_malformed_query_is_a_400_even_if_the_endpoint_never_reads_it(): void {
      $response = $this->get('api/plain?include=a,,b', ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('include', $response->json('errors.0.source.parameter'));
      $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function every_problem_comes_out_together(): void {
      $response = $this->get('api/plain?include=a,,b&page[size]=0', ['Accept' => self::MEDIA_TYPE]);

      $this->assertSame(['include', 'page[size]'], array_column(array_column($response->json('errors'), 'source'), 'parameter'));
    }

    #[Test]
    public function a_valid_query_goes_through(): void {
      $this->get('api/plain?include=author&sort=-title&page[size]=5', ['Accept' => self::MEDIA_TYPE])->assertOk();
      $this->get('api/plain', ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    #[Test]
    public function every_method_is_validated(): void {
      foreach (['GET', 'POST', 'DELETE'] as $method) {
        $this->call($method, 'api/plain?page[size]=0', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE])->assertStatus(400);
      }
    }

    #[Test]
    public function the_rejection_comes_before_the_app_middleware(): void {
      // Sin esperar a `auth`: una consulta mal formada es un 400, no un 401 (ni un error por la ruta `login`).
      // Without waiting for `auth`: a malformed query is a 400, not a 401 (nor an error about the `login` route).
      $this->assertSame(400, $this->get('api/auth?sort=bad%20name', ['Accept' => self::MEDIA_TYPE])->getStatusCode());
    }

    #[Test]
    public function the_unknown_parameter_setting_applies_without_the_endpoint_reading_anything(): void {
      $this->get('api/plain?foo=1', ['Accept' => self::MEDIA_TYPE])->assertOk();

      config()->set('json-api.reject_unknown_query_params', true);

      $this->get('api/plain?foo=1', ['Accept' => self::MEDIA_TYPE])->assertStatus(400);
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance no se toca / Outside the scope it is left alone
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_prefix_the_query_is_not_validated(): void {
      $this->get('web/plain?include=a,,b&page[size]=0', ['Accept' => 'text/html'])->assertOk();
    }

    #[Test]
    public function an_excepted_path_is_not_validated(): void {
      config()->set('json-api.except', ['api/health']);

      $this->get('api/health?include=a,,b', ['Accept' => 'text/html'])->assertOk();
    }

    #[Test]
    public function a_route_outside_the_scope_can_ask_for_it_by_hand_with_the_alias(): void {
      $this->assertSame(ValidateQuery::class, $this->app->make('router')->getMiddleware()['json-api.query'] ?? null);

      $this->get('web/validated?include=a,,b', ['Accept' => 'text/html'])->assertStatus(400);
      $this->get('web/validated?include=author', ['Accept' => 'text/html'])->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // $request->jsonApiQuery()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_request_has_the_jsonapiquery_macro(): void {
      $this->assertTrue(Request::hasMacro('jsonApiQuery'));
    }

    #[Test]
    public function the_controller_reads_the_parsed_query(): void {
      $response = $this->get('api/read?include=author,comments.user&fields[articles]=title&sort=-createdAt&filter[status]=published&page[number]=2&page[size]=25', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame([['author'], ['comments', 'user']], $response->json('include'));
      $this->assertSame(['articles' => ['title']], $response->json('fields'));
      $this->assertSame([['field' => 'created_at', 'descending' => true]], $response->json('sort'));
      $this->assertSame(['status' => 'published'], $response->json('filter'));
      $this->assertSame(['number' => 2, 'size' => 25], $response->json('page'));
    }

    #[Test]
    public function it_returns_a_query_parameters_object_parsed_once_per_request(): void {
      $request = Request::create('/api/articles?include=author');

      $this->assertInstanceOf(QueryParameters::class, $request->jsonApiQuery());
      $this->assertSame($request->jsonApiQuery(), $request->jsonApiQuery());
      $this->assertSame(['same' => true], $this->get('api/once', ['Accept' => self::MEDIA_TYPE])->json());
    }

    #[Test]
    public function each_request_has_its_own_query(): void {
      $first = Request::create('/api/articles?include=author');
      $second = Request::create('/api/articles?include=tags');

      $this->assertSame([['author']], $first->jsonApiQuery()->includes());
      $this->assertSame([['tags']], $second->jsonApiQuery()->includes());
    }

    #[Test]
    public function the_member_case_setting_applies(): void {
      config()->set('json-api.member_case', 'kebab');

      $response = $this->get('api/read?sort=-created-at', ['Accept' => self::MEDIA_TYPE]);

      $this->assertSame([['field' => 'created_at', 'descending' => true]], $response->json('sort'));
    }

    #[Test]
    public function outside_the_scope_it_parses_the_query_when_asked(): void {
      $response = $this->get('web/read?include=author&page[size]=3', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertSame([['author']], $response->json('include'));
      $this->assertSame(['size' => 3], $response->json('page'));
    }

    #[Test]
    public function outside_the_scope_a_malformed_query_is_a_400_only_when_the_controller_asks(): void {
      // Quien lo pide explícitamente recibe un JsonApiException, que siempre se responde en JSON:API.
      // Whoever asks for it explicitly gets a JsonApiException, which is always answered as JSON:API.
      $response = $this->get('web/read?include=a,,b', ['Accept' => 'text/html']);

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('include', $response->json('errors.0.source.parameter'));
    }

    // ---------------------------------------------------------------------------------------------
    // El registro / The registration
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_registered_right_after_the_negotiation_at_the_start_of_the_global_stack(): void {
      $global = $this->globalMiddleware();

      $this->assertSame(ApplyJsonApi::class . ':scope', $global[0] ?? null);
      $this->assertSame(NegotiateContent::class . ':scope', $global[1] ?? null);
      $this->assertSame(ValidateQuery::class . ':scope', $global[2] ?? null);
    }

    #[Test]
    public function it_is_registered_only_once_however_many_times_the_kernel_is_resolved(): void {
      $this->app->make(HttpKernel::class);
      $this->app->make(HttpKernel::class);

      $validating = array_filter($this->globalMiddleware(), fn($middleware) => str_starts_with((string) $middleware, ValidateQuery::class));

      $this->assertCount(1, $validating);
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
