<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Http\Requests\RequestDocument;
  use MundoProgra\JsonApi\Support\Settings;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Un documento de entrada mal formado es un `400` en JSON:API de la familia `request.bad_document`, con el `source.pointer` exacto del
   * miembro que falla. Todos los problemas del documento salen juntos en un solo documento de errores, en el orden `type`, `id`, `attributes`,
   * `relationships`. Los textos no repiten lo que mandó el cliente. Este punto (9.1) revisa la forma del recurso; el linkage de las relaciones es
   * del 9.2 y las reglas de `id` y el JSON mal formado, del 9.3.
   *
   * A malformed input document is a JSON:API `400` of the `request.bad_document` family, with the exact `source.pointer` of the member that fails.
   * All the problems of the document come out together in a single error document, in the order `type`, `id`, `attributes`, `relationships`.
   * The texts do not repeat what the client sent. This point (9.1) checks the shape of the resource; the linkage of the relationships is 9.2 and
   * the `id` rules and malformed JSON are 9.3.
   */
  class RequestDocumentErrorsTest extends TestCase {
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

        return response()->json([
          'type' => $document->type(),
          'id' => $document->id(),
          'attributes' => $document->attributes(),
          'relationships' => $document->relationships(),
        ]);
      });
    }

    private function send(string $json): TestResponse {
      return $this->call('POST', 'api/articles', [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $json);
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
        $this->assertSame($this->english('request.bad_document.title'), $error['title']);
        $this->assertSame($this->english("request.bad_document.reasons.$reason"), $error['detail']);
        $this->assertArrayNotHasKey('parameter', $error['source']);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Lo válido sigue igual / What is valid stays the same
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_complete_valid_document_goes_through(): void {
      // Un POST con `id` solo se acepta si la configuración permite ids del cliente (ver `DocumentIdRulesTest`).
      // A POST with an `id` is only accepted if the configuration allows client ids (see `DocumentIdRulesTest`).
      config()->set('json-api.allow_client_ids', true);

      $response = $this->send('{"data":{"type":"articles","id":"7","attributes":{"title":"T","createdAt":"2026-01-01"},"relationships":{"author":{"data":{"type":"people","id":"9"}}}}}');

      $response->assertOk();
      $this->assertSame('articles', $response->json('type'));
      $this->assertSame('7', $response->json('id'));
      $this->assertSame(['title' => 'T', 'created_at' => '2026-01-01'], $response->json('attributes'));
      $this->assertSame(['author' => ['data' => ['type' => 'people', 'id' => '9']]], $response->json('relationships'));
    }

    #[Test]
    public function a_document_with_only_the_type_goes_through(): void {
      $this->send('{"data":{"type":"articles"}}')->assertOk();
    }

    #[Test]
    public function empty_attributes_and_relationships_objects_go_through(): void {
      $this->send('{"data":{"type":"articles","attributes":{},"relationships":{}}}')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // El documento y `data` / The document and `data`
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function badStructures(): array {
      // [cuerpo, punteros esperados, motivo] / [body, expected pointers, reason]
      return [
        'an empty object' => ['{}', [''], 'member_required'],
        'an object without data' => ['{"meta":{"a":1}}', [''], 'member_required'],
        'a document that is a list' => ['[]', [''], 'not_an_object'],
        'a document that is a list with items' => ['[{"data":{"type":"a"}}]', [''], 'not_an_object'],
        'a document that is text' => ['"articles"', [''], 'not_an_object'],
        'a document that is a number' => ['5', [''], 'not_an_object'],
        'a document that is null' => ['null', [''], 'not_an_object'],
        'data null' => ['{"data":null}', ['/data'], 'not_an_object'],
        'data an empty list' => ['{"data":[]}', ['/data'], 'not_an_object'],
        'data a list of resources' => ['{"data":[{"type":"articles"}]}', ['/data'], 'not_an_object'],
        'data text' => ['{"data":"articles"}', ['/data'], 'not_an_object'],
        'data a number' => ['{"data":5}', ['/data'], 'not_an_object'],
        'data a boolean' => ['{"data":true}', ['/data'], 'not_an_object'],
        'data without type' => ['{"data":{}}', ['/data/type'], 'member_required'],
        'data with other members but no type' => ['{"data":{"id":"1","attributes":{"a":1}}}', ['/data/type'], 'member_required'],
        'a member called Type is not type' => ['{"data":{"Type":"articles"}}', ['/data/type'], 'member_required'],
      ];
    }

    /**
     * @param  list<string>  $pointers
     */
    #[Test]
    #[DataProvider('badStructures')]
    public function a_badly_shaped_document_is_a_400_with_the_exact_pointer(string $body, array $pointers, string $reason): void {
      $this->assertBadDocument($this->send($body), $pointers, $reason);
    }

    // ---------------------------------------------------------------------------------------------
    // type e id / type and id
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badTypesAndIds(): array {
      // [cuerpo, puntero, motivo] / [body, pointer, reason]
      return [
        'type null' => ['{"data":{"type":null}}', '/data/type', 'not_a_string'],
        'type a number' => ['{"data":{"type":5}}', '/data/type', 'not_a_string'],
        'type a boolean' => ['{"data":{"type":true}}', '/data/type', 'not_a_string'],
        'type empty' => ['{"data":{"type":""}}', '/data/type', 'not_a_string'],
        'type a list' => ['{"data":{"type":["articles"]}}', '/data/type', 'not_a_string'],
        'type an object' => ['{"data":{"type":{"a":1}}}', '/data/type', 'not_a_string'],
        'id null' => ['{"data":{"type":"articles","id":null}}', '/data/id', 'not_a_string'],
        'id a number' => ['{"data":{"type":"articles","id":5}}', '/data/id', 'not_a_string'],
        'id a boolean' => ['{"data":{"type":"articles","id":false}}', '/data/id', 'not_a_string'],
        'id empty' => ['{"data":{"type":"articles","id":""}}', '/data/id', 'not_a_string'],
        'id a list' => ['{"data":{"type":"articles","id":["1"]}}', '/data/id', 'not_a_string'],
      ];
    }

    #[Test]
    #[DataProvider('badTypesAndIds')]
    public function a_type_or_an_id_that_is_not_a_non_empty_text_is_a_400(string $body, string $pointer, string $reason): void {
      $this->assertBadDocument($this->send($body), [$pointer], $reason);
    }

    // ---------------------------------------------------------------------------------------------
    // attributes y relationships / attributes and relationships
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function notObjects(): array {
      return [
        'null' => ['null'],
        'an empty list' => ['[]'],
        'a list with items' => ['["a","b"]'],
        'text' => ['"title"'],
        'a number' => ['5'],
        'a boolean' => ['true'],
      ];
    }

    #[Test]
    #[DataProvider('notObjects')]
    public function attributes_that_are_not_an_object_are_a_400(string $value): void {
      $this->assertBadDocument($this->send('{"data":{"type":"articles","attributes":' . $value . '}}'), ['/data/attributes'], 'not_an_object');
    }

    #[Test]
    #[DataProvider('notObjects')]
    public function relationships_that_are_not_an_object_are_a_400(string $value): void {
      $this->assertBadDocument($this->send('{"data":{"type":"articles","relationships":' . $value . '}}'), ['/data/relationships'], 'not_an_object');
    }

    #[Test]
    #[DataProvider('notObjects')]
    public function a_relationship_that_is_not_an_object_is_a_400_pointing_at_it(string $value): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","relationships":{"author":' . $value . '}}}'),
        ['/data/relationships/author'],
        'not_an_object',
      );
    }

    #[Test]
    public function the_pointer_of_a_relationship_escapes_slashes_and_tildes(): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","relationships":{"a/b":"x","c~d":"y"}}}'),
        ['/data/relationships/a~1b', '/data/relationships/c~0d'],
        'not_an_object',
      );
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres reservados y repetidos / Reserved and repeated names
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNames(): array {
      // Los campos de un recurso comparten un solo espacio de nombres: estos cuatro no pueden ser atributo ni relación.
      // The fields of a resource share a single namespace: these four cannot be an attribute or a relationship.
      return ['type' => ['type'], 'id' => ['id'], 'relationships' => ['relationships'], 'links' => ['links']];
    }

    #[Test]
    #[DataProvider('reservedNames')]
    public function an_attribute_cannot_be_called_like_a_reserved_member(string $name): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","attributes":{"' . $name . '":"x"}}}'),
        ["/data/attributes/$name"],
        'reserved_member_name',
      );
    }

    #[Test]
    #[DataProvider('reservedNames')]
    public function a_relationship_cannot_be_called_like_a_reserved_member(string $name): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","relationships":{"' . $name . '":{"data":null}}}}'),
        ["/data/relationships/$name"],
        'reserved_member_name',
      );
    }

    #[Test]
    public function all_the_reserved_attributes_come_out_in_the_order_the_client_sent_them(): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","attributes":{"links":1,"title":"T","id":2,"type":3}}}'),
        ['/data/attributes/links', '/data/attributes/id', '/data/attributes/type'],
        'reserved_member_name',
      );
    }

    #[Test]
    public function a_name_that_is_both_an_attribute_and_a_relationship_is_a_400_pointing_at_the_relationship(): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","attributes":{"author":"x"},"relationships":{"author":{"data":null}}}}'),
        ['/data/relationships/author'],
        'duplicate_member_name',
      );
    }

    #[Test]
    public function two_spellings_of_the_same_name_count_as_the_same_name(): void {
      // Con `member_case = camel`, `homeAddress` se guarda como `home_address` y un nombre que ya está en snake_case no cambia: si el cliente manda
      // `homeAddress` como atributo y `home_address` como relación, el recurso quedaría con un solo campo.
      // With `member_case = camel`, `homeAddress` is stored as `home_address` and a name already in snake_case does not change: if the client sends
      // `homeAddress` as an attribute and `home_address` as a relationship, the resource would end up with a single field.
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","attributes":{"homeAddress":"x"},"relationships":{"home_address":{"data":null}}}}'),
        ['/data/relationships/home_address'],
        'duplicate_member_name',
      );
    }

    #[Test]
    public function two_attributes_that_end_up_with_the_same_name_are_a_400_and_not_a_server_error(): void {
      // La conversión de nombres lanza una excepción cuando dos claves chocan; con datos del cliente eso debe ser un `400` y no un `500`.
      // The name conversion throws an exception when two keys collide; with client data that must be a `400` and not a `500`.
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","attributes":{"homeAddress":"x","home_address":"y"}}}'),
        ['/data/attributes/home_address'],
        'duplicate_member_name',
      );
    }

    #[Test]
    public function two_relationships_that_end_up_with_the_same_name_are_a_400_and_not_a_server_error(): void {
      $this->assertBadDocument(
        $this->send('{"data":{"type":"articles","relationships":{"homeAddress":{"data":null},"home_address":{"data":null}}}}'),
        ['/data/relationships/home_address'],
        'duplicate_member_name',
      );
    }

    // ---------------------------------------------------------------------------------------------
    // Todos juntos / All together
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_problem_of_the_document_comes_out_together_in_a_fixed_order(): void {
      $response = $this->send('{"data":{"type":5,"id":7,"attributes":[],"relationships":[]}}');

      $response->assertStatus(400);
      $this->assertSame(
        ['/data/type', '/data/id', '/data/attributes', '/data/relationships'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer'),
      );
      $this->assertCount(4, $response->json('errors'));
    }

    #[Test]
    public function a_problem_inside_attributes_and_another_inside_relationships_come_out_together(): void {
      $response = $this->send('{"data":{"type":"articles","attributes":{"id":1},"relationships":{"author":"x"}}}');

      $response->assertStatus(400);
      $this->assertSame(
        ['/data/attributes/id', '/data/relationships/author'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer'),
      );
    }

    #[Test]
    public function the_texts_never_repeat_what_the_client_sent(): void {
      $response = $this->send('{"data":{"type":"supersecrettype","attributes":{"supersecretname":1},"relationships":{"supersecretrel":"supersecretvalue"}}}');

      foreach ($response->json('errors') as $error) {
        $this->assertStringNotContainsString('supersecret', $error['title'] . $error['detail']);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Idioma / Language
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_texts_follow_the_application_language(): void {
      $this->app->setLocale('es');

      $response = $this->send('{"data":{"type":""}}');

      $response->assertStatus(400);
      $this->assertSame($this->spanish('request.bad_document.title'), $response->json('errors.0.title'));
      $this->assertSame($this->spanish('request.bad_document.reasons.not_a_string'), $response->json('errors.0.detail'));
    }
  }
