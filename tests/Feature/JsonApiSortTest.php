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
   * `jsonApiSort()` aplica el `sort` del cliente a una consulta de Eloquent, con una lista blanca explícita de nombres. Los nombres de la lista y
   * los del cliente van en `snake_case`; un nombre se puede mapear a otra columna (`['propietario' => 'author_id']`). El orden se aplica en el
   * orden pedido, `default` se usa si el cliente no pide ninguno, y al final se agrega la clave primaria para que las páginas sean estables.
   * Un nombre fuera de la lista es un `400` con `source.parameter = sort`; un `default` fuera de la lista es un error del programador.
   * `jsonApiSort()` applies the client `sort` to an Eloquent query, with an explicit whitelist of names. The names in the list and the ones from
   * the client are in `snake_case`; a name can be mapped to another column (`['owner' => 'author_id']`). The order is applied in the order asked
   * for, `default` is used if the client asks for none, and the primary key is added at the end so the pages are stable.
   * A name outside the list is a `400` with `source.parameter = sort`; a `default` outside the list is a developer error.
   */
  class JsonApiSortTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ids = fn(Builder $query) => response()->json(['ids' => $query->jsonApiPaginate(perPage: 40)->getCollection()->pluck('id')->all()]);

      $router->get('api/sorted', fn() => $ids(Article::query()->jsonApiSort(['id', 'title', 'status', 'created_at'])));
      $router->get('api/sorted-by-default', fn() => $ids(Article::query()->jsonApiSort(['id', 'status'], default: '-id')));
      $router->get('api/sorted-paged', fn() => response()->json(['ids' => Article::query()->jsonApiSort(['status'])->jsonApiPaginate(perPage: 15)->getCollection()->pluck('id')->all()]));
    }

    private function fetch(string $uri): \Illuminate\Testing\TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * El SQL que arma la macro para una petición con esa consulta.
     * The SQL the macro builds for a request with that query.
     *
     * @param  array<int|string, string>  $allowed
     */
    private function sql(string $queryString, array $allowed, ?string $default = null): string {
      $this->app->instance('request', Request::create('/api/articles' . ($queryString === '' ? '' : '?' . $queryString)));

      return Article::query()->jsonApiSort($allowed, $default)->toSql();
    }

    /**
     * @return list<int>
     */
    private function odds(): array {
      return range(1, 39, 2);
    }

    /**
     * @return list<int>
     */
    private function evens(): array {
      return range(2, 40, 2);
    }

    // ---------------------------------------------------------------------------------------------
    // La macro / The macro
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_macro_is_registered_and_the_builder_stays_chainable(): void {
      $this->assertTrue(Builder::hasGlobalMacro('jsonApiSort'));

      $this->app->instance('request', Request::create('/api/articles?sort=id'));
      $query = Article::query();

      $this->assertSame($query, $query->jsonApiSort(['id']));
    }

    // ---------------------------------------------------------------------------------------------
    // El orden / The order
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_sorts_ascending_and_descending(): void {
      $this->assertSame(range(1, 40), $this->fetch('api/sorted?sort=id')->json('ids'));
      $this->assertSame(range(40, 1), $this->fetch('api/sorted?sort=-id')->json('ids'));
    }

    #[Test]
    public function it_sorts_by_several_fields_in_the_order_asked(): void {
      $response = $this->fetch('api/sorted?sort=status,-id');

      $this->assertSame([...array_reverse($this->odds()), ...array_reverse($this->evens())], $response->json('ids'));
    }

    #[Test]
    public function the_primary_key_breaks_the_ties_so_the_pages_are_stable(): void {
      // `status` solo tiene dos valores: sin desempate, el orden dentro de cada uno quedaría a criterio de la base de datos.
      // `status` only has two values: without a tie-break, the order inside each would be left to the database.
      $expected = [...$this->odds(), ...$this->evens()];

      $pages = [];
      foreach ([1, 2, 3] as $number) {
        $pages = [...$pages, ...$this->fetch("api/sorted-paged?sort=status&page[number]=$number")->json('ids')];
      }

      $this->assertSame($expected, $pages);
    }

    #[Test]
    public function the_tie_break_is_not_repeated_when_the_key_is_already_in_the_order(): void {
      $sql = $this->sql('sort=-id', ['id']);

      $this->assertSame(1, substr_count($sql, '"articles"."id"'));
    }

    #[Test]
    public function the_columns_come_qualified_with_the_table_and_the_key_is_last(): void {
      $this->assertStringContainsString('order by "articles"."title" desc, "articles"."id" asc', $this->sql('sort=-title', ['title']));
    }

    // ---------------------------------------------------------------------------------------------
    // Sin orden pedido / No order asked for
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_default_is_used_when_the_client_asks_for_no_order(): void {
      $this->assertSame(range(40, 1), $this->fetch('api/sorted-by-default')->json('ids'));
    }

    #[Test]
    public function the_default_is_ignored_when_the_client_asks_for_an_order(): void {
      $this->assertSame(range(1, 40), $this->fetch('api/sorted-by-default?sort=id')->json('ids'));
    }

    #[Test]
    public function without_an_order_and_without_a_default_the_query_is_left_alone(): void {
      $this->assertStringNotContainsString('order by', $this->sql('', ['id', 'title']));
    }

    #[Test]
    public function the_default_can_be_a_list_like_the_sort_parameter(): void {
      $this->assertStringContainsString('order by "articles"."status" desc, "articles"."title" asc, "articles"."id" asc', $this->sql('', ['status', 'title'], default: '-status,title'));
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres / Names
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_names_from_the_client_follow_the_member_case(): void {
      // Todos los artículos de prueba tienen el mismo `created_at`: el orden sale del desempate por clave.
      // All the test articles have the same `created_at`: the order comes from the tie-break on the key.
      $this->assertSame(range(1, 40), $this->fetch('api/sorted?sort=-createdAt')->json('ids'));
      $this->assertStringContainsString('"articles"."created_at" desc', $this->sql('sort=-createdAt', ['created_at']));

      config()->set('json-api.member_case', 'kebab');

      $this->assertStringContainsString('"articles"."created_at" desc', $this->sql('sort=-created-at', ['created_at']));
    }

    #[Test]
    public function a_name_can_be_mapped_to_another_column(): void {
      $sql = $this->sql('sort=owner', ['id', 'owner' => 'author_id']);

      $this->assertStringContainsString('"articles"."author_id" asc', $sql);
      $this->assertStringNotContainsString('owner', $sql);
    }

    #[Test]
    public function a_column_that_already_has_a_table_is_left_as_written(): void {
      $this->assertStringContainsString('"authors"."name" asc', $this->sql('sort=author_name', ['author_name' => 'authors.name']));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no está permitido / What is not allowed
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_name_outside_the_list_is_a_400_naming_sort(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $response = $this->fetch('api/sorted?sort=password');

      $response->assertStatus(400);
      $this->assertSame('sort', $response->json('errors.0.source.parameter'));
      $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      $this->assertSame($this->english('request.bad_query.reasons.sort_not_allowed'), $response->json('errors.0.detail'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function one_name_outside_the_list_rejects_the_whole_sort_even_if_the_others_are_fine(): void {
      $response = $this->fetch('api/sorted?sort=title,password,-id');

      $response->assertStatus(400);
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function the_text_never_repeats_the_name_the_client_sent(): void {
      $response = $this->fetch('api/sorted?sort=secretColumn');

      $this->assertStringNotContainsString('secret', strtolower((string) $response->getContent()));
    }

    #[Test]
    public function a_malformed_sort_is_still_the_syntax_400(): void {
      $response = $this->fetch('api/sorted?sort=title,,id');

      $response->assertStatus(400);
      $this->assertSame($this->english('request.bad_query.reasons.empty_item'), $response->json('errors.0.detail'));
    }

    #[Test]
    public function the_name_is_checked_against_the_list_as_converted(): void {
      // `createdAt` se convierte a `created_at`: si la lista no lo tiene, es un 400 aunque el cliente lo haya escrito bien.
      // `createdAt` is converted to `created_at`: if the list does not have it, it is a 400 even though the client wrote it right.
      $this->assertSame(400, $this->fetch('api/sorted-by-default?sort=createdAt')->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador / Developer errors
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_default_outside_the_list_is_a_developer_error(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/nope/');

      $this->sql('', ['id'], default: '-nope');
    }

    #[Test]
    public function an_empty_list_of_allowed_names_rejects_every_sort(): void {
      $this->app->instance('request', Request::create('/api/articles?sort=id'));

      $this->expectException(\MundoProgra\JsonApi\Exceptions\JsonApiException::class);

      Article::query()->jsonApiSort([]);
    }

    // ---------------------------------------------------------------------------------------------
    // Junto con la paginación / Together with pagination
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function sorting_and_paginating_still_takes_two_queries(): void {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->fetch('api/sorted-paged?sort=status&page[size]=10');

      $this->assertCount(2, DB::getQueryLog());
    }
  }
