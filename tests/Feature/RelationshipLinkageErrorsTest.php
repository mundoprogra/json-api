<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;
  use MundoProgra\JsonApi\Http\Requests\RequestDocument;
  use MundoProgra\JsonApi\Support\Settings;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El linkage de una relación es `null`, un identificador (`type` e `id`) o una lista de identificadores. Se revisa en dos sitios: dentro de un
   * recurso (`relationships.autor.data`) y como cuerpo entero de un endpoint de relación (`/articles/1/relationships/tags`). En los dos una falla es
   * un `400` de `request.bad_document` con el `source.pointer` exacto, y las dos comparten la misma lista de fallas; solo cambia el prefijo del puntero.
   *
   * The linkage of a relationship is `null`, an identifier (`type` and `id`) or a list of identifiers. It is checked in two places: inside a resource
   * (`relationships.author.data`) and as the whole body of a relationship endpoint (`/articles/1/relationships/tags`). In both a failure is a `400` of
   * `request.bad_document` with the exact `source.pointer`, and both share the same list of failures; only the prefix of the pointer changes.
   */
  class RelationshipLinkageErrorsTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $router->post('api/articles', function (Request $request) {
        $document = RequestDocument::from($request, app(Settings::class));

        return response()->json(['relationships' => $document->relationships()]);
      });

      $router->patch('api/articles/{id}/relationships/tags', function (Request $request) {
        $document = RelationshipDocument::from($request);

        return response()->json(['data' => $document->data(), 'list' => $document->isList()]);
      });
    }

    private function send(string $uri, string $method, string $json): TestResponse {
      return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $json);
    }

    /**
     * Manda un recurso cuya relación `author` trae ese objeto.
     * Sends a resource whose `author` relationship carries that object.
     */
    private function inResource(string $relationship): TestResponse {
      return $this->send('api/articles', 'POST', '{"data":{"type":"articles","relationships":{"author":' . $relationship . '}}}');
    }

    /**
     * Manda ese documento a un endpoint de relación.
     * Sends that document to a relationship endpoint.
     */
    private function atEndpoint(string $document): TestResponse {
      return $this->send('api/articles/1/relationships/tags', 'PATCH', $document);
    }

    /**
     * Comprueba que la respuesta es un `400` de `request.bad_document` con exactamente esos punteros, en ese orden, y todos con ese motivo.
     * Checks that the response is a `400` of `request.bad_document` with exactly those pointers, in that order, and all of them with that reason.
     *
     * @param  list<string>  $pointers
     */
    private function assertBadDocument(TestResponse $response, array $pointers, string $reason): void {
      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame($pointers, array_column(array_column($response->json('errors'), 'source'), 'pointer'));

      foreach ($response->json('errors') as $error) {
        $this->assertSame('400', $error['status']);
        $this->assertSame('mundoprogra.json-api.request.bad_document', $error['code']);
        $this->assertSame($this->english("request.bad_document.reasons.$reason"), $error['detail']);
        $this->assertArrayNotHasKey('parameter', $error['source']);
      }
    }

    /**
     * Antepone un prefijo a cada puntero relativo.
     * Puts a prefix before each relative pointer.
     *
     * @param  list<string>  $relative
     * @return list<string>
     */
    private function prefixed(string $prefix, array $relative): array {
      return array_map(fn(string $pointer) => $prefix . $pointer, $relative);
    }

    // ---------------------------------------------------------------------------------------------
    // Lo válido / What is valid
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function validLinkages(): array {
      return [
        'null' => ['null'],
        'an identifier' => ['{"type":"people","id":"9"}'],
        'an identifier with meta' => ['{"type":"people","id":"9","meta":{"a":1}}'],
        'a list of identifiers' => ['[{"type":"people","id":"9"},{"type":"people","id":"10"}]'],
        'an empty list' => ['[]'],
      ];
    }

    #[Test]
    #[DataProvider('validLinkages')]
    public function a_valid_linkage_goes_through_inside_a_resource(string $linkage): void {
      $this->inResource('{"data":' . $linkage . '}')->assertOk();
    }

    #[Test]
    #[DataProvider('validLinkages')]
    public function a_valid_linkage_goes_through_at_a_relationship_endpoint(string $linkage): void {
      $this->atEndpoint('{"data":' . $linkage . '}')->assertOk();
    }

    #[Test]
    public function a_relationship_with_only_links_or_only_meta_goes_through(): void {
      $this->inResource('{"links":{"related":"/people/9"}}')->assertOk();
      $this->inResource('{"meta":{"a":1}}')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Fallas del linkage, dentro de un recurso y en un endpoint / Linkage failures, in a resource and at an endpoint
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function badLinkages(): array {
      // [valor de `data`, punteros relativos a `data`, motivo] / [value of `data`, pointers relative to `data`, reason]
      return [
        'text' => ['"people"', [''], 'invalid_linkage'],
        'a number' => ['5', [''], 'invalid_linkage'],
        'a boolean' => ['true', [''], 'invalid_linkage'],
        'an identifier without type and id' => ['{}', ['/type', '/id'], 'member_required'],
        'an identifier without id' => ['{"type":"people"}', ['/id'], 'member_required'],
        'an identifier without type' => ['{"id":"9"}', ['/type'], 'member_required'],
        'a type that is not a string' => ['{"type":5,"id":"9"}', ['/type'], 'not_a_string'],
        'an id that is not a string' => ['{"type":"people","id":9}', ['/id'], 'not_a_string'],
        'an id that is a number like text' => ['{"type":"people","id":9.5}', ['/id'], 'not_a_string'],
        'a null type' => ['{"type":null,"id":"9"}', ['/type'], 'not_a_string'],
        'a null id' => ['{"type":"people","id":null}', ['/id'], 'not_a_string'],
        'an empty type' => ['{"type":"","id":"9"}', ['/type'], 'not_a_string'],
        'an empty id' => ['{"type":"people","id":""}', ['/id'], 'not_a_string'],
        'type and id that are not strings' => ['{"type":5,"id":7}', ['/type', '/id'], 'not_a_string'],
        'an item without id' => ['[{"type":"people","id":"9"},{"type":"people"}]', ['/1/id'], 'member_required'],
        'an item without type' => ['[{"id":"9"}]', ['/0/type'], 'member_required'],
        'an item with an id that is not a string' => ['[{"type":"people","id":"9"},{"type":"people","id":10}]', ['/1/id'], 'not_a_string'],
        'items that are text' => ['["people","tags"]', ['/0', '/1'], 'not_an_object'],
        'an item that is null' => ['[null]', ['/0'], 'not_an_object'],
        'an item that is a list' => ['[[]]', ['/0'], 'not_an_object'],
        'a nested list of identifiers' => ['[[{"type":"people","id":"9"}]]', ['/0'], 'not_an_object'],
      ];
    }

    /**
     * @param  list<string>  $relative
     */
    #[Test]
    #[DataProvider('badLinkages')]
    public function a_bad_linkage_inside_a_resource_is_a_400_pointing_at_it(string $data, array $relative, string $reason): void {
      $this->assertBadDocument(
        $this->inResource('{"data":' . $data . '}'),
        $this->prefixed('/data/relationships/author/data', $relative),
        $reason,
      );
    }

    /**
     * @param  list<string>  $relative
     */
    #[Test]
    #[DataProvider('badLinkages')]
    public function a_bad_linkage_at_a_relationship_endpoint_is_a_400_pointing_at_it(string $data, array $relative, string $reason): void {
      $this->assertBadDocument(
        $this->atEndpoint('{"data":' . $data . '}'),
        $this->prefixed('/data', $relative),
        $reason,
      );
    }

    // ---------------------------------------------------------------------------------------------
    // Solo dentro de un recurso / Only inside a resource
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function emptyRelationships(): array {
      return [
        'an empty object' => ['{}'],
        'only unknown members' => ['{"foo":1}'],
        'only an unknown member that looks like data' => ['{"Data":null}'],
      ];
    }

    #[Test]
    #[DataProvider('emptyRelationships')]
    public function a_relationship_needs_links_data_or_meta(string $relationship): void {
      $this->assertBadDocument($this->inResource($relationship), ['/data/relationships/author'], 'member_required');
    }

    #[Test]
    public function the_problems_of_several_relationships_come_out_together_in_the_order_they_were_sent(): void {
      $response = $this->send(
        'api/articles',
        'POST',
        '{"data":{"type":"articles","relationships":{"author":{"data":"x"},"tags":{"data":[{"type":"tags"}]},"editor":{"data":null}}}}',
      );

      $response->assertStatus(400);
      $this->assertSame(
        ['/data/relationships/author/data', '/data/relationships/tags/data/0/id'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer'),
      );
    }

    #[Test]
    public function the_pointer_uses_the_name_the_client_sent_for_the_relationship(): void {
      $response = $this->send('api/articles', 'POST', '{"data":{"type":"articles","relationships":{"homeAddress":{"data":5}}}}');

      $this->assertSame('/data/relationships/homeAddress/data', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    public function a_bad_linkage_comes_after_the_problems_of_the_attributes(): void {
      $response = $this->send(
        'api/articles',
        'POST',
        '{"data":{"type":"articles","attributes":{"id":1},"relationships":{"author":{"data":5}}}}',
      );

      $this->assertSame(
        ['/data/attributes/id', '/data/relationships/author/data'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer'),
      );
    }

    // ---------------------------------------------------------------------------------------------
    // Solo en un endpoint de relación / Only at a relationship endpoint
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function badEndpointDocuments(): array {
      return [
        'an empty object' => ['{}', [''], 'member_required'],
        'only meta' => ['{"meta":{"a":1}}', [''], 'member_required'],
        'a document that is a list' => ['[]', [''], 'not_an_object'],
        'a document that is text' => ['"people"', [''], 'not_an_object'],
        'a document that is null' => ['null', [''], 'not_an_object'],
      ];
    }

    /**
     * @param  list<string>  $pointers
     */
    #[Test]
    #[DataProvider('badEndpointDocuments')]
    public function a_relationship_endpoint_document_must_be_an_object_with_data(string $document, array $pointers, string $reason): void {
      $this->assertBadDocument($this->atEndpoint($document), $pointers, $reason);
    }

    #[Test]
    public function a_whole_resource_sent_to_a_relationship_endpoint_is_accepted_as_one_identifier_without_its_other_members(): void {
      $response = $this->atEndpoint('{"data":{"type":"tags","id":"1","attributes":{"name":"php"}}}');

      $response->assertOk();
      $this->assertSame(['type' => 'tags', 'id' => '1'], $response->json('data'));
    }

    #[Test]
    public function the_endpoint_gives_back_the_linkage_and_whether_it_is_a_list(): void {
      $one = $this->atEndpoint('{"data":{"type":"tags","id":"1"}}');
      $many = $this->atEndpoint('{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}');
      $none = $this->atEndpoint('{"data":null}');

      $this->assertFalse($one->json('list'));
      $this->assertTrue($many->json('list'));
      $this->assertSame(2, count($many->json('data')));
      $this->assertFalse($none->json('list'));
      $this->assertNull($none->json('data'));
    }

    // ---------------------------------------------------------------------------------------------
    // Textos / Texts
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_texts_never_repeat_what_the_client_sent(): void {
      $response = $this->inResource('{"data":{"type":"supersecrettype","id":9}}');

      foreach ($response->json('errors') as $error) {
        $this->assertStringNotContainsString('supersecret', $error['title'] . $error['detail']);
      }
    }

    #[Test]
    public function the_invalid_linkage_text_follows_the_application_language(): void {
      $this->app->setLocale('es');

      $response = $this->atEndpoint('{"data":"people"}');

      $response->assertStatus(400);
      $this->assertSame($this->spanish('request.bad_document.reasons.invalid_linkage'), $response->json('errors.0.detail'));
    }
  }
