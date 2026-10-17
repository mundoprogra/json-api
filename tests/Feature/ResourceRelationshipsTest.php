<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use LogicException;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\ArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\TagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Las relaciones de un recurso: `Relationship::one($modelo, Recurso::class)` y `Relationship::many($modelos, Recurso::class)`, con su
   * *resource linkage* (solo `type` e `id`), y `links` y `meta` opcionales. Salen solo si la relación está cargada (`whenLoaded`);
   * una relación a uno cargada pero vacía (que `whenLoaded` entrega como `null`) sale como `data: null`, y una a muchos vacía como `data: []`.
   * Los nombres siguen las reglas de los atributos: `member_case`, `id` y `type` prohibidos y sin repetirse entre atributos y relaciones.
   * The relationships of a resource: `Relationship::one($model, Resource::class)` and `Relationship::many($models, Resource::class)`, with their
   * *resource linkage* (only `type` and `id`), and optional `links` and `meta`. They only come out when the relation is loaded (`whenLoaded`);
   * a to-one relation that is loaded but empty (which `whenLoaded` hands over as `null`) comes out as `data: null`, and an empty to-many as `data: []`.
   * The names follow the attribute rules: `member_case`, `id` and `type` forbidden and no repeats between attributes and relationships.
   */
  class ResourceRelationshipsTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private function author(): Author {
      return (new Author())->forceFill(['id' => 7, 'name' => 'Ada']);
    }

    /**
     * @return list<Tag>
     */
    private function tags(): array {
      return [
        (new Tag())->forceFill(['id' => 1, 'slug' => 'php', 'name' => 'PHP']),
        (new Tag())->forceFill(['id' => 2, 'slug' => 'laravel', 'name' => 'Laravel']),
      ];
    }

    /**
     * Un artículo con las relaciones indicadas ya "cargadas".
     * An article with the given relations already "loaded".
     *
     * @param  array<string, mixed>  $loaded
     */
    private function article(array $loaded = []): Article {
      $article = (new Article())->forceFill(['id' => 1, 'title' => 'Hello']);

      foreach ($loaded as $name => $value) {
        $article->setRelation($name, $value);
      }

      return $article;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(ResourceObject $resource): array {
      return $resource->resolve(Request::create('/api/articles/1'));
    }

    /**
     * @param  array<string, mixed>  $loaded
     * @return array<string, mixed>
     */
    private function relationshipsOf(array $loaded): array {
      return $this->resolve(ArticleResource::make($this->article($loaded)))['relationships'];
    }

    /**
     * Un recurso con los atributos y las relaciones indicados, sean cuales sean.
     * A resource with the given attributes and relationships, whatever they are.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string, mixed>  $relationships
     */
    private function resourceWith(array $attributes, array $relationships): ResourceObject {
      return new class($this->article(), $attributes, $relationships) extends ResourceObject {
        /**
         * @param  array<string, mixed>  $attributesToShow
         * @param  array<int|string, mixed>  $relationshipsToShow
         */
        public function __construct(mixed $resource, private readonly array $attributesToShow, private readonly array $relationshipsToShow) {
          parent::__construct($resource);
        }

        protected function jsonApiAttributes(Request $request): array {
          return $this->attributesToShow;
        }

        protected function jsonApiRelationships(Request $request): array {
          return $this->relationshipsToShow;
        }
      };
    }

    // ---------------------------------------------------------------------------------------------
    // A uno / To-one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_one_relation_comes_out_as_a_resource_identifier(): void {
      $this->assertSame(
        ['author' => ['data' => ['type' => 'authors', 'id' => '7']]],
        $this->relationshipsOf(['author' => $this->author()]),
      );
    }

    #[Test]
    public function the_linkage_only_has_the_identity_not_the_attributes(): void {
      $data = $this->relationshipsOf(['author' => $this->author()])['author']['data'];

      $this->assertSame(['type', 'id'], array_keys($data));
    }

    #[Test]
    public function a_loaded_but_empty_to_one_relation_comes_out_as_data_null(): void {
      // `whenLoaded` entrega `null` (sin llamar a la función) cuando la relación está cargada y vacía.
      // `whenLoaded` hands over `null` (without calling the function) when the relation is loaded and empty.
      $relationships = $this->relationshipsOf(['editor' => null]);

      $this->assertSame(['editor' => ['data' => null]], $relationships);
      $this->assertArrayHasKey('data', $relationships['editor']);
    }

    #[Test]
    public function an_empty_to_one_relationship_can_also_be_built_directly(): void {
      $resource = $this->resourceWith([], ['editor' => Relationship::one(null, AuthorResource::class)]);

      $this->assertSame(['editor' => ['data' => null]], $this->resolve($resource)['relationships']);
    }

    // ---------------------------------------------------------------------------------------------
    // A muchos / To-many
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_many_relation_comes_out_as_a_list_of_identifiers_in_order(): void {
      $this->assertSame(
        ['tags' => ['data' => [['type' => 'labels', 'id' => 'php'], ['type' => 'labels', 'id' => 'laravel']]]],
        $this->relationshipsOf(['tags' => collect($this->tags())]),
      );
    }

    #[Test]
    public function the_id_of_the_linkage_is_the_one_the_related_resource_says(): void {
      // Las etiquetas tienen `slug` como clave de ruta: ese es su id, no el número.
      // The tags have `slug` as route key: that is their id, not the number.
      $ids = array_column($this->relationshipsOf(['tags' => $this->tags()])['tags']['data'], 'id');

      $this->assertSame(['php', 'laravel'], $ids);
    }

    #[Test]
    public function an_empty_to_many_relation_is_an_empty_list_not_an_omitted_member(): void {
      $this->assertSame(['tags' => ['data' => []]], $this->relationshipsOf(['tags' => collect()]));
    }

    // ---------------------------------------------------------------------------------------------
    // Relación no cargada / Relation not loaded
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_relation_that_is_not_loaded_is_left_out(): void {
      $relationships = $this->relationshipsOf(['author' => $this->author()]);

      $this->assertSame(['author'], array_keys($relationships));
    }

    #[Test]
    public function without_any_loaded_relation_there_is_no_relationships_member(): void {
      $this->assertArrayNotHasKey('relationships', $this->resolve(ArticleResource::make($this->article())));
    }

    // ---------------------------------------------------------------------------------------------
    // Orden / Order
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_relationships_come_after_the_attributes_and_before_the_links(): void {
      $resource = new class($this->article(['author' => $this->author()])) extends ArticleResource {
        protected function jsonApiRoute(): ?string {
          return null;
        }

        protected function jsonApiLinks(Request $request): array {
          return ['self' => 'https://example.com/articles/1'];
        }

        protected function jsonApiMeta(Request $request): array {
          return ['a' => 1];
        }
      };

      $this->assertSame(['type', 'id', 'attributes', 'relationships', 'links', 'meta'], array_keys($this->resolve($resource)));
    }

    // ---------------------------------------------------------------------------------------------
    // links y meta de la relación / links and meta of the relationship
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_relationship_can_carry_links_before_the_data(): void {
      $relationship = Relationship::one($this->author(), AuthorResource::class)
        ->links(self: 'https://example.com/articles/1/relationships/author', related: 'https://example.com/articles/1/author');

      $this->assertSame([
        'links' => [
          'self' => 'https://example.com/articles/1/relationships/author',
          'related' => 'https://example.com/articles/1/author',
        ],
        'data' => ['type' => 'authors', 'id' => '7'],
      ], $this->resolve($this->resourceWith([], ['author' => $relationship]))['relationships']['author']);
    }

    #[Test]
    public function only_the_links_that_are_given_come_out(): void {
      $relationship = Relationship::many($this->tags(), TagResource::class)->links(related: 'https://example.com/articles/1/tags');

      $this->assertSame(
        ['related' => 'https://example.com/articles/1/tags'],
        $this->resolve($this->resourceWith([], ['tags' => $relationship]))['relationships']['tags']['links'],
      );
    }

    #[Test]
    public function a_relationship_can_carry_meta_after_the_data(): void {
      $relationship = Relationship::one($this->author(), AuthorResource::class)->meta(['since_year' => 2020]);

      $this->assertSame(
        ['data' => ['type' => 'authors', 'id' => '7'], 'meta' => ['since_year' => 2020]],
        $this->resolve($this->resourceWith([], ['author' => $relationship]))['relationships']['author'],
      );
    }

    #[Test]
    public function without_links_or_meta_those_members_are_left_out(): void {
      $relationship = Relationship::one($this->author(), AuthorResource::class)->links()->meta([]);

      $this->assertSame(
        ['data' => ['type' => 'authors', 'id' => '7']],
        $this->resolve($this->resourceWith([], ['author' => $relationship]))['relationships']['author'],
      );
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres / Names
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function styles(): array {
      return [
        'camel' => ['camel', 'articleAuthor'],
        'kebab' => ['kebab', 'article-author'],
        'snake' => ['snake', 'article_author'],
      ];
    }

    #[Test]
    #[DataProvider('styles')]
    public function the_relationship_names_follow_the_member_case(string $style, string $expected): void {
      config()->set('json-api.member_case', $style);

      $resource = $this->resourceWith([], ['article_author' => Relationship::one($this->author(), AuthorResource::class)]);

      $this->assertSame([$expected], array_keys($this->resolve($resource)['relationships']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNames(): array {
      return ['id' => ['id'], 'type' => ['type']];
    }

    #[Test]
    #[DataProvider('reservedNames')]
    public function a_relationship_cannot_be_named_id_or_type(string $name): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/"' . $name . '"/');
      $this->expectExceptionMessageMatches('/relationship/i');

      $this->resolve($this->resourceWith([], [$name => Relationship::one($this->author(), AuthorResource::class)]));
    }

    #[Test]
    public function an_attribute_and_a_relationship_cannot_share_a_name(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/"author"/');

      $this->resolve($this->resourceWith(['author' => 'Ada'], ['author' => Relationship::one($this->author(), AuthorResource::class)]));
    }

    #[Test]
    public function the_names_are_compared_after_the_conversion(): void {
      // `article_author` y `articleAuthor` terminan siendo el mismo nombre en estilo camel.
      // `article_author` and `articleAuthor` end up being the same name in camel style.
      $this->expectException(LogicException::class);

      $this->resolve($this->resourceWith(['articleAuthor' => 'x'], ['article_author' => Relationship::one($this->author(), AuthorResource::class)]));
    }

    #[Test]
    public function the_same_names_are_fine_when_the_style_keeps_them_apart(): void {
      config()->set('json-api.member_case', 'snake');

      $data = $this->resolve($this->resourceWith(['articleAuthor' => 'x'], ['article_author' => Relationship::one($this->author(), AuthorResource::class)]));

      $this->assertSame(['articleAuthor' => 'x'], $data['attributes']);
      $this->assertSame(['article_author'], array_keys($data['relationships']));
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador / Developer errors
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_related_resource_without_an_id_is_an_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/id/');

      $this->resolve($this->resourceWith([], ['author' => Relationship::one(new Author(), AuthorResource::class)]));
    }

    #[Test]
    public function something_that_is_not_a_relationship_is_an_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/Relationship/');

      $this->resolve($this->resourceWith([], ['author' => 'oops']));
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_document_with_relationships_is_valid_and_keeps_empty_values_in_the_json(): void {
      $article = $this->article(['author' => $this->author(), 'editor' => null, 'tags' => collect()]);
      $this->app->make('router')->get('api/articles/1', fn() => ArticleResource::make($article));

      $response = $this->get('api/articles/1', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      // Un `data: null` y un `data: []` son valores distintos y válidos: no se pueden perder en el JSON.
      // A `data: null` and a `data: []` are different, valid values: they must not be lost in the JSON.
      $this->assertStringContainsString('"editor":{"data":null}', (string) $response->getContent());
      $this->assertStringContainsString('"tags":{"data":[]}', (string) $response->getContent());
      $this->assertSame(['type' => 'authors', 'id' => '7'], $response->json('data.relationships.author.data'));
    }
  }
