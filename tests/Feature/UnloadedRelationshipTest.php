<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Collection as EloquentCollection;
  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use LogicException;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\LinkedArticleResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Una relación que no está cargada no se calcula (no se consulta la base de datos), pero se puede anunciar con sus `links`: `Relationship::unloaded()`
   * da una relación sin `data`, solo con `links` y/o `meta`; sin ninguno de los dos es un error del programador, porque la spec exige al menos uno de
   * `links`, `data` o `meta`. `$this->relationshipLinks($request, 'author')` arma los links de una relación a partir del `links.self` del propio
   * recurso: `{self}/relationships/author` y `{self}/author`, con el nombre en el estilo de `member_case`. Es opcional: da `[]` si el recurso no tiene `self`.
   *
   * A relationship that is not loaded is not worked out (the database is not queried), but it can be announced with its `links`:
   * `Relationship::unloaded()` gives a relationship without `data`, with only `links` and/or `meta`; with neither it is a developer error, because the
   * spec requires at least one of `links`, `data` or `meta`. `$this->relationshipLinks($request, 'author')` builds the links of a relationship from the
   * `links.self` of the resource itself: `{self}/relationships/author` and `{self}/author`, with the name in the `member_case` style. It is optional: it
   * gives `[]` if the resource has no `self`.
   */
  class UnloadedRelationshipTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const SELF = 'http://localhost/api/articles/1';

    private function article(): Article {
      return (new Article())->forceFill(['id' => 1, 'title' => 'Hello']);
    }

    protected function defineRoutes($router): void {
      $router->get('api/articles/{article}', fn() => LinkedArticleResource::make($this->article()))->name('articles.show');
      $router->get('api/v1/articles/{article}', fn() => 'prefixed')->name('v1.articles.show');

      $router->get('api/unloaded', fn() => LinkedArticleResource::make($this->article()));
      $router->get('api/loaded', fn() => LinkedArticleResource::make(
        $this->article()
          ->setRelation('author', (new Author())->forceFill(['id' => 9, 'name' => 'Ada']))
          ->setRelation('tags', new EloquentCollection([(new Tag())->forceFill(['slug' => 'php', 'name' => 'PHP'])]))
      ));
      $router->get('api/emptied', fn() => LinkedArticleResource::make($this->article()->setRelation('author', null)->setRelation('tags', new EloquentCollection())));
      $router->get('api/list', fn() => LinkedArticleResource::collection([$this->article(), $this->article()->forceFill(['id' => 2])]));
    }

    private function fetch(string $uri): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * Los links de una relación de este artículo.
     * The links of a relationship of this article.
     *
     * @return array{self: string, related: string}
     */
    private function links(string $name, string $base = self::SELF): array {
      return ['self' => "$base/relationships/$name", 'related' => "$base/$name"];
    }

    // ---------------------------------------------------------------------------------------------
    // Relationship::unloaded()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_unloaded_relationship_with_links_has_only_links(): void {
      $this->assertSame(['links' => ['self' => 'a', 'related' => 'b']], Relationship::unloaded()->links(self: 'a', related: 'b')->toArray());
      $this->assertSame(['links' => ['self' => 'a']], Relationship::unloaded()->links(self: 'a')->toArray());
      $this->assertSame(['links' => ['related' => 'b']], Relationship::unloaded()->links(related: 'b')->toArray());
    }

    #[Test]
    public function an_unloaded_relationship_can_have_only_meta(): void {
      $this->assertSame(['meta' => ['count' => 3]], Relationship::unloaded()->meta(['count' => 3])->toArray());
    }

    #[Test]
    public function an_unloaded_relationship_can_have_links_and_meta_in_that_order(): void {
      $relationship = Relationship::unloaded()->links(self: 'a')->meta(['count' => 3]);

      $this->assertSame(['links', 'meta'], array_keys($relationship->toArray()));
      $this->assertArrayNotHasKey('data', $relationship->toArray());
    }

    /**
     * @return array<string, array{callable(): Relationship}>
     */
    public static function emptyOnes(): array {
      return [
        'nothing at all' => [fn() => Relationship::unloaded()],
        'links that are all null' => [fn() => Relationship::unloaded()->links()],
        'an empty meta' => [fn() => Relationship::unloaded()->meta([])],
      ];
    }

    /**
     * @param  callable(): Relationship  $relationship
     */
    #[Test]
    #[DataProvider('emptyOnes')]
    public function an_unloaded_relationship_with_neither_links_nor_meta_is_a_developer_error(callable $relationship): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/links.*data.*meta/');

      $relationship()->toArray();
    }

    #[Test]
    public function the_original_does_not_change_when_links_or_meta_are_added(): void {
      $original = Relationship::unloaded()->meta(['a' => 1]);
      $original->links(self: 'x');

      $this->assertSame(['meta' => ['a' => 1]], $original->toArray());
    }

    // ---------------------------------------------------------------------------------------------
    // En un recurso / In a resource
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_unloaded_relationships_of_a_resource_come_out_with_only_their_links(): void {
      $response = $this->fetch('api/unloaded');

      $response->assertOk();
      $this->assertSame([
        'author' => ['links' => $this->links('author')],
        'tags' => ['links' => $this->links('tags')],
      ], $response->json('data.relationships'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_loaded_relationships_carry_their_linkage_after_the_links(): void {
      $response = $this->fetch('api/loaded');

      $this->assertSame(['links', 'data'], array_keys($response->json('data.relationships.author')));
      $this->assertSame(['type' => 'authors', 'id' => '9'], $response->json('data.relationships.author.data'));
      $this->assertSame($this->links('author'), $response->json('data.relationships.author.links'));
      $this->assertSame([['type' => 'labels', 'id' => 'php']], $response->json('data.relationships.tags.data'));
      $this->assertSame($this->links('tags'), $response->json('data.relationships.tags.links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_loaded_but_empty_relationship_has_null_or_an_empty_list_and_still_its_links(): void {
      $response = $this->fetch('api/emptied');

      $this->assertNull($response->json('data.relationships.author.data'));
      $this->assertArrayHasKey('data', $response->json('data.relationships.author'));
      $this->assertSame([], $response->json('data.relationships.tags.data'));
      $this->assertSame($this->links('author'), $response->json('data.relationships.author.links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function sparse_fieldsets_apply_to_unloaded_relationships_too(): void {
      $response = $this->fetch('api/unloaded?fields[articles]=title,author');

      $this->assertSame(['author'], array_keys($response->json('data.relationships')));
      $this->assertSame(['title' => 'Hello'], $response->json('data.attributes'));
    }

    #[Test]
    public function every_element_of_a_collection_gets_its_own_links(): void {
      $response = $this->fetch('api/list');

      $this->assertSame($this->links('author'), $response->json('data.0.relationships.author.links'));
      $this->assertSame($this->links('author', 'http://localhost/api/articles/2'), $response->json('data.1.relationships.author.links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // relationshipLinks()
    // ---------------------------------------------------------------------------------------------

    /**
     * Un recurso que expone `relationshipLinks()` para poder probarlo. Con `$route` null no tiene `self`; `$links` reemplaza al `self`.
     * A resource that exposes `relationshipLinks()` so it can be tested. With `$route` null it has no `self`; `$links` replaces the `self`.
     *
     * @param  array<string, mixed>  $links
     * @return array<string, string>
     */
    private function linksOf(?string $route, string $name, array $links = []): array {
      $resource = new class($this->article(), $route, $links) extends ResourceObject {
        /**
         * @param  array<string, mixed>  $links
         */
        public function __construct(mixed $resource, private readonly ?string $route, private readonly array $links) {
          parent::__construct($resource);
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }

        protected function jsonApiRoute(): ?string {
          return $this->route;
        }

        protected function jsonApiLinks(Request $request): array {
          return $this->links;
        }

        /**
         * @return array<string, string>
         */
        public function probe(Request $request, string $name): array {
          return $this->relationshipLinks($request, $name);
        }
      };

      return $resource->probe(Request::create('/api/articles/1'), $name);
    }

    #[Test]
    public function the_links_come_from_the_self_link_of_the_resource(): void {
      $this->assertSame($this->links('author'), $this->linksOf('articles.show', 'author'));
    }

    #[Test]
    public function a_resource_without_a_self_link_gives_no_links(): void {
      $this->assertSame([], $this->linksOf(null, 'author'));
    }

    #[Test]
    public function a_route_that_does_not_exist_gives_no_links(): void {
      $this->assertSame([], $this->linksOf('nope.show', 'author'));
    }

    #[Test]
    public function a_self_link_written_by_hand_is_the_base(): void {
      $this->assertSame(
        ['self' => 'https://example.com/me/relationships/author', 'related' => 'https://example.com/me/author'],
        $this->linksOf(null, 'author', ['self' => 'https://example.com/me'])
      );
    }

    #[Test]
    public function a_self_link_written_as_an_object_uses_its_href(): void {
      $this->assertSame(
        ['self' => 'https://example.com/me/relationships/author', 'related' => 'https://example.com/me/author'],
        $this->linksOf(null, 'author', ['self' => ['href' => 'https://example.com/me', 'title' => 'Me']])
      );
    }

    #[Test]
    public function a_self_link_object_without_href_gives_no_links(): void {
      $this->assertSame([], $this->linksOf(null, 'author', ['self' => ['title' => 'Me']]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function styles(): array {
      return ['camel' => ['camel', 'homeAddress'], 'kebab' => ['kebab', 'home-address'], 'snake' => ['snake', 'home_address']];
    }

    #[Test]
    #[DataProvider('styles')]
    public function the_name_goes_in_the_member_case_style(string $style, string $expected): void {
      config()->set('json-api.member_case', $style);

      $this->assertSame($this->links($expected), $this->linksOf('articles.show', 'home_address'));
    }

    #[Test]
    public function the_route_name_prefix_setting_applies_to_the_self_link(): void {
      config()->set('json-api.links.route_name_prefix', 'v1.');

      $this->assertSame($this->links('author', 'http://localhost/api/v1/articles/1'), $this->linksOf('articles.show', 'author'));
    }

    #[Test]
    public function the_links_can_be_given_straight_to_a_relationship(): void {
      $relationship = Relationship::unloaded()->links(...$this->linksOf('articles.show', 'author'));

      $this->assertSame(['links' => $this->links('author')], $relationship->toArray());
    }
  }
