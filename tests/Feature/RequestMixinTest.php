<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Los métodos que el paquete le agrega a la petición para que el controlador lea el documento de entrada sin tocar el JSON:
   * `isJsonApi()` (¿el paquete trata esta petición como de JSON:API?), `jsonApiType()`, `jsonApiId()`, `jsonApiAttributes()`,
   * `hasJsonApiRelationship()`, `jsonApiRelationshipId()` y `jsonApiRelationshipIds()`. Todos se apoyan en el documento ya leído
   * (`RequestDocument::of()` o `RelationshipDocument::of()`), así que funcionan con o sin el middleware, dentro y fuera del alcance, y un documento
   * mal formado es el mismo `400` de siempre. Los nombres de atributos y relaciones van en snake_case, sea cual sea el `member_case`.
   *
   * The methods the package adds to the request so the controller reads the input document without touching the JSON:
   * `isJsonApi()` (does the package treat this request as JSON:API?), `jsonApiType()`, `jsonApiId()`, `jsonApiAttributes()`,
   * `hasJsonApiRelationship()`, `jsonApiRelationshipId()` and `jsonApiRelationshipIds()`. All of them rely on the already read document
   * (`RequestDocument::of()` or `RelationshipDocument::of()`), so they work with or without the middleware, inside and outside the scope, and a
   * malformed document is the same `400` as ever. Attribute and relationship names are in snake_case, whatever the `member_case` is.
   */
  class RequestMixinTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $router->post('api/articles', fn(Request $request) => response()->json([
        'type' => $request->jsonApiType(),
        'id' => $request->jsonApiId(),
        'attributes' => $request->jsonApiAttributes(),
        'hasAuthor' => $request->hasJsonApiRelationship('author'),
        'authorId' => $request->jsonApiRelationshipId('author'),
        'tagIds' => $request->jsonApiRelationshipIds('tags'),
      ]));
      $router->patch('api/articles/{id}/relationships/tags', fn(Request $request) => response()->json([
        'id' => $request->jsonApiRelationshipId(),
        'ids' => $request->jsonApiRelationshipIds(),
      ]));
    }

    private function request(string $json, string $method = 'POST', string $uri = '/api/articles'): Request {
      return Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE], $json);
    }

    private function resource(string $inner): Request {
      return $this->request('{"data":{"type":"articles",' . $inner . '}}');
    }

    private function linkage(string $data): Request {
      return $this->request('{"data":' . $data . '}', 'PATCH', '/api/articles/7/relationships/tags');
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que existe / What exists
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array {
      $names = ['isJsonApi', 'jsonApiType', 'jsonApiId', 'jsonApiAttributes', 'hasJsonApiRelationship', 'jsonApiRelationshipId', 'jsonApiRelationshipIds'];

      return array_combine($names, array_map(fn(string $name) => [$name], $names));
    }

    #[Test]
    #[DataProvider('methods')]
    public function the_request_has_the_method(string $name): void {
      $this->assertTrue(Request::hasMacro($name));
      $this->assertTrue(FormRequest::hasMacro($name), 'A form request must have it too.');
    }

    // ---------------------------------------------------------------------------------------------
    // isJsonApi
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_request_under_the_prefix_is_json_api(): void {
      $this->assertTrue(Request::create('/api/articles')->isJsonApi());
      $this->assertTrue(Request::create('/api')->isJsonApi());
    }

    #[Test]
    public function a_request_outside_the_prefix_is_not(): void {
      $this->assertFalse(Request::create('/web/articles')->isJsonApi());
      $this->assertFalse(Request::create('/apix')->isJsonApi());
    }

    #[Test]
    public function a_request_that_asks_for_the_media_type_is_json_api_anywhere(): void {
      $this->assertTrue(Request::create('/web/articles', 'GET', [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE])->isJsonApi());
    }

    #[Test]
    public function in_global_mode_every_request_is_json_api(): void {
      config()->set('json-api.mode', 'global');

      $this->assertTrue(Request::create('/web/articles')->isJsonApi());
    }

    #[Test]
    public function an_excepted_path_is_not_json_api(): void {
      config()->set('json-api.except', ['api/health']);

      $this->assertFalse(Request::create('/api/health')->isJsonApi());
      $this->assertTrue(Request::create('/api/articles')->isJsonApi());
    }

    #[Test]
    public function a_request_marked_by_hand_is_json_api_even_when_excepted(): void {
      config()->set('json-api.except', ['api/health']);
      $request = Request::create('/api/health');

      app(RequestScope::class)->mark($request);

      $this->assertTrue($request->isJsonApi());
    }

    #[Test]
    public function it_decides_with_the_configuration_of_the_moment(): void {
      $request = Request::create('/web/articles');

      $this->assertFalse($request->isJsonApi());

      config()->set('json-api.path_prefix', 'web');

      $this->assertTrue($request->isJsonApi());
    }

    // ---------------------------------------------------------------------------------------------
    // type e id / type and id
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_gives_the_type_of_the_document(): void {
      $this->assertSame('articles', $this->resource('"attributes":{"title":"T"}')->jsonApiType());
    }

    #[Test]
    public function it_gives_the_id_of_the_document_or_null(): void {
      $this->assertNull($this->resource('"attributes":{}')->jsonApiId());
      $this->assertSame('7', $this->request('{"data":{"type":"articles","id":"7"}}', 'PATCH', '/api/articles/7')->jsonApiId());
    }

    // ---------------------------------------------------------------------------------------------
    // Atributos / Attributes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_gives_the_attributes(): void {
      $request = $this->resource('"attributes":{"title":"Hello","body":null,"tags":["a","b"]}');

      $this->assertSame(['title' => 'Hello', 'body' => null, 'tags' => ['a', 'b']], $request->jsonApiAttributes());
    }

    #[Test]
    public function without_attributes_there_are_none(): void {
      $this->assertSame([], $this->resource('"relationships":{}')->jsonApiAttributes());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function attributeStyles(): array {
      return [
        'camel' => ['camel', 'homeAddress'],
        'kebab' => ['kebab', 'home-address'],
        'snake' => ['snake', 'home_address'],
      ];
    }

    #[Test]
    #[DataProvider('attributeStyles')]
    public function attribute_names_come_in_snake_case_whatever_the_member_case(string $style, string $name): void {
      config()->set('json-api.member_case', $style);

      $this->assertSame(['home_address' => 'x'], $this->resource('"attributes":{"' . $name . '":"x"}')->jsonApiAttributes());
    }

    // ---------------------------------------------------------------------------------------------
    // Relaciones de un recurso / Relationships of a resource
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_one_relationship_gives_its_id(): void {
      $request = $this->resource('"relationships":{"author":{"data":{"type":"people","id":"9"}}}');

      $this->assertTrue($request->hasJsonApiRelationship('author'));
      $this->assertSame('9', $request->jsonApiRelationshipId('author'));
      $this->assertSame(['9'], $request->jsonApiRelationshipIds('author'));
    }

    #[Test]
    public function a_to_many_relationship_gives_its_ids_in_the_order_the_client_used(): void {
      $request = $this->resource('"relationships":{"tags":{"data":[{"type":"tags","id":"3"},{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}}');

      $this->assertTrue($request->hasJsonApiRelationship('tags'));
      $this->assertSame(['3', '1', '2'], $request->jsonApiRelationshipIds('tags'));
    }

    #[Test]
    public function a_to_many_relationship_has_no_single_id(): void {
      $request = $this->resource('"relationships":{"tags":{"data":[{"type":"tags","id":"1"}]}}');

      $this->assertNull($request->jsonApiRelationshipId('tags'));
    }

    #[Test]
    public function an_emptied_relationship_is_there_but_has_no_ids(): void {
      $request = $this->resource('"relationships":{"author":{"data":null},"tags":{"data":[]}}');

      $this->assertTrue($request->hasJsonApiRelationship('author'));
      $this->assertNull($request->jsonApiRelationshipId('author'));
      $this->assertSame([], $request->jsonApiRelationshipIds('author'));
      $this->assertTrue($request->hasJsonApiRelationship('tags'));
      $this->assertSame([], $request->jsonApiRelationshipIds('tags'));
    }

    #[Test]
    public function a_relationship_the_client_did_not_send_is_not_there(): void {
      $request = $this->resource('"relationships":{"author":{"data":null}}');

      $this->assertFalse($request->hasJsonApiRelationship('editor'));
      $this->assertNull($request->jsonApiRelationshipId('editor'));
      $this->assertSame([], $request->jsonApiRelationshipIds('editor'));
    }

    #[Test]
    public function a_relationship_with_only_links_or_meta_is_there_but_has_no_ids(): void {
      $request = $this->resource('"relationships":{"author":{"links":{"related":"/people/9"}},"editor":{"meta":{"a":1}}}');

      $this->assertTrue($request->hasJsonApiRelationship('author'));
      $this->assertNull($request->jsonApiRelationshipId('author'));
      $this->assertSame([], $request->jsonApiRelationshipIds('author'));
      $this->assertTrue($request->hasJsonApiRelationship('editor'));
    }

    #[Test]
    public function a_document_without_relationships_has_none(): void {
      $request = $this->resource('"attributes":{"title":"T"}');

      $this->assertFalse($request->hasJsonApiRelationship('author'));
    }

    #[Test]
    #[DataProvider('attributeStyles')]
    public function relationship_names_are_asked_for_in_snake_case_whatever_the_member_case(string $style, string $name): void {
      config()->set('json-api.member_case', $style);
      $request = $this->resource('"relationships":{"' . $name . '":{"data":{"type":"addresses","id":"4"}}}');

      $this->assertTrue($request->hasJsonApiRelationship('home_address'));
      $this->assertSame('4', $request->jsonApiRelationshipId('home_address'));
      $this->assertSame(['4'], $request->jsonApiRelationshipIds('home_address'));
    }

    #[Test]
    public function the_name_the_client_wrote_is_not_the_name_to_ask_for(): void {
      $request = $this->resource('"relationships":{"homeAddress":{"data":{"type":"addresses","id":"4"}}}');

      $this->assertFalse($request->hasJsonApiRelationship('homeAddress'));
      $this->assertNull($request->jsonApiRelationshipId('homeAddress'));
    }

    // ---------------------------------------------------------------------------------------------
    // El linkage de un endpoint de relación / The linkage of a relationship endpoint
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function at_a_relationship_endpoint_the_id_of_a_to_one_linkage_is_given_without_a_name(): void {
      $request = $this->linkage('{"type":"people","id":"9"}');

      $this->assertSame('9', $request->jsonApiRelationshipId());
      $this->assertSame(['9'], $request->jsonApiRelationshipIds());
    }

    #[Test]
    public function at_a_relationship_endpoint_the_ids_of_a_list_are_given_in_order(): void {
      $request = $this->linkage('[{"type":"tags","id":"3"},{"type":"tags","id":"1"}]');

      $this->assertSame(['3', '1'], $request->jsonApiRelationshipIds());
      $this->assertNull($request->jsonApiRelationshipId());
    }

    #[Test]
    public function at_a_relationship_endpoint_null_and_an_empty_list_have_no_ids(): void {
      foreach (['null', '[]'] as $data) {
        $request = $this->linkage($data);

        $this->assertNull($request->jsonApiRelationshipId(), $data);
        $this->assertSame([], $request->jsonApiRelationshipIds(), $data);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Un documento mal formado / A malformed document
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function documentMethods(): array {
      return [
        'jsonApiType' => ['jsonApiType'],
        'jsonApiId' => ['jsonApiId'],
        'jsonApiAttributes' => ['jsonApiAttributes'],
      ];
    }

    #[Test]
    #[DataProvider('documentMethods')]
    public function a_body_that_is_not_json_is_the_usual_400(string $method): void {
      try {
        $this->request('{')->{$method}();
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $error = $e->errors()[0]->toArray();

        $this->assertSame(400, $e->httpStatus());
        $this->assertSame('', $error['source']['pointer']);
        $this->assertSame($this->english('request.bad_document.reasons.malformed_json'), $error['detail']);
      }
    }

    #[Test]
    #[DataProvider('documentMethods')]
    public function a_request_without_a_body_is_a_400_too(string $method): void {
      // Un `GET` o una acción sin cuerpo no tiene documento: pedirlo es un error visible, no un valor vacío que pasa desapercibido.
      // A `GET` or an action without a body has no document: asking for it is a visible error, not an empty value that goes unnoticed.
      $this->expectException(JsonApiException::class);

      Request::create('/api/articles', 'GET')->{$method}();
    }

    #[Test]
    public function a_badly_shaped_document_is_the_usual_400_with_its_pointers(): void {
      try {
        $this->resource('"attributes":[],"relationships":{"author":"x"}')->jsonApiAttributes();
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $pointers = array_map(fn($error) => $error->toArray()['source']['pointer'], $e->errors());

        $this->assertSame(['/data/attributes', '/data/relationships/author'], $pointers);
      }
    }

    #[Test]
    public function the_relationship_methods_also_reject_a_bad_document(): void {
      $this->expectException(JsonApiException::class);

      $this->resource('"relationships":{"author":{"data":"x"}}')->jsonApiRelationshipId('author');
    }

    #[Test]
    public function the_linkage_methods_also_reject_a_bad_linkage(): void {
      $this->expectException(JsonApiException::class);

      $this->linkage('{"type":"tags"}')->jsonApiRelationshipIds();
    }

    #[Test]
    public function the_id_rules_apply_when_reading_the_document(): void {
      // Un POST con `id` del cliente es el `403` de siempre; un PATCH sin `id`, el `400`.
      // A POST with a client `id` is the usual `403`; a PATCH without an `id`, the `400`.
      try {
        $this->request('{"data":{"type":"articles","id":"abc"}}')->jsonApiType();
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $this->assertSame(403, $e->httpStatus());
      }

      try {
        $this->request('{"data":{"type":"articles"}}', 'PATCH', '/api/articles/7')->jsonApiType();
        $this->fail('A JsonApiException was expected.');
      } catch (JsonApiException $e) {
        $this->assertSame(400, $e->httpStatus());
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Desde un controlador / From a controller
    // ---------------------------------------------------------------------------------------------

    private function send(string $method, string $uri, string $body): TestResponse {
      return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $body);
    }

    #[Test]
    public function a_controller_reads_the_whole_document_with_the_methods(): void {
      $response = $this->send('POST', 'api/articles', '{"data":{"type":"articles","attributes":{"createdAt":"2026-01-01","title":"T"},"relationships":{"author":{"data":{"type":"people","id":"9"}},"tags":{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}}}}');

      $response->assertOk();
      $this->assertSame([
        'type' => 'articles',
        'id' => null,
        'attributes' => ['created_at' => '2026-01-01', 'title' => 'T'],
        'hasAuthor' => true,
        'authorId' => '9',
        'tagIds' => ['1', '2'],
      ], $response->json());
    }

    #[Test]
    public function a_controller_reads_the_linkage_of_a_relationship_endpoint(): void {
      $response = $this->send('PATCH', 'api/articles/7/relationships/tags', '{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}');

      $response->assertOk();
      $this->assertSame(['id' => null, 'ids' => ['1', '2']], $response->json());
    }

    #[Test]
    public function a_bad_document_read_by_the_controller_comes_out_as_json_api(): void {
      // `$validatesDocuments` está apagado en esta clase: aquí el `400` lo da el propio método, sin el middleware.
      // `$validatesDocuments` is off in this class: here the `400` comes from the method itself, without the middleware.
      $response = $this->send('POST', 'api/articles', '{"data":{"type":""}}');

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame('/data/type', $response->json('errors.0.source.pointer'));
    }
  }
