<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Query\Allowed;
  use MundoProgra\JsonApi\Query\Eloquent\ApplyCriteria;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La garantía de que `ApplyCriteria` y las macros `jsonApiFilter()`, `jsonApiSort()` y `jsonApiInclude()` arman la MISMA consulta: para una
   * matriz de peticiones, la cadena de macros (que lee la petición del contenedor) y `criteria()` + `ApplyCriteria::to()` (que reciben todo en
   * sus argumentos) dan el mismo `toSql()`, los mismos bindings y las mismas relaciones por cargar. Con ella, una app existente no nota que las
   * macros se apoyan en el puente, y una app con Clean Architecture obtiene exactamente lo que habría obtenido con las macros.
   * The guarantee that `ApplyCriteria` and the `jsonApiFilter()`, `jsonApiSort()` and `jsonApiInclude()` macros build the SAME query: for a
   * matrix of requests, the macro chain (which reads the request from the container) and `criteria()` + `ApplyCriteria::to()` (which take
   * everything in their arguments) give the same `toSql()`, the same bindings and the same relations to load. With it, an existing app does not
   * notice the macros rest on the bridge, and a Clean Architecture app gets exactly what it would have got with the macros.
   */
  class MacrosEqualApplyCriteriaTest extends TestCase {
    use UsesArticleTables;

    /** @var array<int|string, string> */
    private const EXACT = ['status', 'owner' => 'author_id'];

    /** @var array<int|string, string> */
    private const PARTIAL = ['title'];

    /** @var array<int|string, string> */
    private const SCOPES = ['published', 'live' => 'published'];

    /** @var array<int|string, string> */
    private const SORT = ['id', 'title', 'status', 'owner' => 'author_id'];

    /** @var list<string> */
    private const INCLUDE = ['author', 'tags', 'comments.author'];

    private function allowed(): Allowed {
      return Allowed::make(
        filter: ['status', 'owner', 'title', 'published', 'live'],
        sort: ['id', 'title', 'status', 'owner'],
        include: self::INCLUDE,
        defaultSort: '-id',
      );
    }

    /**
     * La consulta que arma la cadena de macros para una petición con esa query string.
     * The query the macro chain builds for a request with that query string.
     *
     * @return Builder<Article>
     */
    private function viaMacros(string $queryString): Builder {
      $this->app->instance('request', Request::create('/api/articles?' . $queryString));

      return Article::query()
        ->jsonApiFilter(exact: self::EXACT, partial: self::PARTIAL, scopes: self::SCOPES)
        ->jsonApiSort(self::SORT, default: '-id')
        ->jsonApiInclude(self::INCLUDE);
    }

    /**
     * La consulta que arma `criteria()` + `ApplyCriteria::to()` para la misma query string, con una petición que el contenedor no conoce.
     * The query `criteria()` + `ApplyCriteria::to()` build for the same query string, with a request the container does not know.
     *
     * @return Builder<Article>
     */
    private function viaBridge(string $queryString): Builder {
      $criteria = QueryParameters::of(Request::create('/api/articles?' . $queryString))->criteria($this->allowed());

      return ApplyCriteria::to(Article::query(), $criteria, exact: self::EXACT, partial: self::PARTIAL, scopes: self::SCOPES, sort: self::SORT);
    }

    /**
     * @param  Builder<Article>  $query
     * @return array{sql: string, bindings: list<mixed>, loads: list<string>}
     */
    private function shape(Builder $query): array {
      return ['sql' => $query->toSql(), 'bindings' => $query->getBindings(), 'loads' => array_keys($query->getEagerLoads())];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requests(): array {
      return [
        'no query' => [''],
        'exact' => ['filter[status]=published'],
        'exact with a comma list' => ['filter[status]=draft,published'],
        'exact with empty items' => ['filter[status]=,published,'],
        'exact mapped to another column' => ['filter[owner]=2'],
        'partial' => ['filter[title]=Article%201'],
        'partial with a percent' => ['filter[title]=100%25'],
        'partial with an underscore' => ['filter[title]=50_50'],
        'partial with a bang' => ['filter[title]=wow!'],
        'scope' => ['filter[published]=1'],
        'scope under another name' => ['filter[live]=0'],
        'several filters' => ['filter[status]=published&filter[owner]=1&filter[title]=A'],
        'an empty value' => ['filter[status]=&filter[title]=x'],
        'sort' => ['sort=title'],
        'sort descending and several' => ['sort=-status,title'],
        'sort mapped to another column' => ['sort=-owner'],
        'sort by the primary key' => ['sort=id'],
        'sort by the same column twice' => ['sort=title,-title'],
        'include' => ['include=author'],
        'several includes' => ['include=author,tags'],
        'a nested include' => ['include=comments.author'],
        'everything together' => ['filter[status]=published&filter[title]=A&sort=-owner,title&include=author,comments.author'],
        'page parameters are not part of the query' => ['filter[status]=draft&page[number]=2&page[size]=5'],
      ];
    }

    #[Test]
    #[DataProvider('requests')]
    public function the_macros_and_the_bridge_build_the_same_query(string $queryString): void {
      $this->assertSame($this->shape($this->viaMacros($queryString)), $this->shape($this->viaBridge($queryString)));
    }

    #[Test]
    public function both_ways_bring_the_same_rows_from_the_database(): void {
      $this->seedArticles(40);
      Article::query()->whereBetween('id', [1, 10])->update(['author_id' => 1]);
      Article::query()->whereBetween('id', [11, 20])->update(['author_id' => 2]);

      $queryString = 'filter[status]=published&sort=-owner,title';

      $this->assertSame(
        $this->viaMacros($queryString)->pluck('id')->all(),
        $this->viaBridge($queryString)->pluck('id')->all(),
      );
    }
  }
