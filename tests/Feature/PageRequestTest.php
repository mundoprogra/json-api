<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Closure;
  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Query\PageRequest;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `PageRequest` dice qué página y de qué tamaño hay que traer: el número y el tamaño efectivos de una petición.
   * El número es `page[number]` o 1. El tamaño es `page[size]` si el cliente lo pidió; si no, el argumento del controlador (`perPage`) y, si
   * tampoco, `pagination.size`. Después se acota por el máximo (`maxPerPage` o `pagination.max_size`): lo que pide el cliente por encima
   * del máximo se recorta (`on_exceed = clamp`, por defecto) o es un `400` con `source.parameter = page[size]` (`on_exceed = error`).
   * Lo que pone el programador (por defecto o argumento) por encima del máximo se recorta siempre, sin error: no es culpa del cliente.
   * La configuración se lee en cada llamada. Los valores inválidos del cliente (cero, negativos, texto) ya son un `400` en `QueryParameters`.
   * `PageRequest` says which page and of what size to fetch: the effective number and size of a request.
   * The number is `page[number]` or 1. The size is `page[size]` if the client asked for it; otherwise the controller argument (`perPage`) and,
   * failing that, `pagination.size`. Then it is capped by the maximum (`maxPerPage` or `pagination.max_size`): what the client asks for above the
   * maximum is trimmed (`on_exceed = clamp`, the default) or is a `400` with `source.parameter = page[size]` (`on_exceed = error`).
   * What the developer sets (default or argument) above the maximum is always trimmed, with no error: it is not the client's fault.
   * The config is read on every call. The client's invalid values (zero, negatives, text) are already a `400` in `QueryParameters`.
   */
  class PageRequestTest extends TestCase {
    use ReadsPackageTexts;

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    /**
     * @param  array<string, mixed>  $page  la familia `page` ya interpretada, como la entrega PHP / the `page` family already parsed, as PHP gives it
     */
    private function pageOf(array $page = [], ?int $perPage = null, ?int $maxPerPage = null): PageRequest {
      $query = $page === [] ? [] : ['page' => $page];

      return PageRequest::of(Request::create('/api/articles', 'GET', $query), $perPage, $maxPerPage);
    }

    /**
     * @param  Closure(): mixed  $call
     */
    private function errorOf(Closure $call): JsonApiException {
      try {
        $call();
      } catch (JsonApiException $e) {
        return $e;
      }

      $this->fail('Expected a JsonApiException.');
    }

    // ---------------------------------------------------------------------------------------------
    // Los valores por defecto y lo que pide el cliente / The defaults and what the client asks for
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_page_it_is_the_first_page_with_the_configured_size(): void {
      $page = $this->pageOf();

      $this->assertSame(1, $page->number());
      $this->assertSame(15, $page->size());
    }

    #[Test]
    public function the_client_asks_for_the_number_and_the_size(): void {
      $page = $this->pageOf(['number' => '3', 'size' => '25']);

      $this->assertSame(3, $page->number());
      $this->assertSame(25, $page->size());
    }

    #[Test]
    public function the_number_and_the_size_can_come_alone(): void {
      $this->assertSame([3, 15], $this->numbers($this->pageOf(['number' => '3'])));
      $this->assertSame([1, 40], $this->numbers($this->pageOf(['size' => '40'])));
    }

    /**
     * @return array{int, int}
     */
    private function numbers(PageRequest $page): array {
      return [$page->number(), $page->size()];
    }

    #[Test]
    public function the_default_size_is_read_from_the_configuration_each_time(): void {
      $this->assertSame(15, $this->pageOf()->size());

      config()->set('json-api.pagination.size', 30);

      $this->assertSame(30, $this->pageOf()->size());
    }

    #[Test]
    public function the_other_keys_of_the_page_family_do_not_matter(): void {
      $this->assertSame([2, 15], $this->numbers($this->pageOf(['number' => '2', 'cursor' => 'abc'])));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que pide el cliente por encima del máximo / What the client asks for above the maximum
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_size_above_the_maximum_is_trimmed_to_it_by_default(): void {
      $this->assertSame(100, $this->pageOf(['size' => '500'])->size());
    }

    #[Test]
    public function a_size_at_or_below_the_maximum_is_left_as_it_is(): void {
      $this->assertSame(100, $this->pageOf(['size' => '100'])->size());
      $this->assertSame(99, $this->pageOf(['size' => '99'])->size());
    }

    #[Test]
    public function the_maximum_is_read_from_the_configuration_each_time(): void {
      config()->set('json-api.pagination.max_size', 20);

      $this->assertSame(20, $this->pageOf(['size' => '50'])->size());
    }

    #[Test]
    public function with_on_exceed_error_a_size_above_the_maximum_is_a_400_that_points_to_the_size(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $errors = $this->errorOf(fn() => $this->pageOf(['size' => '500']))->errors();

      $this->assertCount(1, $errors);
      $error = $errors[0]->toArray();

      $this->assertSame('400', $error['status']);
      $this->assertSame('mundoprogra.json-api.request.bad_query', $error['code']);
      $this->assertSame($this->english('request.bad_query.title'), $error['title']);
      $this->assertSame(['parameter' => 'page[size]'], $error['source']);
      $this->assertSame(strtr($this->english('request.bad_query.reasons.page_size_too_large'), [':max' => '100']), $error['detail']);
    }

    #[Test]
    public function with_on_exceed_error_a_size_at_the_maximum_is_still_fine(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $this->assertSame(100, $this->pageOf(['size' => '100'])->size());
    }

    #[Test]
    public function the_error_names_the_maximum_that_is_in_force(): void {
      config()->set('json-api.pagination.on_exceed', 'error');
      config()->set('json-api.pagination.max_size', 20);

      $this->assertStringContainsString('20', $this->errorOf(fn() => $this->pageOf(['size' => '50']))->errors()[0]->toArray()['detail']);
    }

    #[Test]
    public function the_error_text_follows_the_language_of_the_application(): void {
      config()->set('json-api.pagination.on_exceed', 'error');
      $this->app->setLocale('es');

      $detail = $this->errorOf(fn() => $this->pageOf(['size' => '500']))->errors()[0]->toArray()['detail'];

      $this->assertSame(strtr($this->spanish('request.bad_query.reasons.page_size_too_large'), [':max' => '100']), $detail);
    }

    #[Test]
    public function the_error_follows_the_code_switch(): void {
      config()->set('json-api.pagination.on_exceed', 'error');
      config()->set('json-api.errors.include_code', false);

      $this->assertArrayNotHasKey('code', $this->errorOf(fn() => $this->pageOf(['size' => '500']))->errors()[0]->toArray());
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que el cliente manda mal ya es un 400 en QueryParameters / What the client sends wrong is already a 400 in QueryParameters
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPages(): array {
      return [
        'size zero' => [['size' => '0'], 'page[size]'],
        'size negative' => [['size' => '-5'], 'page[size]'],
        'size text' => [['size' => 'abc'], 'page[size]'],
        'size decimal' => [['size' => '2.5'], 'page[size]'],
        'number zero' => [['number' => '0'], 'page[number]'],
        'number text' => [['number' => 'x'], 'page[number]'],
      ];
    }

    /**
     * @param  array<string, mixed>  $page
     */
    #[Test]
    #[DataProvider('invalidPages')]
    public function an_invalid_number_or_size_is_a_400_that_points_to_it(array $page, string $parameter): void {
      $errors = $this->errorOf(fn() => $this->pageOf($page))->errors();

      $this->assertSame($parameter, $errors[0]->toArray()['source']['parameter']);
      $this->assertSame('400', $errors[0]->toArray()['status']);
    }

    #[Test]
    public function a_page_that_is_not_a_family_is_a_400(): void {
      $request = Request::create('/api/articles', 'GET', ['page' => '2']);

      $errors = $this->errorOf(fn() => PageRequest::of($request))->errors();

      $this->assertSame('page', $errors[0]->toArray()['source']['parameter']);
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que decide el programador / What the developer decides
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_controller_size_is_the_default_when_the_client_does_not_ask(): void {
      $this->assertSame(30, $this->pageOf(perPage: 30)->size());
    }

    #[Test]
    public function what_the_client_asks_wins_over_the_controller_size(): void {
      $this->assertSame(10, $this->pageOf(['size' => '10'], perPage: 30)->size());
    }

    #[Test]
    public function the_controller_maximum_replaces_the_configured_one(): void {
      $this->assertSame(50, $this->pageOf(['size' => '80'], maxPerPage: 50)->size());
      $this->assertSame(150, $this->pageOf(['size' => '150'], maxPerPage: 200)->size());
    }

    #[Test]
    public function with_on_exceed_error_the_controller_maximum_is_the_one_that_counts(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      $this->assertSame(150, $this->pageOf(['size' => '150'], maxPerPage: 200)->size());

      $detail = $this->errorOf(fn() => $this->pageOf(['size' => '80'], maxPerPage: 50))->errors()[0]->toArray()['detail'];

      $this->assertSame(strtr($this->english('request.bad_query.reasons.page_size_too_large'), [':max' => '50']), $detail);
    }

    #[Test]
    public function a_default_above_the_maximum_is_trimmed_without_an_error_even_with_on_exceed_error(): void {
      config()->set('json-api.pagination.on_exceed', 'error');

      // Ni el tamaño por defecto de la configuración ni el del controlador son culpa del cliente.
      // Neither the configured default size nor the controller one is the client's fault.
      config()->set('json-api.pagination.size', 500);
      $this->assertSame(100, $this->pageOf()->size());

      config()->set('json-api.pagination.size', 15);
      $this->assertSame(100, $this->pageOf(perPage: 500)->size());
      $this->assertSame(40, $this->pageOf(perPage: 500, maxPerPage: 40)->size());
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function invalidArguments(): array {
      return [
        'perPage zero' => [0, null],
        'perPage negative' => [-1, null],
        'maxPerPage zero' => [null, 0],
        'maxPerPage negative' => [null, -10],
      ];
    }

    #[Test]
    #[DataProvider('invalidArguments')]
    public function a_size_or_maximum_that_is_not_positive_is_a_developer_error(?int $perPage, ?int $maxPerPage): void {
      $this->expectException(InvalidArgumentException::class);

      $this->pageOf(perPage: $perPage, maxPerPage: $maxPerPage);
    }

    // ---------------------------------------------------------------------------------------------
    // El valor es inmutable y se puede repetir / The value is immutable and repeatable
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function asking_twice_for_the_same_request_gives_the_same_answer(): void {
      $request = Request::create('/api/articles', 'GET', ['page' => ['number' => '2', 'size' => '500']]);

      $first = PageRequest::of($request);
      $second = PageRequest::of($request);

      $this->assertSame([2, 100], $this->numbers($first));
      $this->assertSame($this->numbers($first), $this->numbers($second));
    }

    #[Test]
    public function the_size_follows_the_configuration_for_the_same_request_object(): void {
      $request = Request::create('/api/articles', 'GET');

      $this->assertSame(15, PageRequest::of($request)->size());

      config()->set('json-api.pagination.size', 40);

      $this->assertSame(40, PageRequest::of($request)->size());
    }
  }
