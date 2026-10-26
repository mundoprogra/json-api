<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\ApiUser;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Los errores de la API de referencia, con todo el paquete encendido: un escenario por cada tipo de error (`406`, `415`, `400` por la consulta,
   * `400` por el documento, `403`, `401`, `409`, `422`, `404` y `405`), cada uno con su `code` y su `source` exactos y validado contra el schema oficial;
   * y el ORDEN en que se comprueban, fijado con peticiones que fallan por dos motivos a la vez. El orden es el de la pila: primero los middleware
   * globales del paquete (`Content-Type` → `Accept` → consulta → documento), después el enrutador (`405`/`404` de ruta), el middleware `auth` (`401`) y por
   * último el `FormRequest` (`409` → `422`) y el cuerpo del controlador (`404` de recurso). Al final, una sola petición con TODOS los problemas se
   * arregla de uno en uno y va dando cada error en ese orden.
   *
   * The reference API errors, with the whole package on: one scenario for each kind of error (`406`, `415`, `400` for the query, `400` for the
   * document, `403`, `401`, `409`, `422`, `404` and `405`), each with its exact `code` and `source` and validated against the official schema; and the
   * ORDER they are checked in, pinned with requests that fail for two reasons at once. The order is that of the stack: first the package global
   * middleware (`Content-Type` → `Accept` → query → document), then the router (route `405`/`404`), the `auth` middleware (`401`) and lastly the
   * `FormRequest` (`409` → `422`) and the controller body (resource `404`). At the end, a single request with ALL the problems is fixed one at a time
   * and gives each error in that order.
   */
  class ReferenceApiErrorsTest extends ReferenceApiTestCase {
    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const CODE = 'mundoprogra.json-api.';

    private function signIn(): void {
      $this->actingAs(new ApiUser());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function article(array $attributes = ['title' => 'Valid'], string $type = 'articles', ?string $id = null): array {
      return ['data' => array_filter(['type' => $type, 'id' => $id, 'attributes' => $attributes], fn($value) => $value !== null)];
    }

    /**
     * Comprueba el primer error de una respuesta: su estado, su `code`, y los miembros de `source` que se pidan (`parameter`, `pointer` o `header`).
     * Checks the first error of a response: its status, its `code`, and the `source` members asked for (`parameter`, `pointer` or `header`).
     *
     * @param  array<string, string>  $source
     */
    private function assertError(TestResponse $response, int $status, string $code, array $source = []): void {
      $response->assertStatus($status);
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame((string) $status, $response->json('errors.0.status'));
      $this->assertSame(self::CODE . $code, $response->json('errors.0.code'));

      foreach ($source as $member => $expected) {
        $this->assertSame($expected, $response->json("errors.0.source.$member"), "source.$member");
      }
    }

    // ---------------------------------------------------------------------------------------------
    // 406 y 415: la negociación de contenido / 406 and 415: content negotiation
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_accept_that_does_not_allow_json_api_is_a_406_naming_the_header(): void {
      foreach (['application/json', 'text/html', 'application/vnd.api+json;charset=utf-8'] as $accept) {
        $this->assertError($this->jsonApi('GET', 'api/articles', null, ['HTTP_ACCEPT' => $accept]), 406, 'request.not_acceptable', ['header' => 'Accept']);
      }
    }

    #[Test]
    public function an_accept_of_anything_or_none_is_accepted(): void {
      $this->jsonApi('GET', 'api/articles?page[size]=1', null, ['HTTP_ACCEPT' => '*/*'])->assertOk();
      $this->jsonApi('GET', 'api/articles?page[size]=1', null, ['HTTP_ACCEPT' => ''])->assertOk();
    }

    #[Test]
    public function a_content_type_that_is_not_json_api_is_a_415_naming_the_header(): void {
      $this->signIn();

      foreach (['application/json', 'application/vnd.api+json; charset=utf-8', ''] as $contentType) {
        $this->assertError(
          $this->jsonApi('POST', 'api/articles', $this->article(), ['CONTENT_TYPE' => $contentType]),
          415,
          'request.unsupported_media_type',
          ['header' => 'Content-Type']
        );
      }
    }

    // ---------------------------------------------------------------------------------------------
    // 400: la consulta / 400: the query
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_query_the_endpoint_does_not_allow_is_a_400_naming_the_parameter(): void {
      $this->assertError($this->jsonApi('GET', 'api/articles?include=nope'), 400, 'request.bad_query', ['parameter' => 'include']);
      $this->assertError($this->jsonApi('GET', 'api/articles?sort=nope'), 400, 'request.bad_query', ['parameter' => 'sort']);
      $this->assertError($this->jsonApi('GET', 'api/articles?filter[nope]=1'), 400, 'request.bad_query', ['parameter' => 'filter[nope]']);
    }

    #[Test]
    public function a_query_that_is_badly_formed_is_a_400_naming_the_parameter(): void {
      $this->assertError($this->jsonApi('GET', 'api/articles?include=a,,b'), 400, 'request.bad_query', ['parameter' => 'include']);
      $this->assertError($this->jsonApi('GET', 'api/articles?page[size]=0'), 400, 'request.bad_query', ['parameter' => 'page[size]']);
      $this->assertError($this->jsonApi('GET', 'api/articles?fields=title'), 400, 'request.bad_query', ['parameter' => 'fields']);
    }

    #[Test]
    public function the_syntax_of_the_query_is_judged_before_what_the_endpoint_allows(): void {
      // `page[size]=0` lo ve el middleware global; `sort=nope` solo lo ve el controlador, después. Gana el primero y sale solo.
      // `page[size]=0` is seen by the global middleware; `sort=nope` is only seen by the controller, later. The first one wins and comes out alone.
      $response = $this->jsonApi('GET', 'api/articles?sort=nope&page[size]=0');

      $this->assertError($response, 400, 'request.bad_query', ['parameter' => 'page[size]']);
      $this->assertCount(1, $response->json('errors'));
    }

    // ---------------------------------------------------------------------------------------------
    // 400 y 403: el documento / 400 and 403: the document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_body_that_is_not_json_is_a_400_at_the_root(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('POST', 'api/articles', '{'), 400, 'request.bad_document', ['pointer' => '']);
    }

    #[Test]
    public function a_document_with_the_wrong_shape_is_a_400_with_the_exact_pointer(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('POST', 'api/articles', '{}'), 400, 'request.bad_document', ['pointer' => '']);
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(['id' => 'x'])), 400, 'request.bad_document', ['pointer' => '/data/attributes/id']);
      $this->assertError($this->jsonApi('PATCH', 'api/articles/1', $this->article()), 400, 'request.bad_document', ['pointer' => '/data/id']);
      $this->assertError(
        $this->jsonApi('POST', 'api/articles', ['data' => ['type' => 'articles', 'relationships' => ['author' => ['data' => 'x']]]]),
        400,
        'request.bad_document',
        ['pointer' => '/data/relationships/author/data']
      );
    }

    #[Test]
    public function an_id_chosen_by_the_client_is_a_403(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(id: 'abc')), 403, 'request.client_id_not_allowed', ['pointer' => '/data/id']);
    }

    // ---------------------------------------------------------------------------------------------
    // 401, 409 y 422 / 401, 409 and 422
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_valid_write_without_authentication_is_a_401(): void {
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article()), 401, 'auth.unauthenticated');
    }

    #[Test]
    public function a_type_or_id_that_is_not_the_endpoints_is_a_409_pointing_at_it(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('PATCH', 'api/articles/1', $this->article(type: 'people', id: '1')), 409, 'resource.conflict', ['pointer' => '/data/type']);
      $this->assertError($this->jsonApi('PATCH', 'api/articles/1', $this->article(id: '2')), 409, 'resource.conflict', ['pointer' => '/data/id']);
    }

    #[Test]
    public function a_failed_rule_is_a_422_with_the_exact_pointer_and_one_code_per_rule(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(['title' => ''])), 422, 'validation.required', ['pointer' => '/data/attributes/title']);
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(['title' => 'ok', 'status' => 'archived'])), 422, 'validation.in', ['pointer' => '/data/attributes/status']);
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(['title' => str_repeat('x', 51)])), 422, 'validation.max', ['pointer' => '/data/attributes/title']);
    }

    // ---------------------------------------------------------------------------------------------
    // 404 y 405 / 404 and 405
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_resource_that_does_not_exist_and_a_route_that_does_not_exist_are_two_different_404s(): void {
      $resource = $this->jsonApi('GET', 'api/articles/999');
      $route = $this->jsonApi('GET', 'api/nope');

      $this->assertError($resource, 404, 'resource.not_found');
      $this->assertError($route, 404, 'route.not_found');
      $this->assertArrayNotHasKey('source', $resource->json('errors.0'));
    }

    #[Test]
    public function a_method_the_route_does_not_have_is_a_405_with_the_allowed_methods(): void {
      $response = $this->jsonApi('PUT', 'api/articles/1', $this->article());

      $this->assertError($response, 405, 'request.method_not_allowed');

      $allowed = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
      sort($allowed);

      $this->assertSame(['DELETE', 'GET', 'HEAD', 'PATCH'], $allowed);
    }

    // ---------------------------------------------------------------------------------------------
    // El orden de las comprobaciones: dos motivos a la vez / The order of the checks: two reasons at once
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_content_type_is_judged_before_the_accept(): void {
      $this->signIn();

      $response = $this->jsonApi('POST', 'api/articles', $this->article(), ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html']);

      $this->assertError($response, 415, 'request.unsupported_media_type', ['header' => 'Content-Type']);
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function the_negotiation_comes_before_the_query(): void {
      $this->assertError($this->jsonApi('GET', 'api/articles?page[size]=0', null, ['HTTP_ACCEPT' => 'text/html']), 406, 'request.not_acceptable');
    }

    #[Test]
    public function the_query_comes_before_the_document(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('POST', 'api/articles?page[size]=0', '{'), 400, 'request.bad_query', ['parameter' => 'page[size]']);
    }

    #[Test]
    public function the_document_comes_before_the_authentication(): void {
      // Sin sesión, un documento mal formado es un 400 y un `id` del cliente es un 403: ambos salen en la pila global, antes del middleware `auth`.
      // Without a session, a malformed document is a 400 and a client `id` is a 403: both come out in the global stack, before the `auth` middleware.
      $this->assertError($this->jsonApi('POST', 'api/articles', '{'), 400, 'request.bad_document', ['pointer' => '']);
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(id: 'abc')), 403, 'request.client_id_not_allowed');
    }

    #[Test]
    public function the_authentication_comes_before_the_conflict_the_validation_and_the_missing_resource(): void {
      $this->assertError($this->jsonApi('PATCH', 'api/articles/1', $this->article(type: 'people', id: '1')), 401, 'auth.unauthenticated');
      $this->assertError($this->jsonApi('POST', 'api/articles', $this->article(['title' => ''])), 401, 'auth.unauthenticated');
      $this->assertError($this->jsonApi('DELETE', 'api/articles/999'), 401, 'auth.unauthenticated');
    }

    #[Test]
    public function the_conflict_comes_before_the_validation(): void {
      $this->signIn();

      $response = $this->jsonApi('PATCH', 'api/articles/1', $this->article(['title' => str_repeat('x', 51)], 'people', '1'));

      $this->assertError($response, 409, 'resource.conflict', ['pointer' => '/data/type']);
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function the_shape_of_the_document_comes_before_the_conflict(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('PATCH', 'api/articles/1', ['data' => ['type' => 'people', 'id' => '1', 'attributes' => []]]), 400, 'request.bad_document', ['pointer' => '/data/attributes']);
    }

    #[Test]
    public function the_shape_of_the_document_comes_before_the_client_id(): void {
      $this->signIn();

      $response = $this->jsonApi('POST', 'api/articles', $this->article(['type' => 'x'], id: 'abc'));

      $this->assertError($response, 400, 'request.bad_document', ['pointer' => '/data/attributes/type']);
      $this->assertCount(1, $response->json('errors'));
    }

    #[Test]
    public function the_validation_comes_before_the_missing_resource_because_the_form_request_resolves_first(): void {
      $this->signIn();

      $this->assertError($this->jsonApi('PATCH', 'api/articles/999', $this->article(['title' => str_repeat('x', 51)], id: '999')), 422, 'validation.max');
      $this->assertError($this->jsonApi('PATCH', 'api/articles/999', $this->article(['title' => 'ok'], id: '999')), 404, 'resource.not_found');
    }

    #[Test]
    public function the_global_checks_come_before_the_router_so_they_also_cover_routes_that_do_not_exist(): void {
      $this->assertError($this->jsonApi('GET', 'api/nope', null, ['HTTP_ACCEPT' => 'text/html']), 406, 'request.not_acceptable');
      $this->assertError($this->jsonApi('GET', 'api/nope?include=a,,b'), 400, 'request.bad_query', ['parameter' => 'include']);
    }

    #[Test]
    public function a_method_that_the_route_does_not_have_is_a_405_even_without_authentication(): void {
      $this->assertError($this->jsonApi('PUT', 'api/articles/1', $this->article()), 405, 'request.method_not_allowed');
    }

    // ---------------------------------------------------------------------------------------------
    // Todos los problemas en una petición / Every problem in a single request
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_request_with_every_problem_gives_each_error_in_order_as_they_are_fixed(): void {
      // Una creación con diez problemas: Content-Type, Accept, consulta, JSON roto, forma, `id` del cliente, sin sesión, tipo, regla y, al final, éxito.
      // A creation with ten problems: Content-Type, Accept, query, broken JSON, shape, client `id`, no session, type, rule and, in the end, success.
      $bad = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
      $uri = 'api/articles?page[size]=0';

      $this->assertError($this->jsonApi('POST', $uri, '{', $bad), 415, 'request.unsupported_media_type', ['header' => 'Content-Type']);

      $this->assertError($this->jsonApi('POST', $uri, '{', ['HTTP_ACCEPT' => 'application/json']), 406, 'request.not_acceptable', ['header' => 'Accept']);

      $this->assertError($this->jsonApi('POST', $uri, '{'), 400, 'request.bad_query', ['parameter' => 'page[size]']);

      $uri = 'api/articles';

      $this->assertError($this->jsonApi('POST', $uri, '{'), 400, 'request.bad_document', ['pointer' => '']);

      $this->assertError(
        $this->jsonApi('POST', $uri, $this->article(['type' => 'x'], 'people', 'abc')),
        400,
        'request.bad_document',
        ['pointer' => '/data/attributes/type']
      );

      $this->assertError($this->jsonApi('POST', $uri, $this->article(['title' => ''], 'people', 'abc')), 403, 'request.client_id_not_allowed', ['pointer' => '/data/id']);

      $this->assertError($this->jsonApi('POST', $uri, $this->article(['title' => ''], 'people')), 401, 'auth.unauthenticated');

      $this->signIn();

      $this->assertError($this->jsonApi('POST', $uri, $this->article(['title' => ''], 'people')), 409, 'resource.conflict', ['pointer' => '/data/type']);

      $this->assertError($this->jsonApi('POST', $uri, $this->article(['title' => ''])), 422, 'validation.required', ['pointer' => '/data/attributes/title']);

      $this->jsonApi('POST', $uri, $this->article(['title' => 'Finally']))->assertCreated();
    }
  }
