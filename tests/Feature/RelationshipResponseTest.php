<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Support\Responsable;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use LogicException;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\TagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Una `Relationship` devuelta por un controlador es la respuesta de un endpoint de relación (`GET /articles/1/relationships/author`): un documento
   * de JSON:API cuyo primer nivel es el objeto de la relación, `{"jsonapi": ..., "links": ..., "data": ..., "meta": ...}`, con el `Content-Type` exacto
   * `application/vnd.api+json` y `200`. Los identificadores salen solo con `type` e `id`. Una relación vacía es `data: null` (a uno) o `data: []`
   * (a muchos), y una sin cargar (`Relationship::unloaded()`) no lleva `data`.
   *
   * A `Relationship` returned by a controller is the response of a relationship endpoint (`GET /articles/1/relationships/author`): a JSON:API
   * document whose top level is the relationship object, `{"jsonapi": ..., "links": ..., "data": ..., "meta": ...}`, with the exact
   * `application/vnd.api+json` `Content-Type` and `200`. Identifiers come out with only `type` and `id`. An empty relationship is `data: null` (to-one)
   * or `data: []` (to-many), and an unloaded one (`Relationship::unloaded()`) carries no `data`.
   */
  class RelationshipResponseTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const LINKS = ['self' => 'https://example.com/articles/1/relationships/author', 'related' => 'https://example.com/articles/1/author'];

    private function author(): Author {
      return (new Author())->forceFill(['id' => 9, 'name' => 'Ada', 'email' => 'ada@example.com']);
    }

    /**
     * @return list<Tag>
     */
    private function tags(): array {
      return [
        (new Tag())->forceFill(['slug' => 'php', 'name' => 'PHP']),
        (new Tag())->forceFill(['slug' => 'laravel', 'name' => 'Laravel']),
      ];
    }

    protected function defineRoutes($router): void {
      $router->get('api/articles/1/relationships/author', fn() => Relationship::one($this->author(), AuthorResource::class)->links(...self::LINKS));
      $router->get('api/articles/1/relationships/tags', fn() => Relationship::many($this->tags(), TagResource::class)->links(self: 'https://example.com/articles/1/relationships/tags'));
      $router->get('api/articles/1/relationships/editor', fn() => Relationship::one(null, AuthorResource::class)->links(...self::LINKS));
      $router->get('api/articles/1/relationships/comments', fn() => Relationship::many([], TagResource::class)->links(...self::LINKS));
      $router->get('api/articles/1/relationships/counted', fn() => Relationship::many($this->tags(), TagResource::class)->links(...self::LINKS)->meta(['count' => 2]));
      $router->get('api/articles/1/relationships/bare', fn() => Relationship::one($this->author(), AuthorResource::class));
      $router->get('api/articles/1/relationships/unloaded', fn() => Relationship::unloaded()->links(...self::LINKS)->meta(['loaded' => false]));
      $router->get('web/articles/1/relationships/author', fn() => Relationship::one($this->author(), AuthorResource::class)->links(...self::LINKS));
    }

    private function fetch(string $uri): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_one_relationship_is_a_document_with_its_links_and_one_identifier(): void {
      $response = $this->fetch('api/articles/1/relationships/author');

      $response->assertOk();
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'links' => self::LINKS,
        'data' => ['type' => 'authors', 'id' => '9'],
      ], $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_to_many_relationship_is_a_list_of_identifiers_in_order(): void {
      $response = $this->fetch('api/articles/1/relationships/tags');

      $this->assertSame([['type' => 'labels', 'id' => 'php'], ['type' => 'labels', 'id' => 'laravel']], $response->json('data'));
      $this->assertSame(['self' => 'https://example.com/articles/1/relationships/tags'], $response->json('links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_identifiers_never_carry_the_attributes_of_the_related_resource(): void {
      $response = $this->fetch('api/articles/1/relationships/author');

      $this->assertSame(['type', 'id'], array_keys($response->json('data')));
    }

    #[Test]
    public function an_empty_to_one_relationship_is_data_null(): void {
      $response = $this->fetch('api/articles/1/relationships/editor');

      $response->assertOk();
      $this->assertArrayHasKey('data', (array) $response->json());
      $this->assertNull($response->json('data'));
      $this->assertStringContainsString('"data":null', (string) $response->getContent());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function an_empty_to_many_relationship_is_an_empty_list(): void {
      $response = $this->fetch('api/articles/1/relationships/comments');

      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_members_come_in_the_order_jsonapi_links_data_meta(): void {
      $response = $this->fetch('api/articles/1/relationships/counted');

      $this->assertSame(['jsonapi', 'links', 'data', 'meta'], array_keys((array) $response->json()));
      $this->assertSame(['count' => 2], $response->json('meta'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_relationship_without_links_has_only_jsonapi_and_data(): void {
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $this->fetch('api/articles/1/relationships/bare')->json()));
    }

    #[Test]
    public function an_unloaded_relationship_with_meta_has_links_and_meta_and_no_data(): void {
      // Un documento de primer nivel necesita `data`, `errors` o `meta`: sin `data`, lo que lo hace válido es el `meta`.
      // A top-level document needs `data`, `errors` or `meta`: without `data`, what makes it valid is the `meta`.
      $response = $this->fetch('api/articles/1/relationships/unloaded');

      $response->assertOk();
      $this->assertSame(['jsonapi', 'links', 'meta'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_jsonapi_member_follows_the_setting(): void {
      config()->set('json-api.jsonapi_object', false);

      $response = $this->fetch('api/articles/1/relationships/author');

      $this->assertSame(['links', 'data'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // La respuesta / The response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_responsable_with_the_exact_content_type_and_a_200(): void {
      $this->assertInstanceOf(Responsable::class, Relationship::unloaded());

      $response = $this->fetch('api/articles/1/relationships/author');

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_also_applies_outside_the_prefix_because_the_relationship_is_what_decides(): void {
      $response = $this->get('web/articles/1/relationships/author', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['type' => 'authors', 'id' => '9'], $response->json('data'));
    }

    #[Test]
    public function to_response_gives_a_json_response_straight_away(): void {
      $response = Relationship::one($this->author(), AuthorResource::class)->toResponse(Request::create('/api/articles/1/relationships/author'));

      $this->assertInstanceOf(JsonResponse::class, $response);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------------------------------------
    // document() / document()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_document_needs_no_http_request(): void {
      $document = Relationship::many($this->tags(), TagResource::class)->links(self: 'a')->document();

      $this->assertSame(['jsonapi', 'links', 'data'], array_keys($document));
      $this->assertSame(['self' => 'a'], $document['links']);
    }

    #[Test]
    public function the_document_is_the_same_body_the_response_sends(): void {
      $relationship = Relationship::one($this->author(), AuthorResource::class)->links(...self::LINKS);

      $this->assertSame($relationship->document(), json_decode((string) $relationship->toResponse(Request::create('/'))->getContent(), true));
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador / Developer errors
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_related_resource_without_an_id_is_a_developer_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/no id/');

      Relationship::one(new Author(['name' => 'Unsaved']), AuthorResource::class)->document();
    }

    #[Test]
    public function an_unloaded_relationship_with_neither_links_nor_meta_is_a_developer_error(): void {
      $this->expectException(LogicException::class);

      Relationship::unloaded()->document();
    }

    #[Test]
    public function an_unloaded_relationship_with_only_links_is_not_a_valid_document(): void {
      // Como objeto dentro de un recurso basta con `links`, pero como documento de primer nivel la spec exige `data`, `errors` o `meta`.
      // As an object inside a resource `links` is enough, but as a top-level document the spec requires `data`, `errors` or `meta`.
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/data or meta/');

      Relationship::unloaded()->links(...self::LINKS)->document();
    }

    #[Test]
    public function an_unloaded_relationship_with_only_links_still_works_inside_a_resource(): void {
      $this->assertSame(['links' => self::LINKS], Relationship::unloaded()->links(...self::LINKS)->toArray());
    }
  }
