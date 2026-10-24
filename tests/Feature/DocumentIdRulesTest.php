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
   * El `id` de un documento de entrada depende del método. Al crear (`POST`) el cliente no debe mandar `id`: si lo manda y `allow_client_ids` está
   * apagado (lo normal) es un `403` con su propio código, `request.client_id_not_allowed`, y puntero `/data/id`. Al actualizar (`PATCH`, y `PUT`
   * si la app lo usa) el `id` es obligatorio: si falta es un `400` de `request.bad_document` con puntero `/data/id`. Si el documento tiene
   * problemas de forma (`400`), salen esos y no el `403`. Que el `id` del documento sea el de la URL (`409`) se revisa en el punto 9.6.
   *
   * The `id` of an input document depends on the method. When creating (`POST`) the client must not send an `id`: if it does and `allow_client_ids`
   * is off (the usual case) it is a `403` with its own code, `request.client_id_not_allowed`, and pointer `/data/id`. When updating (`PATCH`, and `PUT`
   * if the app uses it) the `id` is required: if it is missing it is a `400` of `request.bad_document` with pointer `/data/id`. If the document has
   * shape problems (`400`), those come out and not the `403`. That the `id` of the document is the one in the URL (`409`) is checked in point 9.6.
   */
  class DocumentIdRulesTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $answer = function (Request $request) {
        $document = RequestDocument::from($request, app(Settings::class));

        return response()->json(['type' => $document->type(), 'id' => $document->id()]);
      };

      $router->post('api/articles', $answer);
      $router->patch('api/articles/{id}', $answer);
      $router->put('api/articles/{id}', $answer);
    }

    private function send(string $method, string $json): TestResponse {
      $uri = $method === 'POST' ? 'api/articles' : 'api/articles/7';

      return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $json);
    }

    /**
     * Comprueba que la respuesta es el `403` de un `id` del cliente que el servidor no acepta.
     * Checks that the response is the `403` of a client `id` the server does not accept.
     */
    private function assertClientIdRefused(TestResponse $response): void {
      $response->assertStatus(403);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCount(1, $response->json('errors'));
      $this->assertSame('403', $response->json('errors.0.status'));
      $this->assertSame('mundoprogra.json-api.request.client_id_not_allowed', $response->json('errors.0.code'));
      $this->assertSame($this->english('request.client_id_not_allowed.title'), $response->json('errors.0.title'));
      $this->assertSame($this->english('request.client_id_not_allowed.detail'), $response->json('errors.0.detail'));
      $this->assertSame('/data/id', $response->json('errors.0.source.pointer'));
      $this->assertArrayNotHasKey('parameter', $response->json('errors.0.source'));
    }

    /**
     * Comprueba que la respuesta es un `400` de `request.bad_document` con exactamente esos punteros, en ese orden.
     * Checks that the response is a `400` of `request.bad_document` with exactly those pointers, in that order.
     *
     * @param  list<string>  $pointers
     */
    private function assertBadDocument(TestResponse $response, array $pointers, string $reason): void {
      $response->assertStatus(400);
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame($pointers, array_column(array_column($response->json('errors'), 'source'), 'pointer'));
      $this->assertSame('mundoprogra.json-api.request.bad_document', $response->json('errors.0.code'));
      $this->assertSame($this->english("request.bad_document.reasons.$reason"), $response->json('errors.0.detail'));
    }

    // ---------------------------------------------------------------------------------------------
    // POST: el id lo pone el servidor / POST: the server sets the id
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_post_without_an_id_goes_through(): void {
      $response = $this->send('POST', '{"data":{"type":"articles","attributes":{"title":"T"}}}');

      $response->assertOk();
      $this->assertNull($response->json('id'));
    }

    #[Test]
    public function a_post_with_an_id_is_a_403_by_default(): void {
      $this->assertClientIdRefused($this->send('POST', '{"data":{"type":"articles","id":"abc-1"}}'));
    }

    #[Test]
    public function client_ids_are_not_allowed_by_default(): void {
      $this->assertFalse(config('json-api.allow_client_ids'));
    }

    #[Test]
    public function a_post_with_an_id_goes_through_when_client_ids_are_allowed(): void {
      config()->set('json-api.allow_client_ids', true);

      $response = $this->send('POST', '{"data":{"type":"articles","id":"abc-1"}}');

      $response->assertOk();
      $this->assertSame('abc-1', $response->json('id'));
    }

    #[Test]
    public function the_403_text_follows_the_application_language(): void {
      $this->app->setLocale('es');

      $response = $this->send('POST', '{"data":{"type":"articles","id":"abc-1"}}');

      $response->assertStatus(403);
      $this->assertSame($this->spanish('request.client_id_not_allowed.title'), $response->json('errors.0.title'));
      $this->assertSame($this->spanish('request.client_id_not_allowed.detail'), $response->json('errors.0.detail'));
    }

    #[Test]
    public function the_403_never_repeats_the_id_the_client_sent(): void {
      $response = $this->send('POST', '{"data":{"type":"articles","id":"supersecretid"}}');

      $response->assertStatus(403);
      $this->assertStringNotContainsString('supersecret', (string) $response->getContent());
    }

    #[Test]
    public function the_403_is_not_sent_when_the_document_also_has_shape_problems(): void {
      // Un documento mal formado es un `400` y no se revisa el `id` del cliente: primero hay que poder entender el documento.
      // A malformed document is a `400` and the client `id` is not checked: the document has to be understood first.
      $this->assertBadDocument($this->send('POST', '{"data":{"type":"","id":"abc-1"}}'), ['/data/type'], 'not_a_string');
      $this->assertBadDocument($this->send('POST', '{"data":{"type":"articles","id":"abc-1","attributes":[]}}'), ['/data/attributes'], 'not_an_object');
    }

    #[Test]
    public function an_id_that_is_not_a_text_in_a_post_is_a_400_and_not_a_403(): void {
      $this->assertBadDocument($this->send('POST', '{"data":{"type":"articles","id":5}}'), ['/data/id'], 'not_a_string');
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH y PUT: el id es obligatorio / PATCH and PUT: the id is required
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function updateMethods(): array {
      return ['PATCH' => ['PATCH'], 'PUT' => ['PUT']];
    }

    #[Test]
    #[DataProvider('updateMethods')]
    public function an_update_with_an_id_goes_through(string $method): void {
      $response = $this->send($method, '{"data":{"type":"articles","id":"7"}}');

      $response->assertOk();
      $this->assertSame('7', $response->json('id'));
    }

    #[Test]
    #[DataProvider('updateMethods')]
    public function an_update_without_an_id_is_a_400_pointing_at_it(string $method): void {
      $this->assertBadDocument($this->send($method, '{"data":{"type":"articles","attributes":{"title":"T"}}}'), ['/data/id'], 'member_required');
    }

    #[Test]
    #[DataProvider('updateMethods')]
    public function an_update_does_not_depend_on_allow_client_ids(string $method): void {
      foreach ([true, false] as $allowed) {
        config()->set('json-api.allow_client_ids', $allowed);

        $this->send($method, '{"data":{"type":"articles","id":"7"}}')->assertOk();
      }
    }

    #[Test]
    #[DataProvider('updateMethods')]
    public function an_update_with_an_empty_or_non_text_id_is_a_400(string $method): void {
      $this->assertBadDocument($this->send($method, '{"data":{"type":"articles","id":""}}'), ['/data/id'], 'not_a_string');
      $this->assertBadDocument($this->send($method, '{"data":{"type":"articles","id":null}}'), ['/data/id'], 'not_a_string');
      $this->assertBadDocument($this->send($method, '{"data":{"type":"articles","id":7}}'), ['/data/id'], 'not_a_string');
    }

    #[Test]
    public function the_missing_id_comes_out_with_the_other_problems_in_the_usual_order(): void {
      $response = $this->send('PATCH', '{"data":{"type":5,"attributes":[],"relationships":[]}}');

      $response->assertStatus(400);
      $this->assertSame(
        ['/data/type', '/data/id', '/data/attributes', '/data/relationships'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer'),
      );
    }

    #[Test]
    public function the_missing_id_text_is_the_same_as_any_required_member(): void {
      $response = $this->send('PATCH', '{"data":{"type":"articles"}}');

      $this->assertSame($this->english('request.bad_document.reasons.member_required'), $response->json('errors.0.detail'));
    }
  }
