<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use Illuminate\Pagination\LengthAwarePaginator;
  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Query\Pagination;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `jsonApiPaginate()` es una macro del constructor de Eloquent que trae la página que pidió el cliente: `page[number]` y `page[size]`, con el
   * tamaño calculado por `PageRequest` (cliente → argumento → opciones del middleware → configuración, acotado por el máximo). Devuelve el
   * paginador de Laravel; los links y el `meta` los arma `Pagination`. Una página fuera de rango es una página vacía, no un error.
   * `jsonApiPaginate()` is an Eloquent builder macro that fetches the page the client asked for: `page[number]` and `page[size]`, with the
   * size worked out by `PageRequest` (client → argument → middleware options → config, capped by the maximum). It returns Laravel's
   * paginator; the links and `meta` are built by `Pagination`. A page out of range is an empty page, not an error.
   */
  class JsonApiPaginateTest extends TestCase {
    use AssertsJsonApi;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    /**
     * Lo que cada ruta cuenta de la página que trajo.
     * What each route reports about the page it fetched.
     */
    private function summary(LengthAwarePaginator $page): \Illuminate\Http\JsonResponse {
      return response()->json([
        'ids' => $page->getCollection()->pluck('id')->all(),
        'total' => $page->total(),
        'perPage' => $page->perPage(),
        'currentPage' => $page->currentPage(),
      ]);
    }

    protected function defineRoutes($router): void {
      $articles = fn() => Article::query()->orderBy('id');

      $router->get('api/articles', fn() => $this->summary($articles()->jsonApiPaginate()));
      $router->get('api/per-page', fn() => $this->summary($articles()->jsonApiPaginate(perPage: 7)));
      $router->get('api/max-per-page', fn() => $this->summary($articles()->jsonApiPaginate(maxPerPage: 6)));
      $router->get('api/options', fn() => $this->summary($articles()->jsonApiPaginate()))->middleware('json-api.options:per_page=9');
      $router->get('api/argument-and-options', fn() => $this->summary($articles()->jsonApiPaginate(perPage: 7)))->middleware('json-api.options:per_page=9');
      $router->get('api/published', fn() => $this->summary(Article::query()->where('status', 'published')->orderBy('id')->jsonApiPaginate()));

      $router->get('api/document', function (Request $request) {
        $page = Article::query()->orderBy('id')->jsonApiPaginate();
        $pagination = Pagination::of($page, $request);

        return response()->json([
          'jsonapi' => ['version' => '1.1'],
          'data' => $page->getCollection()->map(fn(Article $article) => ['type' => 'articles', 'id' => (string) $article->id, 'attributes' => ['title' => $article->title]])->all(),
          'links' => $pagination->links(),
          'meta' => $pagination->meta(),
        ]);
      });
    }

    private function fetch(string $uri): \Illuminate\Testing\TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return list<int>
     */
    private function range(int $from, int $to): array {
      return range($from, $to);
    }

    // ---------------------------------------------------------------------------------------------
    // La macro / The macro
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_macro_is_registered_on_the_eloquent_builder(): void {
      $this->assertTrue(Builder::hasGlobalMacro('jsonApiPaginate'));
    }

    #[Test]
    public function it_returns_the_laravel_paginator(): void {
      $this->app->instance('request', Request::create('/api/articles'));

      $this->assertInstanceOf(LengthAwarePaginator::class, Article::query()->jsonApiPaginate());
    }

    // ---------------------------------------------------------------------------------------------
    // La página que pidió el cliente / The page the client asked for
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_page_parameters_it_uses_the_default_size_and_the_first_page(): void {
      $response = $this->fetch('api/articles');

      $this->assertSame($this->range(1, 15), $response->json('ids'));
      $this->assertSame(40, $response->json('total'));
      $this->assertSame(15, $response->json('perPage'));
      $this->assertSame(1, $response->json('currentPage'));
    }

    #[Test]
    public function the_client_asks_for_a_size(): void {
      $this->assertSame($this->range(1, 25), $this->fetch('api/articles?page[size]=25')->json('ids'));
    }

    #[Test]
    public function the_client_asks_for_a_page(): void {
      $response = $this->fetch('api/articles?page[number]=2&page[size]=10');

      $this->assertSame($this->range(11, 20), $response->json('ids'));
      $this->assertSame(2, $response->json('currentPage'));
    }

    #[Test]
    public function the_last_page_has_what_is_left(): void {
      // 40 filas en páginas de 12: las páginas 1 a 3 traen 36 y la cuarta trae las 4 que quedan (37 a 40).
      // 40 rows in pages of 12: pages 1 to 3 bring 36 and the fourth brings the 4 that are left (37 to 40).
      $this->assertSame($this->range(37, 40), $this->fetch('api/articles?page[number]=4&page[size]=12')->json('ids'));
    }

    #[Test]
    public function a_page_beyond_the_last_is_empty_and_not_an_error(): void {
      $response = $this->fetch('api/articles?page[number]=9&page[size]=10');

      $response->assertOk();
      $this->assertSame([], $response->json('ids'));
      $this->assertSame(40, $response->json('total'));
      $this->assertSame(9, $response->json('currentPage'));
    }

    #[Test]
    public function conditions_added_before_it_count_in_the_total(): void {
      $response = $this->fetch('api/published?page[size]=5');

      $this->assertSame(20, $response->json('total'));
      $this->assertSame([2, 4, 6, 8, 10], $response->json('ids'));
    }

    // ---------------------------------------------------------------------------------------------
    // El tamaño: máximo y cascada / The size: maximum and cascade
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_size_above_the_maximum_is_trimmed(): void {
      config()->set('json-api.pagination.max_size', 20);

      $response = $this->fetch('api/articles?page[size]=500');

      $this->assertSame(20, $response->json('perPage'));
      $this->assertCount(20, $response->json('ids'));
    }

    #[Test]
    public function a_size_above_the_maximum_can_be_a_400_instead(): void {
      config()->set('json-api.pagination.max_size', 20);
      config()->set('json-api.pagination.on_exceed', 'error');

      $response = $this->fetch('api/articles?page[size]=500');

      $response->assertStatus(400);
      $this->assertSame('page[size]', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function an_invalid_size_is_a_400(): void {
      $this->fetch('api/articles?page[size]=0')->assertStatus(400);
    }

    #[Test]
    public function the_controller_argument_sets_the_default_size(): void {
      $this->assertSame(7, $this->fetch('api/per-page')->json('perPage'));
    }

    #[Test]
    public function the_client_wins_over_the_controller_argument(): void {
      $this->assertSame(5, $this->fetch('api/per-page?page[size]=5')->json('perPage'));
    }

    #[Test]
    public function the_controller_maximum_caps_the_client(): void {
      $this->assertSame(6, $this->fetch('api/max-per-page?page[size]=50')->json('perPage'));
    }

    #[Test]
    public function the_middleware_options_set_the_default_size(): void {
      $this->assertSame(9, $this->fetch('api/options')->json('perPage'));
    }

    #[Test]
    public function the_controller_argument_wins_over_the_middleware_options(): void {
      $this->assertSame(7, $this->fetch('api/argument-and-options')->json('perPage'));
    }

    #[Test]
    public function the_default_size_in_the_config_is_read_on_every_call(): void {
      $this->assertSame(15, $this->fetch('api/articles')->json('perPage'));

      config()->set('json-api.pagination.size', 5);

      $this->assertSame(5, $this->fetch('api/articles')->json('perPage'));
    }

    // ---------------------------------------------------------------------------------------------
    // Consultas / Queries
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_page_takes_two_queries_one_to_count_and_one_to_read(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->fetch('api/articles?page[size]=10');

      $this->assertCount(2, DB::getQueryLog());
    }

    // ---------------------------------------------------------------------------------------------
    // Junto con los links y el meta / Together with the links and the meta
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_with_its_links_and_meta_is_a_valid_document(): void {
      $response = $this->fetch('api/document?page[size]=10');

      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCount(10, $response->json('data'));
      $this->assertSame(4, $response->json('meta.page.lastPage'));
      $this->assertSame(40, $response->json('meta.page.total'));
    }

    #[Test]
    public function following_the_next_link_gives_the_next_page(): void {
      $first = $this->fetch('api/document?page[size]=10&sort=-title');

      $second = $this->get((string) $first->json('links.next'), ['Accept' => self::MEDIA_TYPE]);

      $this->assertSame(['11', '12', '13', '14', '15', '16', '17', '18', '19', '20'], array_column($second->json('data'), 'id'));
      $this->assertSame(2, $second->json('meta.page.currentPage'));
      $this->assertStringContainsString('sort=-title', rawurldecode((string) $second->json('links.next')));
    }
  }
