<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Support\AssertsCompoundDocuments;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Las lecturas de la API de referencia (`GET /articles` y `GET /articles/{id}`) sobre la base de datos de pruebas, con todo el paquete encendido:
   * filtros con lista blanca, orden, paginación, `include` y sparse fieldsets, solos y combinados. Cada respuesta se valida contra el schema oficial.
   * Los datos: 40 artículos (los de id par están publicados); los primeros 20 tienen autor (el artículo `n` es del autor `((n - 1) % 3) + 1`), dos
   * etiquetas y dos comentarios (de los autores 1 y 2).
   *
   * The reads of the reference API (`GET /articles` and `GET /articles/{id}`) over the test database, with the whole package on: whitelisted
   * filters, sorting, pagination, `include` and sparse fieldsets, alone and combined. Every response is validated against the official schema.
   * The data: 40 articles (those with an even id are published); the first 20 have an author (article `n` belongs to author `((n - 1) % 3) + 1`),
   * two tags and two comments (by authors 1 and 2).
   */
  class ReferenceApiReadsTest extends ReferenceApiTestCase {
    use AssertsCompoundDocuments;

    private const SELF = 'http://localhost/api/articles';

    private function get_(string $uri): TestResponse {
      return $this->jsonApi('GET', $uri);
    }

    /**
     * Los ids de `data`, en orden.
     * The ids in `data`, in order.
     *
     * @return list<string>
     */
    private function ids(TestResponse $response): array {
      return array_column((array) $response->json('data'), 'id');
    }

    // ---------------------------------------------------------------------------------------------
    // index: lo básico / index: the basics
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_index_is_a_paginated_collection_newest_id_first_by_default(): void {
      $response = $this->get_('api/articles');

      $size = (int) config('json-api.pagination.size');

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertCount($size, $response->json('data'));
      $this->assertSame('40', $response->json('data.0.id'));
      $this->assertSame(['currentPage' => 1, 'perPage' => $size, 'lastPage' => (int) ceil(40 / $size), 'total' => 40], $response->json('meta.page'));
      $this->assertSame(['jsonapi', 'data', 'links', 'meta'], array_keys((array) $response->json()));
    }

    #[Test]
    public function every_article_is_a_full_resource_object_with_its_links(): void {
      $article = $this->get_('api/articles?page[size]=1')->json('data.0');

      $this->assertSame('articles', $article['type']);
      $this->assertSame('40', $article['id']);
      $this->assertSame(['title', 'status', 'createdAt', 'updatedAt'], array_keys($article['attributes']));
      $this->assertSame(['title' => 'Article 40', 'status' => 'published'], array_intersect_key($article['attributes'], ['title' => 1, 'status' => 1]));
      $this->assertSame(['self' => self::SELF . '/40'], $article['links']);
    }

    #[Test]
    public function relationships_that_were_not_asked_for_come_with_only_their_links(): void {
      $article = $this->get_('api/articles?page[size]=1')->json('data.0');

      $this->assertSame(['author', 'tags', 'comments'], array_keys($article['relationships']));

      foreach (['author', 'tags', 'comments'] as $name) {
        $this->assertSame(
          ['links' => ['self' => self::SELF . "/40/relationships/$name", 'related' => self::SELF . "/40/$name"]],
          $article['relationships'][$name]
        );
      }
    }

    // ---------------------------------------------------------------------------------------------
    // index: paginación / index: pagination
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function pages_follow_the_number_and_size_of_the_client(): void {
      $response = $this->get_('api/articles?page[number]=2&page[size]=10');

      $this->assertSame(['30', '29', '28', '27', '26', '25', '24', '23', '22', '21'], $this->ids($response));
      $this->assertSame(['currentPage' => 2, 'perPage' => 10, 'lastPage' => 4, 'total' => 40], $response->json('meta.page'));
      $this->assertSame(self::SELF . '?page%5Bnumber%5D=1&page%5Bsize%5D=10', $response->json('links.prev'));
      $this->assertSame(self::SELF . '?page%5Bnumber%5D=3&page%5Bsize%5D=10', $response->json('links.next'));
    }

    #[Test]
    public function a_page_out_of_range_is_an_empty_list_whose_prev_is_the_last_page(): void {
      $response = $this->get_('api/articles?page[number]=99&page[size]=10');

      $response->assertOk();
      $this->assertSame([], $response->json('data'));
      $this->assertSame(self::SELF . '?page%5Bnumber%5D=4&page%5Bsize%5D=10', $response->json('links.prev'));
      $this->assertNull($response->json('links.next'));
    }

    // ---------------------------------------------------------------------------------------------
    // index: orden / index: sorting
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_client_can_sort_ascending_or_descending(): void {
      $this->assertSame(['1', '2', '3', '4', '5'], $this->ids($this->get_('api/articles?sort=id&page[size]=5')));
      $this->assertSame(['40', '39', '38'], $this->ids($this->get_('api/articles?sort=-id&page[size]=3')));
    }

    #[Test]
    public function the_client_can_sort_by_a_text_column(): void {
      // "Article 1" va antes que "Article 10" porque es más corto: orden de texto, no numérico.
      // "Article 1" comes before "Article 10" because it is shorter: text order, not numeric.
      $this->assertSame(['1', '10', '11', '12', '13'], $this->ids($this->get_('api/articles?sort=title&page[size]=5')));
    }

    #[Test]
    public function a_sort_field_is_written_in_the_member_case_style_of_the_client(): void {
      // `createdAt` en el estilo del cliente es la columna `created_at`.
      // `createdAt` in the client style is the `created_at` column.
      $this->assertSame(['1', '2', '3'], $this->ids($this->get_('api/articles?sort=createdAt,id&page[size]=3')));
    }

    #[Test]
    public function several_sort_fields_apply_in_the_order_given(): void {
      // Los borradores (`draft`) van antes que los publicados; dentro de cada grupo, el id más alto primero.
      // Drafts (`draft`) come before the published ones; inside each group, the highest id first.
      $this->assertSame(['39', '37', '35'], $this->ids($this->get_('api/articles?sort=status,-id&page[size]=3')));
    }

    // ---------------------------------------------------------------------------------------------
    // index: filtros / index: filters
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_exact_filter_keeps_only_the_matching_articles(): void {
      $response = $this->get_('api/articles?filter[status]=published&page[size]=5');

      $this->assertSame(['40', '38', '36', '34', '32'], $this->ids($response));
      $this->assertSame(20, $response->json('meta.page.total'));
    }

    #[Test]
    public function a_filter_name_can_stand_for_a_column_and_take_a_list_of_values(): void {
      // `filter[author]` es la columna `author_id`; con comas es un `IN`.
      // `filter[author]` is the `author_id` column; with commas it is an `IN`.
      $this->assertSame(['2', '5', '8', '11', '14', '17', '20'], $this->ids($this->get_('api/articles?filter[author]=2&sort=id&page[size]=50')));
      $this->assertSame(
        ['1', '3', '4', '6', '7', '9', '10', '12', '13', '15', '16', '18', '19'],
        $this->ids($this->get_('api/articles?filter[author]=1,3&sort=id&page[size]=50'))
      );
    }

    #[Test]
    public function a_partial_filter_matches_part_of_the_text(): void {
      $this->assertSame(
        ['1', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19'],
        $this->ids($this->get_('api/articles?filter[title]=Article%201&sort=id&page[size]=50'))
      );
    }

    #[Test]
    public function a_filter_can_call_a_scope_of_the_model(): void {
      $this->assertSame(['1', '3', '5'], $this->ids($this->get_('api/articles?filter[published]=0&sort=id&page[size]=3')));
      $this->assertSame(['2', '4', '6'], $this->ids($this->get_('api/articles?filter[published]=1&sort=id&page[size]=3')));
    }

    #[Test]
    public function a_filter_with_no_matches_is_an_empty_collection(): void {
      $response = $this->get_('api/articles?filter[status]=archived');

      $this->assertSame([], $response->json('data'));
      $this->assertSame(0, $response->json('meta.page.total'));
      $this->assertArrayNotHasKey('included', (array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // index: include / index: include
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function include_author_brings_the_authors_once_each_and_links_them(): void {
      $response = $this->get_('api/articles?include=author&sort=id&page[size]=5');

      $this->assertSame(['authors:1', 'authors:2', 'authors:3'], $this->included((array) $response->json()));
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('data.0.relationships.author.data'));
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('data.3.relationships.author.data'));
      $this->assertSame(['name' => 'Author 1'], $response->json('included.0.attributes'));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function the_relationships_not_asked_for_stay_as_links_when_another_one_is_included(): void {
      $article = $this->get_('api/articles?include=author&sort=id&page[size]=1')->json('data.0');

      $this->assertArrayHasKey('data', $article['relationships']['author']);
      $this->assertArrayNotHasKey('data', $article['relationships']['tags']);
      $this->assertArrayNotHasKey('data', $article['relationships']['comments']);
    }

    #[Test]
    public function include_tags_brings_each_tag_once(): void {
      $response = $this->get_('api/articles?include=tags&sort=id&page[size]=5');
      $included = $this->included((array) $response->json());

      sort($included);

      $this->assertSame(['labels:tag-1', 'labels:tag-2', 'labels:tag-3', 'labels:tag-4', 'labels:tag-5'], $included);
      $this->assertCount(2, $response->json('data.0.relationships.tags.data'));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function a_nested_include_brings_every_level(): void {
      $response = $this->get_('api/articles?include=comments.author&sort=id&page[size]=3');

      $this->assertSame(
        ['comments:1', 'comments:2', 'comments:3', 'comments:4', 'comments:5', 'comments:6', 'authors:1', 'authors:2'],
        $this->included((array) $response->json())
      );
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function an_article_without_an_author_has_a_null_linkage_and_includes_nothing(): void {
      $response = $this->get_('api/articles?include=author&sort=-id&page[size]=3');

      $this->assertSame(['40', '39', '38'], $this->ids($response));
      $this->assertArrayHasKey('data', $response->json('data.0.relationships.author'));
      $this->assertNull($response->json('data.0.relationships.author.data'));
      $this->assertArrayNotHasKey('included', (array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // index: sparse fieldsets / index: sparse fieldsets
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function fields_keep_only_the_attributes_asked_for_and_drop_the_other_relationships(): void {
      $article = $this->get_('api/articles?fields[articles]=title&page[size]=1')->json('data.0');

      $this->assertSame(['title' => 'Article 40'], $article['attributes']);
      $this->assertArrayNotHasKey('relationships', $article);
      $this->assertSame(['self' => self::SELF . '/40'], $article['links']);
    }

    #[Test]
    public function fields_can_keep_a_relationship_and_that_is_what_gets_included(): void {
      $response = $this->get_('api/articles?include=author,tags&fields[articles]=title,author&sort=id&page[size]=3');

      $this->assertSame(['author'], array_keys($response->json('data.0.relationships')));
      $this->assertSame(['authors:1', 'authors:2', 'authors:3'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function fields_for_an_included_type_trim_each_included_resource(): void {
      $response = $this->get_('api/articles?include=author&fields[authors]=name&sort=id&page[size]=3');

      $this->assertSame(['name' => 'Author 1'], $response->json('included.0.attributes'));
    }

    // ---------------------------------------------------------------------------------------------
    // index: todo junto / index: everything together
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function filter_sort_include_fields_and_page_work_together_and_the_links_keep_them_all(): void {
      $response = $this->get_('api/articles?filter[status]=published&sort=id&include=author&fields[articles]=title,author&page[number]=2&page[size]=3');

      // Publicados en orden: 2, 4, 6 | 8, 10, 12. Sus autores: 2, 1 y 3.
      // Published in order: 2, 4, 6 | 8, 10, 12. Their authors: 2, 1 and 3.
      $this->assertSame(['8', '10', '12'], $this->ids($response));
      $this->assertSame(['authors:2', 'authors:1', 'authors:3'], $this->included((array) $response->json()));
      $this->assertSame(['title' => 'Article 8'], $response->json('data.0.attributes'));
      $this->assertSame(
        self::SELF . '?filter%5Bstatus%5D=published&sort=id&include=author&fields%5Barticles%5D=title%2Cauthor&page%5Bnumber%5D=3&page%5Bsize%5D=3',
        $response->json('links.next')
      );
      $this->assertSame(['currentPage' => 2, 'perPage' => 3, 'lastPage' => 7, 'total' => 20], $response->json('meta.page'));
      $this->assertCompound((array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // show / show
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function show_gives_one_article_as_a_resource_document(): void {
      $response = $this->get_('api/articles/1');

      $response->assertOk();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertSame('1', $response->json('data.id'));
      $this->assertSame('Article 1', $response->json('data.attributes.title'));
      $this->assertSame(self::SELF . '/1', $response->json('data.links.self'));
      $this->assertSame(
        ['links' => ['self' => self::SELF . '/1/relationships/author', 'related' => self::SELF . '/1/author']],
        $response->json('data.relationships.author')
      );
    }

    #[Test]
    public function show_takes_include_and_brings_the_related_resources(): void {
      $response = $this->get_('api/articles/1?include=author,tags');

      // El artículo 1 es del autor 1 y tiene las etiquetas 2 y 3.
      // Article 1 belongs to author 1 and has tags 2 and 3.
      $this->assertSame(['authors:1', 'labels:tag-2', 'labels:tag-3'], $this->included((array) $response->json()));
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('data.relationships.author.data'));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function show_takes_a_nested_include(): void {
      $response = $this->get_('api/articles/1?include=comments.author');

      $this->assertSame(['comments:1', 'comments:2', 'authors:1', 'authors:2'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function show_takes_sparse_fieldsets(): void {
      $response = $this->get_('api/articles/1?fields[articles]=title');

      $this->assertSame(['title' => 'Article 1'], $response->json('data.attributes'));
      $this->assertArrayNotHasKey('relationships', $response->json('data'));
    }

    #[Test]
    public function show_of_an_article_without_an_author_included_gives_a_null_linkage(): void {
      $response = $this->get_('api/articles/30?include=author');

      $this->assertNull($response->json('data.relationships.author.data'));
      $this->assertArrayNotHasKey('included', (array) $response->json());
    }

    #[Test]
    public function show_of_an_article_that_does_not_exist_is_a_json_api_404(): void {
      $response = $this->get_('api/articles/999');

      $response->assertNotFound();
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('404', $response->json('errors.0.status'));
      $this->assertSame('mundoprogra.json-api.resource.not_found', $response->json('errors.0.code'));
      $this->assertStringNotContainsString('Article', (string) $response->json('errors.0.detail'));
    }
  }
