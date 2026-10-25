<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Routing\Middleware\SubstituteBindings;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Requests\FixedIdRequest;
  use MundoProgra\JsonApi\Tests\Fixtures\Requests\LooseRequest;
  use MundoProgra\JsonApi\Tests\Fixtures\Requests\NoIdCheckRequest;
  use MundoProgra\JsonApi\Tests\Fixtures\Requests\StoreArticleRequest;
  use MundoProgra\JsonApi\Tests\Fixtures\Requests\UpdateArticleRequest;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El trait `InteractsWithJsonApi` convierte un `FormRequest` en uno que entiende documentos JSON:API. Tiene tres efectos:
   *  1. Las reglas se escriben sobre los datos planos del documento: los atributos en snake_case y, para cada relación, su objeto
   *     (`author.data.id`, `tags.data.*.id`). Así `validated()` sirve para llenar un modelo y los errores salen con su `source.pointer`.
   *  2. Si el documento no tiene la forma de un recurso, o trae un `id` que no se acepta, se responde antes de correr las reglas (400 o 403).
   *  3. Si el `type` no es el que el endpoint espera (`jsonApiExpectedType()`), o el `id` no es el de la URL (`jsonApiExpectedId()`), es un `409`
   *     que también sale antes de las reglas.
   *
   * The `InteractsWithJsonApi` trait turns a `FormRequest` into one that understands JSON:API documents. It has three effects:
   *  1. The rules are written over the flat data of the document: attributes in snake_case and, for each relationship, its object
   *     (`author.data.id`, `tags.data.*.id`). That way `validated()` can fill a model and the errors come out with their `source.pointer`.
   *  2. If the document does not have the shape of a resource, or carries an `id` that is not accepted, the answer comes before the rules run (400 or 403).
   *  3. If the `type` is not the one the endpoint expects (`jsonApiExpectedType()`), or the `id` is not the one in the URL (`jsonApiExpectedId()`),
   *     it is a `409` that also comes before the rules.
   */
  class InteractsWithJsonApiTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const UPDATE = '{"data":{"type":"articles","id":"7","attributes":{"title":"T"}}}';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $store = fn(StoreArticleRequest $request) => response()->json(['validated' => $request->validated(), 'type' => $request->jsonApiType()]);
      $update = fn(UpdateArticleRequest $request) => response()->json(['validated' => $request->validated()]);
      $ok = fn() => response()->json(['ok' => true]);

      $router->post('api/articles', $store);
      $router->post('api/authors/{author}/articles', fn(StoreArticleRequest $request, string $author) => response()->json(['author' => $author]));
      $router->match(['PATCH', 'PUT'], 'api/articles/{article}', fn(UpdateArticleRequest $request, string $article) => response()->json(['article' => $article]));
      $router->patch('api/profile', $update);

      $router->bind('bound', fn(string $value) => (new Article())->forceFill(['id' => (int) $value]));
      $router->bind('tag', fn(string $value) => (new Tag())->forceFill(['slug' => $value]));
      $router->patch('api/bound/{bound}', fn(UpdateArticleRequest $request, Article $bound) => response()->json(['bound' => $bound->getKey()]))->middleware(SubstituteBindings::class);
      $router->patch('api/tags/{tag}', fn(UpdateArticleRequest $request, Tag $tag) => response()->json(['tag' => $tag->slug]))->middleware(SubstituteBindings::class);

      $router->post('api/loose', fn(LooseRequest $request) => response()->json(['type' => $request->jsonApiType()]));
      $router->patch('api/loose/{thing}', fn(LooseRequest $request, string $thing) => $ok());
      $router->patch('api/fixed/{thing}', fn(FixedIdRequest $request, string $thing) => $ok());
      $router->patch('api/nocheck/{thing}', fn(NoIdCheckRequest $request, string $thing) => $ok());
    }

    private function send(string $method, string $uri, string $body): TestResponse {
      return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $body);
    }

    /**
     * @param  list<string>  $pointers
     */
    private function assertErrors(TestResponse $response, int $status, array $pointers): void {
      $response->assertStatus($status);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame($pointers, array_column(array_column($response->json('errors'), 'source'), 'pointer'));
    }

    /**
     * @param  list<string>  $pointers
     * @param  list<string>  $reasons
     */
    private function assertConflict(TestResponse $response, array $pointers, array $reasons): void {
      $this->assertErrors($response, 409, $pointers);

      foreach ($response->json('errors') as $index => $error) {
        $this->assertSame('409', $error['status']);
        $this->assertSame('mundoprogra.json-api.resource.conflict', $error['code']);
        $this->assertSame($this->english('resource.conflict.title'), $error['title']);
        $this->assertSame($this->english('resource.conflict.reasons.' . $reasons[$index]), $error['detail']);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Las reglas sobre los datos planos / The rules over the flat data
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_rules_see_the_attributes_and_the_relationships_and_validated_gives_only_what_has_a_rule(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"Hello","createdAt":"2026-01-01","extra":"not validated"},"relationships":{"author":{"data":{"type":"people","id":"9"}},"tags":{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}}}}');

      $response->assertOk();
      $this->assertSame([
        'title' => 'Hello',
        'created_at' => '2026-01-01',
        'author' => ['data' => ['id' => '9']],
        'tags' => ['data' => [['id' => '1'], ['id' => '2']]],
      ], $response->json('validated'));
    }

    #[Test]
    public function the_attribute_names_are_the_snake_case_ones(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"T","createdAt":"2026-01-01"}}}');

      $this->assertSame(['title', 'created_at'], array_keys($response->json('validated')));
    }

    #[Test]
    public function a_failed_rule_on_an_attribute_points_at_it_with_the_client_style_name(): void {
      $this->assertErrors($this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"T","createdAt":"not a date"}}}'), 422, ['/data/attributes/createdAt']);
    }

    #[Test]
    public function a_missing_required_attribute_points_at_it(): void {
      $this->assertErrors($this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{}}}'), 422, ['/data/attributes/title']);
    }

    #[Test]
    public function a_failed_rule_on_a_to_one_linkage_points_at_its_id(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"T"},"relationships":{"author":{"data":{"type":"people","id":"abc"}}}}}');

      $this->assertErrors($response, 422, ['/data/relationships/author/data/id']);
    }

    #[Test]
    public function a_failed_rule_on_a_to_many_linkage_points_at_the_item(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"T"},"relationships":{"tags":{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"x"}]}}}}');

      $this->assertErrors($response, 422, ['/data/relationships/tags/data/1/id']);
    }

    #[Test]
    public function every_failed_rule_comes_out_together_in_the_order_of_the_rules(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"far too long a title","createdAt":"nope"},"relationships":{"author":{"data":{"type":"people","id":"abc"}}}}}');

      $this->assertErrors($response, 422, ['/data/attributes/title', '/data/attributes/createdAt', '/data/relationships/author/data/id']);
    }

    #[Test]
    public function the_type_is_still_readable_in_the_controller(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"title":"T"}}}');

      $this->assertSame('articles', $response->json('type'));
    }

    // ---------------------------------------------------------------------------------------------
    // Un documento que no se puede entender / A document that cannot be understood
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_body_that_is_not_json_is_a_400_before_the_rules(): void {
      $this->assertErrors($this->send('POST', 'api/articles', '{'), 400, ['']);
    }

    #[Test]
    public function a_badly_shaped_document_is_a_400_with_its_pointers_and_not_a_422(): void {
      $this->assertErrors($this->send('POST', 'api/articles', '{"data":{"type":5,"attributes":[]}}'), 400, ['/data/type', '/data/attributes']);
    }

    #[Test]
    public function a_client_id_in_a_post_is_the_usual_403(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","id":"abc","attributes":{"title":"T"}}}');

      $this->assertErrors($response, 403, ['/data/id']);
    }

    #[Test]
    public function an_update_without_an_id_is_the_usual_400(): void {
      $this->assertErrors($this->send('PATCH', 'api/articles/7', '{"data":{"type":"articles","attributes":{"title":"T"}}}'), 400, ['/data/id']);
    }

    // ---------------------------------------------------------------------------------------------
    // 409: el tipo / the type
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_type_other_than_the_expected_one_is_a_409_when_creating(): void {
      $this->assertConflict($this->send('POST', 'api/articles', '{"data":{"type":"people","attributes":{"title":"T"}}}'), ['/data/type'], ['type_mismatch']);
    }

    #[Test]
    public function a_type_other_than_the_expected_one_is_a_409_when_updating(): void {
      $this->assertConflict($this->send('PATCH', 'api/articles/7', '{"data":{"type":"people","id":"7"}}'), ['/data/type'], ['type_mismatch']);
    }

    #[Test]
    public function the_type_is_compared_exactly(): void {
      $this->assertConflict($this->send('POST', 'api/articles', '{"data":{"type":"Articles","attributes":{"title":"T"}}}'), ['/data/type'], ['type_mismatch']);
    }

    #[Test]
    public function without_an_expected_type_any_type_goes_through(): void {
      $response = $this->send('POST', 'api/loose', '{"data":{"type":"anything-at-all"}}');

      $response->assertOk();
      $this->assertSame('anything-at-all', $response->json('type'));
    }

    #[Test]
    public function the_conflict_comes_before_the_rules(): void {
      // El título falta (un 422), pero el tipo no es el del endpoint: primero se responde el 409.
      // The title is missing (a 422), but the type is not the endpoint one: the 409 is answered first.
      $this->assertConflict($this->send('POST', 'api/articles', '{"data":{"type":"people","attributes":{}}}'), ['/data/type'], ['type_mismatch']);
    }

    #[Test]
    public function a_badly_shaped_document_comes_before_the_conflict(): void {
      $this->assertErrors($this->send('POST', 'api/articles', '{"data":{"type":"","attributes":{}}}'), 400, ['/data/type']);
    }

    // ---------------------------------------------------------------------------------------------
    // 409: el id contra la URL / the id against the URL
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_update_with_the_id_of_the_url_goes_through(): void {
      $response = $this->send('PATCH', 'api/articles/7', self::UPDATE);

      $response->assertOk();
      $this->assertSame('7', $response->json('article'));
    }

    #[Test]
    public function an_update_with_another_id_than_the_url_is_a_409(): void {
      $this->assertConflict($this->send('PATCH', 'api/articles/8', self::UPDATE), ['/data/id'], ['id_mismatch']);
    }

    #[Test]
    public function the_type_and_the_id_conflicts_come_out_together_type_first(): void {
      $response = $this->send('PATCH', 'api/articles/8', '{"data":{"type":"people","id":"7"}}');

      $this->assertConflict($response, ['/data/type', '/data/id'], ['type_mismatch', 'id_mismatch']);
    }

    #[Test]
    public function a_put_is_compared_like_a_patch(): void {
      config()->set('json-api.allow_put', true);

      $this->send('PUT', 'api/articles/7', self::UPDATE)->assertOk();
      $this->assertConflict($this->send('PUT', 'api/articles/8', self::UPDATE), ['/data/id'], ['id_mismatch']);
    }

    #[Test]
    public function a_bound_model_is_compared_by_its_route_key(): void {
      $this->send('PATCH', 'api/bound/7', self::UPDATE)->assertOk();
      $this->assertConflict($this->send('PATCH', 'api/bound/8', self::UPDATE), ['/data/id'], ['id_mismatch']);
    }

    #[Test]
    public function a_model_with_its_own_route_key_is_compared_by_that_key(): void {
      $body = '{"data":{"type":"articles","id":"php","attributes":{"title":"T"}}}';

      $this->send('PATCH', 'api/tags/php', $body)->assertOk();
      $this->assertConflict($this->send('PATCH', 'api/tags/laravel', $body), ['/data/id'], ['id_mismatch']);
    }

    #[Test]
    public function a_route_without_parameters_has_no_id_to_compare(): void {
      $this->send('PATCH', 'api/profile', self::UPDATE)->assertOk();
    }

    #[Test]
    public function a_post_is_never_compared_with_the_url(): void {
      // `{author}` es el padre, no el artículo que se crea.
      // `{author}` is the parent, not the article being created.
      $response = $this->send('POST', 'api/authors/3/articles', '{"data":{"type":"articles","attributes":{"title":"T"}}}');

      $response->assertOk();
      $this->assertSame('3', $response->json('author'));
    }

    #[Test]
    public function the_expected_id_can_be_declared_by_the_request(): void {
      $this->send('PATCH', 'api/fixed/1', '{"data":{"type":"articles","id":"99"}}')->assertOk();
      $this->assertConflict($this->send('PATCH', 'api/fixed/1', '{"data":{"type":"articles","id":"1"}}'), ['/data/id'], ['id_mismatch']);
    }

    #[Test]
    public function the_id_check_can_be_turned_off(): void {
      $this->send('PATCH', 'api/nocheck/1', '{"data":{"type":"articles","id":"2"}}')->assertOk();
    }

    #[Test]
    public function a_request_without_an_expected_type_still_compares_the_id(): void {
      $this->assertConflict($this->send('PATCH', 'api/loose/1', '{"data":{"type":"anything","id":"2"}}'), ['/data/id'], ['id_mismatch']);
    }

    // ---------------------------------------------------------------------------------------------
    // Textos / Texts
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_conflict_texts_follow_the_application_language(): void {
      $this->app->setLocale('es');

      $response = $this->send('PATCH', 'api/articles/8', '{"data":{"type":"people","id":"7"}}');

      $response->assertStatus(409);
      $this->assertSame($this->spanish('resource.conflict.reasons.type_mismatch'), $response->json('errors.0.detail'));
      $this->assertSame($this->spanish('resource.conflict.reasons.id_mismatch'), $response->json('errors.1.detail'));
    }

    #[Test]
    public function the_conflict_texts_never_repeat_what_the_client_sent(): void {
      $response = $this->send('PATCH', 'api/articles/8', '{"data":{"type":"supersecrettype","id":"supersecretid"}}');

      $response->assertStatus(409);
      $this->assertStringNotContainsString('supersecret', implode(' ', array_column($response->json('errors'), 'detail')));
    }
  }
