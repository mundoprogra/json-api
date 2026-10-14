<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\ModelNotFoundException;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\Validator;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;

  /**
   * Una API de ejemplo (artículos) con los casos que se encuentra una app real: lecturas y creaciones correctas, y los distintos errores.
   * An example API (articles) with the cases a real app runs into: successful reads and creations, and the different errors.
   */
  class ErrorsEndToEndTest extends TestCase {
    use AssertsJsonApi;

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);
    }

    protected function defineRoutes($router): void {
      $router->get('articles', fn() => ['data' => [['type' => 'articles', 'id' => '1']]]);

      $router->get('articles/{id}', function (string $id) {
        if ($id === '999') {
          throw (new ModelNotFoundException())->setModel('App\\Models\\Article', [$id]);
        }

        return ['data' => ['type' => 'articles', 'id' => $id]];
      });

      $router->post('articles', function (Request $request) {
        Validator::make($request->input('data.attributes', []), ['title' => 'required'], ['required' => ':attribute is required'])->validate();

        return response()->json(['data' => ['type' => 'articles', 'id' => '1']], 201, ['Location' => 'https://example.com/articles/1']);
      });

      $router->get('articles-paged', fn(Request $request) => $request->validate(['page.size' => 'integer|min:1'], ['min' => 'Must be at least 1']));
      $router->get('me', fn() => 'private')->middleware('auth');
      $router->get('crash', fn() => throw new RuntimeException('database exploded'));
    }

    #[Test]
    public function a_successful_read_is_left_exactly_as_the_app_wrote_it(): void {
      $response = $this->get('articles', ['Accept' => 'application/vnd.api+json']);

      $response->assertStatus(200);
      $this->assertSame(['data' => [['type' => 'articles', 'id' => '1']]], $response->json());
      $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_successful_creation_keeps_its_status_and_headers(): void {
      $response = $this->postJson('articles', ['data' => ['type' => 'articles', 'attributes' => ['title' => 'Hello']]]);

      $response->assertStatus(201);
      $this->assertSame('https://example.com/articles/1', $response->headers->get('Location'));
    }

    #[Test]
    public function an_existing_resource_is_read_normally_and_a_missing_one_is_a_json_api_404(): void {
      $this->get('articles/1')->assertStatus(200);

      $response = $this->get('articles/999');

      $response->assertStatus(404);
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
    }

    #[Test]
    public function an_invalid_creation_points_to_the_attribute(): void {
      $response = $this->postJson('articles', ['data' => ['type' => 'articles', 'attributes' => []]]);

      $response->assertStatus(422);
      $this->assertSame('/data/attributes/title', $response->json('errors.0.source.pointer'));
      $this->assertSame('title is required', $response->json('errors.0.detail'));
    }

    #[Test]
    public function an_invalid_query_parameter_points_to_the_parameter(): void {
      $response = $this->getJson('articles-paged?page[size]=0');

      $response->assertStatus(422);
      $this->assertSame('page[size]', $response->json('errors.0.source.parameter'));
    }

    #[Test]
    public function an_unauthenticated_request_is_a_401(): void {
      $response = $this->get('me', ['Accept' => 'application/json']);

      $response->assertStatus(401);
      $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
      $this->assertStringNotContainsString('private', (string) $response->getContent());
    }

    #[Test]
    public function an_unsupported_method_is_a_405_that_says_what_is_allowed(): void {
      $response = $this->deleteJson('articles/1');

      $response->assertStatus(405);
      $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
      $this->assertSame('mundoprogra.json-api.request.method_not_allowed', $response->json('errors.0.code'));
    }

    #[Test]
    public function a_crash_is_a_500_that_hides_what_went_wrong(): void {
      $response = $this->get('crash');

      $response->assertStatus(500);
      $this->assertStringNotContainsString('database exploded', (string) $response->getContent());
      $this->assertSame('mundoprogra.json-api.server.error', $response->json('errors.0.code'));
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function failures(): array {
      // [método, ruta, estado esperado] / [method, route, expected status]
      return [
        'a missing resource' => ['GET', 'articles/999', 404],
        'a route that does not exist' => ['GET', 'does-not-exist', 404],
        'an invalid creation' => ['POST', 'articles', 422],
        'an invalid parameter' => ['GET', 'articles-paged?page[size]=0', 422],
        'an unsupported method' => ['DELETE', 'articles/1', 405],
        'a crash' => ['GET', 'crash', 500],
      ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function every_failure_is_a_valid_json_api_document(string $method, string $uri, int $status): void {
      $response = $this->json($method, $uri, $method === 'POST' ? ['data' => ['attributes' => []]] : []);

      $response->assertStatus($status);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }
  }
