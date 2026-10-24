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
   * Un cuerpo que no es JSON es un `400` de `request.bad_document` con el motivo `malformed_json` y el puntero raíz (el documento entero), tanto
   * para un recurso como para un endpoint de relación. Es distinto de un JSON válido que no tiene la forma de un documento (`null`, `[]`, un
   * texto o un número), que sigue siendo `not_an_object`: el `null` de JSON no es un error de sintaxis.
   *
   * A body that is not JSON is a `400` of `request.bad_document` with the reason `malformed_json` and the root pointer (the whole document), both
   * for a resource and for a relationship endpoint. It is different from valid JSON that does not have the shape of a document (`null`, `[]`, text
   * or a number), which is still `not_an_object`: the JSON `null` is not a syntax error.
   */
  class MalformedJsonTest extends TestCase {
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

        return response()->json(['type' => $document->type()]);
      });

      $router->patch('api/articles/{id}/relationships/tags', function (Request $request) {
        return response()->json(['data' => RelationshipDocument::from($request)->data()]);
      });
    }

    private function send(string $uri, string $method, string $body): TestResponse {
      return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $body);
    }

    private function inResource(string $body): TestResponse {
      return $this->send('api/articles', 'POST', $body);
    }

    private function atEndpoint(string $body): TestResponse {
      return $this->send('api/articles/1/relationships/tags', 'PATCH', $body);
    }

    private function assertMalformed(TestResponse $response): void {
      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCount(1, $response->json('errors'));
      $this->assertSame('400', $response->json('errors.0.status'));
      $this->assertSame('mundoprogra.json-api.request.bad_document', $response->json('errors.0.code'));
      $this->assertSame($this->english('request.bad_document.title'), $response->json('errors.0.title'));
      $this->assertSame($this->english('request.bad_document.reasons.malformed_json'), $response->json('errors.0.detail'));
      $this->assertSame('', $response->json('errors.0.source.pointer'));
      $this->assertArrayNotHasKey('parameter', $response->json('errors.0.source'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notJson(): array {
      return [
        'an empty body' => [''],
        'only spaces' => ['   '],
        'plain text' => ['not json'],
        'an unclosed object' => ['{'],
        'a cut document' => ['{"data":{"type":"articles"'],
        'a missing value' => ['{"data":'],
        'names without quotes' => ['{data:{type:"articles"}}'],
        'single quotes' => ["{'data':{'type':'articles'}}"],
        'a trailing comma' => ['{"data":{"type":"articles",}}'],
        'text after the document' => ['{"data":{"type":"articles"}} extra'],
        'two documents' => ['{"data":{"type":"articles"}}{"data":{"type":"articles"}}'],
        'a byte order mark' => ["\xEF\xBB\xBF" . '{"data":{"type":"articles"}}'],
        'invalid UTF-8' => ['{"data":{"type":"' . "\xC3\x28" . '"}}'],
        'a comment' => ['{"data":{"type":"articles"}} // note'],
        'form data' => ['data[type]=articles'],
        'XML' => ['<data><type>articles</type></data>'],
      ];
    }

    #[Test]
    #[DataProvider('notJson')]
    public function a_body_that_is_not_json_is_a_400_for_a_resource(string $body): void {
      $this->assertMalformed($this->inResource($body));
    }

    #[Test]
    #[DataProvider('notJson')]
    public function a_body_that_is_not_json_is_a_400_for_a_relationship_endpoint(string $body): void {
      $this->assertMalformed($this->atEndpoint($body));
    }

    #[Test]
    public function a_document_nested_deeper_than_json_can_be_read_is_malformed(): void {
      $deep = str_repeat('[', 600) . str_repeat(']', 600);

      $this->assertMalformed($this->inResource('{"data":{"type":"articles","attributes":{"tree":' . $deep . '}}}'));
    }

    // ---------------------------------------------------------------------------------------------
    // JSON válido que no es un documento / Valid JSON that is not a document
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function validJsonThatIsNotADocument(): array {
      return ['null' => ['null'], 'an empty list' => ['[]'], 'text' => ['"articles"'], 'a number' => ['5'], 'a boolean' => ['false']];
    }

    #[Test]
    #[DataProvider('validJsonThatIsNotADocument')]
    public function valid_json_that_is_not_an_object_is_not_a_malformed_body(string $body): void {
      foreach ([$this->inResource($body), $this->atEndpoint($body)] as $response) {
        $response->assertStatus(400);
        $this->assertSame($this->english('request.bad_document.reasons.not_an_object'), $response->json('errors.0.detail'));
        $this->assertSame('', $response->json('errors.0.source.pointer'));
      }
    }

    #[Test]
    public function json_with_extra_whitespace_and_unicode_is_fine(): void {
      $this->inResource(" \n\t{ \"data\" : { \"type\" : \"art\u{ED}culos\" } }\n ")->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Textos / Texts
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_text_follows_the_application_language(): void {
      $this->app->setLocale('es');

      $response = $this->inResource('{');

      $response->assertStatus(400);
      $this->assertSame($this->spanish('request.bad_document.reasons.malformed_json'), $response->json('errors.0.detail'));
    }

    #[Test]
    public function the_text_never_repeats_the_body_the_client_sent(): void {
      $response = $this->inResource('supersecretbody');

      $this->assertStringNotContainsString('supersecret', (string) $response->getContent());
    }
  }
