<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Http\Middleware\Options;
  use MundoProgra\JsonApi\Http\Middleware\ValidateQuery;
  use MundoProgra\JsonApi\Query\PageRequest;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;

  /**
   * El grupo `json-api` junta los tres middleware que se aplican solos dentro del alcance, para dárselos de una vez a una ruta suelta fuera de él:
   * `json-api.apply` (marca la petición como de JSON:API y arregla el `Accept`), `json-api.negotiate` (415 y 406) y `json-api.query` (400 de la consulta),
   * en ese orden. Cada uno sigue existiendo por separado con su alias. `json-api.options` no está en el grupo porque exige valores
   * (`json-api.options:per_page=25`); se agrega a la ruta o al grupo de rutas cuando hace falta.
   * The `json-api` group puts together the three middleware that apply on their own inside the scope, to give them all at once to a single route
   * outside it: `json-api.apply` (marks the request as JSON:API and fixes the `Accept`), `json-api.negotiate` (415 and 406) and `json-api.query`
   * (400 for the query), in that order. Each one still exists on its own with its alias. `json-api.options` is not in the group because it needs
   * values (`json-api.options:per_page=25`); it is added to the route or to the group of routes when needed.
   */
  class MiddlewareGroupTest extends TestCase {
    use AssertsJsonApi;

    protected bool $negotiates = true;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ok = fn() => response()->json(['ok' => true]);

      $router->match(['GET', 'POST'], 'web/group', $ok)->middleware('json-api');
      $router->match(['GET', 'POST'], 'api/group', $ok)->middleware('json-api');
      $router->get('web/group-boom', fn() => throw new RuntimeException('boom'))->middleware('json-api');
      $router->get('web/plain-boom', fn() => throw new RuntimeException('boom'));
      $router->get('web/group-auth', fn() => 'secret')->middleware(['json-api', AuthenticateLikeTheSkeleton::class]);
      $router->get('web/group-options', fn(Request $request) => response()->json(['options' => $request->attributes->get(Options::ATTRIBUTE)]))->middleware('json-api');
      $router->get('web/group-and-options', fn(Request $request) => response()->json(['size' => PageRequest::of($request)->size()]))->middleware(['json-api', 'json-api.options:per_page=7']);
      $router->get('web/group-default-size', fn(Request $request) => response()->json(['size' => PageRequest::of($request)->size()]))->middleware('json-api');
    }

    // ---------------------------------------------------------------------------------------------
    // Qué existe / What exists
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_json_api_group_has_the_three_middleware_in_this_order(): void {
      $groups = $this->app->make('router')->getMiddlewareGroups();

      $this->assertSame(['json-api.apply', 'json-api.negotiate', 'json-api.query'], $groups['json-api'] ?? null);
    }

    #[Test]
    public function each_middleware_keeps_its_own_alias(): void {
      $aliases = $this->app->make('router')->getMiddleware();

      $this->assertSame(ApplyJsonApi::class, $aliases['json-api.apply'] ?? null);
      $this->assertSame(NegotiateContent::class, $aliases['json-api.negotiate'] ?? null);
      $this->assertSame(ValidateQuery::class, $aliases['json-api.query'] ?? null);
      $this->assertSame(Options::class, $aliases['json-api.options'] ?? null);
    }

    #[Test]
    public function the_name_json_api_is_the_group_and_no_longer_an_alias(): void {
      $this->assertArrayNotHasKey('json-api', $this->app->make('router')->getMiddleware());
    }

    // ---------------------------------------------------------------------------------------------
    // Una ruta suelta fuera del alcance recibe el trato completo / A single route outside the scope gets the full treatment
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_route_outside_the_scope_negotiates_the_content_type(): void {
      // Sin `Accept`: lo único que mete esta petición en el alcance es el grupo (pedir el media type ya la metería sola).
      // Without an `Accept`: the only thing that brings this request into the scope is the group (asking for the media type would already do it).
      $response = $this->call('POST', 'web/group', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"data":{}}');

      $response->assertStatus(415);
      $this->assertSame('Content-Type', $response->json('errors.0.source.header'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function it_judges_the_accept_the_client_sent_not_the_one_the_group_fixed(): void {
      // `json-api.apply` va primero y guarda el original: sin eso, el `Accept` ya corregido pasaría la negociación.
      // `json-api.apply` goes first and keeps the original: without that, the already fixed `Accept` would pass the negotiation.
      $response = $this->get('web/group', ['Accept' => 'text/html']);

      $response->assertStatus(406);
      $this->assertSame('Accept', $response->json('errors.0.source.header'));
    }

    #[Test]
    public function it_validates_the_query(): void {
      $response = $this->get('web/group?include=a,,b');

      $response->assertStatus(400);
      $this->assertSame('include', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function a_correct_request_goes_through(): void {
      $this->get('web/group?include=author', ['Accept' => self::MEDIA_TYPE])->assertOk();
      $this->get('web/group')->assertOk();
    }

    #[Test]
    public function the_errors_of_the_route_come_out_as_json_api_because_the_request_is_marked(): void {
      // La misma excepción en una ruta sin el grupo sale como siempre en Laravel: el grupo es lo que marca la petición.
      // The same exception on a route without the group comes out as usual in Laravel: the group is what marks the request.
      $marked = $this->get('web/group-boom');
      $control = $this->get('web/plain-boom', ['Accept' => 'text/html']);

      $marked->assertStatus(500);
      $this->assertSame(self::MEDIA_TYPE, $marked->headers->get('Content-Type'));
      $this->assertStringNotContainsString('vnd.api+json', (string) $control->headers->get('Content-Type'));
    }

    #[Test]
    public function the_group_comes_before_the_middleware_of_the_app(): void {
      // Sin esperar a `auth`, y sin que `auth` calcule la ruta `login` porque la petición ya pide JSON.
      // Without waiting for `auth`, and without `auth` working out the `login` route because the request already asks for JSON.
      $response = $this->get('web/group-auth');

      $response->assertStatus(401);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    public function inside_the_scope_a_route_with_the_group_is_judged_once(): void {
      $response = $this->call('POST', 'api/group', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => 'application/json'], '{"data":{}}');

      $response->assertStatus(415);
      $this->assertCount(1, $response->json('errors'));
    }

    // ---------------------------------------------------------------------------------------------
    // Options no va en el grupo / Options is not in the group
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_group_alone_sets_no_pagination_options(): void {
      $this->assertNull($this->get('web/group-options', ['Accept' => self::MEDIA_TYPE])->json('options'));
      $this->assertSame(config('json-api.pagination.size'), $this->get('web/group-default-size', ['Accept' => self::MEDIA_TYPE])->json('size'));
    }

    #[Test]
    public function the_options_middleware_is_added_next_to_the_group_when_needed(): void {
      $this->assertSame(7, $this->get('web/group-and-options', ['Accept' => self::MEDIA_TYPE])->json('size'));
    }
  }
