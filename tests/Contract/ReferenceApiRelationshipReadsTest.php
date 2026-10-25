<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Testing\TestResponse;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La lectura de las relaciones de un artículo en la API de referencia, de las dos formas que define la spec: el recurso relacionado
   * (`GET /articles/1/author` y `/tags`, con el recurso o la colección completos) y la relación misma (`GET /articles/1/relationships/author` y `/tags`,
   * solo el linkage con sus links). Los datos: el artículo 1 es del autor 1 y tiene las etiquetas `tag-2` y `tag-3`; el 30 no tiene autor ni etiquetas.
   * Una relación a uno vacía es `data: null`. Estos endpoints no soportan `include` ni `filter` y los rechazan con `400`. Cada respuesta se valida
   * contra el schema oficial.
   *
   * Reading the relationships of an article in the reference API, in the two ways the spec defines: the related resource (`GET /articles/1/author`
   * and `/tags`, with the full resource or collection) and the relationship itself (`GET /articles/1/relationships/author` and `/tags`, only the linkage
   * with its links). The data: article 1 belongs to author 1 and has the tags `tag-2` and `tag-3`; article 30 has no author or tags. An empty to-one
   * relationship is `data: null`. These endpoints support neither `include` nor `filter` and reject them with `400`. Every response is validated
   * against the official schema.
   */
  class ReferenceApiRelationshipReadsTest extends ReferenceApiTestCase {
    private const SELF = 'http://localhost/api/articles';

    private function get_(string $uri): TestResponse {
      return $this->jsonApi('GET', $uri);
    }

    // ---------------------------------------------------------------------------------------------
    // El recurso relacionado, a uno / The related resource, to-one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_related_author_is_the_full_resource(): void {
      $response = $this->get_('api/articles/1/author');

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertSame(['type' => 'authors', 'id' => '1', 'attributes' => ['name' => 'Author 1']], $response->json('data'));
    }

    #[Test]
    public function an_article_without_an_author_has_data_null_at_the_related_endpoint(): void {
      $response = $this->get_('api/articles/30/author');

      $response->assertOk();
      $this->assertSame(['jsonapi' => ['version' => '1.1'], 'data' => null], $response->json());
      $this->assertStringContainsString('"data":null', (string) $response->getContent());
    }

    #[Test]
    public function the_related_author_takes_sparse_fieldsets(): void {
      // Un `fields` vacío deja el recurso sin atributos (y sin el miembro `attributes`); con el nombre, solo ese atributo.
      // An empty `fields` leaves the resource without attributes (and without the `attributes` member); with the name, only that attribute.
      $this->assertArrayNotHasKey('attributes', $this->get_('api/articles/1/author?fields[authors]=')->json('data'));
      $this->assertSame(['name' => 'Author 1'], $this->get_('api/articles/1/author?fields[authors]=name')->json('data.attributes'));
    }

    #[Test]
    public function the_related_author_of_an_article_that_does_not_exist_is_a_404(): void {
      $response = $this->get_('api/articles/999/author');

      $response->assertNotFound();
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
    }

    // ---------------------------------------------------------------------------------------------
    // El recurso relacionado, a muchos / The related resource, to-many
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_related_tags_are_a_collection_sorted_by_slug(): void {
      $response = $this->get_('api/articles/1/tags');

      $response->assertOk();
      $this->assertSame(['tag-2', 'tag-3'], array_column($response->json('data'), 'id'));
      $this->assertSame(['type' => 'labels', 'id' => 'tag-2', 'attributes' => ['name' => 'Tag 2']], $response->json('data.0'));
      $this->assertSame(['currentPage' => 1, 'perPage' => (int) config('json-api.pagination.size'), 'lastPage' => 1, 'total' => 2], $response->json('meta.page'));
    }

    #[Test]
    public function the_related_tags_can_be_sorted_and_paged(): void {
      $this->assertSame(['tag-3', 'tag-2'], array_column($this->get_('api/articles/1/tags?sort=-slug')->json('data'), 'id'));

      $page = $this->get_('api/articles/1/tags?page[number]=2&page[size]=1');

      $this->assertSame(['tag-3'], array_column($page->json('data'), 'id'));
      $this->assertSame(self::SELF . '/1/tags?page%5Bnumber%5D=1&page%5Bsize%5D=1', $page->json('links.prev'));
      $this->assertNull($page->json('links.next'));
    }

    #[Test]
    public function the_related_tags_of_each_article_are_its_own(): void {
      // El artículo 4 tiene las etiquetas 5 y 1.
      // Article 4 has tags 5 and 1.
      $this->assertSame(['tag-1', 'tag-5'], array_column($this->get_('api/articles/4/tags')->json('data'), 'id'));
    }

    #[Test]
    public function an_article_without_tags_has_an_empty_related_collection(): void {
      $response = $this->get_('api/articles/30/tags');

      $response->assertOk();
      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
      $this->assertSame(0, $response->json('meta.page.total'));
    }

    #[Test]
    public function the_related_tags_of_an_article_that_does_not_exist_are_a_404(): void {
      $this->get_('api/articles/999/tags')->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // La relación misma, a uno / The relationship itself, to-one
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_author_relationship_is_only_the_linkage_with_its_links(): void {
      $response = $this->get_('api/articles/1/relationships/author');

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'links' => ['self' => self::SELF . '/1/relationships/author', 'related' => self::SELF . '/1/author'],
        'data' => ['type' => 'authors', 'id' => '1'],
      ], $response->json());
    }

    #[Test]
    public function an_article_without_an_author_has_data_null_at_the_relationship_endpoint(): void {
      $response = $this->get_('api/articles/30/relationships/author');

      $response->assertOk();
      $this->assertNull($response->json('data'));
      $this->assertArrayHasKey('data', (array) $response->json());
      $this->assertSame(self::SELF . '/30/relationships/author', $response->json('links.self'));
    }

    // ---------------------------------------------------------------------------------------------
    // La relación misma, a muchos / The relationship itself, to-many
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_tags_relationship_is_a_list_of_identifiers_sorted_by_slug(): void {
      $response = $this->get_('api/articles/1/relationships/tags');

      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'links' => ['self' => self::SELF . '/1/relationships/tags', 'related' => self::SELF . '/1/tags'],
        'data' => [['type' => 'labels', 'id' => 'tag-2'], ['type' => 'labels', 'id' => 'tag-3']],
      ], $response->json());
    }

    #[Test]
    public function an_article_without_tags_has_an_empty_list_at_the_relationship_endpoint(): void {
      $response = $this->get_('api/articles/30/relationships/tags');

      $this->assertSame([], $response->json('data'));
      $this->assertStringContainsString('"data":[]', (string) $response->getContent());
    }

    #[Test]
    public function a_relationship_of_an_article_that_does_not_exist_is_a_404(): void {
      $this->get_('api/articles/999/relationships/author')->assertNotFound();
      $this->get_('api/articles/999/relationships/tags')->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // Todo cuadra / It all adds up
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_linkage_of_the_relationship_endpoints_is_the_linkage_of_the_article_with_include(): void {
      $article = $this->get_('api/articles/1?include=author,tags')->json('data.relationships');

      $this->assertSame($article['author']['data'], $this->get_('api/articles/1/relationships/author')->json('data'));

      // Las etiquetas del artículo vienen en el orden de la base de datos y las del endpoint, por `slug`: se comparan como conjuntos.
      // The tags of the article come in database order and those of the endpoint by `slug`: they are compared as sets.
      $this->assertEqualsCanonicalizing($article['tags']['data'], $this->get_('api/articles/1/relationships/tags')->json('data'));
    }

    #[Test]
    public function the_related_endpoint_gives_the_same_resource_the_article_included(): void {
      $included = $this->get_('api/articles/1?include=author')->json('included.0');

      $this->assertSame($included, $this->get_('api/articles/1/author')->json('data'));
    }

    #[Test]
    public function the_links_in_an_article_lead_to_working_endpoints(): void {
      $relationships = $this->get_('api/articles/1')->json('data.relationships');

      foreach (['author', 'tags'] as $name) {
        $self = $this->get_($relationships[$name]['links']['self']);
        $related = $this->get_($relationships[$name]['links']['related']);

        $self->assertOk();
        $related->assertOk();
        $this->assertSame($relationships[$name]['links'], $self->json('links'));
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que estos endpoints no soportan / What these endpoints do not support
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function include_is_a_400_at_every_relationship_and_related_endpoint(): void {
      foreach (['author', 'tags', 'relationships/author', 'relationships/tags'] as $endpoint) {
        $response = $this->get_("api/articles/1/$endpoint?include=comments");

        $response->assertStatus(400);
        $this->assertSame('include', $response->json('errors.0.source.parameter'));
        $this->assertSame('mundoprogra.json-api.request.bad_query', $response->json('errors.0.code'));
      }
    }

    #[Test]
    public function filter_is_a_400_at_the_related_tags_endpoint(): void {
      $response = $this->get_('api/articles/1/tags?filter[name]=Tag');

      $response->assertStatus(400);
      $this->assertSame('filter[name]', $response->json('errors.0.source.parameter'));
    }
  }
