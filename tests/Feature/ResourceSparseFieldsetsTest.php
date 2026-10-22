<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use LogicException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\FullArticleResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Sparse fieldsets: `fields[tipo]=a,b` hace que un `ResourceObject` de ese tipo deje solo esos atributos y relaciones; `type`, `id`,
   * `links` y `meta` se quedan. Un campo que no existe se ignora (no es un 400) y `fields[tipo]=` vacío deja el recurso sin atributos ni
   * relaciones. Los demás tipos no se tocan. Los nombres siguen el `member_case`, el orden es el del recurso y lo que ya se omitía
   * (un `when()` que no aplica, una relación sin cargar) se sigue omitiendo aunque se pida. Los errores del programador no dependen de lo
   * que pida el cliente. Se lee de la petición que recibe el recurso, o de la del contenedor si `document()` no recibe ninguna.
   * Solo actúan dentro del alcance del paquete (modo, prefijo y `except`, o una ruta marcada con el alias `json-api`): fuera de él el recurso
   * sale completo, `fields` se ignora y nunca hay un `400`, igual que el resto del manejo de la consulta.
   * Sparse fieldsets: `fields[type]=a,b` makes a `ResourceObject` of that type let through only those attributes and relationships; `type`,
   * `id`, `links` and `meta` stay. A field that does not exist is ignored (it is not a 400) and an empty `fields[type]=` leaves the resource
   * with no attributes or relationships. Other types are not touched. Names follow `member_case`, the order is the resource's and what was
   * already omitted (a `when()` that does not apply, a relation not loaded) stays omitted even if asked for. Developer errors do not depend
   * on what the client asks for. It is read from the request the resource receives, or from the container one if `document()` is given none.
   * They only act inside the package scope (mode, prefix and `except`, or a route marked with the `json-api` alias): outside it the resource
   * comes out whole, `fields` is ignored and there is never a `400`, like the rest of the query handling.
   */
  class ResourceSparseFieldsetsTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private int $controllerCalls = 0;

    protected function setUp(): void {
      parent::setUp();

      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $show = function () {
        $this->controllerCalls++;

        return FullArticleResource::make($this->article());
      };

      $router->get('api/articles/1', $show);
      $router->get('api/skip/1', $show);
      $router->get('web/articles/1', $show);
      $router->get('web/marked/1', $show)->middleware('json-api');
    }

    /**
     * Un artículo con el autor y las etiquetas "cargados"; el `body` es `null` a propósito.
     * An article with the author and the tags "loaded"; the `body` is `null` on purpose.
     */
    private function article(bool $loaded = true): Article {
      $article = (new Article())->forceFill(['id' => 1, 'title' => 'Hello', 'body' => null, 'published_at' => '2026-10-22T00:00:00Z']);

      if ($loaded) {
        $article->setRelation('author', (new Author())->forceFill(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']));
        $article->setRelation('tags', [
          (new Tag())->forceFill(['id' => 1, 'slug' => 'php', 'name' => 'PHP']),
          (new Tag())->forceFill(['id' => 2, 'slug' => 'laravel', 'name' => 'Laravel']),
        ]);
      }

      return $article;
    }

    /**
     * El `data` del documento de un artículo pedido con esa query string.
     * The `data` of the document of an article asked for with that query string.
     *
     * @return array<string, mixed>
     */
    private function data(string $queryString = '', bool $loaded = true): array {
      $request = Request::create('/api/articles/1' . ($queryString === '' ? '' : '?' . $queryString));

      return FullArticleResource::make($this->article($loaded))->document($request)['data'];
    }

    private function fetch(string $uri): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    // ---------------------------------------------------------------------------------------------
    // Sin `fields`, o de otro tipo: nada cambia / Without `fields`, or of another type: nothing changes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_fields_the_resource_comes_out_whole(): void {
      $data = $this->data();

      $this->assertSame(['title', 'body', 'publishedAt'], array_keys($data['attributes']));
      $this->assertSame(['author', 'tags'], array_keys($data['relationships']));
      $this->assertSame('articles', $data['type']);
      $this->assertSame('1', $data['id']);
      $this->assertSame(['self' => 'https://example.com/articles/1'], $data['links']);
      $this->assertSame(['revision' => 3], $data['meta']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fieldsOfOtherTypes(): array {
      return [
        'another type' => ['fields[people]=name'],
        'an empty list for another type' => ['fields[people]='],
        'the type with another case' => ['fields[Articles]=title'],
        'the singular of the type' => ['fields[article]=title'],
      ];
    }

    #[Test]
    #[DataProvider('fieldsOfOtherTypes')]
    public function the_fields_of_another_type_do_not_touch_the_resource(string $queryString): void {
      $this->assertSame($this->data(), $this->data($queryString));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que pasa y lo que no / What gets through and what does not
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function only_the_requested_attributes_come_out(): void {
      $data = $this->data('fields[articles]=title');

      $this->assertSame(['title' => 'Hello'], $data['attributes']);
      $this->assertArrayNotHasKey('relationships', $data);
    }

    #[Test]
    public function a_field_can_be_a_relationship_and_it_keeps_its_resource_linkage(): void {
      $data = $this->data('fields[articles]=title,author');

      $this->assertSame(['title' => 'Hello'], $data['attributes']);
      $this->assertSame(['author'], array_keys($data['relationships']));
      $this->assertSame(['data' => ['type' => 'authors', 'id' => '7']], $data['relationships']['author']);
    }

    #[Test]
    public function asking_only_for_relationships_leaves_no_attributes_member(): void {
      $data = $this->data('fields[articles]=tags');

      $this->assertArrayNotHasKey('attributes', $data);
      $this->assertSame(['tags'], array_keys($data['relationships']));
    }

    #[Test]
    public function type_id_links_and_meta_always_stay(): void {
      foreach (['fields[articles]=title', 'fields[articles]=', 'fields[articles]=nope'] as $queryString) {
        $data = $this->data($queryString);

        $this->assertSame('articles', $data['type'], $queryString);
        $this->assertSame('1', $data['id'], $queryString);
        $this->assertSame(['self' => 'https://example.com/articles/1'], $data['links'], $queryString);
        $this->assertSame(['revision' => 3], $data['meta'], $queryString);
      }
    }

    #[Test]
    public function an_empty_list_leaves_the_resource_without_attributes_or_relationships(): void {
      $data = $this->data('fields[articles]=');

      $this->assertSame(['type', 'id', 'links', 'meta'], array_keys($data));
    }

    #[Test]
    public function a_field_that_does_not_exist_is_ignored_and_is_not_an_error(): void {
      $this->assertSame(['title' => 'Hello'], $this->data('fields[articles]=title,nope')['attributes']);
    }

    #[Test]
    public function when_no_requested_field_exists_it_is_the_same_as_asking_for_none(): void {
      $this->assertSame($this->data('fields[articles]='), $this->data('fields[articles]=nope,other'));
    }

    #[Test]
    public function the_order_is_the_resources_not_the_clients(): void {
      $this->assertSame(['title', 'publishedAt'], array_keys($this->data('fields[articles]=publishedAt,title')['attributes']));
    }

    #[Test]
    public function a_repeated_field_is_the_same_as_one(): void {
      $this->assertSame($this->data('fields[articles]=title'), $this->data('fields[articles]=title,title'));
    }

    #[Test]
    public function the_fieldsets_of_several_types_apply_each_to_its_own_type(): void {
      $this->assertSame(['body' => null], $this->data('fields[people]=name&fields[articles]=body')['attributes']);
    }

    #[Test]
    public function a_null_attribute_that_is_asked_for_is_kept(): void {
      $this->assertSame(['body' => null], $this->data('fields[articles]=body')['attributes']);
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que ya se omitía se sigue omitiendo / What was already omitted stays omitted
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_conditional_attribute_that_does_not_apply_stays_out_even_if_asked_for(): void {
      $this->assertSame(['title' => 'Hello'], $this->data('fields[articles]=secret,title')['attributes']);
    }

    #[Test]
    public function a_relationship_that_is_not_loaded_stays_out_even_if_asked_for(): void {
      $data = $this->data('fields[articles]=tags', loaded: false);

      $this->assertArrayNotHasKey('relationships', $data);
      $this->assertArrayNotHasKey('attributes', $data);
    }

    // ---------------------------------------------------------------------------------------------
    // Los nombres siguen `member_case` / The names follow `member_case`
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function memberCases(): array {
      // [estilo, el campo como lo pide el cliente, el nombre que sale] / [style, the field as the client asks, the name that comes out]
      return [
        'camel' => ['camel', 'publishedAt', 'publishedAt'],
        'kebab' => ['kebab', 'published-at', 'published-at'],
        'snake' => ['snake', 'published_at', 'published_at'],
      ];
    }

    #[Test]
    #[DataProvider('memberCases')]
    public function a_field_is_asked_for_in_the_configured_style(string $style, string $asked, string $comesOut): void {
      config()->set('json-api.member_case', $style);

      $this->assertSame([$comesOut], array_keys($this->data('fields[articles]=' . $asked)['attributes']));
    }

    // ---------------------------------------------------------------------------------------------
    // Los errores del programador no dependen del cliente / Developer errors do not depend on the client
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_name_shared_by_an_attribute_and_a_relationship_is_an_error_whatever_the_client_asks_for(): void {
      $resource = new class(new Article()) extends ResourceObject {
        public function type(): string {
          return 'things';
        }

        public function id(): string {
          return '1';
        }

        protected function jsonApiAttributes(Request $request): array {
          return ['name' => 'x', 'other' => 'y'];
        }

        protected function jsonApiRelationships(Request $request): array {
          return ['name' => null];
        }
      };

      $this->expectException(LogicException::class);

      $resource->document(Request::create('/api/things/1?fields[things]=other'));
    }

    // ---------------------------------------------------------------------------------------------
    // De dónde se lee / Where it is read from
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_a_request_the_one_in_the_container_is_used(): void {
      $this->app->instance('request', Request::create('/api/articles/1?fields[articles]=title'));

      $document = FullArticleResource::make($this->article())->document();

      $this->assertSame(['title' => 'Hello'], $document['data']['attributes']);
    }

    #[Test]
    public function the_request_that_is_given_wins_over_the_container_one(): void {
      $this->app->instance('request', Request::create('/api/articles/1?fields[articles]=title'));

      $document = FullArticleResource::make($this->article())->document(Request::create('/api/articles/1?fields[articles]=body'));

      $this->assertSame(['body' => null], $document['data']['attributes']);
    }

    #[Test]
    public function a_malformed_fields_parameter_is_a_400_when_the_resource_is_built(): void {
      // La misma consulta que rechazaría el middleware: el recurso también la valida, así que nunca se arma con una consulta rota.
      // The same query the middleware would reject: the resource validates it too, so it is never built with a broken query.
      try {
        $this->data('fields=title');
        $this->fail('Expected a JsonApiException.');
      } catch (JsonApiException $e) {
        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame('fields', $e->errors()[0]->toArray()['source']['parameter']);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Por HTTP / Over HTTP
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function over_http_the_response_carries_only_the_requested_fields(): void {
      $response = $this->fetch('api/articles/1?fields[articles]=title,author');

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['title' => 'Hello'], $response->json('data.attributes'));
      $this->assertSame(['author'], array_keys($response->json('data.relationships')));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function over_http_an_empty_list_still_gives_a_valid_document(): void {
      $response = $this->fetch('api/articles/1?fields[articles]=');

      $response->assertOk();
      $this->assertArrayNotHasKey('attributes', $response->json('data'));
      $this->assertArrayNotHasKey('relationships', $response->json('data'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function over_http_a_malformed_fields_parameter_is_a_400_before_the_controller_runs(): void {
      $response = $this->fetch('api/articles/1?fields=title');

      $response->assertStatus(400);
      $this->assertSame(['fields'], array_column(array_column($response->json('errors'), 'source'), 'parameter'));
      $this->assertSame(0, $this->controllerCalls);
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance no se toca nada / Outside the scope nothing is touched
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_scope_the_resource_comes_out_whole_and_fields_is_ignored(): void {
      $response = $this->get('web/articles/1?fields[articles]=title', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['title', 'body', 'publishedAt'], array_keys($response->json('data.attributes')));
      $this->assertSame(['author', 'tags'], array_keys($response->json('data.relationships')));
    }

    #[Test]
    public function outside_the_scope_a_malformed_fields_parameter_is_ignored_and_is_never_a_400(): void {
      $response = $this->get('web/articles/1?fields=title', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertSame(['title', 'body', 'publishedAt'], array_keys($response->json('data.attributes')));
      $this->assertSame(1, $this->controllerCalls);
    }

    #[Test]
    public function a_request_built_by_hand_outside_the_prefix_gets_the_whole_resource_too(): void {
      $request = Request::create('/web/articles/1?fields[articles]=title');

      $data = FullArticleResource::make($this->article())->document($request)['data'];

      $this->assertSame($this->data(), $data);
    }

    #[Test]
    public function an_excepted_path_gets_the_whole_resource(): void {
      config()->set('json-api.except', ['api/skip/*']);

      $response = $this->fetch('api/skip/1?fields[articles]=title');

      $response->assertOk();
      $this->assertSame(['title', 'body', 'publishedAt'], array_keys($response->json('data.attributes')));
    }

    #[Test]
    public function in_global_mode_every_route_applies_the_fieldsets(): void {
      config()->set('json-api.mode', 'global');

      $response = $this->get('web/articles/1?fields[articles]=title', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertSame(['title' => 'Hello'], $response->json('data.attributes'));
    }

    #[Test]
    public function a_route_marked_by_hand_outside_the_prefix_applies_the_fieldsets(): void {
      $response = $this->get('web/marked/1?fields[articles]=title', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertSame(['title' => 'Hello'], $response->json('data.attributes'));
    }

    #[Test]
    public function a_route_marked_by_hand_outside_the_prefix_answers_a_malformed_fields_with_a_400(): void {
      $response = $this->get('web/marked/1?fields=title', ['Accept' => 'text/html']);

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['fields'], array_column(array_column($response->json('errors'), 'source'), 'parameter'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }
  }
