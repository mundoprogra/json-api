<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Pagination\LengthAwarePaginator;
  use Illuminate\Support\Facades\DB;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\BlogArticleResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsCompoundDocuments;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\BuildsBlogArticles;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `included` de una colección: se arma con los recursos de todos los elementos de `data`, siguiendo las rutas de `include` en el orden que dio el
   * cliente y, dentro de cada nivel, en el orden de los elementos. Un recurso compartido por varios elementos aparece una sola vez y ninguno de los que
   * ya están en `data` se repite. Va después de `data` y antes de `links` y `meta`. Con datos reales y `jsonApiInclude()`, armarlo no suma consultas a las
   * de la carga anticipada: el número de consultas no crece con el número de filas.
   *
   * `included` of a collection: it is built from the resources of all the elements in `data`, following the `include` paths in the order the client
   * gave them and, inside each level, in the order of the elements. A resource shared by several elements appears once and none of those already in
   * `data` is repeated. It goes after `data` and before `links` and `meta`. With real data and `jsonApiInclude()`, building it adds no queries to those
   * of the eager loading: the number of queries does not grow with the number of rows.
   */
  class IncludedInCollectionsTest extends TestCase {
    use AssertsCompoundDocuments;
    use AssertsJsonApi;
    use BuildsBlogArticles;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->seedRelations(20);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $router->get('api/articles', fn() => BlogArticleResource::collection([$this->article(), $this->secondArticle()]));
      $router->get('web/articles', fn() => BlogArticleResource::collection([$this->article(), $this->secondArticle()]));
      $router->get('api/none', fn() => BlogArticleResource::collection([]));
      $router->get('api/cycle', fn() => BlogArticleResource::collection([$this->articleInACycle(10), $this->articleInACycle(11)]));
      $router->get('api/page', fn() => BlogArticleResource::collection(new LengthAwarePaginator([$this->article(), $this->secondArticle()], 6, 2, 1)));
      $router->get('api/greedy', fn() => BlogArticleResource::collection([$this->article()])->additional(['included' => 'evil']));

      // Con datos reales: la página se trae con la carga anticipada que permite `jsonApiInclude()`.
      // With real data: the page is fetched with the eager loading `jsonApiInclude()` allows.
      $router->get('api/db', fn() => BlogArticleResource::collection(
        Article::query()->jsonApiInclude(['author', 'tags', 'comments.author'])->orderBy('id')->jsonApiPaginate()
      ));
    }

    private function fetch(string $query = '', string $uri = 'api/articles'): TestResponse {
      return $this->get($uri . ($query === '' ? '' : '?' . $query), ['Accept' => self::MEDIA_TYPE]);
    }

    // ---------------------------------------------------------------------------------------------
    // El orden y los repetidos / Order and repeats
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_related_resources_of_every_element_are_included_in_the_order_of_the_elements(): void {
      $response = $this->fetch('include=author');

      $this->assertSame(['authors:1', 'authors:2'], $this->included((array) $response->json()));
      $this->assertCount(2, $response->json('data'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function a_resource_shared_by_several_elements_appears_once(): void {
      // La etiqueta `laravel` es de los dos artículos.
      // The `laravel` tag belongs to both articles.
      $this->assertSame(['labels:php', 'labels:laravel', 'labels:rails'], $this->included((array) $this->fetch('include=tags')->json()));
    }

    #[Test]
    public function several_paths_follow_the_order_of_the_client_and_then_the_order_of_the_elements(): void {
      $this->assertSame(
        ['labels:php', 'labels:laravel', 'labels:rails', 'authors:1', 'authors:2'],
        $this->included((array) $this->fetch('include=tags,author')->json())
      );
    }

    #[Test]
    public function a_nested_path_goes_one_level_at_a_time_across_all_the_elements(): void {
      $response = $this->fetch('include=comments.author');

      $this->assertSame(['comments:100', 'comments:101', 'comments:102', 'authors:1', 'authors:2'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function no_resource_of_data_is_repeated_in_included(): void {
      // Los artículos 10 y 11 son de `data`; el autor tiene cargados los artículos 10, 11 y 12, y solo el 12 es nuevo.
      // Articles 10 and 11 are in `data`; the author has articles 10, 11 and 12 loaded, and only 12 is new.
      $response = $this->fetch('include=author.articles', 'api/cycle');

      $this->assertSame(['authors:1', 'articles:12'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // Nada que incluir / Nothing to include
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_include_there_is_no_included(): void {
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $this->fetch()->json()));
    }

    #[Test]
    public function an_empty_collection_has_an_empty_data_and_no_included(): void {
      $response = $this->fetch('include=author', 'api/none');

      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertSame([], $response->json('data'));
    }

    #[Test]
    public function outside_the_package_scope_include_is_ignored(): void {
      $response = $this->get('web/articles?include=author', ['Accept' => 'text/html']);

      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Sparse fieldsets
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_sparse_fieldsets_apply_to_the_included_resources(): void {
      $response = $this->fetch('include=author&fields[authors]=name');

      $this->assertSame(['name' => 'Ada'], $response->json('included.0.attributes'));
      $this->assertSame(['name' => 'Bob'], $response->json('included.1.attributes'));
    }

    #[Test]
    public function a_relationship_the_fieldsets_hide_is_not_included_for_any_element(): void {
      $response = $this->fetch('include=author&fields[articles]=title');

      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Paginada / Paginated
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function in_a_paginated_collection_included_goes_after_data_and_before_links_and_meta(): void {
      $response = $this->fetch('include=author&page[size]=2', 'api/page');

      $this->assertSame(['jsonapi', 'data', 'included', 'links', 'meta'], array_keys((array) $response->json()));
      $this->assertStringContainsString('include=author', (string) $response->json('links.next'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_cannot_override_the_included_the_package_built(): void {
      $response = $this->fetch('include=author', 'api/greedy');

      $this->assertSame(['authors:1'], $this->included((array) $response->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Con datos reales y sin consultas de más / With real data and no extra queries
    // ---------------------------------------------------------------------------------------------

    /**
     * Cuántas consultas hace una petición completa, desde que llega hasta que sale el documento.
     * How many queries a whole request runs, from arrival to the document going out.
     */
    private function queriesFor(string $query): int {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->fetch($query, 'api/db')->assertOk();

      return count(DB::getQueryLog());
    }

    #[Test]
    public function a_real_page_includes_the_authors_of_its_articles(): void {
      $response = $this->fetch('include=author&page[size]=5', 'api/db');

      // El artículo `n` es del autor `((n - 1) % 3) + 1`: los artículos 1 a 5 son de los autores 1, 2, 3, 1, 2.
      // Article `n` belongs to author `((n - 1) % 3) + 1`: articles 1 to 5 belong to authors 1, 2, 3, 1, 2.
      $this->assertSame(['authors:1', 'authors:2', 'authors:3'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function a_real_page_includes_each_tag_once(): void {
      $keys = $this->included((array) $this->fetch('include=tags&page[size]=5', 'api/db')->json());

      sort($keys);

      $this->assertSame(['labels:tag-1', 'labels:tag-2', 'labels:tag-3', 'labels:tag-4', 'labels:tag-5'], $keys);
    }

    #[Test]
    public function a_real_page_includes_its_comments_and_their_authors(): void {
      $response = $this->fetch('include=comments.author&page[size]=5', 'api/db');
      $keys = $this->included((array) $response->json());

      // Dos comentarios por artículo (los autores 1 y 2): 10 comentarios y 2 autores.
      // Two comments per article (authors 1 and 2): 10 comments and 2 authors.
      $this->assertCount(12, $keys);
      $this->assertCount(10, array_filter($keys, fn(string $key) => str_starts_with($key, 'comments:')));
      $this->assertEqualsCanonicalizing(['authors:1', 'authors:2'], array_values(array_filter($keys, fn(string $key) => str_starts_with($key, 'authors:'))));
      $this->assertCompound((array) $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_queries_do_not_grow_with_the_rows_and_building_included_adds_none(): void {
      // Contar + traer la página + una consulta por relación cargada: autor, etiquetas, comentarios y autores de los comentarios.
      // Count + fetch the page + one query per loaded relationship: author, tags, comments and authors of the comments.
      $this->assertSame(6, $this->queriesFor('include=author,tags,comments.author&page[size]=5'));
      $this->assertSame(6, $this->queriesFor('include=author,tags,comments.author&page[size]=15'));
    }

    #[Test]
    public function without_include_only_the_count_and_the_page_are_fetched(): void {
      $this->assertSame(2, $this->queriesFor('page[size]=5'));
    }

    #[Test]
    public function each_relationship_asked_for_costs_one_extra_query(): void {
      $this->assertSame(3, $this->queriesFor('include=author&page[size]=5'));
    }
  }
