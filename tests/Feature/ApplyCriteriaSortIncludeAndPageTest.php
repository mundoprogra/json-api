<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use LogicException;
  use MundoProgra\JsonApi\Query\Eloquent\ApplyCriteria;
  use MundoProgra\JsonApi\Query\ListCriteria;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `ApplyCriteria::sort()`, `includes()`, `paginate()` y `to()` aplican a una consulta de Eloquent el orden, las inclusiones y la página de un
   * `ListCriteria`; `to()` junta los filtros, el orden y las inclusiones. Igual que `filters()`, no leen la petición ni `Settings`.
   * `sort()` aplica el orden en el orden dado, mapea cada nombre a su columna con el mapa del repositorio, no repite una columna y agrega la
   * clave primaria al final (si no está ya) para que las páginas sean estables; sin orden deja la consulta como está, y un nombre que el mapa no
   * conoce es un error del programador (`LogicException`). `includes()` carga por anticipado las rutas del criterio. `paginate()` trae la página y
   * el tamaño del criterio.
   * `ApplyCriteria::sort()`, `includes()`, `paginate()` and `to()` apply the sort, the includes and the page of a `ListCriteria` to an Eloquent
   * query; `to()` puts together the filters, the sort and the includes. Like `filters()`, they read neither the request nor `Settings`.
   * `sort()` applies the order in the order given, maps each name to its column with the repository map, does not repeat a column and adds the
   * primary key at the end (if it is not there already) so the pages are stable; with no sort it leaves the query as it is, and a name the map
   * does not know is a developer error (`LogicException`). `includes()` eager loads the paths of the criteria. `paginate()` fetches the page and
   * the size of the criteria.
   */
  class ApplyCriteriaSortIncludeAndPageTest extends TestCase {
    use UsesArticleTables;

    protected function setUp(): void {
      parent::setUp();

      $this->seedArticles(40);
      // Los artículos 1 a 10 son del autor 1 y del 11 al 20 del autor 2; el resto no tiene autor.
      // Articles 1 to 10 belong to author 1 and 11 to 20 to author 2; the rest have no author.
      Article::query()->whereBetween('id', [1, 10])->update(['author_id' => 1]);
      Article::query()->whereBetween('id', [11, 20])->update(['author_id' => 2]);
    }

    /**
     * @param  list<array{field: string, descending: bool}>  $sort
     * @param  list<string>  $includes
     * @param  array<string, string>  $filters
     */
    private function criteria(array $sort = [], array $includes = [], array $filters = [], int $pageNumber = 1, int $pageSize = 100): ListCriteria {
      return new ListCriteria(pageSize: $pageSize, pageNumber: $pageNumber, filters: $filters, sort: $sort, includes: $includes);
    }

    /**
     * Los ids en el orden en que los trae la consulta, sin agregarle ningún orden.
     * The ids in the order the query brings them, without adding any order.
     *
     * @param  Builder<Article>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array {
      return $query->pluck('id')->map(fn($id) => (int) $id)->all();
    }

    // ---------------------------------------------------------------------------------------------
    // sort()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_sort_is_applied_in_the_order_given_with_its_directions(): void {
      $criteria = $this->criteria(sort: [['field' => 'status', 'descending' => false], ['field' => 'id', 'descending' => true]]);

      $query = ApplyCriteria::sort(Article::query(), $criteria, ['status', 'id']);

      // Primero los borradores (ids impares) y luego los publicados (pares), cada grupo de mayor a menor.
      // First the drafts (odd ids) and then the published ones (even), each group from highest to lowest.
      $this->assertSame([...range(39, 1, -2), ...range(40, 2, -2)], $this->ids($query));
    }

    #[Test]
    public function a_name_is_mapped_to_its_column(): void {
      $criteria = $this->criteria(sort: [['field' => 'owner', 'descending' => true]]);

      $query = ApplyCriteria::sort(Article::query(), $criteria, ['owner' => 'author_id']);

      $this->assertSame([...range(11, 20), ...range(1, 10), ...range(21, 40)], $this->ids($query));
    }

    #[Test]
    public function the_primary_key_is_added_at_the_end_as_a_tie_break(): void {
      $criteria = $this->criteria(sort: [['field' => 'status', 'descending' => false]]);

      $query = ApplyCriteria::sort(Article::query(), $criteria, ['status']);

      $this->assertStringContainsString('order by "articles"."status" asc, "articles"."id" asc', $query->toSql());
      $this->assertSame([...range(1, 39, 2), ...range(2, 40, 2)], $this->ids($query));
    }

    #[Test]
    public function the_primary_key_is_not_added_twice_when_it_is_already_in_the_sort(): void {
      $criteria = $this->criteria(sort: [['field' => 'id', 'descending' => true]]);

      $sql = ApplyCriteria::sort(Article::query(), $criteria, ['id'])->toSql();

      $this->assertSame(1, substr_count($sql, '"articles"."id"'));
    }

    #[Test]
    public function a_column_two_names_point_to_is_ordered_only_once(): void {
      $criteria = $this->criteria(sort: [['field' => 'owner', 'descending' => false], ['field' => 'author', 'descending' => true]]);

      $sql = ApplyCriteria::sort(Article::query(), $criteria, ['owner' => 'author_id', 'author' => 'author_id'])->toSql();

      $this->assertSame(1, substr_count($sql, 'author_id'));
    }

    #[Test]
    public function a_column_that_already_has_a_table_is_left_as_it_is(): void {
      $criteria = $this->criteria(sort: [['field' => 'state', 'descending' => false]]);

      $sql = ApplyCriteria::sort(Article::query(), $criteria, ['state' => 'articles.status'])->toSql();

      $this->assertStringContainsString('order by "articles"."status" asc', $sql);
    }

    #[Test]
    public function without_a_sort_the_query_is_left_alone_and_it_is_the_same_builder(): void {
      $query = Article::query();

      $result = ApplyCriteria::sort($query, $this->criteria(), ['status']);

      $this->assertSame($query, $result);
      $this->assertStringNotContainsString('order by', $result->toSql());
    }

    #[Test]
    public function a_sort_name_the_map_does_not_know_is_a_developer_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/"nope"/');

      ApplyCriteria::sort(Article::query(), $this->criteria(sort: [['field' => 'nope', 'descending' => false]]), ['status']);
    }

    #[Test]
    public function the_sort_does_not_read_the_request(): void {
      $this->app->instance('request', Request::create('/api/articles?sort=-id'));

      $query = ApplyCriteria::sort(Article::query(), $this->criteria(sort: [['field' => 'id', 'descending' => false]]), ['id']);

      $this->assertSame(range(1, 40), $this->ids($query));
    }

    // ---------------------------------------------------------------------------------------------
    // includes()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_includes_are_eager_loaded(): void {
      $query = ApplyCriteria::includes(Article::query(), $this->criteria(includes: ['author', 'tags']));

      $this->assertSame(['author', 'tags'], array_keys($query->getEagerLoads()));
    }

    #[Test]
    public function a_path_with_levels_is_eager_loaded_with_its_prefixes(): void {
      $query = ApplyCriteria::includes(Article::query(), $this->criteria(includes: ['comments.author']));

      $this->assertSame(['comments', 'comments.author'], array_keys($query->getEagerLoads()));
    }

    #[Test]
    public function the_included_relations_are_really_loaded(): void {
      $this->seedRelations(20);

      $article = ApplyCriteria::includes(Article::query(), $this->criteria(includes: ['author']))->orderBy('id')->firstOrFail();

      $this->assertTrue($article->relationLoaded('author'));
      $this->assertSame(1, $article->getRelation('author')->getKey());
    }

    #[Test]
    public function without_includes_nothing_is_loaded_and_it_is_the_same_builder(): void {
      $query = Article::query();

      $result = ApplyCriteria::includes($query, $this->criteria());

      $this->assertSame($query, $result);
      $this->assertSame([], $result->getEagerLoads());
    }

    // ---------------------------------------------------------------------------------------------
    // paginate()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_and_the_size_are_those_of_the_criteria(): void {
      // La petición del contenedor pide otra página: manda el criterio, sin dependencia oculta de `app('request')`.
      // The container's request asks for another page: the criteria rules, with no hidden dependency on `app('request')`.
      $this->app->instance('request', Request::create('/api/articles?page[number]=1&page[size]=50'));

      $page = ApplyCriteria::paginate(Article::query()->orderBy('id'), $this->criteria(pageNumber: 3, pageSize: 5));

      $this->assertSame([11, 12, 13, 14, 15], $page->getCollection()->pluck('id')->map(fn($id) => (int) $id)->all());
      $this->assertSame(3, $page->currentPage());
      $this->assertSame(5, $page->perPage());
      $this->assertSame(40, $page->total());
    }

    #[Test]
    public function a_page_out_of_range_is_an_empty_page(): void {
      $page = ApplyCriteria::paginate(Article::query()->orderBy('id'), $this->criteria(pageNumber: 99, pageSize: 5));

      $this->assertCount(0, $page->getCollection());
      $this->assertSame(40, $page->total());
    }

    // ---------------------------------------------------------------------------------------------
    // to()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function to_applies_the_filters_the_sort_and_the_includes_together(): void {
      $criteria = $this->criteria(
        sort: [['field' => 'id', 'descending' => true]],
        includes: ['author'],
        filters: ['status' => 'published'],
      );

      $query = ApplyCriteria::to(Article::query(), $criteria, exact: ['status'], sort: ['id']);

      $this->assertSame(range(40, 2, -2), $this->ids($query));
      $this->assertSame(['author'], array_keys($query->getEagerLoads()));
    }

    #[Test]
    public function to_does_not_paginate_and_returns_the_same_builder(): void {
      $query = Article::query();

      $result = ApplyCriteria::to($query, $this->criteria(pageNumber: 2, pageSize: 5));

      $this->assertSame($query, $result);
      $this->assertCount(40, $result->get());
    }

    #[Test]
    public function to_reports_a_name_the_repository_does_not_map(): void {
      $this->expectException(LogicException::class);

      ApplyCriteria::to(Article::query(), $this->criteria(filters: ['nope' => '1']), exact: ['status']);
    }
  }
