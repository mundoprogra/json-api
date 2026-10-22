<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Http\Middleware\Options;
  use MundoProgra\JsonApi\Query\PageRequest;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El middleware `Options` (alias `json-api.options`) cambia la paginación de una ruta o de un grupo de rutas:
   * `->middleware('json-api.options:per_page=25,max_per_page=50')`. Es opt-in: no se registra solo. Entra en la cascada de `PageRequest`
   * después del argumento del controlador y antes de la configuración: `page[size]` del cliente → argumento (`perPage`, `maxPerPage`) →
   * opciones del middleware → `pagination.*`. Dos aplicaciones se combinan opción por opción, y la más interna (la de la ruta) manda.
   * Un error al escribir las opciones es un error del programador, no del cliente: `InvalidArgumentException` con el nombre de la opción.
   * The `Options` middleware (alias `json-api.options`) changes the pagination of a route or a group of routes:
   * `->middleware('json-api.options:per_page=25,max_per_page=50')`. It is opt-in: it does not register itself. It enters the `PageRequest`
   * cascade after the controller argument and before the config: client `page[size]` → argument (`perPage`, `maxPerPage`) → middleware
   * options → `pagination.*`. Two applications combine option by option, and the innermost (the route one) wins.
   * A mistake writing the options is a developer error, not the client's: `InvalidArgumentException` naming the option.
   */
  class PageOptionsTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $size = fn(Request $request) => response()->json(['size' => PageRequest::of($request)->size(), 'number' => PageRequest::of($request)->number()]);

      // Sin el middleware: la configuración manda.
      // Without the middleware: the config rules.
      $router->get('api/plain', $size);

      $router->get('api/options', $size)->middleware('json-api.options:per_page=25,max_per_page=50');
      $router->get('api/only-size', $size)->middleware('json-api.options:per_page=25');
      $router->get('api/only-max', $size)->middleware('json-api.options:max_per_page=50');
      $router->get('web/options', $size)->middleware('json-api.options:per_page=25,max_per_page=50');
      $router->get('api/huge-default', $size)->middleware('json-api.options:per_page=500');

      // El argumento del controlador va por encima de las opciones del middleware.
      // The controller argument goes above the middleware options.
      $router->get('api/arg-size', fn(Request $request) => response()->json(['size' => PageRequest::of($request, perPage: 30)->size()]))
        ->middleware('json-api.options:per_page=25,max_per_page=50');
      $router->get('api/arg-max', fn(Request $request) => response()->json(['size' => PageRequest::of($request, maxPerPage: 40)->size()]))
        ->middleware('json-api.options:per_page=25,max_per_page=50');

      // Un grupo y una ruta dentro de él.
      // A group and a route inside it.
      $router->middleware('json-api.options:per_page=10,max_per_page=50')->group(function ($router) use ($size) {
        $router->get('api/grouped', $size);
        $router->get('api/grouped-size', $size)->middleware('json-api.options:per_page=20');
        $router->get('api/grouped-max', $size)->middleware('json-api.options:max_per_page=15');
      });
    }

    private function english(string $key): string {
      return Arr::get(require __DIR__ . '/../../lang/en/errors.php', $key);
    }

    // ---------------------------------------------------------------------------------------------
    // El alias y el opt-in / The alias and the opt-in
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_middleware_has_the_options_alias(): void {
      $this->assertSame(Options::class, $this->app->make('router')->getMiddleware()['json-api.options'] ?? null);
    }

    #[Test]
    public function without_the_middleware_the_configuration_rules(): void {
      $this->assertSame(15, $this->get('api/plain')->json('size'));
      $this->assertSame(100, $this->get('api/plain?page[size]=500')->json('size'));
    }

    #[Test]
    public function a_route_with_the_middleware_does_not_change_the_others(): void {
      $this->assertSame(25, $this->get('api/options')->json('size'));
      $this->assertSame(15, $this->get('api/plain')->json('size'));
    }

    // ---------------------------------------------------------------------------------------------
    // La cascada / The cascade
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_option_size_is_the_default_when_the_client_does_not_ask(): void {
      $this->assertSame(25, $this->get('api/options')->json('size'));
    }

    #[Test]
    public function what_the_client_asks_wins_over_the_option_size(): void {
      $this->assertSame(10, $this->get('api/options?page[size]=10')->json('size'));
    }

    #[Test]
    public function the_option_maximum_replaces_the_configured_one(): void {
      $this->assertSame(50, $this->get('api/options?page[size]=80')->json('size'));
      $this->assertSame(50, $this->get('api/options?page[size]=50')->json('size'));
    }

    #[Test]
    public function the_option_maximum_can_be_higher_than_the_configured_one(): void {
      config()->set('json-api.pagination.max_size', 20);

      $this->assertSame(20, $this->get('api/plain?page[size]=50')->json('size'));
      $this->assertSame(50, $this->get('api/options?page[size]=50')->json('size'));
    }

    #[Test]
    public function with_on_exceed_error_the_option_maximum_is_the_one_in_the_message(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $response = $this->get('api/options?page[size]=80');

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['parameter' => 'page[size]'], $response->json('errors.0.source'));
      $this->assertSame(strtr($this->english('request.bad_query.reasons.page_size_too_large'), [':max' => '50']), $response->json('errors.0.detail'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function only_the_size_option_leaves_the_maximum_to_the_configuration(): void {
      $this->assertSame(25, $this->get('api/only-size')->json('size'));
      $this->assertSame(100, $this->get('api/only-size?page[size]=500')->json('size'));
    }

    #[Test]
    public function only_the_maximum_option_leaves_the_default_size_to_the_configuration(): void {
      $this->assertSame(15, $this->get('api/only-max')->json('size'));
      $this->assertSame(50, $this->get('api/only-max?page[size]=500')->json('size'));
    }

    #[Test]
    public function the_maximum_option_trims_the_configured_default_size_too(): void {
      config()->set('json-api.pagination.size', 90);

      $this->assertSame(50, $this->get('api/only-max')->json('size'));
    }

    #[Test]
    public function an_option_size_above_the_maximum_is_trimmed_without_an_error_even_with_on_exceed_error(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $this->assertSame(100, $this->get('api/huge-default')->json('size'));
    }

    #[Test]
    public function the_controller_argument_goes_above_the_option(): void {
      $this->assertSame(30, $this->get('api/arg-size')->json('size'));
      $this->assertSame(10, $this->get('api/arg-size?page[size]=10')->json('size'));
      $this->assertSame(40, $this->get('api/arg-max?page[size]=45')->json('size'));
      $this->assertSame(25, $this->get('api/arg-max')->json('size'));
    }

    #[Test]
    public function the_configuration_is_still_read_each_time(): void {
      config()->set('json-api.pagination.size', 30);

      $this->assertSame(30, $this->get('api/plain')->json('size'));
      $this->assertSame(25, $this->get('api/options')->json('size'));
    }

    #[Test]
    public function it_does_not_touch_the_page_number(): void {
      $this->assertSame(3, $this->get('api/options?page[number]=3')->json('number'));
      $this->assertSame(1, $this->get('api/options')->json('number'));
    }

    #[Test]
    public function it_also_works_outside_the_scope_of_the_package(): void {
      // Las opciones son de la ruta: no dependen de que el paquete actúe sobre ella.
      // The options belong to the route: they do not depend on the package acting on it.
      $this->assertSame(25, $this->get('web/options', ['Accept' => 'text/html'])->json('size'));
    }

    // ---------------------------------------------------------------------------------------------
    // Grupos y rutas / Groups and routes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_group_sets_the_options_for_its_routes(): void {
      $this->assertSame(10, $this->get('api/grouped')->json('size'));
      $this->assertSame(50, $this->get('api/grouped?page[size]=99')->json('size'));
    }

    #[Test]
    public function the_route_overrides_the_group_only_in_the_options_it_sets(): void {
      // La ruta cambia el tamaño; el máximo del grupo sigue valiendo.
      // The route changes the size; the group maximum still holds.
      $this->assertSame(20, $this->get('api/grouped-size')->json('size'));
      $this->assertSame(50, $this->get('api/grouped-size?page[size]=99')->json('size'));

      // La ruta cambia el máximo; el tamaño del grupo sigue valiendo, recortado al nuevo máximo si hace falta.
      // The route changes the maximum; the group size still holds, trimmed to the new maximum if needed.
      $this->assertSame(10, $this->get('api/grouped-max')->json('size'));
      $this->assertSame(15, $this->get('api/grouped-max?page[size]=99')->json('size'));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que el programador escribe mal / What the developer writes wrong
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function badOptions(): array {
      // [las opciones tal como llegan a handle(), lo que debe decir el mensaje] / [the options as they reach handle(), what the message must say]
      return [
        'no options at all' => [[], 'at least one'],
        'an unknown option' => [['per_pag=5'], 'per_pag'],
        'an option without a value sign' => [['per_page'], 'per_page'],
        'an option without a name' => [['=5'], '=5'],
        'per_page zero' => [['per_page=0'], 'per_page'],
        'per_page negative' => [['per_page=-1'], 'per_page'],
        'per_page text' => [['per_page=abc'], 'per_page'],
        'per_page empty' => [['per_page='], 'per_page'],
        'per_page decimal' => [['per_page=2.5'], 'per_page'],
        'per_page with spaces' => [['per_page= 5'], 'per_page'],
        'max_per_page zero' => [['max_per_page=0'], 'max_per_page'],
        'max_per_page text' => [['max_per_page=lots'], 'max_per_page'],
        'a repeated option' => [['per_page=1', 'per_page=2'], 'per_page'],
      ];
    }

    /**
     * @param  list<string>  $options
     */
    #[Test]
    #[DataProvider('badOptions')]
    public function a_badly_written_option_is_a_developer_error_that_names_it(array $options, string $mentions): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage($mentions);

      $this->app->make(Options::class)->handle(Request::create('/api/articles'), fn() => response('ok'), ...$options);
    }

    #[Test]
    public function the_middleware_lets_the_response_through_untouched(): void {
      $response = $this->app->make(Options::class)->handle(Request::create('/api/articles'), fn() => response('as the route made it', 202), 'per_page=5');

      $this->assertSame(202, $response->getStatusCode());
      $this->assertSame('as the route made it', $response->getContent());
    }

    #[Test]
    public function the_options_reach_the_page_request_of_the_same_request_object(): void {
      $plain = Request::create('/api/articles');
      $asking = Request::create('/api/articles?page[size]=50');

      $this->app->make(Options::class)->handle($plain, fn() => response('ok'), 'per_page=7', 'max_per_page=9');
      $this->app->make(Options::class)->handle($asking, fn() => response('ok'), 'per_page=7', 'max_per_page=9');

      $this->assertSame(7, PageRequest::of($plain)->size());
      $this->assertSame(9, PageRequest::of($asking)->size());
    }
  }
