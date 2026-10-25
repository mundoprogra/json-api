<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\ApiUser;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Las escrituras de artículos de la API de referencia (`POST`, `PATCH` y `DELETE` en `/articles`), con todo el paquete encendido y un usuario
   * autenticado: `201` con `Location`, `200` con el recurso actualizado y `204` sin cuerpo. Las reglas del `FormRequest` dan `422` con el puntero exacto
   * del atributo o de la relación; una relación que apunta a algo que no existe es un `404` con el puntero al identificador, y en los dos casos no se
   * guarda nada. Sin autenticar, toda escritura es un `401`, y las lecturas siguen siendo públicas. Cada respuesta se valida contra el schema oficial.
   *
   * The article writes of the reference API (`POST`, `PATCH` and `DELETE` on `/articles`), with the whole package on and an authenticated user:
   * `201` with `Location`, `200` with the updated resource and `204` without a body. The `FormRequest` rules give `422` with the exact pointer of the
   * attribute or relationship; a relationship pointing at something that does not exist is a `404` with the pointer to the identifier, and in both
   * cases nothing is saved. Unauthenticated, every write is a `401`, and reads remain public. Every response is validated against the official schema.
   */
  class ReferenceApiWritesTest extends ReferenceApiTestCase {
    private const SELF = 'http://localhost/api/articles';

    protected function setUp(): void {
      parent::setUp();

      $this->actingAs(new ApiUser());
    }

    /**
     * Un documento de recurso `articles`.
     * An `articles` resource document.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @return array<string, mixed>
     */
    private function document(array $attributes = [], array $relationships = [], ?string $id = null): array {
      $data = ['type' => 'articles'];

      if ($id !== null) {
        $data['id'] = $id;
      }

      if ($attributes !== []) {
        $data['attributes'] = $attributes;
      }

      if ($relationships !== []) {
        $data['relationships'] = $relationships;
      }

      return ['data' => $data];
    }

    private function signOut(): void {
      $this->app['auth']->forgetGuards();
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

    // ---------------------------------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function store_creates_the_article_and_answers_201_with_location(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'New article', 'status' => 'published']));

      $response->assertCreated();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame(self::SELF . '/41', $response->headers->get('Location'));
      $this->assertSame(self::SELF . '/41', $response->json('data.links.self'));
      $this->assertSame('41', $response->json('data.id'));
      $this->assertSame(['title' => 'New article', 'status' => 'published'], array_intersect_key($response->json('data.attributes'), ['title' => 1, 'status' => 1]));
      $this->assertNotNull($response->json('data.attributes.createdAt'));
      $this->assertSame('New article', Article::query()->findOrFail(41)->title);
    }

    #[Test]
    public function a_new_article_is_a_draft_unless_the_client_says_otherwise(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Draft one']));

      $this->assertSame('draft', $response->json('data.attributes.status'));
    }

    #[Test]
    public function the_created_article_shows_its_relationships_as_links(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'New article']));

      $this->assertSame(
        ['links' => ['self' => self::SELF . '/41/relationships/author', 'related' => self::SELF . '/41/author']],
        $response->json('data.relationships.author')
      );
      $this->assertArrayNotHasKey('included', (array) $response->json());
    }

    #[Test]
    public function store_takes_the_author_and_the_tags_in_the_relationships(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'With relations'], [
        'author' => ['data' => ['type' => 'authors', 'id' => '2']],
        'tags' => ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels', 'id' => 'tag-4']]],
      ]));

      $response->assertCreated();
      $this->assertSame(2, Article::query()->findOrFail(41)->author_id);
      $this->assertSame(['tag-1', 'tag-4'], $this->tagsOf(41));

      // Y se leen de vuelta por la misma API.
      // And they read back through the same API.
      $shown = $this->jsonApi('GET', 'api/articles/41?include=author,tags');
      $this->assertSame(['authors:2', 'labels:tag-1', 'labels:tag-4'], array_map(fn(array $r) => $r['type'] . ':' . $r['id'], $shown->json('included')));
    }

    #[Test]
    public function store_with_a_null_author_creates_an_article_without_one(): void {
      $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Nobody'], ['author' => ['data' => null]]))->assertCreated();

      $this->assertNull(Article::query()->findOrFail(41)->author_id);
    }

    // ---------------------------------------------------------------------------------------------
    // store: reglas (422) y relaciones que no existen (404) / store: rules (422) and relationships that do not exist (404)
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_title_is_a_422_pointing_at_the_attribute(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['status' => 'draft']));

      $response->assertStatus(422);
      $this->assertSame('/data/attributes/title', $response->json('errors.0.source.pointer'));
      $this->assertSame('mundoprogra.json-api.validation.required', $response->json('errors.0.code'));
      $this->assertSame(40, Article::query()->count());
    }

    #[Test]
    public function a_title_that_is_too_long_and_a_status_that_is_not_allowed_are_422(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => str_repeat('x', 51), 'status' => 'archived']));

      $this->assertSame(['/data/attributes/title', '/data/attributes/status'], array_column(array_column($response->json('errors'), 'source'), 'pointer'));
    }

    #[Test]
    public function a_linkage_that_is_not_valid_points_at_the_exact_member(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Bad linkage'], [
        'author' => ['data' => ['type' => 'authors', 'id' => 'abc']],
        'tags' => ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'people', 'id' => 'tag-2']]],
      ]));

      $response->assertStatus(422);
      $this->assertSame(
        ['/data/relationships/author/data/id', '/data/relationships/tags/data/1/type'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer')
      );
    }

    #[Test]
    public function an_author_that_does_not_exist_is_a_404_pointing_at_the_linkage_and_nothing_is_saved(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Ghost author'], ['author' => ['data' => ['type' => 'authors', 'id' => '99']]]));

      $response->assertNotFound();
      $this->assertSame('/data/relationships/author/data', $response->json('errors.0.source.pointer'));
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
      $this->assertSame(40, Article::query()->count());
    }

    #[Test]
    public function a_tag_that_does_not_exist_is_a_404_pointing_at_that_item_and_nothing_is_saved(): void {
      $response = $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Ghost tag'], [
        'tags' => ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels', 'id' => 'nope']]],
      ]));

      $response->assertNotFound();
      $this->assertSame('/data/relationships/tags/data/1', $response->json('errors.0.source.pointer'));
      $this->assertSame(40, Article::query()->count());
      $this->assertSame(0, DB::table('article_tag')->where('article_id', 41)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // update
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function update_changes_only_what_the_client_sent_and_answers_200(): void {
      $before = Article::query()->findOrFail(2);

      $response = $this->jsonApi('PATCH', 'api/articles/2', $this->document(['title' => 'Renamed'], id: '2'));

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('Renamed', $response->json('data.attributes.title'));
      $this->assertSame($before->status, $response->json('data.attributes.status'));
      $this->assertSame('Renamed', Article::query()->findOrFail(2)->title);
      $this->assertSame($before->author_id, Article::query()->findOrFail(2)->author_id);
    }

    #[Test]
    public function update_can_change_the_status_alone(): void {
      $this->jsonApi('PATCH', 'api/articles/1', $this->document(['status' => 'published'], id: '1'))->assertOk();

      $article = Article::query()->findOrFail(1);

      $this->assertSame('published', $article->status);
      $this->assertSame('Article 1', $article->title);
    }

    #[Test]
    public function update_can_change_empty_and_keep_the_author(): void {
      // El artículo 1 es del autor 1.
      // Article 1 belongs to author 1.
      $this->jsonApi('PATCH', 'api/articles/1', $this->document(id: '1', relationships: ['author' => ['data' => ['type' => 'authors', 'id' => '3']]]))->assertOk();
      $this->assertSame(3, Article::query()->findOrFail(1)->author_id);

      $this->jsonApi('PATCH', 'api/articles/1', $this->document(['title' => 'Same author'], id: '1'))->assertOk();
      $this->assertSame(3, Article::query()->findOrFail(1)->author_id);

      $this->jsonApi('PATCH', 'api/articles/1', $this->document(id: '1', relationships: ['author' => ['data' => null]]))->assertOk();
      $this->assertNull(Article::query()->findOrFail(1)->author_id);
    }

    #[Test]
    public function update_replaces_the_tags_empties_them_or_leaves_them(): void {
      // El artículo 1 tiene las etiquetas 2 y 3.
      // Article 1 has tags 2 and 3.
      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));

      $this->jsonApi('PATCH', 'api/articles/1', $this->document(['title' => 'Untouched tags'], id: '1'))->assertOk();
      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));

      $this->jsonApi('PATCH', 'api/articles/1', $this->document(id: '1', relationships: ['tags' => ['data' => [['type' => 'labels', 'id' => 'tag-5']]]]))->assertOk();
      $this->assertSame(['tag-5'], $this->tagsOf(1));

      $this->jsonApi('PATCH', 'api/articles/1', $this->document(id: '1', relationships: ['tags' => ['data' => []]]))->assertOk();
      $this->assertSame([], $this->tagsOf(1));
    }

    #[Test]
    public function update_with_a_tag_that_does_not_exist_is_a_404_and_changes_nothing(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1', $this->document(['title' => 'Should not stay'], id: '1', relationships: [
        'tags' => ['data' => [['type' => 'labels', 'id' => 'nope']]],
      ]));

      $response->assertNotFound();
      $this->assertSame('/data/relationships/tags/data/0', $response->json('errors.0.source.pointer'));
      $this->assertSame('Article 1', Article::query()->findOrFail(1)->title);
      $this->assertSame(['tag-2', 'tag-3'], $this->tagsOf(1));
    }

    #[Test]
    public function update_with_an_invalid_attribute_is_a_422_and_changes_nothing(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/1', $this->document(['title' => str_repeat('x', 51)], id: '1'));

      $response->assertStatus(422);
      $this->assertSame('/data/attributes/title', $response->json('errors.0.source.pointer'));
      $this->assertSame('Article 1', Article::query()->findOrFail(1)->title);
    }

    #[Test]
    public function update_of_an_article_that_does_not_exist_is_a_404(): void {
      $response = $this->jsonApi('PATCH', 'api/articles/999', $this->document(['title' => 'Nope'], id: '999'));

      $response->assertNotFound();
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
    }

    // ---------------------------------------------------------------------------------------------
    // destroy
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function destroy_answers_204_without_a_body_and_removes_the_article_with_its_relations(): void {
      $response = $this->jsonApi('DELETE', 'api/articles/1');

      $response->assertNoContent();
      $this->assertSame('', (string) $response->getContent());
      $this->assertNotSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertNull(Article::query()->find(1));
      $this->assertSame(0, DB::table('article_tag')->where('article_id', 1)->count());
      $this->assertSame(0, DB::table('comments')->where('article_id', 1)->count());
    }

    #[Test]
    public function a_deleted_article_is_gone_for_the_reads(): void {
      $this->jsonApi('DELETE', 'api/articles/1')->assertNoContent();

      $this->jsonApi('GET', 'api/articles/1')->assertNotFound();
      $this->assertSame(39, $this->jsonApi('GET', 'api/articles?page[size]=1')->json('meta.page.total'));
    }

    #[Test]
    public function destroy_of_an_article_that_does_not_exist_is_a_404(): void {
      $this->jsonApi('DELETE', 'api/articles/999')->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // Autenticación / Authentication
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_write_without_authentication_is_a_401_and_changes_nothing(): void {
      $this->signOut();

      $responses = [
        $this->jsonApi('POST', 'api/articles', $this->document(['title' => 'Anonymous'])),
        $this->jsonApi('PATCH', 'api/articles/1', $this->document(['title' => 'Anonymous'], id: '1')),
        $this->jsonApi('DELETE', 'api/articles/1'),
      ];

      foreach ($responses as $response) {
        $response->assertUnauthorized();
        $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $this->assertSame('mundoprogra.json-api.auth.unauthenticated', $response->json('errors.0.code'));
      }

      $this->assertSame(40, Article::query()->count());
      $this->assertSame('Article 1', Article::query()->findOrFail(1)->title);
    }

    #[Test]
    public function reads_stay_public_without_authentication(): void {
      $this->signOut();

      $this->jsonApi('GET', 'api/articles?page[size]=2')->assertOk();
      $this->jsonApi('GET', 'api/articles/1')->assertOk();
    }
  }
