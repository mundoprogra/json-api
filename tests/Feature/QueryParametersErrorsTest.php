<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Support\Settings;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Un parámetro de consulta mal formado es un `400` en JSON:API con `source.parameter` exacto. Todos los problemas de una petición salen juntos
   * en un solo documento, en el orden `include`, `fields`, `sort`, `filter`, `page`. Los textos no repiten lo que mandó el cliente.
   * A malformed query parameter is a JSON:API `400` with an exact `source.parameter`. All the problems of a request come out together in a single
   * document, in the order `include`, `fields`, `sort`, `filter`, `page`. The texts do not repeat what the client sent.
   */
  class QueryParametersErrorsTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $router->get('api/articles', function (Request $request) {
        $query = QueryParameters::from($request, app(Settings::class));

        return response()->json(['include' => $query->includes(), 'sort' => $query->sort(), 'page' => $query->page()]);
      });
    }

    private function fetch(string $queryString): TestResponse {
      return $this->get('api/articles?' . $queryString, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Comprueba que la respuesta es un `400` con exactamente esos parámetros, en ese orden.
     * Checks that the response is a `400` with exactly those parameters, in that order.
     *
     * @param  list<string>  $parameters
     */
    private function assertBadQuery(TestResponse $response, array $parameters, ?string $reason = null): void {
      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame($parameters, array_column(array_column($response->json('errors'), 'source'), 'parameter'));
      $this->assertJsonApiDocument((string) $response->getContent());

      if ($reason !== null) {
        foreach ($response->json('errors') as $error) {
          $this->assertSame($this->english("request.bad_query.reasons.$reason"), $error['detail']);
        }
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Lo válido sigue igual / What is valid stays the same
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_valid_query_goes_through(): void {
      $response = $this->fetch('include=author&sort=-createdAt&page[size]=5');

      $response->assertOk();
      $this->assertSame([['author']], $response->json('include'));
      $this->assertSame(['size' => 5], $response->json('page'));
    }

    #[Test]
    public function a_request_without_query_parameters_goes_through(): void {
      $this->fetch('')->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNames(): array {
      return [
        'camel' => ['createdAt'],
        'kebab' => ['created-at'],
        'snake' => ['created_at'],
        'with digits' => ['address2'],
        'with an accent' => ['café'],
        'a single letter' => ['a'],
      ];
    }

    #[Test]
    #[DataProvider('validNames')]
    public function these_names_are_valid(string $name): void {
      $this->fetch('sort=' . rawurlencode($name))->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Parámetros mal formados / Malformed parameters
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformed(): array {
      return [
        'fields as a plain value' => ['fields=x', 'fields'],
        'filter as a plain value' => ['filter=x', 'filter'],
        'page as a plain value' => ['page=2', 'page'],
        'include as a list' => ['include[]=a', 'include'],
        'sort as a map' => ['sort[a]=b', 'sort'],
        'a list of fields as a map' => ['fields[articles][x]=1', 'fields[articles]'],
      ];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function a_parameter_with_the_wrong_shape_is_a_400_naming_that_parameter(string $queryString, string $parameter): void {
      $this->assertBadQuery($this->fetch($queryString), [$parameter], 'malformed');
    }

    #[Test]
    public function two_filters_that_end_up_with_the_same_name_are_malformed(): void {
      // `createdAt` y `created_at` son el mismo filtro una vez convertidos.
      // `createdAt` and `created_at` are the same filter once converted.
      $this->assertBadQuery($this->fetch('filter[createdAt]=1&filter[created_at]=2'), ['filter'], 'malformed');
    }

    // ---------------------------------------------------------------------------------------------
    // Elementos vacíos / Empty items
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyItems(): array {
      return [
        'a gap in include' => ['include=a,,b', 'include'],
        'only a comma in include' => ['include=,', 'include'],
        'a leading comma in sort' => ['sort=,title', 'sort'],
        'a trailing comma in fields' => ['fields[articles]=title,', 'fields[articles]'],
      ];
    }

    #[Test]
    #[DataProvider('emptyItems')]
    public function an_empty_item_in_a_list_is_a_400(string $queryString, string $parameter): void {
      $this->assertBadQuery($this->fetch($queryString), [$parameter], 'empty_item');
    }

    #[Test]
    public function a_value_that_is_entirely_empty_is_not_an_error_it_means_none(): void {
      $this->fetch('include=&sort=&fields[articles]=')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres inválidos / Invalid names
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array {
      return [
        'a space in include' => ['include=bad%20name', 'include'],
        'a dollar sign in include' => ['include=a%24b', 'include'],
        'a slash in include' => ['include=a%2Fb', 'include'],
        'a semicolon in include' => ['include=x%3Bdrop', 'include'],
        'a leading hyphen in include' => ['include=-bad', 'include'],
        'a trailing hyphen in include' => ['include=bad-', 'include'],
        'a leading underscore in include' => ['include=_bad', 'include'],
        'a bad second level in include' => ['include=author.bad%20name', 'include'],
        'a space in sort' => ['sort=bad%20name', 'sort'],
        'two minus signs in sort' => ['sort=--title', 'sort'],
        'a space in a field' => ['fields[articles]=bad%20name', 'fields[articles]'],
        'a space in a type' => ['fields[bad%20type]=title', 'fields[bad type]'],
        'a space in a filter name' => ['filter[bad%20key]=x', 'filter[bad key]'],
      ];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function a_name_that_is_not_a_valid_member_name_is_a_400(string $queryString, string $parameter): void {
      $this->assertBadQuery($this->fetch($queryString), [$parameter], 'invalid_name');
    }

    #[Test]
    public function the_texts_never_repeat_what_the_client_sent(): void {
      $response = $this->fetch('include=%3Cscript%3Ealert(1)%3C%2Fscript%3E');

      $response->assertStatus(400);
      $this->assertStringNotContainsString('script', (string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // page
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPages(): array {
      return [
        'a size that is not a number' => ['page[size]=abc', 'page[size]'],
        'a size of zero' => ['page[size]=0', 'page[size]'],
        'an empty size' => ['page[size]=', 'page[size]'],
        'a negative number' => ['page[number]=-1', 'page[number]'],
        'a number with decimals' => ['page[number]=1.5', 'page[number]'],
        'a number too big for an integer' => ['page[size]=99999999999999999999', 'page[size]'],
        'a size given as a list' => ['page[size][x]=1', 'page[size]'],
      ];
    }

    #[Test]
    #[DataProvider('invalidPages')]
    public function a_number_or_size_that_is_not_a_positive_integer_is_a_400(string $queryString, string $parameter): void {
      $this->assertBadQuery($this->fetch($queryString), [$parameter], 'invalid_page');
    }

    #[Test]
    public function a_number_with_a_space_is_invalid_for_the_class_itself(): void {
      // Por HTTP, el middleware `TrimStrings` de Laravel recorta el espacio antes de que el paquete lo lea; la clase en sí no lo perdona.
      // Over HTTP, Laravel's `TrimStrings` middleware trims the space before the package reads it; the class itself does not forgive it.
      $request = Request::create('/api/articles?page[number]=%202');

      try {
        QueryParameters::from($request, app(Settings::class));
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $this->assertSame(['page[number]'], array_map(fn($error) => $error->toArray()['source']['parameter'], $e->errors()));
      }
    }

    #[Test]
    public function other_page_keys_are_never_an_error(): void {
      $this->fetch('page[cursor]=anything&page[offset]=abc')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Todos los problemas juntos / All the problems together
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_problem_comes_out_in_a_single_document_in_a_fixed_order(): void {
      $response = $this->fetch('page[size]=0&filter=y&sort=bad%20name&fields=x&include=a,,b');

      $this->assertBadQuery($response, ['include', 'fields', 'sort', 'filter', 'page[size]']);
      $this->assertCount(5, $response->json('errors'));
    }

    #[Test]
    public function a_bad_number_and_a_bad_size_are_two_errors(): void {
      $this->assertBadQuery($this->fetch('page[number]=abc&page[size]=0'), ['page[number]', 'page[size]'], 'invalid_page');
    }

    // ---------------------------------------------------------------------------------------------
    // Cómo es cada error / What each error looks like
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function each_error_has_its_own_status_title_code_and_detail(): void {
      $error = $this->fetch('page[size]=0')->json('errors.0');

      $this->assertSame('400', $error['status']);
      $this->assertSame('mundoprogra.json-api.request.bad_query', $error['code']);
      $this->assertSame($this->english('request.bad_query.title'), $error['title']);
      $this->assertSame($this->english('request.bad_query.reasons.invalid_page'), $error['detail']);
      $this->assertSame(['parameter' => 'page[size]'], $error['source']);
    }

    #[Test]
    public function the_texts_follow_the_language_of_the_app(): void {
      $this->app->setLocale('es');
      $spanish = require __DIR__ . '/../../lang/es/errors.php';

      $error = $this->fetch('page[size]=0')->json('errors.0');

      $this->assertSame(Arr::get($spanish, 'request.bad_query.title'), $error['title']);
      $this->assertSame(Arr::get($spanish, 'request.bad_query.reasons.invalid_page'), $error['detail']);
      $this->assertNotSame($this->english('request.bad_query.title'), $error['title']);
    }

    #[Test]
    public function the_code_follows_the_code_settings(): void {
      config()->set('json-api.errors.code_prefix', 'acme');
      $this->assertSame('acme.request.bad_query', $this->fetch('page[size]=0')->json('errors.0.code'));

      config()->set('json-api.errors.codes', ['request.bad_query' => 'Q-1']);
      $this->assertSame('Q-1', $this->fetch('page[size]=0')->json('errors.0.code'));

      config()->set('json-api.errors.include_code', false);
      $this->assertArrayNotHasKey('code', $this->fetch('page[size]=0')->json('errors.0'));
    }

    // ---------------------------------------------------------------------------------------------
    // Profundidad de include / Depth of include
    // ---------------------------------------------------------------------------------------------

    private function tooDeepDetail(int $max): string {
      return str_replace(':max', (string) $max, $this->english('request.bad_query.reasons.include_too_deep'));
    }

    #[Test]
    public function an_include_path_up_to_the_maximum_depth_goes_through(): void {
      // Por defecto el máximo es 3 niveles.
      // By default the maximum is 3 levels.
      $this->fetch('include=a.b.c')->assertOk();
      $this->fetch('include=a,b.c,d.e.f')->assertOk();
    }

    #[Test]
    public function a_deeper_include_path_is_a_400_naming_include(): void {
      $response = $this->fetch('include=a.b.c.d');

      $response->assertStatus(400);
      $this->assertSame(['include'], array_column(array_column($response->json('errors'), 'source'), 'parameter'));
      $this->assertSame($this->tooDeepDetail(3), $response->json('errors.0.detail'));
      $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_maximum_depth_comes_from_the_settings_on_every_call(): void {
      config()->set('json-api.query.max_include_depth', 1);
      $this->fetch('include=a')->assertOk();
      $this->fetch('include=a.b')->assertStatus(400);

      config()->set('json-api.query.max_include_depth', 5);
      $this->fetch('include=a.b.c.d.e')->assertOk();
      $this->fetch('include=a.b.c.d.e.f')->assertStatus(400);
    }

    #[Test]
    public function the_detail_says_the_maximum_that_applies(): void {
      config()->set('json-api.query.max_include_depth', 2);

      $this->assertSame($this->tooDeepDetail(2), $this->fetch('include=a.b.c')->json('errors.0.detail'));
    }

    #[Test]
    public function a_single_error_is_given_however_many_paths_are_too_deep(): void {
      $response = $this->fetch('include=a.b.c.d,x.y.z.w,ok');

      $this->assertCount(1, $response->json('errors'));
      $this->assertSame('include', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function a_bad_name_is_reported_before_the_depth(): void {
      $this->assertBadQuery($this->fetch('include=a.b.c.d,bad%20name'), ['include'], 'invalid_name');
    }

    #[Test]
    public function the_depth_error_comes_together_with_the_others_in_the_usual_order(): void {
      $this->assertBadQuery($this->fetch('page[size]=0&include=a.b.c.d'), ['include', 'page[size]']);
    }

    #[Test]
    public function only_include_has_a_depth_limit(): void {
      $this->fetch('fields[articles]=a,b,c,d,e&sort=a,b,c,d,e,f')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Parámetros desconocidos / Unknown parameters
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function by_default_an_unknown_parameter_is_ignored(): void {
      $this->fetch('foo=1&bar[baz]=2&signature=abc')->assertOk();
    }

    #[Test]
    public function when_rejecting_an_unknown_parameter_written_only_with_lowercase_letters_is_a_400(): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $response = $this->fetch('foo=1');

      $this->assertBadQuery($response, ['foo'], 'unknown_parameter');
      $this->assertSame($this->english('request.bad_query.title'), $response->json('errors.0.title'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parametersWithAnotherCharacter(): array {
      // Tienen al menos un carácter fuera de `a-z`: la spec los deja como parámetros propios de la implementación.
      // They have at least one character outside `a-z`: the spec leaves them as implementation-specific parameters.
      return [
        'with an underscore' => ['utm_source=mail'],
        'with a capital letter' => ['sortBy=name'],
        'with a hyphen' => ['foo-bar=1'],
        'with a digit' => ['x1=1'],
        'starting with a capital' => ['Foo=1'],
      ];
    }

    #[Test]
    #[DataProvider('parametersWithAnotherCharacter')]
    public function even_when_rejecting_a_parameter_with_another_character_is_always_ignored(string $queryString): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $this->fetch($queryString)->assertOk();
    }

    #[Test]
    public function the_standard_parameters_are_never_unknown(): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $this->fetch('include=author&fields[articles]=title&sort=-title&filter[status]=published&page[size]=5&page[number]=1')->assertOk();
    }

    #[Test]
    public function an_unknown_family_is_reported_by_its_base_name(): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $this->assertBadQuery($this->fetch('foo[bar]=1'), ['foo'], 'unknown_parameter');
    }

    #[Test]
    public function an_unknown_parameter_with_an_empty_value_is_still_unknown(): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $this->assertBadQuery($this->fetch('foo='), ['foo'], 'unknown_parameter');
    }

    #[Test]
    public function every_unknown_parameter_is_reported_after_the_standard_ones_in_the_order_given(): void {
      config()->set('json-api.reject_unknown_query_params', true);

      $this->assertBadQuery($this->fetch('zeta=1&page[size]=0&alpha=2&include=a,,b'), ['include', 'page[size]', 'zeta', 'alpha']);
    }

    #[Test]
    public function the_laravel_signed_url_parameters_are_unknown_to_the_package_when_rejecting(): void {
      // `signature` y `expires` llevan solo `a-z`: con la opción encendida dan 400. Para rutas con URL firmadas se usa `except`.
      // `signature` and `expires` use only `a-z`: with the option on they give 400. For routes with signed URLs `except` is used.
      config()->set('json-api.reject_unknown_query_params', true);

      $this->assertBadQuery($this->fetch('expires=1&signature=abc'), ['expires', 'signature'], 'unknown_parameter');
    }

    #[Test]
    public function the_setting_is_read_on_every_call(): void {
      $this->fetch('foo=1')->assertOk();

      config()->set('json-api.reject_unknown_query_params', true);
      $this->fetch('foo=1')->assertStatus(400);

      config()->set('json-api.reject_unknown_query_params', false);
      $this->fetch('foo=1')->assertOk();
    }
  }
