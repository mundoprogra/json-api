<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\ApiUser;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La API de referencia en cada estilo de nombres (`member_case`: `camel`, `kebab` o `snake`). Los nombres de varias palabras de la API
   * (`created_at`, `updated_at` y los de `meta.page`) salen y se piden en el estilo configurado: en los atributos, en `sort`, en `fields` y en el `meta`
   * de paginación. Lo que no cambia con el estilo es el resto del documento: los tipos, los ids, los links, los punteros de los errores y el linkage.
   * Cada respuesta se valida contra el schema oficial.
   *
   * The reference API in each naming style (`member_case`: `camel`, `kebab` or `snake`). The multi-word names of the API (`created_at`, `updated_at`
   * and those of `meta.page`) come out and are asked for in the configured style: in the attributes, in `sort`, in `fields` and in the pagination
   * `meta`. What does not change with the style is the rest of the document: types, ids, links, error pointers and linkage. Every response is
   * validated against the official schema.
   */
  class ReferenceApiNamingStylesTest extends ReferenceApiTestCase {
    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function styles(): array {
      return [
        'camel' => ['camel', 'createdAt', 'updatedAt', ['currentPage', 'perPage', 'lastPage', 'total']],
        'kebab' => ['kebab', 'created-at', 'updated-at', ['current-page', 'per-page', 'last-page', 'total']],
        'snake' => ['snake', 'created_at', 'updated_at', ['current_page', 'per_page', 'last_page', 'total']],
      ];
    }

    /**
     * @param  list<string>  $meta
     */
    #[Test]
    #[DataProvider('styles')]
    public function the_attributes_and_the_pagination_meta_come_out_in_the_configured_style(string $style, string $created, string $updated, array $meta): void {
      config()->set('json-api.member_case', $style);

      $response = $this->jsonApi('GET', 'api/articles?page[size]=2');

      $this->assertSame(['title', 'status', $created, $updated], array_keys($response->json('data.0.attributes')));
      $this->assertSame($meta, array_keys($response->json('meta.page')));
    }

    /**
     * @param  list<string>  $meta
     */
    #[Test]
    #[DataProvider('styles')]
    public function sort_and_fields_are_asked_for_in_the_configured_style(string $style, string $created, string $updated, array $meta): void {
      config()->set('json-api.member_case', $style);

      $sorted = $this->jsonApi('GET', "api/articles?sort=$created,id&page[size]=3");
      $reversed = $this->jsonApi('GET', "api/articles?sort=-$created,-id&page[size]=3");
      $fields = $this->jsonApi('GET', "api/articles?fields[articles]=title,$created&page[size]=1");

      $this->assertSame(['1', '2', '3'], array_column($sorted->json('data'), 'id'));
      $this->assertSame(['40', '39', '38'], array_column($reversed->json('data'), 'id'));
      $this->assertSame(['title', $created], array_keys($fields->json('data.0.attributes')));
    }

    /**
     * @param  list<string>  $meta
     */
    #[Test]
    #[DataProvider('styles')]
    public function a_write_works_in_every_style_and_answers_in_it(string $style, string $created, string $updated, array $meta): void {
      config()->set('json-api.member_case', $style);
      $this->actingAs(new ApiUser());

      $response = $this->jsonApi('POST', 'api/articles', ['data' => ['type' => 'articles', 'attributes' => ['title' => 'Styled']]]);

      $response->assertCreated();
      $this->assertSame(['title', 'status', $created, $updated], array_keys($response->json('data.attributes')));
    }

    /**
     * @param  list<string>  $meta
     */
    #[Test]
    #[DataProvider('styles')]
    public function what_the_style_does_not_touch_is_the_same_in_every_style(string $style, string $created, string $updated, array $meta): void {
      config()->set('json-api.member_case', $style);
      $this->actingAs(new ApiUser());

      $article = $this->jsonApi('GET', 'api/articles/1?include=author,tags');

      $this->assertSame('articles', $article->json('data.type'));
      $this->assertSame('http://localhost/api/articles/1', $article->json('data.links.self'));
      $this->assertSame(['type' => 'authors', 'id' => '1'], $article->json('data.relationships.author.data'));
      $this->assertSame(['authors:1', 'labels:tag-2', 'labels:tag-3'], array_map(fn(array $r) => $r['type'] . ':' . $r['id'], $article->json('included')));

      $invalid = $this->jsonApi('POST', 'api/articles', ['data' => ['type' => 'articles', 'attributes' => ['title' => 'ok', 'status' => 'archived']]]);

      $this->assertSame('/data/attributes/status', $invalid->json('errors.0.source.pointer'));
    }
  }
