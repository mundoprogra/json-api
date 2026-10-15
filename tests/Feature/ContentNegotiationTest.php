<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Support\Arr;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El middleware `NegotiateContent` (alias `json-api.negotiate`) aplica las reglas de ContentNegotiator a la petición:
   * el `Content-Type` solo en peticiones con cuerpo (415) y el `Accept` en todas (406), con `source.header` en el error.
   * El `Accept` se lee del original que guardó `ApplyJsonApi` (el de la petición ya viene corregido), o de la cabecera si no se aplicó.
   * The `NegotiateContent` middleware (alias `json-api.negotiate`) applies the ContentNegotiator rules to the request:
   * the `Content-Type` only on requests with a body (415) and the `Accept` on all of them (406), with `source.header` on the error.
   * The `Accept` is read from the original that `ApplyJsonApi` kept (the request one is already fixed), or from the header if it was not applied.
   */
  class ContentNegotiationTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ok = fn() => response()->json(['ok' => true]);
      $methods = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

      // Bajo el prefijo (dentro del alcance), fuera de él, y fuera pero marcada a mano.
      // Under the prefix (inside the scope), outside it, and outside but marked by hand.
      $router->match($methods, 'api/negotiated', $ok)->middleware('json-api.negotiate');
      $router->match($methods, 'web/negotiated', $ok)->middleware('json-api.negotiate');
      $router->match($methods, 'web/marked-negotiated', $ok)->middleware(['json-api', 'json-api.negotiate']);
    }

    /**
     * Una petición con cuerpo y las cabeceras indicadas. Un Content-Type null no se envía; '' simula que falta.
     * A request with a body and the given headers. A null Content-Type is not sent; '' simulates it being absent.
     */
    private function send(string $method, string $uri = 'api/negotiated', ?string $contentType = self::MEDIA_TYPE, string $accept = self::MEDIA_TYPE, string $body = '{"data":{"type":"articles"}}'): TestResponse {
      $server = ['HTTP_ACCEPT' => $accept];

      if ($contentType !== null) {
        $server['CONTENT_TYPE'] = $contentType;
      }

      return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function english(string $key): string {
      return Arr::get(require __DIR__ . '/../../lang/en/errors.php', $key);
    }

    private function assertRejected(TestResponse $response, int $status, string $header, string $reason): void {
      $response->assertStatus($status);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame($header, $response->json('errors.0.source.header'));
      $this->assertSame((string) $status, $response->json('errors.0.status'));
      $this->assertSame(
        $this->english(($status === 415 ? 'request.unsupported_media_type' : 'request.not_acceptable') . ".reasons.$reason"),
        $response->json('errors.0.detail'),
      );
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Content-Type
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function verbsWithBody(): array {
      return ['POST' => ['POST'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[Test]
    #[DataProvider('verbsWithBody')]
    public function a_body_with_the_json_api_media_type_is_accepted(string $method): void {
      $this->send($method)->assertOk();
    }

    #[Test]
    public function the_media_type_may_carry_a_profile(): void {
      $this->send('POST', contentType: self::MEDIA_TYPE . '; profile="https://example.com/profiles/audit"')->assertOk();
    }

    #[Test]
    #[DataProvider('verbsWithBody')]
    public function plain_json_is_a_415_in_strict_mode(string $method): void {
      $this->assertRejected($this->send($method, contentType: 'application/json'), 415, 'Content-Type', 'not_json_api');
    }

    #[Test]
    public function plain_json_is_accepted_when_headers_are_not_strict(): void {
      config()->set('json-api.strict_headers', false);

      $this->send('POST', contentType: 'application/json')->assertOk();
    }

    #[Test]
    public function another_media_type_is_a_415_even_when_headers_are_not_strict(): void {
      config()->set('json-api.strict_headers', false);

      $this->assertRejected($this->send('POST', contentType: 'text/plain'), 415, 'Content-Type', 'not_json_api');
    }

    #[Test]
    public function a_parameter_other_than_ext_or_profile_is_a_415(): void {
      $this->assertRejected(
        $this->send('POST', contentType: self::MEDIA_TYPE . '; charset=utf-8'),
        415,
        'Content-Type',
        'unsupported_parameter',
      );
    }

    #[Test]
    public function an_extension_is_a_415_because_none_is_supported(): void {
      $this->assertRejected(
        $this->send('POST', contentType: self::MEDIA_TYPE . '; ext="https://jsonapi.org/ext/atomic"'),
        415,
        'Content-Type',
        'unsupported_extension',
      );
    }

    #[Test]
    public function a_body_without_content_type_is_a_415_in_strict_mode_and_accepted_otherwise(): void {
      $this->assertRejected($this->send('POST', contentType: ''), 415, 'Content-Type', 'missing');

      config()->set('json-api.strict_headers', false);

      $this->send('POST', contentType: '')->assertOk();
    }

    #[Test]
    public function an_unreadable_content_type_is_a_415(): void {
      $this->assertRejected($this->send('POST', contentType: ';;;'), 415, 'Content-Type', 'malformed');
    }

    #[Test]
    public function a_request_without_a_body_does_not_need_a_content_type(): void {
      $this->send('GET', contentType: null, body: '')->assertOk();
      $this->send('GET', contentType: 'text/plain', body: '')->assertOk();
      $this->send('POST', contentType: 'application/json', body: '')->assertOk();
      $this->send('DELETE', contentType: null, body: '')->assertOk();
    }

    #[Test]
    public function put_is_left_alone_unless_it_is_allowed(): void {
      $this->send('PUT', contentType: 'application/json')->assertOk();

      config()->set('json-api.allow_put', true);

      $this->assertRejected($this->send('PUT', contentType: 'application/json'), 415, 'Content-Type', 'not_json_api');
      $this->send('PUT')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Accept
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_accept_with_the_json_api_media_type_is_accepted(): void {
      $this->send('GET', accept: self::MEDIA_TYPE, contentType: null, body: '')->assertOk();
      $this->send('GET', accept: self::MEDIA_TYPE . '; profile="https://example.com/p"', contentType: null, body: '')->assertOk();
    }

    #[Test]
    public function an_accept_that_only_offers_the_media_type_with_foreign_parameters_is_a_406(): void {
      $this->assertRejected(
        $this->send('GET', accept: self::MEDIA_TYPE . '; charset=utf-8', contentType: null, body: ''),
        406,
        'Accept',
        'unsupported_parameter',
      );
    }

    #[Test]
    public function one_acceptable_appearance_is_enough(): void {
      $this->send('GET', accept: self::MEDIA_TYPE . '; charset=utf-8, ' . self::MEDIA_TYPE, contentType: null, body: '')->assertOk();
    }

    #[Test]
    public function an_accept_that_does_not_allow_the_media_type_is_a_406_even_though_the_app_sees_it_fixed(): void {
      // Dentro del alcance ApplyJsonApi cambia el Accept de la petición; la negociación tiene que juzgar el que mandó el cliente.
      // Inside the scope ApplyJsonApi changes the request Accept; the negotiation has to judge the one the client sent.
      $this->assertRejected($this->send('GET', accept: 'text/html', contentType: null, body: ''), 406, 'Accept', 'not_json_api');
    }

    #[Test]
    public function a_refused_media_type_is_a_406(): void {
      $this->assertRejected(
        $this->send('GET', accept: self::MEDIA_TYPE . ';q=0', contentType: null, body: ''),
        406,
        'Accept',
        'refused',
      );
    }

    #[Test]
    public function wildcards_and_a_missing_accept_are_accepted(): void {
      foreach (['*/*', 'application/*', ''] as $accept) {
        $this->send('GET', accept: $accept, contentType: null, body: '')->assertOk();
      }
    }

    #[Test]
    public function plain_json_in_accept_is_a_406_in_strict_mode_and_accepted_otherwise(): void {
      $this->assertRejected($this->send('GET', accept: 'application/json', contentType: null, body: ''), 406, 'Accept', 'not_json_api');

      config()->set('json-api.strict_headers', false);

      $this->send('GET', accept: 'application/json', contentType: null, body: '')->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Los dos a la vez y fuera del alcance / Both at once and outside the scope
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function when_both_headers_are_wrong_the_content_type_is_reported_first(): void {
      $response = $this->send('POST', contentType: 'application/json', accept: 'text/html');

      $this->assertRejected($response, 415, 'Content-Type', 'not_json_api');
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function by_hand_outside_the_scope_the_header_itself_is_judged(): void {
      $this->assertRejected($this->send('GET', 'web/negotiated', null, 'text/html', ''), 406, 'Accept', 'not_json_api');
      $this->send('GET', 'web/negotiated', null, self::MEDIA_TYPE, '')->assertOk();
    }

    #[Test]
    public function by_hand_with_the_json_api_middleware_the_original_accept_is_judged(): void {
      $this->assertRejected($this->send('GET', 'web/marked-negotiated', null, 'text/html', ''), 406, 'Accept', 'not_json_api');
    }

    #[Test]
    public function the_middleware_has_the_negotiate_alias(): void {
      $this->assertSame(NegotiateContent::class, $this->app->make('router')->getMiddleware()['json-api.negotiate'] ?? null);
    }
  }
