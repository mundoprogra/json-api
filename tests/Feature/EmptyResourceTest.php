<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\ArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El recurso relacionado de una relación a uno que está vacía (`/articles/1/editor` cuando el artículo no tiene editor) es un documento con
   * `data: null`, no un error: `AuthorResource::make(null)`. Es la respuesta que pide la spec, con el `Content-Type` exacto y `200`.
   *
   * The related resource of an empty to-one relationship (`/articles/1/editor` when the article has no editor) is a document with `data: null`,
   * not an error: `AuthorResource::make(null)`. It is the response the spec asks for, with the exact `Content-Type` and `200`.
   */
  class EmptyResourceTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    protected function defineRoutes($router): void {
      // El artículo no tiene editor: la relación está cargada y vacía.
      // The article has no editor: the relationship is loaded and empty.
      $editor = fn() => (new Article())->forceFill(['id' => 1])->setRelation('editor', null)->getRelation('editor');

      $router->get('api/articles/1/editor', fn() => AuthorResource::make($editor()));
      $router->get('api/articles/1/editor-extra', fn() => AuthorResource::make(null)->additional(['meta' => ['why' => 'no editor']]));
      $router->get('api/articles/1/editor-greedy', fn() => AuthorResource::make(null)->additional(['data' => 'evil', 'jsonapi' => 'evil']));
      $router->get('api/articles/1/editor-article', fn() => ArticleResource::make(null));
    }

    private function fetch(string $uri = 'api/articles/1/editor'): TestResponse {
      return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    #[Test]
    public function an_empty_resource_is_a_document_with_data_null(): void {
      $this->assertSame(['jsonapi' => ['version' => '1.1'], 'data' => null], AuthorResource::make(null)->document());
    }

    #[Test]
    public function the_response_is_a_200_with_data_null_and_the_exact_content_type(): void {
      $response = $this->fetch();

      $response->assertOk();
      $this->assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
      $this->assertSame(['jsonapi' => ['version' => '1.1'], 'data' => null], $response->json());
      $this->assertStringContainsString('"data":null', (string) $response->getContent());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function it_works_for_any_resource_class(): void {
      $this->assertSame(['jsonapi' => ['version' => '1.1'], 'data' => null], $this->fetch('api/articles/1/editor-article')->json());
    }

    #[Test]
    public function the_jsonapi_member_follows_the_setting(): void {
      config()->set('json-api.jsonapi_object', false);

      $response = $this->fetch();

      $this->assertSame(['data' => null], $response->json());
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_members_are_added_but_data_stays_null(): void {
      $response = $this->fetch('api/articles/1/editor-extra');

      $this->assertSame(['jsonapi', 'data', 'meta'], array_keys((array) $response->json()));
      $this->assertNull($response->json('data'));
      $this->assertSame(['why' => 'no editor'], $response->json('meta'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function additional_can_never_override_jsonapi_or_data(): void {
      $response = $this->fetch('api/articles/1/editor-greedy');

      $response->assertOk();
      $this->assertSame(['version' => '1.1'], $response->json('jsonapi'));
      $this->assertArrayHasKey('data', (array) $response->json());
      $this->assertNull($response->json('data'));
    }

    #[Test]
    public function an_empty_resource_is_never_a_201(): void {
      $this->assertSame(200, $this->fetch()->getStatusCode());
      $this->assertNull($this->fetch()->headers->get('Location'));
    }
  }
