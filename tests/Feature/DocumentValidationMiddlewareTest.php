<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Http\Kernel as HttpKernel;
  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Http\Middleware\ValidateDocument;
  use MundoProgra\JsonApi\Http\Middleware\ValidateQuery;
  use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;
  use MundoProgra\JsonApi\Http\Requests\RequestDocument;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\Support\ReadsPackageTexts;
  use MundoProgra\JsonApi\Tests\Support\ReadsTheGlobalMiddleware;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Todo `POST` o `PATCH` con un cuerpo JSON dentro del alcance del paquete tiene su documento validado antes de llegar al controlador: un
   * documento mal formado es un `400` (o el `403` de un `id` del cliente) aunque el endpoint no lo lea. Lo que se valida depende de la URL: en un
   * endpoint de relación (`/articles/1/relationships/tags`) el documento es un linkage y ahí también se valida un `DELETE`; en cualquier otro
   * es un recurso. `PUT` solo se valida con `allow_put`. No se validan `GET`, un cuerpo vacío ni un cuerpo que no es JSON. El resultado queda en
   * la petición y el controlador lo lee con `RequestDocument::of()` o `RelationshipDocument::of()`. Corre justo después de la validación de la consulta.
   *
   * Every `POST` or `PATCH` with a JSON body inside the package scope has its document validated before it reaches the controller: a malformed
   * document is a `400` (or the `403` of a client `id`) even if the endpoint does not read it. What is validated depends on the URL: at a relationship
   * endpoint (`/articles/1/relationships/tags`) the document is a linkage and a `DELETE` is validated there too; anywhere else it is a resource. `PUT` is
   * only validated with `allow_put`. `GET`, an empty body and a body that is not JSON are not validated. The result is kept on the request and the
   * controller reads it with `RequestDocument::of()` or `RelationshipDocument::of()`. It runs right after the query validation.
   */
  class DocumentValidationMiddlewareTest extends TestCase {
    use AssertsJsonApi;
    use ReadsPackageTexts;
    use ReadsTheGlobalMiddleware;

    protected bool $validatesDocuments = true;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const CREATE = '{"data":{"type":"articles","attributes":{"title":"T"}}}';

    private const UPDATE = '{"data":{"type":"articles","id":"7","attributes":{"title":"T"}}}';

    private const LINKAGE = '{"data":[{"type":"tags","id":"1"}]}';

    private const GARBAGE = '{';

    // Cuántas veces llegó una petición al controlador, y cuántas llevaba antes de la última petición enviada.
    // How many times a request reached the controller, and how many there were before the last request sent.
    private static int $reached = 0;

    private static int $reachedBefore = 0;

    protected function setUp(): void {
      parent::setUp();

      self::$reached = 0;
      self::$reachedBefore = 0;
      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $ok = function () {
        self::$reached++;

        return response()->json(['ok' => true]);
      };

      foreach (['api', 'web'] as $area) {
        $router->match(['GET', 'POST', 'DELETE'], "$area/articles", $ok);
        $router->match(['GET', 'PATCH', 'PUT', 'DELETE'], "$area/articles/{id}", $ok);
        $router->match(['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], "$area/articles/{id}/relationships/tags", $ok);
      }

      $router->post('api/health', $ok);
      $router->post('web/validated', $ok)->middleware('json-api.document');
      $router->match(['POST', 'DELETE'], 'web/validated/{id}/relationships/tags', $ok)->middleware('json-api.document');
      $router->post('web/forced-relationship', $ok)->middleware('json-api.document:relationship');
      $router->patch('api/forced-resource/{id}/relationships/tags', $ok)->middleware('json-api.document:resource');
      $router->post('web/group', $ok)->middleware('json-api');
      $router->post('api/auth', fn() => 'secret')->middleware(AuthenticateLikeTheSkeleton::class);

      $readResource = fn(Request $request) => response()->json([
        'type' => RequestDocument::of($request)->type(),
        'same' => RequestDocument::of($request) === RequestDocument::of($request),
      ]);
      $router->post('api/read', $readResource);
      $router->post('web/read', $readResource);
      $router->patch('api/read-linkage/{id}/relationships/tags', fn(Request $request) => response()->json([
        'data' => RelationshipDocument::of($request)->data(),
        'same' => RelationshipDocument::of($request) === RelationshipDocument::of($request),
      ]));
    }

    /**
     * @param  array<string, string>  $server
     */
    private function send(string $method, string $uri, string $body, array $server = []): TestResponse {
      self::$reachedBefore = self::$reached;

      return $this->call($method, $uri, [], [], [], $server + ['CONTENT_TYPE' => self::MEDIA_TYPE, 'HTTP_ACCEPT' => self::MEDIA_TYPE], $body);
    }

    /**
     * @param  list<string>  $pointers
     */
    private function assertRejected(TestResponse $response, int $status, array $pointers): void {
      $response->assertStatus($status);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertSame($pointers, array_column(array_column($response->json('errors'), 'source'), 'pointer'));
      $this->assertSame(self::$reachedBefore, self::$reached, 'The controller must not run.');
    }

    // ---------------------------------------------------------------------------------------------
    // Dentro del alcance, un recurso / Inside the scope, a resource
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_malformed_body_is_a_400_even_if_the_endpoint_never_reads_it(): void {
      $response = $this->send('POST', 'api/articles', self::GARBAGE);

      $this->assertRejected($response, 400, ['']);
      $this->assertSame('mundoprogra.json-api.request.bad_document', $response->json('errors.0.code'));
    }

    #[Test]
    public function a_badly_shaped_document_is_a_400_with_every_problem_together(): void {
      $this->assertRejected($this->send('POST', 'api/articles', '{"data":{"type":5,"attributes":[]}}'), 400, ['/data/type', '/data/attributes']);
    }

    #[Test]
    public function a_valid_create_and_a_valid_update_go_through(): void {
      $this->send('POST', 'api/articles', self::CREATE)->assertOk();
      $this->send('PATCH', 'api/articles/7', self::UPDATE)->assertOk();

      $this->assertSame(2, self::$reached);
    }

    #[Test]
    public function the_id_rules_apply_before_the_controller(): void {
      $this->assertRejected($this->send('POST', 'api/articles', '{"data":{"type":"articles","id":"abc"}}'), 403, ['/data/id']);
      $this->assertRejected($this->send('PATCH', 'api/articles/7', self::CREATE), 400, ['/data/id']);
    }

    #[Test]
    public function the_rejection_comes_before_the_app_middleware(): void {
      // Sin esperar a `auth`: un documento mal formado es un 400, no un 401.
      // Without waiting for `auth`: a malformed document is a 400, not a 401.
      $this->assertSame(400, $this->send('POST', 'api/auth', self::GARBAGE)->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------
    // Qué se valida y qué no / What is validated and what is not
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_get_is_never_validated_even_with_a_body(): void {
      $this->send('GET', 'api/articles', self::GARBAGE)->assertOk();
      $this->send('GET', 'api/articles/7/relationships/tags', self::GARBAGE)->assertOk();
    }

    #[Test]
    public function a_delete_of_a_resource_is_not_validated(): void {
      $this->send('DELETE', 'api/articles/7', self::GARBAGE)->assertOk();
      $this->send('DELETE', 'api/articles', self::GARBAGE)->assertOk();
    }

    #[Test]
    public function an_empty_body_is_not_validated(): void {
      $this->send('POST', 'api/articles', '')->assertOk();
      $this->send('PATCH', 'api/articles/7', '')->assertOk();
      $this->send('POST', 'api/articles/7/relationships/tags', '')->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNotJson(): array {
      return [
        'a form' => ['application/x-www-form-urlencoded'],
        'plain text' => ['text/plain'],
        'multipart' => ['multipart/form-data; boundary=x'],
      ];
    }

    #[Test]
    #[DataProvider('bodiesThatAreNotJson')]
    public function a_body_that_is_not_json_by_its_content_type_is_not_validated(string $contentType): void {
      // Quien sube un archivo o manda un formulario no manda un documento JSON:API; la negociación de contenido ya decide si eso se acepta.
      // Whoever uploads a file or sends a form is not sending a JSON:API document; content negotiation already decides whether that is accepted.
      $this->send('POST', 'api/articles', 'a=1&b={', ['CONTENT_TYPE' => $contentType])->assertOk();
    }

    #[Test]
    public function a_plain_json_content_type_is_validated(): void {
      $this->assertRejected($this->send('POST', 'api/articles', self::GARBAGE, ['CONTENT_TYPE' => 'application/json']), 400, ['']);
    }

    #[Test]
    public function a_put_is_validated_only_when_the_app_uses_put(): void {
      $this->send('PUT', 'api/articles/7', self::GARBAGE)->assertOk();

      config()->set('json-api.allow_put', true);

      $this->assertRejected($this->send('PUT', 'api/articles/7', self::GARBAGE), 400, ['']);
      $this->send('PUT', 'api/articles/7', self::UPDATE)->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Un endpoint de relación / A relationship endpoint
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function relationshipMethods(): array {
      return ['POST' => ['POST'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[Test]
    #[DataProvider('relationshipMethods')]
    public function a_bad_linkage_is_a_400_at_a_relationship_endpoint(string $method): void {
      $response = $this->send($method, 'api/articles/7/relationships/tags', '{"data":"x"}');

      $this->assertRejected($response, 400, ['/data']);
      $this->assertSame($this->english('request.bad_document.reasons.invalid_linkage'), $response->json('errors.0.detail'));
    }

    #[Test]
    #[DataProvider('relationshipMethods')]
    public function a_valid_linkage_goes_through_at_a_relationship_endpoint(string $method): void {
      $this->send($method, 'api/articles/7/relationships/tags', self::LINKAGE)->assertOk();
    }

    #[Test]
    public function the_same_body_is_judged_by_the_kind_of_endpoint(): void {
      // Un recurso nuevo no lleva `id`; un identificador de relación sí.
      // A new resource has no `id`; a relationship identifier does.
      $body = '{"data":{"type":"tags"}}';

      $this->send('POST', 'api/articles', $body)->assertOk();
      $this->assertRejected($this->send('POST', 'api/articles/7/relationships/tags', $body), 400, ['/data/id']);
    }

    #[Test]
    public function a_delete_with_a_body_is_validated_only_at_a_relationship_endpoint(): void {
      $this->assertRejected($this->send('DELETE', 'api/articles/7/relationships/tags', self::GARBAGE), 400, ['']);
      $this->send('DELETE', 'api/articles/7/relationships/tags', '')->assertOk();
    }

    #[Test]
    public function a_put_at_a_relationship_endpoint_is_validated_only_when_the_app_uses_put(): void {
      $this->send('PUT', 'api/articles/7/relationships/tags', self::GARBAGE)->assertOk();

      config()->set('json-api.allow_put', true);

      $this->assertRejected($this->send('PUT', 'api/articles/7/relationships/tags', self::GARBAGE), 400, ['']);
    }

    // ---------------------------------------------------------------------------------------------
    // El resultado queda en la petición / The result is kept on the request
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_controller_reads_the_parsed_resource_document_once(): void {
      $response = $this->send('POST', 'api/read', self::CREATE);

      $response->assertOk();
      $this->assertSame(['type' => 'articles', 'same' => true], $response->json());
    }

    #[Test]
    public function the_controller_reads_the_parsed_linkage_once(): void {
      $response = $this->send('PATCH', 'api/read-linkage/7/relationships/tags', self::LINKAGE);

      $response->assertOk();
      $this->assertSame(['data' => [['type' => 'tags', 'id' => '1']], 'same' => true], $response->json());
    }

    #[Test]
    public function each_request_has_its_own_document(): void {
      $first = Request::create('/api/articles', 'POST', [], [], [], [], '{"data":{"type":"articles"}}');
      $second = Request::create('/api/articles', 'POST', [], [], [], [], '{"data":{"type":"people"}}');

      $this->assertSame('articles', RequestDocument::of($first)->type());
      $this->assertSame('people', RequestDocument::of($second)->type());
      $this->assertSame(RequestDocument::of($first), RequestDocument::of($first));
    }

    #[Test]
    public function a_resource_document_and_a_linkage_are_kept_apart_on_the_same_request(): void {
      $request = Request::create('/api/articles/7/relationships/tags', 'PATCH', [], [], [], [], '{"data":{"type":"tags","id":"1"}}');

      $this->assertSame('tags', RequestDocument::of($request)->type());
      $this->assertSame(['type' => 'tags', 'id' => '1'], RelationshipDocument::of($request)->data());
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance no se toca / Outside the scope it is left alone
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_prefix_the_document_is_not_validated(): void {
      $this->send('POST', 'web/articles', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
      $this->send('PATCH', 'web/articles/7', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
      $this->send('POST', 'web/articles/7/relationships/tags', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
    }

    #[Test]
    public function an_excepted_path_is_not_validated(): void {
      config()->set('json-api.except', ['api/health']);

      $this->send('POST', 'api/health', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
    }

    #[Test]
    public function the_controller_can_still_ask_for_it_outside_the_scope(): void {
      // Quien lo pide explícitamente recibe un JsonApiException, que siempre se responde en JSON:API.
      // Whoever asks for it explicitly gets a JsonApiException, which is always answered as JSON:API.
      $this->send('POST', 'web/read', self::CREATE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();

      $response = $this->send('POST', 'web/read', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html']);

      $response->assertStatus(400);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame('', $response->json('errors.0.source.pointer'));
    }

    // ---------------------------------------------------------------------------------------------
    // Una ruta suelta, con el alias y con el grupo / A single route, with the alias and with the group
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_route_outside_the_scope_can_ask_for_it_by_hand_with_the_alias(): void {
      $this->assertSame(ValidateDocument::class, $this->app->make('router')->getMiddleware()['json-api.document'] ?? null);

      $this->assertRejected($this->send('POST', 'web/validated', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html']), 400, ['']);
      $this->send('POST', 'web/validated', self::CREATE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
    }

    #[Test]
    public function the_alias_also_tells_a_relationship_endpoint_by_its_url(): void {
      $this->assertRejected($this->send('POST', 'web/validated/7/relationships/tags', '{"data":"x"}', ['HTTP_ACCEPT' => 'text/html']), 400, ['/data']);
      $this->send('DELETE', 'web/validated/7/relationships/tags', self::LINKAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
    }

    #[Test]
    public function the_relationship_parameter_forces_a_linkage_whatever_the_url_says(): void {
      $this->assertRejected($this->send('POST', 'web/forced-relationship', '{"data":{"type":"tags"}}', ['HTTP_ACCEPT' => 'text/html']), 400, ['/data/id']);
      $this->send('POST', 'web/forced-relationship', self::LINKAGE, ['HTTP_ACCEPT' => 'text/html'])->assertOk();
    }

    #[Test]
    public function the_resource_parameter_forces_a_resource_whatever_the_url_says(): void {
      // Este cuerpo es un identificador válido, pero como recurso trae un atributo llamado `id`.
      // This body is a valid identifier, but as a resource it carries an attribute called `id`.
      $body = '{"data":{"type":"tags","id":"1","attributes":{"id":5}}}';

      $this->send('PATCH', 'api/articles/7/relationships/tags', $body)->assertOk();
      $this->assertRejected($this->send('PATCH', 'api/forced-resource/7/relationships/tags', $body), 400, ['/data/attributes/id']);
    }

    #[Test]
    public function the_json_api_group_validates_the_document_too(): void {
      $this->assertRejected($this->send('POST', 'web/group', self::GARBAGE, ['HTTP_ACCEPT' => 'text/html']), 400, ['']);
    }

    // ---------------------------------------------------------------------------------------------
    // El registro / The registration
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_registered_right_after_the_query_validation_at_the_start_of_the_global_stack(): void {
      $global = $this->globalMiddleware();

      $this->assertSame(ApplyJsonApi::class . ':scope', $global[0] ?? null);
      $this->assertSame(NegotiateContent::class . ':scope', $global[1] ?? null);
      $this->assertSame(ValidateQuery::class . ':scope', $global[2] ?? null);
      $this->assertSame(ValidateDocument::class . ':scope', $global[3] ?? null);
    }

    #[Test]
    public function it_is_registered_only_once_however_many_times_the_kernel_is_resolved(): void {
      $this->app->make(HttpKernel::class);
      $this->app->make(HttpKernel::class);

      $validating = array_filter($this->globalMiddleware(), fn($middleware) => str_starts_with((string) $middleware, ValidateDocument::class));

      $this->assertCount(1, $validating);
    }
  }
