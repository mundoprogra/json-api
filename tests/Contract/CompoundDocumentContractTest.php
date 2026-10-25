<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Http\Request;
  use Illuminate\Pagination\LengthAwarePaginator;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\BlogArticleResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsCompoundDocuments;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\BuildsBlogArticles;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Cada forma de documento compuesto (con `included`) que el paquete sabe armar, en cada estilo de nombres y con y sin el miembro `jsonapi`, se valida
   * contra el JSON Schema oficial y contra las reglas de la spec que el schema no cubre: sin recursos repetidos ni principales dentro de `included`,
   * y con *full linkage*. Incluye rutas anidadas, un ciclo, una colección y una colección paginada.
   *
   * Every compound document shape (with `included`) the package can build, in each naming style and with and without the `jsonapi` member, is validated
   * against the official JSON Schema and against the spec rules the schema does not cover: no repeated or primary resources inside `included`, and with
   * *full linkage*. It includes nested paths, a cycle, a collection and a paginated collection.
   */
  class CompoundDocumentContractTest extends TestCase {
    use AssertsCompoundDocuments;
    use AssertsJsonApi;
    use BuildsBlogArticles;

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array {
      $names = [
        'a resource with a to-one include',
        'a resource with a to-many include',
        'a resource with nested includes',
        'a resource with every include at once',
        'a collection with includes',
        'a collection with nested includes',
        'a paginated collection with includes',
        'a cycle back to the primary resource',
        'a collection where nothing can be included',
      ];

      return array_combine($names, array_map(fn(string $name) => [$name], $names));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function matrix(): array {
      $cases = [];

      foreach (self::shapes() as $name => [$shape]) {
        foreach (['camel', 'kebab', 'snake'] as $style) {
          foreach ([true, false] as $jsonApiObject) {
            $cases["$name / $style / " . ($jsonApiObject ? 'with jsonapi' : 'without jsonapi')] = [$shape, $style, $jsonApiObject];
          }
        }
      }

      return $cases;
    }

    /**
     * El documento de cada forma y si debe traer `included`.
     * The document of each shape and whether it must carry `included`.
     *
     * @return array{array<string, mixed>, bool}
     */
    private function build(string $shape): array {
      $request = fn(string $include) => Request::create('/api/articles?include=' . $include);

      return match ($shape) {
        'a resource with a to-one include' => [BlogArticleResource::make($this->article())->document($request('author')), true],
        'a resource with a to-many include' => [BlogArticleResource::make($this->article())->document($request('tags')), true],
        'a resource with nested includes' => [BlogArticleResource::make($this->article())->document($request('comments.author')), true],
        'a resource with every include at once' => [BlogArticleResource::make($this->article())->document($request('author,tags,comments.author')), true],
        'a collection with includes' => [BlogArticleResource::collection([$this->article(), $this->secondArticle()])->document($request('author,tags')), true],
        'a collection with nested includes' => [BlogArticleResource::collection([$this->article(), $this->secondArticle()])->document($request('comments.author,tags')), true],
        'a paginated collection with includes' => [
          BlogArticleResource::collection(new LengthAwarePaginator([$this->article(), $this->secondArticle()], 6, 2, 2))->document($request('author,comments.author')),
          true,
        ],
        'a cycle back to the primary resource' => [BlogArticleResource::make($this->articleInACycle())->document($request('author.articles')), true],
        'a collection where nothing can be included' => [BlogArticleResource::collection([$this->bareArticle(10), $this->bareArticle(11)])->document($request('author,tags')), false],
      };
    }

    #[Test]
    #[DataProvider('matrix')]
    public function the_document_is_valid_for_the_official_schema_and_for_the_spec_rules_the_schema_does_not_cover(string $shape, string $style, bool $jsonApiObject): void {
      config()->set('json-api.member_case', $style);
      config()->set('json-api.jsonapi_object', $jsonApiObject);

      [$document, $hasIncluded] = $this->build($shape);

      $this->assertJsonApiDocument($document);
      $this->assertSame($hasIncluded, array_key_exists('included', $document));

      if ($hasIncluded) {
        $this->assertNotSame([], $document['included']);
        $this->assertCompound($document);
      }
    }
  }
