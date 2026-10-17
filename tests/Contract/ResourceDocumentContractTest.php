<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\TagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Cada forma de documento que el paquete sabe armar para un recurso, en cada estilo de nombres y con y sin el miembro `jsonapi`, se valida
   * contra el JSON Schema oficial. Un cambio que rompa el documento de una sola de estas formas hace fallar esta prueba.
   * Every document shape the package can build for a resource, in each naming style and with and without the `jsonapi` member, is validated
   * against the official JSON Schema. A change that breaks the document of a single one of these shapes makes this test fail.
   */
  class ResourceDocumentContractTest extends TestCase {
    use AssertsJsonApi;

    protected function defineRoutes($router): void {
      $router->get('api/articles/{article}', fn() => 'show')->name('articles.show');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array {
      $names = [
        'only type and id',
        'attributes',
        'null and nested attributes',
        'self link',
        'self link written by hand',
        'self link as a link object',
        'meta',
        'to-one relationship',
        'empty to-one relationship',
        'to-many relationship',
        'empty to-many relationship',
        'relationship with links and meta',
        'everything together',
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

    #[Test]
    #[DataProvider('matrix')]
    public function the_document_is_valid_for_the_official_schema(string $shape, string $style, bool $jsonApiObject): void {
      config()->set('json-api.member_case', $style);
      config()->set('json-api.jsonapi_object', $jsonApiObject);

      $resource = $this->resourceFor($shape);
      $request = Request::create('/api/articles/1');

      // El cuerpo como arreglo y el de la respuesta HTTP son el mismo y los dos son válidos.
      // The body as an array and the one of the HTTP response are the same and both are valid.
      $this->assertJsonApiDocument((string) json_encode($resource->document($request)), "$shape / $style");
      $this->assertJsonApiDocument((string) $resource->toResponse($request)->getContent(), "$shape / $style");
    }

    #[Test]
    public function a_just_created_resource_is_valid_too(): void {
      $author = (new Author())->forceFill(['id' => 7, 'name' => 'Ada']);
      $author->wasRecentlyCreated = true;

      $response = AuthorResource::make($author)->toResponse(Request::create('/api/authors'));

      $this->assertSame(201, $response->getStatusCode());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    private function resourceFor(string $shape): ResourceObject {
      $author = (new Author())->forceFill(['id' => 7, 'name' => 'Ada']);
      $tags = [
        (new Tag())->forceFill(['id' => 1, 'slug' => 'php', 'name' => 'PHP']),
        (new Tag())->forceFill(['id' => 2, 'slug' => 'laravel', 'name' => 'Laravel']),
      ];

      [$attributes, $relationships, $links, $meta, $route] = match ($shape) {
        'only type and id' => [[], [], [], [], null],
        'attributes' => [['title' => 'Hello', 'word_count' => 3], [], [], [], null],
        'null and nested attributes' => [['subtitle' => null, 'seo_data' => ['keywords' => ['a', 'b'], 'robots' => ['index' => true]]], [], [], [], null],
        'self link' => [['title' => 'Hello'], [], [], [], 'articles.show'],
        'self link written by hand' => [['title' => 'Hello'], [], ['self' => 'https://example.com/me'], [], null],
        'self link as a link object' => [['title' => 'Hello'], [], ['self' => ['href' => 'https://example.com/me', 'title' => 'Me', 'meta' => ['a' => 1]]], [], null],
        'meta' => [['title' => 'Hello'], [], [], ['word_count' => 3, 'copyright' => null], null],
        'to-one relationship' => [[], ['author' => Relationship::one($author, AuthorResource::class)], [], [], null],
        'empty to-one relationship' => [[], ['editor' => Relationship::one(null, AuthorResource::class)], [], [], null],
        'to-many relationship' => [[], ['tags' => Relationship::many($tags, TagResource::class)], [], [], null],
        'empty to-many relationship' => [[], ['tags' => Relationship::many([], TagResource::class)], [], [], null],
        'relationship with links and meta' => [[], [
          'article_author' => Relationship::one($author, AuthorResource::class)
            ->links(self: 'https://example.com/articles/1/relationships/author', related: 'https://example.com/articles/1/author')
            ->meta(['since_year' => 2020]),
        ], [], [], null],
        'everything together' => [
          ['title' => 'Hello', 'subtitle' => null, 'seo_data' => ['robots' => 'all']],
          [
            'article_author' => Relationship::one($author, AuthorResource::class)->links(related: 'https://example.com/articles/1/author'),
            'editor' => Relationship::one(null, AuthorResource::class),
            'tags' => Relationship::many($tags, TagResource::class),
          ],
          ['self' => ['href' => 'https://example.com/me', 'meta' => ['a' => 1]]],
          ['word_count' => 3],
          'articles.show',
        ],
      };

      return new class((new Article())->forceFill(['id' => 1]), $attributes, $relationships, $links, $meta, $route) extends ResourceObject {
        /**
         * @param  array<string, mixed>  $attributes
         * @param  array<string, mixed>  $relationships
         * @param  array<string, mixed>  $links
         * @param  array<string, mixed>  $meta
         */
        public function __construct(
          mixed $resource,
          private readonly array $attributes_,
          private readonly array $relationships_,
          private readonly array $links_,
          private readonly array $meta_,
          private readonly ?string $route_,
        ) {
          parent::__construct($resource);
        }

        protected function jsonApiAttributes(Request $request): array {
          return $this->attributes_;
        }

        protected function jsonApiRelationships(Request $request): array {
          return $this->relationships_;
        }

        protected function jsonApiLinks(Request $request): array {
          return $this->links_;
        }

        protected function jsonApiMeta(Request $request): array {
          return $this->meta_;
        }

        protected function jsonApiRoute(): ?string {
          return $this->route_;
        }
      };
    }
  }
