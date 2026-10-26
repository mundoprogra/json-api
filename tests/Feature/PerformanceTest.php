<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Collection as EloquentCollection;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\BlogArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\CountingBlogArticleResource;
  use MundoProgra\JsonApi\Tests\Support\BuildsBlogArticles;
  use MundoProgra\JsonApi\Tests\Support\UsesArticleTables;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Pruebas básicas de rendimiento: que armar un documento grande no se vuelve pesado cuando los datos crecen. Se miran tres cosas que no dependen de
   * la velocidad de la máquina (el tiempo se deja fuera a propósito, porque haría fallar las pruebas sin motivo):
   *  - el número de consultas no crece con el número de filas (sin N+1);
   *  - la memoria que retiene un documento crece en línea recta con el número de recursos, y por debajo de una cota holgada;
   *  - el trabajo por recurso es fijo: sus relaciones se calculan una vez para `data` y una para `included`, pida el cliente una ruta o varias.
   * Las cotas son unas tres veces lo medido (≈ 4,3 KB por artículo sin `include` y ≈ 8,5 KB con `include`, en 4.000 artículos): no afinan, detectan un
   * descuido grande, como un cambio que de pronto use diez veces más memoria o recorra todo dos veces.
   *
   * Basic performance tests: building a large document does not get heavy as the data grows. Three things are looked at that do not depend on the
   * speed of the machine (time is left out on purpose, because it would make the tests fail for no reason):
   *  - the number of queries does not grow with the number of rows (no N+1);
   *  - the memory a document retains grows in a straight line with the number of resources, and stays below a loose bound;
   *  - the work per resource is fixed: its relationships are worked out once for `data` and once for `included`, whether the client asks for one path
   *    or several.
   * The bounds are about three times what was measured (≈ 4.3 KB per article without `include` and ≈ 8.5 KB with `include`, at 4,000 articles): they do
   * not fine-tune, they catch a big slip, such as a change that suddenly uses ten times more memory or walks everything twice.
   */
  class PerformanceTest extends TestCase {
    use BuildsBlogArticles;
    use UsesArticleTables;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const ALL_PATHS = 'author,tags,comments.author';

    protected function defineRoutes($router): void {
      $router->get('api/db', fn() => BlogArticleResource::collection(
        Article::query()->jsonApiInclude(['author', 'tags', 'comments.author'])->orderBy('id')->jsonApiPaginate()
      ));
    }

    /**
     * Artículos en memoria con autor, dos etiquetas y dos comentarios cada uno; los autores y las etiquetas se comparten.
     * In-memory articles with an author, two tags and two comments each; authors and tags are shared.
     *
     * @return list<Article>
     */
    private function articles(int $count): array {
      $authors = [$this->author(1, 'Ada'), $this->author(2, 'Bob'), $this->author(3, 'Eve')];
      $tags = array_map(fn(string $slug) => $this->tag($slug), ['a', 'b', 'c', 'd', 'e']);
      $articles = [];

      for ($id = 1; $id <= $count; $id++) {
        $articles[] = $this->bareArticle($id)
          ->setRelation('author', $authors[$id % 3])
          ->setRelation('tags', new EloquentCollection([$tags[$id % 5], $tags[($id + 1) % 5]]))
          ->setRelation('comments', new EloquentCollection([
            $this->comment($id * 10, 'First', $authors[0]),
            $this->comment($id * 10 + 1, 'Second', $authors[1]),
          ]));
      }

      return $articles;
    }

    /**
     * La memoria (en KB) que retiene el documento de esos artículos, y cuántos recursos incluye. Antes se arma uno pequeño para que las clases ya estén
     * cargadas y no cuenten.
     *
     * The memory (in KB) the document of those articles retains, and how many resources it includes. A small one is built first so the classes are
     * already loaded and do not count.
     *
     * @param  list<Article>  $articles
     * @return array{float, int}
     */
    private function retained(array $articles, string $include): array {
      $request = Request::create('/api/articles' . ($include === '' ? '' : '?include=' . $include));

      BlogArticleResource::collection(array_slice($articles, 0, 20))->document($request);
      gc_collect_cycles();

      $before = memory_get_usage();
      $document = BlogArticleResource::collection($articles)->document($request);
      $kilobytes = (memory_get_usage() - $before) / 1024;

      return [$kilobytes, count($document['included'] ?? [])];
    }

    // ---------------------------------------------------------------------------------------------
    // Consultas: no crecen con las filas / Queries: they do not grow with the rows
    // ---------------------------------------------------------------------------------------------

    private function queriesFor(string $query): int {
      DB::flushQueryLog();
      DB::enableQueryLog();

      $this->get('api/db?' . $query, ['Accept' => self::MEDIA_TYPE])->assertOk();

      return count(DB::getQueryLog());
    }

    #[Test]
    public function the_queries_stay_the_same_from_ten_rows_to_a_full_page_of_one_hundred(): void {
      $this->seedArticles(300);
      $this->seedRelations(300);

      // Contar + traer la página + una consulta por relación cargada: autor, etiquetas, comentarios y autores de los comentarios.
      // Count + fetch the page + one query per loaded relationship: author, tags, comments and authors of the comments.
      $this->assertSame(6, $this->queriesFor('include=' . self::ALL_PATHS . '&page[size]=10'));
      $this->assertSame(6, $this->queriesFor('include=' . self::ALL_PATHS . '&page[size]=100'));
      $this->assertSame(2, $this->queriesFor('page[size]=10'));
      $this->assertSame(2, $this->queriesFor('page[size]=100'));
    }

    // ---------------------------------------------------------------------------------------------
    // Memoria: lineal y acotada / Memory: linear and bounded
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_memory_of_a_document_grows_in_a_straight_line_with_the_resources(): void {
      $small = $this->articles(1000);
      $large = $this->articles(4000);

      foreach (['' => 'without include', self::ALL_PATHS => 'with include'] as $include => $label) {
        [$few] = $this->retained($small, $include);
        [$many] = $this->retained($large, $include);

        // Cuatro veces más recursos usan unas cuatro veces más memoria; algo cuadrático usaría dieciséis.
        // Four times as many resources use about four times the memory; something quadratic would use sixteen.
        $this->assertGreaterThan(2, $many / $few, "$label: the measure must be taking something.");
        $this->assertLessThan(6, $many / $few, "$label: memory must not grow faster than the resources.");
      }
    }

    #[Test]
    public function four_thousand_articles_stay_under_a_loose_memory_bound(): void {
      $articles = $this->articles(4000);

      [$plain] = $this->retained($articles, '');
      [$compound, $included] = $this->retained($articles, self::ALL_PATHS);

      $this->assertLessThan(13 * 4000, $plain, 'Without include: more than 13 KB per article.');
      $this->assertLessThan(25 * 4000, $compound, 'With include: more than 25 KB per article.');

      // Y el documento compuesto es el correcto a esa escala: 8.000 comentarios, 3 autores y 5 etiquetas, cada uno una sola vez.
      // And the compound document is the right one at that scale: 8,000 comments, 3 authors and 5 tags, each one once.
      $this->assertSame(8008, $included);
    }

    // ---------------------------------------------------------------------------------------------
    // Trabajo por recurso: fijo / Work per resource: fixed
    // ---------------------------------------------------------------------------------------------

    /**
     * Cuántas veces se calcularon las relaciones de los artículos al armar su documento.
     * How many times the relationships of the articles were worked out while building their document.
     */
    private function relationshipCalls(int $count, string $include): int {
      CountingBlogArticleResource::$relationshipCalls = 0;

      CountingBlogArticleResource::collection($this->articles($count))
        ->document(Request::create('/api/articles' . ($include === '' ? '' : '?include=' . $include)));

      return CountingBlogArticleResource::$relationshipCalls;
    }

    #[Test]
    public function the_relationships_of_each_article_are_worked_out_once_for_data_and_once_for_included(): void {
      $this->assertSame(1000, $this->relationshipCalls(1000, ''));
      $this->assertSame(2000, $this->relationshipCalls(1000, 'author'));
    }

    #[Test]
    public function asking_for_more_paths_does_not_work_out_the_relationships_again(): void {
      $this->assertSame(2000, $this->relationshipCalls(1000, self::ALL_PATHS));
      $this->assertSame(2000, $this->relationshipCalls(1000, 'comments.author,tags,author,comments'));
    }

    #[Test]
    public function the_work_per_article_is_the_same_whatever_the_number_of_articles(): void {
      $this->assertSame(2 * 200, $this->relationshipCalls(200, self::ALL_PATHS));
      $this->assertSame(2 * 3000, $this->relationshipCalls(3000, self::ALL_PATHS));
    }
  }
