<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Un `ResourceObject` devuelto por un controlador sale como documento de JSON:API: `{"jsonapi": ..., "data": ...}` con el
   * `Content-Type` exacto `application/vnd.api+json` (sin parámetros), `200` normal y `201` si el modelo se acaba de crear.
   * `with()`, `additional()` y `withResponse()` de Laravel siguen funcionando, pero nunca pueden pisar `jsonapi` ni `data`.
   * Lo que no es un documento del paquete (un `response()->json()` suelto, un `204`) no se toca.
   * A `ResourceObject` returned by a controller comes out as a JSON:API document: `{"jsonapi": ..., "data": ...}` with the exact
   * `application/vnd.api+json` `Content-Type` (no parameters), `200` normally and `201` if the model was just created.
   * Laravel's `with()`, `additional()` and `withResponse()` keep working, but can never override `jsonapi` or `data`.
   * What is not a package document (a loose `response()->json()`, a `204`) is left alone.
   */
  class ResourceResponseTest extends TestCase {
    use AssertsJsonApi;

    protected bool $negotiates = true;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private function author(bool $created = false): Author {
      $author = (new Author())->forceFill(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']);
      $author->wasRecentlyCreated = $created;

      return $author;
    }

    protected function defineRoutes($router): void {
      $router->get('api/authors/7', fn() => AuthorResource::make($this->author()));
      $router->patch('api/authors/7', fn() => AuthorResource::make($this->author()));
      $router->post('api/authors', fn() => AuthorResource::make($this->author(created: true)));
      $router->get('web/authors/7', fn() => AuthorResource::make($this->author()));
      $router->get('api/direct', fn() => AuthorResource::make($this->author())->response());
      $router->get('api/plain', fn() => response()->json(['a' => 1]));
      $router->delete('api/authors/7', fn() => response()->noContent());

      $router->get('api/additional', fn() => AuthorResource::make($this->author())->additional(['links' => ['self' => 'https://example.com/authors/7'], 'meta' => ['count' => 1]]));

      $router->get('api/with', fn() => new class($this->author()) extends AuthorResource {
        public function with(Request $request) {
          return ['meta' => ['from_with' => true]];
        }
      });

      // Intenta pisar los miembros del paquete: no debe poder.
      // It tries to override the package members: it must not be able to.
      $router->get('api/greedy', fn() => (new class($this->author()) extends AuthorResource {
        public function with(Request $request) {
          return ['data' => 'evil', 'jsonapi' => 'evil'];
        }
      })->additional(['data' => 'worse', 'jsonapi' => 'worse']));

      $router->get('api/hook', fn() => new class($this->author()) extends AuthorResource {
        public function withResponse(Request $request, JsonResponse $response): void {
          $response->headers->set('X-Custom', 'yes');
        }
      });
    }

    private function fetch(string $uri = 'api/authors/7'): \Illuminate\Testing\TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_resource_comes_out_as_a_json_api_document(): void {
      $response = $this->fetch();

      $response->assertOk();
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'data' => [
          'type' => 'authors',
          'id' => '7',
          'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ],
      ], $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_jsonapi_member_comes_first_and_follows_the_setting(): void {
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $this->fetch()->json()));

      config()->set('json-api.jsonapi_object', false);

      $response = $this->fetch();
      $this->assertSame(['data'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_member_names_setting_applies_inside_the_document(): void {
      config()->set('json-api.member_case', 'kebab');
      $this->app->make('router')->get('api/kebab', fn() => new class($this->author()) extends AuthorResource {
        protected function jsonApiAttributes(Request $request): array {
          return ['first_name' => 'Ada'];
        }
      });

      $this->assertSame(['first-name' => 'Ada'], $this->fetch('api/kebab')->json('data.attributes'));
    }

    // ---------------------------------------------------------------------------------------------
    // Content-Type y estado / Content-Type and status
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_content_type_is_exactly_the_json_api_media_type_without_parameters(): void {
      $this->assertSame(self::MEDIA_TYPE, $this->fetch()->headers->get('Content-Type'));
      $this->assertSame(self::MEDIA_TYPE, $this->fetch('api/direct')->headers->get('Content-Type'));
    }

    #[Test]
    public function it_also_applies_outside_the_prefix_because_the_resource_is_what_decides(): void {
      $response = $this->get('web/authors/7', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('authors', $response->json('data.type'));
    }

    #[Test]
    public function the_status_is_200_and_201_when_the_model_was_just_created(): void {
      $this->fetch()->assertStatus(200);
      $this->patch('api/authors/7', [], ['Accept' => self::MEDIA_TYPE])->assertStatus(200);

      $created = $this->post('api/authors', [], ['Accept' => self::MEDIA_TYPE]);

      $created->assertStatus(201);
      $this->assertSame(self::MEDIA_TYPE, $created->headers->get('Content-Type'));
      $this->assertSame('7', $created->json('data.id'));
      $this->assertJsonApiDocument((string) $created->getContent());
    }

    #[Test]
    public function to_response_gives_a_json_response(): void {
      $response = AuthorResource::make($this->author())->toResponse(Request::create('/api/authors/7'));

      $this->assertInstanceOf(JsonResponse::class, $response);
      $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------
    // with(), additional() y withResponse() / with(), additional() and withResponse()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function with_adds_top_level_members(): void {
      $response = $this->fetch('api/with');

      $this->assertSame(['from_with' => true], $response->json('meta'));
      $this->assertSame(['jsonapi', 'data', 'meta'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_adds_top_level_members(): void {
      $response = $this->fetch('api/additional');

      $this->assertSame(['count' => 1], $response->json('meta'));
      $this->assertSame(['self' => 'https://example.com/authors/7'], $response->json('links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function with_and_additional_can_never_override_jsonapi_or_data(): void {
      $response = $this->fetch('api/greedy');

      $this->assertSame(['version' => '1.1'], $response->json('jsonapi'));
      $this->assertSame('authors', $response->json('data.type'));
    }

    #[Test]
    public function the_with_response_hook_still_runs(): void {
      $this->assertSame('yes', $this->fetch('api/hook')->headers->get('X-Custom'));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no es un documento del paquete se deja como está / What is not a package document is left alone
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_loose_json_response_keeps_its_own_content_type(): void {
      $response = $this->fetch('api/plain');

      $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
      $this->assertStringNotContainsString('vnd.api+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_204_has_no_content_type_and_no_body(): void {
      $response = $this->delete('api/authors/7', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(204);
      $this->assertFalse($response->headers->has('Content-Type'));
      $this->assertSame('', $response->getContent());
    }
  }
