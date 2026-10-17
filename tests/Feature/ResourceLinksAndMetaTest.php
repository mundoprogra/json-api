<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\LinkedAuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `links.self` y `meta` de un recurso. El recurso declara solo el nombre corto de su ruta (`jsonApiRoute()`, por ejemplo `authors.show`):
   * el paquete le antepone `links.route_name_prefix`, arma la URL con el id y omite el link si no se declaró o si la ruta no existe.
   * `jsonApiLinks()` agrega o reemplaza links, y `jsonApiMeta()` agrega `meta`. En un `201`, la cabecera `Location` es el `links.self`.
   * `links.self` and `meta` of a resource. The resource only declares the short name of its route (`jsonApiRoute()`, for example `authors.show`):
   * the package puts `links.route_name_prefix` in front, builds the URL with the id and leaves the link out if none was declared or the route does not exist.
   * `jsonApiLinks()` adds or replaces links, and `jsonApiMeta()` adds `meta`. On a `201`, the `Location` header is the `links.self`.
   */
  class ResourceLinksAndMetaTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private function author(bool $created = false): Author {
      $author = (new Author())->forceFill(['id' => 7, 'name' => 'Ada']);
      $author->wasRecentlyCreated = $created;

      return $author;
    }

    protected function defineRoutes($router): void {
      // Rutas con nombre: la de consulta de un autor y la de una etiqueta (cuya clave de ruta es el `slug`).
      // Named routes: the show route of an author and of a tag (whose route key is the `slug`).
      $router->get('api/authors/{author}', fn() => LinkedAuthorResource::make($this->author()))->name('authors.show');
      $router->get('api/tags/{tag}', fn() => 'tag')->name('tags.show');
      $router->get('api/v1/prefixed/{author}', fn() => 'prefixed')->name('api.v1.prefixed.show');

      $router->post('api/authors', fn() => LinkedAuthorResource::make($this->author(created: true)));
      $router->post('api/unlinked', fn() => AuthorResource::make($this->author(created: true)));
      $router->get('api/linked-author', fn() => LinkedAuthorResource::make($this->author()));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(ResourceObject $resource): array {
      return $resource->resolve(Request::create('/api/authors/7'));
    }

    /**
     * Un recurso de autor con la ruta indicada, y con los links y el meta que se den.
     * An author resource with the given route, and with the links and meta given.
     *
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $meta
     */
    private function resourceWith(?string $route, array $links = [], array $meta = [], mixed $model = null): ResourceObject {
      return new class($model ?? $this->author(), $route, $links, $meta) extends ResourceObject {
        /**
         * @param  array<string, mixed>  $links
         * @param  array<string, mixed>  $meta
         */
        public function __construct(mixed $resource, private readonly ?string $route, private readonly array $links, private readonly array $meta) {
          parent::__construct($resource);
        }

        protected function jsonApiAttributes(Request $request): array {
          return ['name' => 'Ada'];
        }

        protected function jsonApiRoute(): ?string {
          return $this->route;
        }

        protected function jsonApiLinks(Request $request): array {
          return $this->links;
        }

        protected function jsonApiMeta(Request $request): array {
          return $this->meta;
        }
      };
    }

    // ---------------------------------------------------------------------------------------------
    // links.self
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_self_link_is_the_absolute_url_of_the_declared_route_with_the_id(): void {
      $data = $this->resolve(LinkedAuthorResource::make($this->author()));

      $this->assertSame(['self' => 'http://localhost/api/authors/7'], $data['links']);
    }

    #[Test]
    public function the_resource_object_members_are_in_order_and_links_come_after_the_attributes(): void {
      $data = $this->resolve($this->resourceWith('authors.show', meta: ['a' => 1]));

      $this->assertSame(['type', 'id', 'attributes', 'links', 'meta'], array_keys($data));
    }

    #[Test]
    public function the_route_name_prefix_of_the_settings_goes_in_front_of_the_declared_name(): void {
      config()->set('json-api.links.route_name_prefix', 'api.v1.');

      $data = $this->resolve($this->resourceWith('prefixed.show'));

      $this->assertSame('http://localhost/api/v1/prefixed/7', $data['links']['self']);
    }

    #[Test]
    public function the_id_of_the_resource_is_the_route_parameter_even_with_a_custom_route_key(): void {
      $tag = (new Tag())->forceFill(['id' => 5, 'slug' => 'php']);

      $data = $this->resolve($this->resourceWith('tags.show', model: $tag));

      $this->assertSame('http://localhost/api/tags/php', $data['links']['self']);
    }

    #[Test]
    public function without_a_declared_route_there_is_no_links_member(): void {
      $this->assertArrayNotHasKey('links', $this->resolve($this->resourceWith(null)));
      $this->assertArrayNotHasKey('links', $this->resolve(AuthorResource::make($this->author())));
    }

    #[Test]
    public function a_route_that_does_not_exist_leaves_the_link_out_instead_of_failing(): void {
      $this->assertArrayNotHasKey('links', $this->resolve($this->resourceWith('no.such.route')));
    }

    #[Test]
    public function a_prefix_that_does_not_match_any_route_leaves_the_link_out(): void {
      config()->set('json-api.links.route_name_prefix', 'wrong.');

      $this->assertArrayNotHasKey('links', $this->resolve(LinkedAuthorResource::make($this->author())));
    }

    #[Test]
    public function the_prefix_is_read_on_every_call(): void {
      $resource = LinkedAuthorResource::make($this->author());

      $this->assertArrayHasKey('links', $this->resolve($resource));

      config()->set('json-api.links.route_name_prefix', 'wrong.');

      $this->assertArrayNotHasKey('links', $this->resolve($resource));
    }

    // ---------------------------------------------------------------------------------------------
    // jsonApiLinks()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function more_links_are_added_after_the_self_link(): void {
      $data = $this->resolve($this->resourceWith('authors.show', links: ['related' => 'https://example.com/related']));

      $this->assertSame(['self' => 'http://localhost/api/authors/7', 'related' => 'https://example.com/related'], $data['links']);
    }

    #[Test]
    public function a_link_written_by_hand_replaces_the_one_the_package_built(): void {
      $data = $this->resolve($this->resourceWith('authors.show', links: ['self' => 'https://example.com/me']));

      $this->assertSame(['self' => 'https://example.com/me'], $data['links']);
    }

    #[Test]
    public function links_written_by_hand_work_without_a_declared_route(): void {
      $data = $this->resolve($this->resourceWith(null, links: ['self' => 'https://example.com/me']));

      $this->assertSame(['self' => 'https://example.com/me'], $data['links']);
    }

    // ---------------------------------------------------------------------------------------------
    // meta
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function meta_is_added_and_its_names_are_not_converted(): void {
      $data = $this->resolve($this->resourceWith(null, meta: ['word_count' => 3, 'copyright' => null, 'nested' => ['a_b' => 1]]));

      // `meta` es libre: la spec no le impone nombres, así que `member_case` no lo toca.
      // `meta` is free-form: the spec imposes no names on it, so `member_case` does not touch it.
      $this->assertSame(['word_count' => 3, 'copyright' => null, 'nested' => ['a_b' => 1]], $data['meta']);
    }

    #[Test]
    public function without_meta_the_member_is_left_out(): void {
      $this->assertArrayNotHasKey('meta', $this->resolve($this->resourceWith('authors.show')));
    }

    // ---------------------------------------------------------------------------------------------
    // Location en un 201 / Location on a 201
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_201_carries_a_location_header_with_the_self_link(): void {
      $response = $this->post('api/authors', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
      $this->assertSame('http://localhost/api/authors/7', $response->headers->get('Location'));
      $this->assertSame($response->json('data.links.self'), $response->headers->get('Location'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_201_without_a_self_link_has_no_location_header(): void {
      $response = $this->post('api/unlinked', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
      $this->assertFalse($response->headers->has('Location'));
    }

    #[Test]
    public function a_200_has_no_location_header_even_with_a_self_link(): void {
      $response = $this->get('api/linked-author', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame('http://localhost/api/authors/7', $response->json('data.links.self'));
      $this->assertFalse($response->headers->has('Location'));
    }

    #[Test]
    public function the_location_follows_a_self_link_written_by_hand(): void {
      $this->app->make('router')->post('api/handmade', fn() => $this->resourceWith(null, links: ['self' => 'https://example.com/me'], model: $this->author(created: true)));

      $response = $this->post('api/handmade', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
      $this->assertSame('https://example.com/me', $response->headers->get('Location'));
    }
  }
