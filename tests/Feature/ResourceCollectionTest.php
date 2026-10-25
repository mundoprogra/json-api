<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Support\Responsable;
  use Illuminate\Database\Eloquent\Collection as EloquentCollection;
  use Illuminate\Http\Request;
  use Illuminate\Pagination\CursorPaginator;
  use Illuminate\Pagination\Paginator;
  use Illuminate\Support\Collection;
  use Illuminate\Testing\TestResponse;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Una colección de recursos (`AuthorResource::collection($autores)`) sale como documento de JSON:API: `{"jsonapi": ..., "data": [...]}` con un
   * objeto de recurso completo por elemento, en el orden recibido, y siempre como lista (`data: []` si está vacía). La respuesta lleva el
   * `Content-Type` exacto `application/vnd.api+json` y `200`. `with()` y `additional()` siguen funcionando, pero nunca pisan `jsonapi` ni `data`.
   * Acepta un arreglo o una colección; un paginador simple o de cursor se rechaza con un error claro. La paginación completa (`links` y
   * `meta.page`) es del punto 10.2 y el `included`, de los puntos 10.5 y 10.6.
   *
   * A collection of resources (`AuthorResource::collection($authors)`) comes out as a JSON:API document: `{"jsonapi": ..., "data": [...]}` with a
   * full resource object per element, in the order received, and always as a list (`data: []` if empty). The response carries the exact
   * `application/vnd.api+json` `Content-Type` and `200`. `with()` and `additional()` keep working, but never override `jsonapi` or `data`.
   * It accepts an array or a collection; a simple or cursor paginator is rejected with a clear error. Full pagination (`links` and
   * `meta.page`) is point 10.2 and `included` is points 10.5 and 10.6.
   */
  class ResourceCollectionTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return list<Author>
     */
    private function authors(int $count = 3): array {
      $authors = [];

      for ($id = 1; $id <= $count; $id++) {
        $authors[] = (new Author())->forceFill(['id' => $id, 'name' => "Author $id", 'email' => "a$id@example.com"]);
      }

      return $authors;
    }

    protected function defineRoutes($router): void {
      $router->get('api/authors', fn() => AuthorResource::collection($this->authors()));
      $router->get('web/authors', fn() => AuthorResource::collection($this->authors()));
      $router->get('api/direct', fn() => AuthorResource::collection($this->authors())->response());
      $router->get('api/none', fn() => AuthorResource::collection([]));
      $router->get('api/created', function () {
        $authors = $this->authors(2);
        $authors[0]->wasRecentlyCreated = true;

        return AuthorResource::collection($authors);
      });
      $router->get('api/additional', fn() => AuthorResource::collection($this->authors(1))->additional(['links' => ['self' => 'https://example.com/authors'], 'meta' => ['count' => 1]]));
      $router->get('api/greedy', fn() => AuthorResource::collection($this->authors(1))->additional(['data' => 'evil', 'jsonapi' => 'evil']));
    }

    private function fetch(string $uri = 'api/authors'): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_collection_comes_out_as_a_json_api_document(): void {
      $response = $this->fetch();

      $response->assertOk();
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'data' => [
          ['type' => 'authors', 'id' => '1', 'attributes' => ['name' => 'Author 1', 'email' => 'a1@example.com']],
          ['type' => 'authors', 'id' => '2', 'attributes' => ['name' => 'Author 2', 'email' => 'a2@example.com']],
          ['type' => 'authors', 'id' => '3', 'attributes' => ['name' => 'Author 3', 'email' => 'a3@example.com']],
        ],
      ], $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function each_element_is_the_same_resource_object_a_single_resource_gives(): void {
      $authors = $this->authors(2);
      $document = AuthorResource::collection($authors)->document();

      $this->assertSame(AuthorResource::make($authors[0])->document()['data'], $document['data'][0]);
      $this->assertSame(AuthorResource::make($authors[1])->document()['data'], $document['data'][1]);
    }

    #[Test]
    public function the_members_come_in_the_order_jsonapi_then_data(): void {
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $this->fetch()->json()));
    }

    #[Test]
    public function the_jsonapi_member_follows_the_setting(): void {
      config()->set('json-api.jsonapi_object', false);

      $response = $this->fetch();

      $this->assertSame(['data'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_member_names_setting_applies_to_every_element(): void {
      config()->set('json-api.member_case', 'kebab');

      // Con un recurso que declara un atributo de dos palabras, los dos elementos salen con el estilo configurado.
      // With a resource that declares a two-word attribute, both elements come out in the configured style.
      $resource = new class($this->authors(1)[0]) extends AuthorResource {
        protected function jsonApiAttributes(Request $request): array {
          return ['first_name' => 'Ada'];
        }
      };

      $data = $resource::collection($this->authors(2))->document()['data'];

      $this->assertSame(['first-name' => 'Ada'], $data[0]['attributes']);
      $this->assertSame(['first-name' => 'Ada'], $data[1]['attributes']);
    }

    // ---------------------------------------------------------------------------------------------
    // La lista / The list
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_order_received_is_the_order_given(): void {
      $authors = $this->authors(3);

      $document = AuthorResource::collection([$authors[2], $authors[0], $authors[1]])->document();

      $this->assertSame(['3', '1', '2'], array_column($document['data'], 'id'));
    }

    #[Test]
    public function an_empty_collection_is_an_empty_list(): void {
      $response = $this->fetch('api/none');

      $response->assertOk();
      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function an_empty_eloquent_collection_is_an_empty_list_too(): void {
      $this->assertSame([], AuthorResource::collection(new EloquentCollection())->document()['data']);
    }

    /**
     * @return array<string, array{callable(list<Author>): mixed}>
     */
    public static function sources(): array {
      return [
        'an array' => [fn(array $authors) => $authors],
        'a support collection' => [fn(array $authors) => new Collection($authors)],
        'an eloquent collection' => [fn(array $authors) => new EloquentCollection($authors)],
      ];
    }

    /**
     * @param  callable(list<Author>): mixed  $source
     */
    #[Test]
    #[DataProvider('sources')]
    public function it_takes_an_array_or_a_collection(callable $source): void {
      $document = AuthorResource::collection($source($this->authors(2)))->document();

      $this->assertSame(['1', '2'], array_column($document['data'], 'id'));
    }

    #[Test]
    public function data_is_always_a_list_even_if_the_keys_have_gaps(): void {
      $authors = $this->authors(3);
      $gaps = [5 => $authors[0], 9 => $authors[2]];
      $filtered = (new Collection($authors))->filter(fn(Author $author) => $author->id !== 2);

      foreach ([$gaps, $filtered] as $source) {
        $document = AuthorResource::collection($source)->document();

        $this->assertTrue(array_is_list($document['data']));
        $this->assertSame(['1', '3'], array_column($document['data'], 'id'));
      }

      $this->assertStringContainsString('"data":[{', (string) json_encode(AuthorResource::collection($gaps)->document()));
    }

    #[Test]
    public function a_collection_of_one_is_a_list_of_one(): void {
      $response = $this->get('api/additional', ['Accept' => self::MEDIA_TYPE]);

      $this->assertCount(1, $response->json('data'));
      $this->assertTrue(array_is_list($response->json('data')));
    }

    // ---------------------------------------------------------------------------------------------
    // Respuesta / Response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_responsable_and_the_content_type_is_exactly_the_json_api_media_type(): void {
      $this->assertInstanceOf(Responsable::class, AuthorResource::collection([]));
      $this->assertSame(self::MEDIA_TYPE, $this->fetch()->headers->get('Content-Type'));
      $this->assertSame(self::MEDIA_TYPE, $this->fetch('api/direct')->headers->get('Content-Type'));
      $this->assertSame(self::MEDIA_TYPE, $this->fetch('api/none')->headers->get('Content-Type'));
    }

    #[Test]
    public function it_also_applies_outside_the_prefix_because_the_collection_is_what_decides(): void {
      $response = $this->get('web/authors', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('authors', $response->json('data.0.type'));
    }

    #[Test]
    public function a_collection_is_always_a_200_even_with_models_just_created(): void {
      $response = $this->fetch('api/created');

      $response->assertOk();
      $this->assertNull($response->headers->get('Location'));
    }

    // ---------------------------------------------------------------------------------------------
    // with() y additional() / with() and additional()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function additional_adds_top_level_members(): void {
      $response = $this->fetch('api/additional');

      $this->assertSame(['self' => 'https://example.com/authors'], $response->json('links'));
      $this->assertSame(['count' => 1], $response->json('meta'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_can_never_override_jsonapi_or_data(): void {
      $response = $this->fetch('api/greedy');

      $this->assertSame(['version' => '1.1'], $response->json('jsonapi'));
      $this->assertSame('authors', $response->json('data.0.type'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera de una petición HTTP / Outside an HTTP request
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_document_needs_no_http_request(): void {
      $document = AuthorResource::collection($this->authors(2))->document();

      $this->assertSame(['jsonapi', 'data'], array_keys($document));
      $this->assertCount(2, $document['data']);
    }

    // ---------------------------------------------------------------------------------------------
    // Sparse fieldsets en cada elemento / Sparse fieldsets on each element
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function sparse_fieldsets_apply_to_every_element_inside_the_scope(): void {
      $response = $this->fetch('api/authors?fields[authors]=name');

      $this->assertSame(['name' => 'Author 1'], $response->json('data.0.attributes'));
      $this->assertSame(['name' => 'Author 3'], $response->json('data.2.attributes'));
    }

    #[Test]
    public function outside_the_scope_the_fields_are_ignored(): void {
      $response = $this->get('web/authors?fields[authors]=name', ['Accept' => 'text/html']);

      $this->assertSame(['name', 'email'], array_keys($response->json('data.0.attributes')));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no se acepta / What is not accepted
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function unsupportedPaginators(): array {
      return [
        'a simple paginator' => [fn() => new Paginator([], 15)],
        'a cursor paginator' => [fn() => new CursorPaginator([], 15)],
      ];
    }

    /**
     * @param  callable(): mixed  $paginator
     */
    #[Test]
    #[DataProvider('unsupportedPaginators')]
    public function a_simple_or_cursor_paginator_is_refused_with_a_clear_error(callable $paginator): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/paginat/i');

      AuthorResource::collection($paginator());
    }

    #[Test]
    public function something_that_is_not_a_list_of_resources_is_refused(): void {
      $this->expectException(InvalidArgumentException::class);

      AuthorResource::collection('authors');
    }

    #[Test]
    public function an_element_without_an_id_is_a_developer_error_like_a_single_resource(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/no id/');

      AuthorResource::collection([new Author(['name' => 'Unsaved'])])->document();
    }
  }
