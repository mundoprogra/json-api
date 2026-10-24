<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\DB;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `jsonApiFilter()` aplica el `filter` del cliente a una consulta de Eloquent, con listas blancas explícitas de tres tipos: `exact` (igualdad;
   * una lista con comas es un `IN`), `partial` (`LIKE %valor%` con `%`, `_` y `\` escapados) y `scopes` (llama a un scope del modelo con el valor).
   * Los nombres van en `snake_case`; una entrada `'nombre' => 'columna'` (o `'nombre' => 'scope'`) mapea el nombre. Varios filtros se combinan con AND.
   * Un valor vacío se ignora, uno que no es texto es un `400` mal formado, y un nombre fuera de las listas es un `400` con `source.parameter = filter[nombre]`.
   * `jsonApiFilter()` applies the client `filter` to an Eloquent query, with explicit whitelists of three kinds: `exact` (equality; a comma list is
   * an `IN`), `partial` (`LIKE %value%` with `%`, `_` and `\` escaped) and `scopes` (calls a model scope with the value). The names are in
   * `snake_case`; a `'name' => 'column'` (or `'name' => 'scope'`) entry maps the name. Several filters combine with AND.
   * An empty value is ignored, one that is not text is a malformed `400`, and a name outside the lists is a `400` with `source.parameter = filter[name]`.
   */
  class JsonApiFilterTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      // Los artículos 1 a 10 son del autor 1 y del 11 al 20 del autor 2; el resto no tiene autor.
      // Articles 1 to 10 belong to author 1 and 11 to 20 to author 2; the rest have no author.
      Article::query()->whereBetween('id', [1, 10])->update(['author_id' => 1]);
      Article::query()->whereBetween('id', [11, 20])->update(['author_id' => 2]);

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ids = fn(Builder $query) => response()->json(['ids' => $query->orderBy('id')->jsonApiPaginate(perPage: 100)->getCollection()->pluck('id')->all()]);

      $router->get('api/filtered', fn() => $ids(Article::query()->jsonApiFilter(
        exact: ['status', 'author_id', 'owner' => 'author_id'],
        partial: ['title'],
        scopes: ['published', 'live' => 'published'],
      )));
      $router->get('api/exact-only', fn() => $ids(Article::query()->jsonApiFilter(exact: ['status'])));
      $router->get('api/total', fn() => response()->json(['total' => Article::query()->jsonApiFilter(exact: ['status'])->jsonApiPaginate(perPage: 5)->total()]));
    }

    private function fetch(string $uri): \Illuminate\Testing\TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Tres artículos más, con caracteres que un `LIKE` toma como comodines: `%`, `_` y `\`.
     * Three more articles, with characters a `LIKE` takes as wildcards: `%`, `_` and `\`.
     */
    private function addSpecialTitles(): void {
      Article::query()->insert([
        ['id' => 41, 'title' => '100% real', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 42, 'title' => '50_50 split', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 43, 'title' => 'back\\slash', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
      ]);
    }

    /**
     * El SQL que arma la macro para una petición con esa consulta.
     * The SQL the macro builds for a request with that query.
     *
     * @param  array<int|string, string>  $exact
     */
    private function sql(string $queryString, array $exact = [], array $partial = []): string {
      $this->app->instance('request', Request::create('/api/articles?' . $queryString));

      return Article::query()->jsonApiFilter(exact: $exact, partial: $partial)->toSql();
    }

    // ---------------------------------------------------------------------------------------------
    // La macro / The macro
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_macro_is_registered_and_the_builder_stays_chainable(): void {
      $this->assertTrue(Builder::hasGlobalMacro('jsonApiFilter'));

      $this->app->instance('request', Request::create('/api/articles?filter[status]=published'));
      $query = Article::query();

      $this->assertSame($query, $query->jsonApiFilter(exact: ['status']));
    }

    #[Test]
    public function without_filters_the_query_is_left_alone(): void {
      $this->assertStringNotContainsString('where', $this->sql('', exact: ['status'], partial: ['title']));
    }

    // ---------------------------------------------------------------------------------------------
    // exact
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function exact_filters_by_equality(): void {
      $this->assertSame(range(2, 40, 2), $this->fetch('api/filtered?filter[status]=published')->json('ids'));
    }

    #[Test]
    public function exact_with_a_comma_list_is_an_in(): void {
      $this->assertSame(range(1, 20), $this->fetch('api/filtered?filter[author_id]=1,2')->json('ids'));
      $this->assertStringContainsString('"articles"."status" in (?, ?)', $this->sql('filter[status]=draft,published', exact: ['status']));
    }

    #[Test]
    public function exact_with_a_single_value_is_an_equality_not_an_in(): void {
      $this->assertStringContainsString('"articles"."status" = ?', $this->sql('filter[status]=published', exact: ['status']));
    }

    #[Test]
    public function the_names_from_the_client_follow_the_member_case(): void {
      $this->assertSame(range(1, 10), $this->fetch('api/filtered?filter[authorId]=1')->json('ids'));

      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(range(1, 10), $this->fetch('api/filtered?filter[author-id]=1')->json('ids'));
    }

    #[Test]
    public function a_name_can_be_mapped_to_another_column(): void {
      $this->assertSame(range(11, 20), $this->fetch('api/filtered?filter[owner]=2')->json('ids'));
      $this->assertStringContainsString('"articles"."author_id" = ?', $this->sql('filter[owner]=2', exact: ['owner' => 'author_id']));
    }

    #[Test]
    public function several_filters_combine_with_and(): void {
      $this->assertSame([2, 4, 6, 8, 10], $this->fetch('api/filtered?filter[status]=published&filter[author_id]=1')->json('ids'));
    }

    #[Test]
    public function the_value_is_bound_never_written_into_the_sql(): void {
      $response = $this->fetch('api/filtered?filter[status]=' . rawurlencode("x' OR '1'='1"));

      $response->assertOk();
      $this->assertSame([], $response->json('ids'));
    }

    // ---------------------------------------------------------------------------------------------
    // partial
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function partial_matches_anywhere_in_the_text(): void {
      // "Article 1" está contenido en "Article 1", "Article 10" … "Article 19".
      // "Article 1" is contained in "Article 1", "Article 10" … "Article 19".
      $this->assertSame([1, ...range(10, 19)], $this->fetch('api/filtered?filter[title]=' . rawurlencode('Article 1'))->json('ids'));
    }

    #[Test]
    public function a_percent_sign_is_text_not_a_wildcard(): void {
      $this->addSpecialTitles();

      $this->assertSame([41], $this->fetch('api/filtered?filter[title]=' . rawurlencode('%'))->json('ids'));
      $this->assertSame([41], $this->fetch('api/filtered?filter[title]=' . rawurlencode('100%'))->json('ids'));
    }

    #[Test]
    public function an_underscore_is_text_not_a_single_character_wildcard(): void {
      $this->addSpecialTitles();

      $this->assertSame([42], $this->fetch('api/filtered?filter[title]=' . rawurlencode('_'))->json('ids'));
    }

    #[Test]
    public function a_backslash_is_text_too(): void {
      $this->addSpecialTitles();

      $this->assertSame([43], $this->fetch('api/filtered?filter[title]=' . rawurlencode('\\'))->json('ids'));
    }

    #[Test]
    public function the_sql_declares_the_escape_character(): void {
      $this->assertStringContainsString('like ? escape', strtolower($this->sql('filter[title]=a', partial: ['title'])));
    }

    // ---------------------------------------------------------------------------------------------
    // scopes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_scope_is_called_with_the_value(): void {
      $this->assertSame(range(2, 40, 2), $this->fetch('api/filtered?filter[published]=1')->json('ids'));
      $this->assertSame(range(1, 39, 2), $this->fetch('api/filtered?filter[published]=0')->json('ids'));
    }

    #[Test]
    public function a_scope_can_be_reached_with_another_name(): void {
      $this->assertSame(range(2, 40, 2), $this->fetch('api/filtered?filter[live]=1')->json('ids'));
    }

    // ---------------------------------------------------------------------------------------------
    // Valores / Values
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_empty_value_is_ignored(): void {
      $this->assertSame(range(1, 40), $this->fetch('api/filtered?filter[status]=')->json('ids'));
    }

    #[Test]
    public function a_value_that_is_not_text_is_a_malformed_400(): void {
      $response = $this->fetch('api/filtered?filter[status][]=published');

      $response->assertStatus(400);
      $this->assertSame('filter[status]', $response->json('errors.0.source.parameter'));
      $this->assertSame($this->english('request.bad_query.reasons.malformed'), $response->json('errors.0.detail'));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no está permitido / What is not allowed
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_name_outside_the_lists_is_a_400_naming_that_filter(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $response = $this->fetch('api/filtered?filter[password]=x');

      $response->assertStatus(400);
      $this->assertSame('filter[password]', $response->json('errors.0.source.parameter'));
      $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      $this->assertSame($this->english('request.bad_query.reasons.filter_not_allowed'), $response->json('errors.0.detail'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function the_parameter_is_named_as_the_client_wrote_it(): void {
      $response = $this->fetch('api/exact-only?filter[createdAt]=x');

      $this->assertSame('filter[createdAt]', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function every_name_outside_the_lists_is_its_own_error_in_the_order_given(): void {
      $response = $this->fetch('api/filtered?filter[zeta]=1&filter[status]=published&filter[alpha]=2');

      $response->assertStatus(400);
      $this->assertSame(['filter[zeta]', 'filter[alpha]'], array_column(array_column($response->json('errors'), 'source'), 'parameter'));
    }

    #[Test]
    public function one_name_outside_the_lists_rejects_the_whole_filter_even_if_the_others_are_fine(): void {
      $this->fetch('api/filtered?filter[status]=published&filter[nope]=1')->assertStatus(400);
    }

    #[Test]
    public function a_name_valid_for_one_kind_is_not_valid_for_another(): void {
      // `title` solo está en `partial` y `status` solo en `exact`: aquí la ruta solo permite `status`.
      // `title` is only in `partial` and `status` only in `exact`: here the route only allows `status`.
      $this->fetch('api/exact-only?filter[status]=published')->assertOk();
      $this->fetch('api/exact-only?filter[title]=a')->assertStatus(400);
    }

    #[Test]
    public function no_lists_at_all_means_every_filter_is_rejected(): void {
      $this->app->instance('request', Request::create('/api/articles?filter[status]=published'));

      $this->expectException(\MundoProgra\JsonApi\Exceptions\JsonApiException::class);

      Article::query()->jsonApiFilter();
    }

    #[Test]
    public function the_text_never_repeats_the_value_the_client_sent(): void {
      $response = $this->fetch('api/exact-only?filter[title]=supersecretvalue');

      $this->assertStringNotContainsString('supersecretvalue', (string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador / Developer errors
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_same_name_in_two_lists_is_a_developer_error(): void {
      $this->app->instance('request', Request::create('/api/articles'));

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/title/');

      Article::query()->jsonApiFilter(exact: ['title'], partial: ['title']);
    }

    // ---------------------------------------------------------------------------------------------
    // Junto con la paginación / Together with pagination
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_total_counts_only_the_filtered_rows(): void {
      $this->assertSame(20, $this->fetch('api/total?filter[status]=published')->json('total'));
    }

    #[Test]
    public function filtering_and_paginating_still_takes_two_queries(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->fetch('api/total?filter[status]=published');

      $this->assertCount(2, DB::getQueryLog());
    }
  }
