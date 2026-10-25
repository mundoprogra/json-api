<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Pagination\LengthAwarePaginator;
  use Illuminate\Support\Facades\DB;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\ArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Una colección hecha con un paginador de Laravel (`Article::query()->jsonApiPaginate()`, o `paginate()`) suma al documento los `links` de
   * paginación (`first`, `last`, `prev`, `next`) y el `meta.page` (`currentPage`, `perPage`, `lastPage`, `total`), armados por `Pagination` con la
   * petición actual: conservan `sort`, `filter`, `include` y `fields` tal como los mandó el cliente. `prev` y `next` son `null` en los extremos y
   * una página fuera de rango es `data: []` con `prev` apuntando a la última. Una colección sin paginar no lleva ni `links` ni `meta`.
   * `additional()` puede agregar sus propios `links` y `meta`, pero no pisar los de la paginación. Armar el documento no hace consultas.
   *
   * A collection made with a Laravel paginator (`Article::query()->jsonApiPaginate()`, or `paginate()`) adds to the document the pagination `links`
   * (`first`, `last`, `prev`, `next`) and the `meta.page` (`currentPage`, `perPage`, `lastPage`, `total`), built by `Pagination` from the current
   * request: they keep `sort`, `filter`, `include` and `fields` as the client sent them. `prev` and `next` are `null` at the ends and a page out of
   * range is `data: []` with `prev` pointing at the last one. A collection without a paginator carries neither `links` nor `meta`.
   * `additional()` can add its own `links` and `meta`, but not override those of the pagination. Building the document runs no queries.
   */
  class PaginatedResourceCollectionTest extends TestCase {
    use AssertsJsonApi;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $page = fn() => Article::query()->orderBy('id')->jsonApiPaginate();

      $router->get('api/articles', fn() => ArticleResource::collection($page()));
      $router->get('api/plain', fn() => ArticleResource::collection(Article::query()->orderBy('id')->limit(3)->get()));
      $router->get('api/none', fn() => ArticleResource::collection(Article::query()->where('id', 0)->jsonApiPaginate()));
      $router->get('api/filtered', fn() => ArticleResource::collection(
        Article::query()->jsonApiFilter(exact: ['status'])->jsonApiSort(['id'], default: '-id')->jsonApiPaginate()
      ));
      $router->get('api/extra', fn() => ArticleResource::collection($page())->additional(['links' => ['self' => 'https://example.com/articles'], 'meta' => ['count' => 5]]));
      $router->get('api/greedy', fn() => ArticleResource::collection($page())->additional(['links' => ['first' => 'evil'], 'meta' => ['page' => 'evil']]));
      $router->get('api/authors-page', fn() => AuthorResource::collection(new LengthAwarePaginator($this->authors(2), 7, 2, 2)));
    }

    /**
     * @return list<Author>
     */
    private function authors(int $count): array {
      return array_map(fn(int $id) => (new Author())->forceFill(['id' => $id, 'name' => "Author $id", 'email' => "a$id@example.com"]), range(1, $count));
    }

    private function fetch(string $uri): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * El link de una página de `api/articles` con ese tamaño, tal como lo arma `Pagination` (codificado según RFC 3986).
     * The link of a page of `api/articles` with that size, as `Pagination` builds it (encoded per RFC 3986).
     */
    private function link(int $number, int $size): string {
      return "http://localhost/api/articles?page%5Bnumber%5D=$number&page%5Bsize%5D=$size";
    }

    // ---------------------------------------------------------------------------------------------
    // Links y meta / Links and meta
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_paginated_collection_carries_the_page_in_data_and_the_links_and_meta(): void {
      $response = $this->fetch('api/articles?page[number]=2&page[size]=5');

      $response->assertOk();
      $this->assertSame(['6', '7', '8', '9', '10'], array_column($response->json('data'), 'id'));
      $this->assertSame([
        'first' => $this->link(1, 5),
        'last' => $this->link(8, 5),
        'prev' => $this->link(1, 5),
        'next' => $this->link(3, 5),
      ], $response->json('links'));
      $this->assertSame(['page' => ['currentPage' => 2, 'perPage' => 5, 'lastPage' => 8, 'total' => 40]], $response->json('meta'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_members_come_in_the_order_jsonapi_data_links_meta(): void {
      $this->assertSame(['jsonapi', 'data', 'links', 'meta'], array_keys((array) $this->fetch('api/articles?page[size]=5')->json()));
    }

    #[Test]
    public function the_first_page_has_no_prev_and_the_last_has_no_next(): void {
      $first = $this->fetch('api/articles?page[number]=1&page[size]=5');
      $last = $this->fetch('api/articles?page[number]=8&page[size]=5');

      $this->assertNull($first->json('links.prev'));
      $this->assertSame($this->link(2, 5), $first->json('links.next'));
      $this->assertSame($this->link(7, 5), $last->json('links.prev'));
      $this->assertNull($last->json('links.next'));
      $this->assertJsonApiDocument((string) $first->getContent());
      $this->assertJsonApiDocument((string) $last->getContent());
    }

    #[Test]
    public function the_default_page_size_applies_when_the_client_sends_none(): void {
      $response = $this->fetch('api/articles');

      $this->assertSame(config('json-api.pagination.size'), $response->json('meta.page.perPage'));
      $this->assertCount(min(40, (int) config('json-api.pagination.size')), $response->json('data'));
    }

    #[Test]
    public function the_meta_names_follow_the_member_case_setting(): void {
      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(['current-page', 'per-page', 'last-page', 'total'], array_keys($this->fetch('api/articles?page[size]=5')->json('meta.page')));
    }

    // ---------------------------------------------------------------------------------------------
    // Los links conservan la consulta del cliente / The links keep the client query
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_links_keep_filter_and_sort_as_the_client_sent_them(): void {
      $response = $this->fetch('api/filtered?filter[status]=published&sort=-id&page[size]=5');

      $this->assertSame(['40', '38', '36', '34', '32'], array_column($response->json('data'), 'id'));
      $this->assertSame(
        'http://localhost/api/filtered?filter%5Bstatus%5D=published&sort=-id&page%5Bnumber%5D=2&page%5Bsize%5D=5',
        $response->json('links.next')
      );
      $this->assertSame(['currentPage' => 1, 'perPage' => 5, 'lastPage' => 4, 'total' => 20], $response->json('meta.page'));
    }

    #[Test]
    public function the_links_keep_include_fields_and_other_page_keys(): void {
      $response = $this->fetch('api/articles?include=author&fields[articles]=title&page[size]=5&page[extra]=1');

      $next = (string) $response->json('links.next');

      $this->assertStringContainsString('include=author', $next);
      $this->assertStringContainsString('fields%5Barticles%5D=title', $next);
      $this->assertStringContainsString('page%5Bextra%5D=1', $next);
      $this->assertStringContainsString('page%5Bnumber%5D=2', $next);
    }

    // ---------------------------------------------------------------------------------------------
    // Extremos / Edges
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_page_out_of_range_is_an_empty_list_whose_prev_points_at_the_last_page(): void {
      $response = $this->fetch('api/articles?page[number]=99&page[size]=5');

      $response->assertOk();
      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
      $this->assertSame($this->link(8, 5), $response->json('links.prev'));
      $this->assertNull($response->json('links.next'));
      $this->assertSame(99, $response->json('meta.page.currentPage'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_query_with_no_rows_is_an_empty_list_with_one_page(): void {
      $response = $this->fetch('api/none?page[size]=5');

      $this->assertSame([], $response->json('data'));
      $this->assertSame('http://localhost/api/none?page%5Bnumber%5D=1&page%5Bsize%5D=5', $response->json('links.first'));
      $this->assertSame($response->json('links.first'), $response->json('links.last'));
      $this->assertNull($response->json('links.prev'));
      $this->assertNull($response->json('links.next'));
      $this->assertSame(['currentPage' => 1, 'perPage' => 5, 'lastPage' => 1, 'total' => 0], $response->json('meta.page'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Sin paginador / Without a paginator
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_collection_without_a_paginator_has_neither_links_nor_meta(): void {
      $response = $this->fetch('api/plain');

      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertCount(3, $response->json('data'));
    }

    // ---------------------------------------------------------------------------------------------
    // additional() / additional()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function additional_links_and_meta_are_added_next_to_the_pagination_ones(): void {
      $response = $this->fetch('api/extra?page[size]=5');

      $this->assertSame(['first', 'last', 'prev', 'next', 'self'], array_keys($response->json('links')));
      $this->assertSame('https://example.com/articles', $response->json('links.self'));
      $this->assertSame(['page', 'count'], array_keys($response->json('meta')));
      $this->assertSame(5, $response->json('meta.count'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_cannot_override_the_links_and_meta_of_the_pagination(): void {
      $response = $this->fetch('api/greedy?page[size]=5');

      $this->assertSame('http://localhost/api/greedy?page%5Bnumber%5D=1&page%5Bsize%5D=5', $response->json('links.first'));
      $this->assertSame(['currentPage' => 1, 'perPage' => 5, 'lastPage' => 8, 'total' => 40], $response->json('meta.page'));
    }

    // ---------------------------------------------------------------------------------------------
    // Otros paginadores de longitud conocida / Other length-aware paginators
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_paginator_built_by_hand_works_too(): void {
      $response = $this->fetch('api/authors-page?page[number]=2&page[size]=2');

      $this->assertSame(['1', '2'], array_column($response->json('data'), 'id'));
      $this->assertSame(['currentPage' => 2, 'perPage' => 2, 'lastPage' => 4, 'total' => 7], $response->json('meta.page'));
      $this->assertSame('http://localhost/api/authors-page?page%5Bnumber%5D=3&page%5Bsize%5D=2', $response->json('links.next'));
    }

    #[Test]
    public function the_plain_paginate_of_laravel_works_too(): void {
      $page = Article::query()->orderBy('id')->paginate(5, ['*'], 'page', 3);

      $document = ArticleResource::collection($page)->document(Request::create('/api/articles?page[number]=3&page[size]=5'));

      $this->assertSame(['11', '12', '13', '14', '15'], array_column($document['data'], 'id'));
      $this->assertSame(['page' => ['currentPage' => 3, 'perPage' => 5, 'lastPage' => 8, 'total' => 40]], $document['meta']);
      $this->assertSame($this->link(2, 5), $document['links']['prev']);
      $this->assertSame($this->link(4, 5), $document['links']['next']);
    }

    // ---------------------------------------------------------------------------------------------
    // Sin consultas extra / No extra queries
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function building_the_document_from_a_fetched_page_runs_no_queries(): void {
      $page = Article::query()->orderBy('id')->paginate(5);

      DB::enableQueryLog();
      DB::flushQueryLog();

      ArticleResource::collection($page)->document(Request::create('/api/articles'));

      $this->assertSame([], DB::getQueryLog());
    }

    // ---------------------------------------------------------------------------------------------
    // Respuesta / Response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_response_keeps_the_exact_content_type_and_a_200(): void {
      $response = $this->fetch('api/articles?page[size]=5');

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }
  }
