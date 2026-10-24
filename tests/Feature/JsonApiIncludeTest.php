<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\ArticleResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `jsonApiInclude()` carga por anticipado (`with()`) las relaciones que el cliente pide en `include`, con una lista blanca explícita de rutas
   * (`['author', 'tags', 'comments.author']`). Permitir una ruta permite también sus prefijos (`comments.author` permite `comments`). Una ruta
   * fuera de la lista es un `400` con `source.parameter = include`. Solo carga: el `included` del documento llega con las colecciones, pero como
   * `ResourceObject` muestra el linkage de lo que está cargado, un `include` ya hace aparecer `relationships.*.data`. La carga anticipada hace que
   * el número de consultas dependa de cuántas relaciones se piden y no de cuántas filas hay (sin N+1).
   * `jsonApiInclude()` eager loads (`with()`) the relations the client asks for in `include`, with an explicit whitelist of paths
   * (`['author', 'tags', 'comments.author']`). Allowing a path also allows its prefixes (`comments.author` allows `comments`). A path outside the
   * list is a `400` with `source.parameter = include`. It only loads: the document `included` arrives with collections, but since
   * `ResourceObject` shows the linkage of what is loaded, an `include` already makes `relationships.*.data` appear. Eager loading makes the number
   * of queries depend on how many relations are asked for and not on how many rows there are (no N+1).
   */
  class JsonApiIncludeTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const ALLOWED = ['author', 'tags', 'comments.author'];

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->seedRelations(20);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      // Qué relaciones quedaron cargadas en cada artículo de la página.
      // Which relations ended up loaded on each article of the page.
      $router->get('api/loaded', function () {
        $page = Article::query()->jsonApiInclude(self::ALLOWED)->orderBy('id')->jsonApiPaginate();

        return response()->json(['items' => $page->getCollection()->map(fn(Article $a) => [
          'id' => $a->id,
          'author' => $a->relationLoaded('author'),
          'tags' => $a->relationLoaded('tags'),
          'comments' => $a->relationLoaded('comments'),
          'commentsAuthor' => $a->relationLoaded('comments') && $a->comments->every(fn($c) => $c->relationLoaded('author')),
        ])->all()]);
      });

      // Lee de cada artículo solo las relaciones que el cliente pidió en `include`: si están cargadas no cuesta consultas, y si no, cuesta una por artículo.
      // Reads from each article only the relations the client asked for in `include`: if they are loaded it costs no queries, and if not, it costs one per article.
      $router->get('api/reads', function () {
        $readers = [
          'author' => fn(Article $a) => $a->author?->name,
          'tags' => fn(Article $a) => $a->tags->count(),
          'comments' => fn(Article $a) => $a->comments->count(),
          'comments.author' => fn(Article $a) => $a->comments->map(fn($c) => $c->author?->name)->all(),
        ];
        $asked = explode(',', (string) request()->query('include'));
        $page = Article::query()->jsonApiInclude(self::ALLOWED)->orderBy('id')->jsonApiPaginate();

        return response()->json(['read' => $page->getCollection()->map(fn(Article $a) => array_map(fn(string $path) => $readers[$path]($a), $asked))->all()]);
      });

      $router->get('api/lazy', fn() => response()->json(['read' => Article::query()->orderBy('id')->jsonApiPaginate()->getCollection()->map(fn(Article $a) => $a->author?->name)->all()]));

      $router->get('api/resource', function () {
        $article = Article::query()->jsonApiInclude(self::ALLOWED)->orderBy('id')->first();

        return response()->json(ArticleResource::make($article)->resolve(request()));
      });

      $router->get('api/chain', function () {
        $page = Article::query()
          ->jsonApiFilter(exact: ['status'])
          ->jsonApiInclude(self::ALLOWED)
          ->jsonApiSort(['id'], default: '-id')
          ->jsonApiPaginate();

        return response()->json(['ids' => $page->getCollection()->pluck('id')->all(), 'total' => $page->total(), 'author' => $page->getCollection()->first()->relationLoaded('author')]);
      });
    }

    private function fetch(string $uri): \Illuminate\Testing\TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Cuántas consultas cuesta una petición.
     * How many queries a request costs.
     */
    private function queriesFor(string $uri): int {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->fetch($uri)->assertOk();

      return count(DB::getQueryLog());
    }

    // ---------------------------------------------------------------------------------------------
    // La macro / The macro
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_macro_is_registered_and_the_builder_stays_chainable(): void {
      $this->assertTrue(Builder::hasGlobalMacro('jsonApiInclude'));

      $this->app->instance('request', Request::create('/api/articles?include=author'));
      $query = Article::query();

      $this->assertSame($query, $query->jsonApiInclude(['author']));
    }

    // ---------------------------------------------------------------------------------------------
    // Qué carga / What it loads
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_include_nothing_is_loaded(): void {
      $first = $this->fetch('api/loaded?page[size]=3')->json('items.0');

      $this->assertSame(['author' => false, 'tags' => false, 'comments' => false, 'commentsAuthor' => false], array_diff_key($first, ['id' => 1]));
    }

    #[Test]
    public function an_empty_include_loads_nothing(): void {
      $this->assertFalse($this->fetch('api/loaded?include=&page[size]=3')->json('items.0.author'));
    }

    #[Test]
    public function it_loads_the_relations_asked_for(): void {
      $first = $this->fetch('api/loaded?include=author,tags&page[size]=3')->json('items.0');

      $this->assertTrue($first['author']);
      $this->assertTrue($first['tags']);
      $this->assertFalse($first['comments']);
    }

    #[Test]
    public function it_loads_every_article_of_the_page_not_just_the_first(): void {
      $items = $this->fetch('api/loaded?include=author&page[size]=10')->json('items');

      $this->assertCount(10, $items);
      $this->assertSame([true], array_values(array_unique(array_column($items, 'author'))));
    }

    #[Test]
    public function it_loads_a_nested_path_with_its_parents(): void {
      $first = $this->fetch('api/loaded?include=comments.author&page[size]=3')->json('items.0');

      $this->assertTrue($first['comments']);
      $this->assertTrue($first['commentsAuthor']);
      $this->assertFalse($first['author']);
    }

    #[Test]
    public function allowing_a_nested_path_allows_its_prefix(): void {
      $first = $this->fetch('api/loaded?include=comments&page[size]=3')->json('items.0');

      // Se carga `comments`, pero no los autores de los comentarios: eso exigiría pedir `comments.author`.
      // `comments` is loaded, but not the authors of the comments: that would require asking for `comments.author`.
      $this->assertTrue($first['comments']);
      $this->assertFalse($first['commentsAuthor']);
    }

    #[Test]
    public function asking_for_a_path_and_its_prefix_together_is_fine(): void {
      $first = $this->fetch('api/loaded?include=comments,comments.author&page[size]=3')->json('items.0');

      $this->assertTrue($first['commentsAuthor']);
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no está permitido / What is not allowed
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_path_outside_the_list_is_a_400_naming_include(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $response = $this->fetch('api/loaded?include=editor');

      $response->assertStatus(400);
      $this->assertSame('include', $response->json('errors.0.source.parameter'));
      $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      $this->assertSame($this->english('request.bad_query.reasons.include_not_allowed'), $response->json('errors.0.detail'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function a_path_deeper_than_the_list_allows_is_a_400(): void {
      $this->fetch('api/loaded?include=author.articles')->assertStatus(400);
      $this->fetch('api/loaded?include=comments.author.articles')->assertStatus(400);
    }

    #[Test]
    public function one_path_outside_the_list_rejects_the_whole_include_with_a_single_error(): void {
      $response = $this->fetch('api/loaded?include=author,nope,tags');

      $response->assertStatus(400);
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function the_text_never_repeats_the_path_the_client_sent(): void {
      $response = $this->fetch('api/loaded?include=supersecretrelation');

      $this->assertStringNotContainsString('supersecret', (string) $response->getContent());
    }

    #[Test]
    public function an_empty_list_rejects_every_include(): void {
      $this->app->instance('request', Request::create('/api/articles?include=author'));

      $this->expectException(JsonApiException::class);

      Article::query()->jsonApiInclude([]);
    }

    #[Test]
    public function the_depth_limit_still_applies_before_the_list(): void {
      config()->set('json-api.query.max_include_depth', 1);

      $response = $this->fetch('api/loaded?include=comments.author');

      $response->assertStatus(400);
      $this->assertSame(str_replace(':max', '1', $this->english('request.bad_query.reasons.include_too_deep')), $response->json('errors.0.detail'));
    }

    // ---------------------------------------------------------------------------------------------
    // Sin N+1 / No N+1
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function one_relation_costs_one_extra_query_however_many_rows_there_are(): void {
      // Contar + traer la página + una consulta por relación pedida.
      // Count + fetch the page + one query per relation asked for.
      $this->assertSame(3, $this->queriesFor('api/reads?include=author&page[size]=5'));
      $this->assertSame(3, $this->queriesFor('api/reads?include=author&page[size]=15'));
    }

    #[Test]
    public function two_relations_cost_two_extra_queries(): void {
      $this->assertSame(4, $this->queriesFor('api/reads?include=author,tags&page[size]=5'));
      $this->assertSame(4, $this->queriesFor('api/reads?include=author,tags&page[size]=15'));
    }

    #[Test]
    public function a_two_level_path_costs_one_query_per_level(): void {
      $this->assertSame(4, $this->queriesFor('api/reads?include=comments.author&page[size]=5'));
      $this->assertSame(4, $this->queriesFor('api/reads?include=comments.author&page[size]=15'));
    }

    #[Test]
    public function all_the_relations_together_still_do_not_grow_with_the_rows(): void {
      $this->assertSame(6, $this->queriesFor('api/reads?include=author,tags,comments.author&page[size]=5'));
      $this->assertSame(6, $this->queriesFor('api/reads?include=author,tags,comments.author&page[size]=15'));
    }

    #[Test]
    public function the_control_without_include_does_grow_with_the_rows(): void {
      // Sin `include`, leer `author` de cada artículo cuesta una consulta por artículo: es el N+1 que la carga anticipada evita.
      // Without `include`, reading `author` of each article costs one query per article: it is the N+1 that eager loading avoids.
      $this->assertSame(2 + 5, $this->queriesFor('api/lazy?page[size]=5'));
      $this->assertSame(2 + 15, $this->queriesFor('api/lazy?page[size]=15'));
    }

    // ---------------------------------------------------------------------------------------------
    // Junto con el resto / Together with the rest
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_included_relation_shows_up_as_resource_linkage_in_the_resource(): void {
      $data = $this->fetch('api/resource?include=author,tags')->json();

      $this->assertSame(['type' => 'authors', 'id' => '1'], $data['relationships']['author']['data']);
      $this->assertSame(2, count($data['relationships']['tags']['data']));
    }

    #[Test]
    public function without_include_the_resource_has_no_relationships(): void {
      $this->assertArrayNotHasKey('relationships', $this->fetch('api/resource')->json());
    }

    #[Test]
    public function it_chains_with_filter_sort_and_pagination(): void {
      $response = $this->fetch('api/chain?filter[status]=published&include=author&page[size]=5');

      $this->assertSame([40, 38, 36, 34, 32], $response->json('ids'));
      $this->assertSame(20, $response->json('total'));
      $this->assertTrue($response->json('author'));
    }
  }
