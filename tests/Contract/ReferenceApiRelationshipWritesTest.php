<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\ApiUser;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La escritura de relaciones de un artículo en la API de referencia, con todo el paquete encendido y un usuario autenticado. A uno (`author`) solo
   * se reemplaza con `PATCH`, y `null` la vacía; a muchos (`tags`) se reemplaza con `PATCH`, se agrega con `POST` y se quita con `DELETE`. Cada
   * escritura responde `200` con el linkage resultante. Lo que revisa la app sale con su puntero: una lista donde va un identificador (o al revés) es
   * `422`, un `type` que no es el de la relación es `409` y un recurso que no existe es `404`; en cualquier caso no se cambia nada. La forma del
   * documento la valida el paquete (`400`) antes de llegar al controlador. `POST` y `DELETE` sobre una relación a uno no existen: son `405` con `Allow`.
   * Los datos: el artículo 1 es del autor 1 y tiene las etiquetas `tag-2` y `tag-3`; el 30 no tiene autor ni etiquetas.
   *
   * Writing the relationships of an article in the reference API, with the whole package on and an authenticated user. A to-one (`author`) is only
   * replaced with `PATCH`, and `null` empties it; a to-many (`tags`) is replaced with `PATCH`, added to with `POST` and removed from with `DELETE`.
   * Each write answers `200` with the resulting linkage. What the app checks comes out with its pointer: a list where an identifier goes (or the other
   * way round) is `422`, a `type` that is not the relationship's is `409` and a resource that does not exist is `404`; in any case nothing changes.
   * The shape of the document is validated by the package (`400`) before reaching the controller. `POST` and `DELETE` on a to-one relationship do not
   * exist: they are `405` with `Allow`. The data: article 1 belongs to author 1 and has the tags `tag-2` and `tag-3`; article 30 has no author or tags.
   */
  class ReferenceApiRelationshipWritesTest extends ReferenceApiTestCase {
    private const SELF = 'http://localhost/api/articles';

    protected function setUp(): void {
      parent::setUp();

      $this->actingAs(new ApiUser());
    }

    /**
     * Un linkage a uno.
     * A to-one linkage.
     *
     * @return array<string, mixed>
     */
    private function one(?string $id, string $type = 'authors'): array {
      return ['data' => $id === null ? null : ['type' => $type, 'id' => $id]];
    }

    /**
     * Un linkage a muchos de etiquetas.
     * A to-many linkage of tags.
     *
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function many(array $slugs, string $type = 'labels'): array {
      return ['data' => array_map(fn(string $slug) => ['type' => $type, 'id' => $slug], $slugs)];
    }

    /**
     * Los `slug` de las etiquetas de un artículo, ordenados.
     * The tag slugs of an article, sorted.
     *
     * @return list<string>
     */
    private function tagsOf(int $id): array {
      return Article::query()->findOrFail($id)->tags()->pluck('slug')->sort()->values()->all();
    }

    private function signOut(): void {
      $this->app['auth']->forgetGuards();
    }

    /**
     * @param  list<string>  $pointers
     */
    private function assertRejected(TestResponse $response, int $status, array $pointers): void {
      $response->assertStatus($status);
      $this->assertSame($pointers, array_column(array_column($response->json('errors'), 'source'), 'pointer'));
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH a uno / PATCH to-one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function patching_the_author_sets_it_and_answers_200_with_the_resulting_linkage(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('3'));

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'links' => ['self' => self::SELF . '/1/relationships/author', 'related' => self::SELF . '/1/author'],
        'data' => ['type' => 'authors', 'id' => '3'],
      ], $response->json());
      $this->assertSame(3, Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function patching_the_author_with_null_empties_it(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one(null));

      $response->assertOk();
      $this->assertArrayHasKey('data', (array) $response->json());
      $this->assertNull($response->json('data'));
      $this->assertNull(Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function an_article_without_an_author_can_get_one(): void {
      $this->jsonApi('PATCH', 'api/articles/30/relationships/author', $this->one('2'))->assertOk();

      $this->assertSame(2, Article::query()->findOrFail(30)->author_id);
    }

    #[Test]
    public function patching_the_same_author_again_changes_nothing(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('1'));

      $response->assertOk();
      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function an_author_that_does_not_exist_is_a_404_pointing_at_the_linkage(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('99'));

      $this->assertRejected($response, 404, ['/data']);
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function an_identifier_of_another_type_is_a_409_pointing_at_the_type(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('3', 'people'));

      $this->assertRejected($response, 409, ['/data/type']);
      $this->assertSame('mundoprogra.json-api.resource.conflict', $response->json('errors.0.code'));
      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function a_list_for_a_to_one_relationship_is_a_422_pointing_at_data(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->many(['3'], 'authors'));

      $this->assertRejected($response, 422, ['/data']);
      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function a_badly_shaped_linkage_is_a_400_before_it_reaches_the_controller(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/author', ['data' => ['type' => 'authors']]);

      $this->assertRejected($response, 400, ['/data/id']);
      $this->assertSame('mundoprogra.json-api.request.bad_document', $response->json('errors.0.code'));
    }

    #[Test]
    public function patching_the_author_of_an_article_that_does_not_exist_is_a_404(): void {
      $this->jsonApi('PATCH', 'api/articles/999/relationships/author', $this->one('1'))->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // PATCH a muchos / PATCH to-many
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function patching_the_tags_replaces_the_whole_set(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/tags', $this->many(['tag-5', 'tag-1']));

      $response->assertOk();
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'links' => ['self' => self::SELF . '/1/relationships/tags', 'related' => self::SELF . '/1/tags'],
        'data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels', 'id' => 'tag-5']],
      ], $response->json());
      $this->assertSame(['tag-1', 'tag-5'], $this->tagsOf(1));
    }

    #[Test]
    public function patching_the_tags_with_an_empty_list_empties_them(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1/relationships/tags', $this->many([]));

      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
      $this->assertSame([], $this->tagsOf(1));
    }

    #[Test]
    public function an_identifier_repeated_in_the_list_counts_once(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/30/relationships/tags', $this->many(['tag-1', 'tag-1']));

      $this->assertSame([['type' => 'labels', 'id' => 'tag-1']], $response->json('data'));
      $this->assertSame(['tag-1'], $this->tagsOf(30));
    }

    // ---------------------------------------------------------------------------------------------
    // POST y DELETE a muchos / POST and DELETE to-many
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function posting_tags_adds_the_new_ones_and_keeps_the_rest_without_repeating(): void {
      $response = $this->jsonApi('POST', 'api/articles/1/relationships/tags', $this->many(['tag-1', 'tag-3']));

      $response->assertOk();
      $this->assertSame(['tag-1', 'tag-2', 'tag-3'], array_column($response->json('data'), 'id'));
      $this->assertSame(['tag-1', 'tag-2', 'tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function posting_an_empty_list_changes_nothing(): void {
      $response = $this->jsonApi('POST', 'api/articles/1/relationships/tags', $this->many([]));

      $response->assertOk();
      $this->assertSame(['tag-2', 'tag-3'], array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function deleting_tags_removes_the_members_and_ignores_the_ones_that_were_not(): void {
      $response = $this->jsonApi('DELETE', 'api/articles/1/relationships/tags', $this->many(['tag-2', 'tag-5']));

      $response->assertOk();
      $this->assertSame([['type' => 'labels', 'id' => 'tag-3']], $response->json('data'));
      $this->assertSame(['tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function deleting_every_tag_leaves_an_empty_list(): void {
      $response = $this->jsonApi('DELETE', 'api/articles/1/relationships/tags', $this->many(['tag-2', 'tag-3']));

      $this->assertSame([], $response->json('data'));
      $this->assertSame([], $this->tagsOf(1));
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que se rechaza a muchos, sin cambiar nada / What a to-many rejects, changing nothing
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_tag_that_does_not_exist_is_a_404_pointing_at_its_item_and_nothing_changes(): void {
      foreach (['PATCH', 'POST', 'DELETE'] as $method) {
        $response = $this->jsonApi($method, 'api/articles/1/relationships/tags', $this->many(['tag-1', 'nope']));

        $this->assertRejected($response, 404, ['/data/1']);
        $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1), "$method must change nothing.");
      }
    }

    #[Test]
    public function an_item_of_another_type_is_a_409_pointing_at_its_type_and_nothing_changes(): void {
      foreach (['PATCH', 'POST', 'DELETE'] as $method) {
        $response = $this->jsonApi($method, 'api/articles/1/relationships/tags', ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'people', 'id' => 'tag-4']]]);

        $this->assertRejected($response, 409, ['/data/1/type']);
        $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1), "$method must change nothing.");
      }
    }

    #[Test]
    public function a_single_identifier_or_null_for_a_to_many_relationship_is_a_422_pointing_at_data(): void {
      foreach ([['data' => ['type' => 'labels', 'id' => 'tag-1']], ['data' => null]] as $body) {
        $this->assertRejected($this->jsonApi('PATCH', 'api/articles/1/relationships/tags', $body), 422, ['/data']);
      }

      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function a_badly_shaped_item_is_a_400_with_the_pointer_of_the_member(): void {
      $response = $this->jsonApi('POST', 'api/articles/1/relationships/tags', ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels']]]);

      $this->assertRejected($response, 400, ['/data/1/id']);
      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function the_tags_of_an_article_that_does_not_exist_are_a_404_for_every_method(): void {
      foreach (['PATCH', 'POST', 'DELETE'] as $method) {
        $this->jsonApi($method, 'api/articles/999/relationships/tags', $this->many(['tag-1']))->assertNotFound();
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que no existe: POST y DELETE a uno / What does not exist: POST and DELETE to-one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function post_and_delete_on_a_to_one_relationship_are_a_405_with_the_allowed_methods(): void {
      foreach (['POST', 'DELETE'] as $method) {
        $response = $this->jsonApi($method, 'api/articles/1/relationships/author', $this->one('3'));

        $response->assertStatus(405);
        $this->assertSame('mundoprogra.json-api.request.method_not_allowed', $response->json('errors.0.code'));

        $allowed = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
        sort($allowed);

        $this->assertSame(['GET', 'HEAD', 'PATCH'], $allowed);
      }

      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Se lee de vuelta lo que se escribió / What was written reads back
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function what_was_written_reads_back_through_every_read_endpoint(): void {
      $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('2'))->assertOk();
      $this->jsonApi('PATCH', 'api/articles/1/relationships/tags', $this->many(['tag-4', 'tag-1']))->assertOk();

      $article = $this->jsonApi('GET', 'api/articles/1?include=author,tags');

      $this->assertSame(['type' => 'authors', 'id' => '2'], $article->json('data.relationships.author.data'));
      $this->assertEqualsCanonicalizing(
        [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels', 'id' => 'tag-4']],
        $article->json('data.relationships.tags.data')
      );
      $this->assertSame('2', $this->jsonApi('GET', 'api/articles/1/author')->json('data.id'));
      $this->assertSame(['tag-1', 'tag-4'], array_column($this->jsonApi('GET', 'api/articles/1/tags')->json('data'), 'id'));
    }

    // ---------------------------------------------------------------------------------------------
    // Autenticación / Authentication
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_relationship_write_without_authentication_is_a_401_and_changes_nothing(): void {
      $this->signOut();

      $responses = [
        $this->jsonApi('PATCH', 'api/articles/1/relationships/author', $this->one('3')),
        $this->jsonApi('PATCH', 'api/articles/1/relationships/tags', $this->many(['tag-5'])),
        $this->jsonApi('POST', 'api/articles/1/relationships/tags', $this->many(['tag-5'])),
        $this->jsonApi('DELETE', 'api/articles/1/relationships/tags', $this->many(['tag-2'])),
      ];

      foreach ($responses as $response) {
        $response->assertUnauthorized();
        $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
      }

      $this->assertSame(1, Article::query()->findOrFail(1)->author_id);
      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function reading_a_relationship_stays_public(): void {
      $this->signOut();

      $this->jsonApi('GET', 'api/articles/1/relationships/author')->assertOk();
      $this->jsonApi('GET', 'api/articles/1/relationships/tags')->assertOk();
    }
  }
