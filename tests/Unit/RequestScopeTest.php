<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  /**
   * La única regla que decide si el paquete actúa sobre una petición. De mayor a menor:
   * 1) la ruta marcada a mano con el middleware, 2) `except`, 3) la petición que ya pide el media type de JSON:API, 4) `mode`.
   * The one rule that decides whether the package acts on a request. From highest to lowest:
   * 1) the route marked by hand with the middleware, 2) `except`, 3) a request that already asks for the JSON:API media type, 4) `mode`.
   */
  class RequestScopeTest extends TestCase {
    // ---------------------------------------------------------------------------------------------
    // mode = prefix: se compara por segmentos, sobre la ruta de la URL / compared by segments, on the URL path
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pathsWithTheDefaultPrefix(): array {
      return [
        'the prefix itself' => ['/api', true],
        'under the prefix' => ['/api/users', true],
        'deep under the prefix' => ['/api/v1/users/1', true],
        'with a trailing slash' => ['/api/', true],
        'a path that only starts with the same letters' => ['/apix/users', false],
        'a path that only starts with the same letters, deeper' => ['/apiary', false],
        'a web page' => ['/web/users', false],
        'the home page' => ['/', false],
        'the prefix as a later segment' => ['/users/api', false],
        'the prefix only in the query string' => ['/web/users?next=/api/users', false],
        'in uppercase, as routes are case sensitive' => ['/API/users', false],
      ];
    }

    #[Test]
    #[DataProvider('pathsWithTheDefaultPrefix')]
    public function in_prefix_mode_it_applies_to_the_paths_under_the_prefix(string $uri, bool $expected): void {
      $this->assertSame($expected, $this->scope()->appliesTo(Request::create($uri)), $uri);
    }

    #[Test]
    public function a_prefix_can_have_several_segments(): void {
      $scope = $this->scope(['json-api.path_prefix' => 'api/v1']);

      $this->assertTrue($scope->appliesTo(Request::create('/api/v1')));
      $this->assertTrue($scope->appliesTo(Request::create('/api/v1/users')));
      $this->assertFalse($scope->appliesTo(Request::create('/api/v2/users')));
      $this->assertFalse($scope->appliesTo(Request::create('/api')));
    }

    #[Test]
    public function there_can_be_several_prefixes(): void {
      $scope = $this->scope(['json-api.path_prefix' => ['api', 'partners/api']]);

      $this->assertTrue($scope->appliesTo(Request::create('/api/users')));
      $this->assertTrue($scope->appliesTo(Request::create('/partners/api/users')));
      $this->assertFalse($scope->appliesTo(Request::create('/partners/users')));
    }

    #[Test]
    public function slashes_around_the_prefix_do_not_matter(): void {
      $this->assertTrue($this->scope(['json-api.path_prefix' => '/api/'])->appliesTo(Request::create('/api/users')));
    }

    // ---------------------------------------------------------------------------------------------
    // mode = global
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function anyPath(): array {
      return [
        'the home page' => ['/'],
        'a web page' => ['/web/users'],
        'a path under the prefix' => ['/api/users'],
        'a path that does not exist' => ['/no/such/path'],
      ];
    }

    #[Test]
    #[DataProvider('anyPath')]
    public function in_global_mode_it_applies_to_every_path(string $uri): void {
      $this->assertTrue($this->scope(['json-api.mode' => 'global'])->appliesTo(Request::create($uri)), $uri);
    }

    // ---------------------------------------------------------------------------------------------
    // except: rutas que el paquete nunca toca / routes the package never touches
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function in_global_mode_the_excepted_paths_are_left_out(): void {
      $scope = $this->scope(['json-api.mode' => 'global']);

      $this->assertFalse($scope->appliesTo(Request::create('/up')));
      $this->assertTrue($scope->appliesTo(Request::create('/upload')));
      $this->assertTrue($scope->appliesTo(Request::create('/up/status')));
    }

    #[Test]
    public function in_prefix_mode_the_excepted_paths_under_the_prefix_are_left_out(): void {
      $scope = $this->scope(['json-api.except' => ['api/health', 'api/docs/*']]);

      $this->assertFalse($scope->appliesTo(Request::create('/api/health')));
      $this->assertFalse($scope->appliesTo(Request::create('/api/docs/openapi.json')));
      $this->assertFalse($scope->appliesTo(Request::create('/api/docs/v1/index')));
      $this->assertTrue($scope->appliesTo(Request::create('/api/healthy')));
      $this->assertTrue($scope->appliesTo(Request::create('/api/users')));
    }

    #[Test]
    public function an_empty_except_list_leaves_nothing_out(): void {
      $this->assertTrue($this->scope(['json-api.mode' => 'global', 'json-api.except' => []])->appliesTo(Request::create('/up')));
    }

    // ---------------------------------------------------------------------------------------------
    // Una petición que ya pide el media type de JSON:API / A request that already asks for the JSON:API media type
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function acceptHeadersOutsideThePrefix(): array {
      return [
        'the json:api media type' => ['application/vnd.api+json', true],
        'in uppercase' => ['APPLICATION/VND.API+JSON', true],
        'with a quality value' => ['application/vnd.api+json;q=0.8', true],
        'with parameters, so the negotiation can answer 406 as JSON:API' => ['application/vnd.api+json; foo=bar', true],
        'among other types' => ['text/html, application/vnd.api+json;q=0.8', true],
        'application/json is not enough' => ['application/json', false],
        'any type is not enough' => ['*/*', false],
        'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false],
        'a similar but different type' => ['application/vnd.api+jsonx', false],
        'no header' => ['', false],
      ];
    }

    #[Test]
    #[DataProvider('acceptHeadersOutsideThePrefix')]
    public function a_request_that_asks_for_the_media_type_applies_even_outside_the_prefix(string $accept, bool $expected): void {
      $request = Request::create('/web/users', 'GET', [], [], [], $accept === '' ? [] : ['HTTP_ACCEPT' => $accept]);

      $this->assertSame($expected, $this->scope()->appliesTo($request), $accept);
    }

    // ---------------------------------------------------------------------------------------------
    // Precedencia / Precedence
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function except_wins_over_a_request_that_asks_for_the_media_type(): void {
      $request = Request::create('/up', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/vnd.api+json']);

      $this->assertFalse($this->scope()->appliesTo($request));
      $this->assertFalse($this->scope(['json-api.mode' => 'global'])->appliesTo($request));
    }

    #[Test]
    public function a_route_marked_by_hand_applies_even_outside_the_prefix(): void {
      $scope = $this->scope();
      $request = Request::create('/web/users');

      // Antes de marcarla no aplica: sin esto, el resultado podría deberse a otra cosa.
      // It does not apply before being marked: without this, the result could be due to something else.
      $this->assertFalse($scope->appliesTo($request));

      $scope->mark($request);

      $this->assertTrue($scope->appliesTo($request));
    }

    #[Test]
    public function a_route_marked_by_hand_wins_over_except(): void {
      $scope = $this->scope();
      $request = Request::create('/up');

      $this->assertFalse($scope->appliesTo($request));

      $scope->mark($request);

      $this->assertTrue($scope->appliesTo($request));
    }

    #[Test]
    public function marking_one_request_does_not_affect_another(): void {
      $scope = $this->scope();
      $marked = Request::create('/web/users');
      $other = Request::create('/web/users');

      $scope->mark($marked);

      $this->assertTrue($scope->appliesTo($marked));
      $this->assertFalse($scope->appliesTo($other));
    }

    // ---------------------------------------------------------------------------------------------
    // La configuración se lee en cada llamada / The config is read on every call
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_follows_config_changes_made_after_it_was_created(): void {
      $config = new Repository(['json-api' => $this->defaultConfig(), 'app' => ['debug' => false]]);
      $scope = new RequestScope(new Settings($config));
      $request = Request::create('/web/users');

      $this->assertFalse($scope->appliesTo($request));

      $config->set('json-api.mode', 'global');

      $this->assertTrue($scope->appliesTo($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array {
      return require __DIR__ . '/../../config/json-api.php';
    }

    /**
     * @param  array<string, mixed>  $overrides  Claves con punto. / Dotted keys.
     */
    private function scope(array $overrides = []): RequestScope {
      $config = ['json-api' => $this->defaultConfig(), 'app' => ['debug' => false]];

      foreach ($overrides as $key => $value) {
        Arr::set($config, $key, $value);
      }

      return new RequestScope(new Settings(new Repository($config)));
    }
  }
