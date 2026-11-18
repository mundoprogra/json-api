<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Query\Eloquent\ApplyCriteria;
  use MundoProgra\JsonApi\Query\ListCriteria;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `ApplyCriteria::filters()` aplica los filtros de un `ListCriteria` a una consulta de Eloquent. Es el puente que usa el repositorio de
   * Infraestructura de una app con Clean Architecture: el `ListCriteria` dice QUÉ pidió el cliente y el mapa del repositorio (`exact`,
   * `partial`, `scopes`) dice a qué columna o scope apunta cada nombre. No lee la petición ni `Settings`: todo viene en sus argumentos. Las
   * reglas son las de `jsonApiFilter()`: `exact` es igualdad (una lista con comas es un `IN`), `partial` es un `LIKE %valor%` con `%` y `_`
   * escapados, `scopes` llama a un scope del modelo con el valor, varios filtros se combinan con AND y un valor vacío se ignora. Un nombre que
   * el mapa no conoce es un error del programador (`LogicException`): el desajuste entre `Allowed` y el repositorio se ve en la primera prueba
   * en vez de ignorar el filtro en silencio y devolver filas de más.
   * `ApplyCriteria::filters()` applies the filters of a `ListCriteria` to an Eloquent query. It is the bridge the Infrastructure repository of a
   * Clean Architecture app uses: the `ListCriteria` says WHAT the client asked for and the repository map (`exact`, `partial`, `scopes`) says
   * which column or scope each name points to. It reads neither the request nor `Settings`: everything comes in its arguments. The rules are
   * those of `jsonApiFilter()`: `exact` is equality (a comma list is an `IN`), `partial` is a `LIKE %value%` with `%` and `_` escaped, `scopes`
   * calls a model scope with the value, several filters combine with AND and an empty value is ignored. A name the map does not know is a
   * developer error (`LogicException`): the mismatch between `Allowed` and the repository shows up in the first test instead of the filter being
   * ignored silently and returning too many rows.
   */
  class ApplyCriteriaFiltersTest extends TestCase {
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
     * @param  array<string, string>  $filters
     */
    private function criteria(array $filters): ListCriteria {
      return new ListCriteria(pageSize: 100, filters: $filters);
    }

    /**
     * @param  Builder<Article>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array {
      return $query->orderBy('id')->pluck('id')->map(fn($id) => (int) $id)->all();
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

    // ---------------------------------------------------------------------------------------------
    // exact
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function exact_filters_by_equality(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['status' => 'published']), exact: ['status']);

      $this->assertSame(range(2, 40, 2), $this->ids($query));
    }

    #[Test]
    public function exact_with_a_comma_list_is_an_in(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['author_id' => '1,2']), exact: ['author_id']);

      $this->assertSame(range(1, 20), $this->ids($query));
      $this->assertStringContainsString('"articles"."author_id" in (?, ?)', $query->toSql());
    }

    #[Test]
    public function exact_ignores_the_empty_items_of_the_list(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['status' => ',published,']), exact: ['status']);

      $this->assertStringContainsString('"articles"."status" = ?', $query->toSql());
      $this->assertSame(range(2, 40, 2), $this->ids($query));
    }

    #[Test]
    public function exact_maps_the_name_to_another_column(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['owner' => '2']), exact: ['owner' => 'author_id']);

      $this->assertSame(range(11, 20), $this->ids($query));
    }

    #[Test]
    public function a_column_that_already_has_a_table_is_left_as_it_is(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['state' => 'draft']), exact: ['state' => 'articles.status']);

      $this->assertStringContainsString('"articles"."status" = ?', $query->toSql());
      $this->assertSame(range(1, 39, 2), $this->ids($query));
    }

    // ---------------------------------------------------------------------------------------------
    // partial
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function partial_is_a_like_with_the_value_in_the_middle(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['title' => 'Article 1']), partial: ['title']);

      $this->assertSame([1, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], $this->ids($query));
    }

    #[Test]
    public function partial_takes_percent_underscore_and_backslash_as_plain_characters(): void {
      $this->addSpecialTitles();

      $percent = ApplyCriteria::filters(Article::query(), $this->criteria(['title' => '%']), partial: ['title']);
      $underscore = ApplyCriteria::filters(Article::query(), $this->criteria(['title' => '_']), partial: ['title']);
      $backslash = ApplyCriteria::filters(Article::query(), $this->criteria(['title' => '\\']), partial: ['title']);

      $this->assertSame([41], $this->ids($percent));
      $this->assertSame([42], $this->ids($underscore));
      $this->assertSame([43], $this->ids($backslash));
    }

    #[Test]
    public function the_partial_value_always_goes_as_a_binding(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['title' => "x' or '1'='1"]), partial: ['title']);

      $this->assertStringNotContainsString("1'='1", $query->toSql());
      $this->assertSame(["%x' or '1'='1%"], $query->getBindings());
    }

    // ---------------------------------------------------------------------------------------------
    // scopes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_scope_is_called_with_the_value(): void {
      $published = ApplyCriteria::filters(Article::query(), $this->criteria(['published' => '1']), scopes: ['published']);
      $drafts = ApplyCriteria::filters(Article::query(), $this->criteria(['published' => '0']), scopes: ['published']);

      $this->assertSame(range(2, 40, 2), $this->ids($published));
      $this->assertSame(range(1, 39, 2), $this->ids($drafts));
    }

    #[Test]
    public function a_scope_name_can_point_to_another_scope(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['live' => '1']), scopes: ['live' => 'published']);

      $this->assertSame(range(2, 40, 2), $this->ids($query));
    }

    // ---------------------------------------------------------------------------------------------
    // En conjunto / Together
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function several_filters_combine_with_and(): void {
      $query = ApplyCriteria::filters(
        Article::query(),
        $this->criteria(['status' => 'published', 'author_id' => '1']),
        exact: ['status', 'author_id'],
      );

      $this->assertSame([2, 4, 6, 8, 10], $this->ids($query));
    }

    #[Test]
    public function without_filters_the_query_is_left_alone_and_it_is_the_same_builder(): void {
      $query = Article::query();

      $result = ApplyCriteria::filters($query, $this->criteria([]), exact: ['status']);

      $this->assertSame($query, $result);
      $this->assertStringNotContainsString('where', $result->toSql());
    }

    #[Test]
    public function an_empty_value_is_ignored(): void {
      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['status' => '']), exact: ['status']);

      $this->assertStringNotContainsString('where', $query->toSql());
    }

    #[Test]
    public function it_does_not_read_the_request_only_the_criteria(): void {
      // Aunque la petición del contenedor pida otra cosa, manda el `ListCriteria`: no hay dependencia oculta de `app('request')`.
      // Even if the container's request asks for something else, the `ListCriteria` rules: there is no hidden dependency on `app('request')`.
      $this->app->instance('request', Request::create('/api/articles?filter[status]=draft'));

      $query = ApplyCriteria::filters(Article::query(), $this->criteria(['status' => 'published']), exact: ['status']);

      $this->assertSame(range(2, 40, 2), $this->ids($query));
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador / Developer errors
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_filter_the_map_does_not_know_is_a_developer_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/"nope"/');

      ApplyCriteria::filters(Article::query(), $this->criteria(['nope' => '1']), exact: ['status']);
    }

    #[Test]
    public function a_name_in_more_than_one_list_is_a_developer_error(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/"status".*more than one list/');

      ApplyCriteria::filters(Article::query(), $this->criteria(['status' => 'draft']), exact: ['status'], partial: ['status']);
    }
  }
