<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\ArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\LinkedAuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `document()` da el cuerpo completo de un recurso (`jsonapi`, `data` y lo que agreguen `with()` y `additional()`) como un arreglo,
   * sin necesitar una petición HTTP: sirve para un job, un comando o una prueba. Es el mismo cuerpo que la respuesta; el estado y las
   * cabeceras (`201`, `Location`, `Content-Type`) siguen siendo solo de la respuesta.
   * `document()` gives the full body of a resource (`jsonapi`, `data` and what `with()` and `additional()` add) as an array,
   * without needing an HTTP request: it serves a job, a command or a test. It is the same body as the response; the status and the
   * headers (`201`, `Location`, `Content-Type`) remain the response's alone.
   */
  class ResourceDocumentTest extends TestCase {
    use AssertsJsonApi;

    private function author(): Author {
      return (new Author())->forceFill(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']);
    }

    protected function defineRoutes($router): void {
      $router->get('api/authors/{author}', fn() => 'show')->name('authors.show');
    }

    #[Test]
    public function it_gives_the_whole_body_as_an_array(): void {
      $document = AuthorResource::make($this->author())->document(Request::create('/'));

      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'data' => [
          'type' => 'authors',
          'id' => '7',
          'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ],
      ], $document);
    }

    #[Test]
    public function it_is_the_same_body_the_response_carries(): void {
      $resource = LinkedAuthorResource::make($this->author());
      $request = Request::create('/api/authors/7');

      $this->assertSame($resource->document($request), json_decode((string) $resource->toResponse($request)->getContent(), true));
    }

    #[Test]
    public function it_is_a_valid_json_api_document(): void {
      $document = LinkedAuthorResource::make($this->author())->document(Request::create('/'));

      $this->assertJsonApiDocument((string) json_encode($document));
    }

    #[Test]
    public function it_works_without_a_request_using_the_one_in_the_container(): void {
      $this->app->instance('request', Request::create('/from/container'));

      $resource = new class($this->author()) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return ['path' => $request->path()];
        }
      };

      $this->assertSame('from/container', $resource->document()['data']['attributes']['path']);
    }

    #[Test]
    public function the_request_given_is_the_one_used(): void {
      $resource = new class($this->author()) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return ['path' => $request->path()];
        }
      };

      $this->assertSame('given/path', $resource->document(Request::create('/given/path'))['data']['attributes']['path']);
    }

    #[Test]
    public function it_follows_the_jsonapi_setting(): void {
      config()->set('json-api.jsonapi_object', false);

      $this->assertSame(['data'], array_keys(AuthorResource::make($this->author())->document(Request::create('/'))));
    }

    #[Test]
    public function it_includes_what_with_and_additional_add_but_never_overrides_jsonapi_or_data(): void {
      $resource = (new class($this->author()) extends AuthorResource {
        public function with(Request $request) {
          return ['meta' => ['from_with' => true], 'data' => 'evil'];
        }
      })->additional(['links' => ['self' => 'https://example.com/me'], 'jsonapi' => 'worse']);

      $document = $resource->document(Request::create('/'));

      $this->assertSame(['jsonapi', 'data', 'meta', 'links'], array_keys($document));
      $this->assertSame(['version' => '1.1'], $document['jsonapi']);
      $this->assertSame('authors', $document['data']['type']);
      $this->assertSame(['from_with' => true], $document['meta']);
    }

    #[Test]
    public function it_carries_the_relationships_links_and_meta_of_the_resource(): void {
      $article = (new Article())->forceFill(['id' => 1, 'title' => 'Hello']);
      $article->setRelation('author', $this->author());
      $article->setRelation('tags', collect());

      $data = ArticleResource::make($article)->document(Request::create('/'))['data'];

      $this->assertSame(['data' => ['type' => 'authors', 'id' => '7']], $data['relationships']['author']);
      $this->assertSame(['data' => []], $data['relationships']['tags']);
    }

    #[Test]
    public function it_builds_the_self_link_with_no_http_request_at_all(): void {
      $data = LinkedAuthorResource::make($this->author())->document()['data'];

      $this->assertStringEndsWith('/api/authors/7', $data['links']['self']);
    }

    #[Test]
    public function the_status_and_the_headers_are_not_part_of_the_body(): void {
      $author = $this->author();
      $author->wasRecentlyCreated = true;

      $document = LinkedAuthorResource::make($author)->document(Request::create('/'));

      // El `201` y el `Location` son de la respuesta; el cuerpo es el mismo de un `200`.
      // The `201` and the `Location` belong to the response; the body is the same as a `200`.
      $this->assertSame(['jsonapi', 'data'], array_keys($document));
      $this->assertSame(201, LinkedAuthorResource::make($author)->toResponse(Request::create('/'))->getStatusCode());
    }
  }
