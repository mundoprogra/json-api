<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\Middleware\Options;
  use MundoProgra\JsonApi\Query\Allowed;
  use MundoProgra\JsonApi\Query\ListCriteria;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `ListCriteria` es la consulta de un listado ya validada y sin nada de Laravel: los filtros, el orden, las inclusiones y la página (número y
   * tamaño) que una app con Clean Architecture le pasa a su repositorio. Se obtiene con `$request->jsonApiQuery()->criteria(Allowed $allowed)`:
   * los nombres salen en `snake_case`, un valor de filtro vacío se descarta, el orden por defecto se aplica si el cliente no pide ninguno y la
   * página sale calculada como en `jsonApiPaginate()`. Lo que el cliente pida fuera de `Allowed` es un `400`, y todas las violaciones salen
   * JUNTAS (en el orden `include`, `sort`, `filter`, `page`), con el nombre del filtro tal como lo escribió el cliente. Una app también puede
   * construirla a mano (en una prueba, desde una consola).
   * `ListCriteria` is the already validated query of a listing with nothing from Laravel in it: the filters, the sort, the includes and the page
   * (number and size) that a Clean Architecture app hands to its repository. It comes from `$request->jsonApiQuery()->criteria(Allowed $allowed)`:
   * names come out in `snake_case`, an empty filter value is dropped, the default sort applies if the client asks for none and the page is worked
   * out as in `jsonApiPaginate()`. Whatever the client asks for outside `Allowed` is a `400`, and ALL the violations come out TOGETHER (in the order
   * `include`, `sort`, `filter`, `page`), with the filter name as the client wrote it. An app can also build it by hand (in a test, from a console).
   */
  class ListCriteriaTest extends TestCase {
    private function request(string $queryString): Request {
      return Request::create('/api/users?' . $queryString);
    }

    private function criteria(string $queryString, ?Allowed $allowed = null, ?int $perPage = null, ?int $maxPerPage = null): ListCriteria {
      $allowed ??= Allowed::make(filter: ['status', 'name', 'blocked_by'], sort: ['created_at', 'name'], include: ['roles', 'roles.permissions']);

      return QueryParameters::of($this->request($queryString))->criteria($allowed, $perPage, $maxPerPage);
    }

    /**
     * Los `source.parameter` de los errores del `400` que sale al pedir esta consulta, en su orden.
     * The `source.parameter` of the errors of the `400` raised by asking for this query, in their order.
     *
     * @return list<string|null>
     */
    private function parametersOf(string $queryString, ?Allowed $allowed = null): array {
      try {
        $this->criteria($queryString, $allowed);
      } catch (JsonApiException $e) {
        $this->assertSame(400, $e->httpStatus());

        return array_map(fn($error) => $error->toArray()['source']['parameter'] ?? null, $e->errors());
      }

      $this->fail('A JsonApiException was expected.');
    }

    // ---------------------------------------------------------------------------------------------
    // El objeto de valor / The value object
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_holds_what_it_is_given(): void {
      $criteria = new ListCriteria(
        filters: ['status' => 'active'],
        sort: [['field' => 'created_at', 'descending' => true]],
        includes: ['roles.permissions'],
        pageNumber: 3,
        pageSize: 20,
      );

      $this->assertSame(['status' => 'active'], $criteria->filters());
      $this->assertSame([['field' => 'created_at', 'descending' => true]], $criteria->sort());
      $this->assertSame(['roles.permissions'], $criteria->includes());
      $this->assertSame(3, $criteria->pageNumber());
      $this->assertSame(20, $criteria->pageSize());
    }

    #[Test]
    public function by_default_it_has_no_filters_no_sort_no_includes_and_the_first_page(): void {
      $criteria = new ListCriteria(pageSize: 15);

      $this->assertSame([], $criteria->filters());
      $this->assertSame([], $criteria->sort());
      $this->assertSame([], $criteria->includes());
      $this->assertSame(1, $criteria->pageNumber());
    }

    #[Test]
    public function the_offset_is_the_rows_before_the_page(): void {
      $this->assertSame(0, (new ListCriteria(pageNumber: 1, pageSize: 20))->offset());
      $this->assertSame(40, (new ListCriteria(pageNumber: 3, pageSize: 20))->offset());
    }

    #[Test]
    public function a_filter_is_read_by_name_and_is_null_when_it_was_not_asked_for(): void {
      $criteria = new ListCriteria(filters: ['status' => 'active'], pageSize: 15);

      $this->assertSame('active', $criteria->filter('status'));
      $this->assertNull($criteria->filter('name'));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function invalidPages(): array {
      return [
        'a page number of zero' => [0, 15],
        'a negative page number' => [-1, 15],
        'a page size of zero' => [1, 0],
        'a negative page size' => [1, -5],
      ];
    }

    #[Test]
    #[DataProvider('invalidPages')]
    public function a_page_number_or_size_below_one_is_a_programmer_error(int $number, int $size): void {
      $this->expectException(InvalidArgumentException::class);

      new ListCriteria(pageNumber: $number, pageSize: $size);
    }

    #[Test]
    public function the_neutral_classes_import_nothing_from_laravel(): void {
      // Es lo que permite que `Application` use `ListCriteria` y `Allowed` sin depender del framework.
      // It is what lets `Application` use `ListCriteria` and `Allowed` without depending on the framework.
      foreach (['ListCriteria', 'Allowed'] as $class) {
        $path = __DIR__ . '/../../src/Query/' . $class . '.php';

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertSame(0, preg_match('/^\s*use\s+Illuminate\\\\/m', $source), "$class imports a class from Illuminate.");
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que sale de la petición / What comes out of the request
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_filters_come_out_by_snake_case_name_with_the_client_values(): void {
      $criteria = $this->criteria('filter[status]=active&filter[blockedBy]=1');

      $this->assertSame(['status' => 'active', 'blocked_by' => '1'], $criteria->filters());
    }

    #[Test]
    public function an_empty_filter_value_is_left_out(): void {
      $criteria = $this->criteria('filter[status]=&filter[name]=Ada');

      $this->assertSame(['name' => 'Ada'], $criteria->filters());
    }

    #[Test]
    public function the_sort_comes_out_in_the_order_asked_for_with_snake_case_names(): void {
      $criteria = $this->criteria('sort=-createdAt,name');

      $this->assertSame([
        ['field' => 'created_at', 'descending' => true],
        ['field' => 'name', 'descending' => false],
      ], $criteria->sort());
    }

    #[Test]
    public function the_default_sort_applies_when_the_client_asks_for_none_and_loses_when_it_asks_for_one(): void {
      $allowed = Allowed::make(sort: ['created_at', 'name'], defaultSort: '-created_at');

      $this->assertSame([['field' => 'created_at', 'descending' => true]], $this->criteria('', $allowed)->sort());
      $this->assertSame([['field' => 'name', 'descending' => false]], $this->criteria('sort=name', $allowed)->sort());
    }

    #[Test]
    public function the_includes_come_out_as_dotted_snake_case_paths(): void {
      $allowed = Allowed::make(include: ['blog_posts.author_profile', 'roles']);

      $this->assertSame(['blog_posts.author_profile', 'roles'], $this->criteria('include=blogPosts.authorProfile,roles', $allowed)->includes());
    }

    #[Test]
    public function an_include_prefix_of_an_allowed_path_is_allowed(): void {
      $this->assertSame(['roles'], $this->criteria('include=roles')->includes());
    }

    #[Test]
    public function a_request_without_a_query_gives_empty_filters_sort_and_includes(): void {
      $criteria = $this->criteria('');

      $this->assertSame([], $criteria->filters());
      $this->assertSame([], $criteria->sort());
      $this->assertSame([], $criteria->includes());
    }

    // ---------------------------------------------------------------------------------------------
    // La página / The page
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_is_the_one_the_client_asked_for(): void {
      $criteria = $this->criteria('page[number]=3&page[size]=5');

      $this->assertSame(3, $criteria->pageNumber());
      $this->assertSame(5, $criteria->pageSize());
    }

    #[Test]
    public function without_a_page_it_is_the_first_one_with_the_configured_size(): void {
      config()->set('json-api.pagination.size', 20);

      $criteria = $this->criteria('');

      $this->assertSame(1, $criteria->pageNumber());
      $this->assertSame(20, $criteria->pageSize());
    }

    #[Test]
    public function the_default_size_can_be_given_by_the_caller_and_by_the_middleware_options(): void {
      $this->assertSame(7, $this->criteria('', perPage: 7)->pageSize());

      $request = $this->request('');
      $request->attributes->set(Options::ATTRIBUTE, ['per_page' => 9]);

      $this->assertSame(9, QueryParameters::of($request)->criteria(Allowed::make())->pageSize());
    }

    #[Test]
    public function a_size_above_the_maximum_is_trimmed_by_default(): void {
      $this->assertSame(10, $this->criteria('page[size]=50', maxPerPage: 10)->pageSize());
    }

    // ---------------------------------------------------------------------------------------------
    // Los errores del cliente, todos juntos / The client errors, all together
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_filter_outside_the_allowed_list_is_a_400_named_as_the_client_wrote_it(): void {
      $this->assertSame(['filter[blockedBy]'], $this->parametersOf('filter[blockedBy]=1', Allowed::make(filter: ['status'])));
    }

    #[Test]
    public function a_filter_name_in_the_kebab_style_is_reported_as_written(): void {
      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(['filter[blocked-by]'], $this->parametersOf('filter[blocked-by]=1', Allowed::make(filter: ['status'])));
    }

    #[Test]
    public function a_sort_outside_the_allowed_list_is_a_single_400_on_sort(): void {
      $this->assertSame(['sort'], $this->parametersOf('sort=nope,-other', Allowed::make(sort: ['name'])));
    }

    #[Test]
    public function an_include_outside_the_allowed_list_is_a_single_400_on_include(): void {
      $this->assertSame(['include'], $this->parametersOf('include=nope,other', Allowed::make(include: ['roles'])));
    }

    #[Test]
    public function an_include_deeper_than_the_allowed_path_is_a_400(): void {
      $this->assertSame(['include'], $this->parametersOf('include=roles.permissions', Allowed::make(include: ['roles'])));
    }

    #[Test]
    public function several_filters_outside_the_list_give_one_error_each_in_the_order_received(): void {
      $this->assertSame(['filter[beta]', 'filter[alpha]'], $this->parametersOf('filter[beta]=1&filter[status]=a&filter[alpha]=1', Allowed::make(filter: ['status'])));
    }

    #[Test]
    public function a_filter_value_that_is_not_text_is_a_400_on_that_filter(): void {
      $this->assertSame(['filter[status]'], $this->parametersOf('filter[status][]=a', Allowed::make(filter: ['status'])));
    }

    #[Test]
    public function every_violation_comes_out_together_in_the_order_include_sort_filter_page(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $parameters = $this->parametersOf('page[size]=1000&filter[nope]=1&sort=-nope&include=nope', Allowed::make());

      $this->assertSame(['include', 'sort', 'filter[nope]', 'page[size]'], $parameters);
    }

    #[Test]
    public function a_request_with_only_a_page_error_gives_that_single_error(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $this->assertSame(['page[size]'], $this->parametersOf('page[size]=1000', Allowed::make()));
    }

    // ---------------------------------------------------------------------------------------------
    // La consulta no cambia / The query does not change
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_same_parsed_query_can_be_checked_against_two_lists_independently(): void {
      $query = QueryParameters::of($this->request('filter[status]=active&sort=name'));

      $wide = $query->criteria(Allowed::make(filter: ['status'], sort: ['name']));

      $this->assertSame(['status' => 'active'], $wide->filters());

      try {
        $query->criteria(Allowed::make());
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $this->assertCount(2, $e->errors());
      }

      $this->assertSame(['status' => 'active'], $query->criteria(Allowed::make(filter: ['status'], sort: ['name']))->filters());
    }
  }
