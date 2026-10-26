<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi\ApiUser;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * El recorrido completo de un cliente de la API de referencia, de punta a punta y siguiendo los links que dan las propias respuestas en vez de
   * armar URL a mano: crea un artículo con autor y etiquetas, lo lee con `include`, cambia sus relaciones por los endpoints de relación (agrega, quita,
   * reemplaza y vacía), lo actualiza, lo encuentra con un filtro, lee sus recursos relacionados, lo borra y comprueba que ya no está. Cada respuesta se
   * valida contra el schema oficial.
   *
   * A whole client journey through the reference API, end to end and following the links the responses themselves give instead of building URLs by
   * hand: it creates an article with an author and tags, reads it with `include`, changes its relationships through the relationship endpoints (adds,
   * removes, replaces and empties), updates it, finds it with a filter, reads its related resources, deletes it and checks it is gone. Every response is
   * validated against the official schema.
   */
  class ReferenceApiJourneyTest extends ReferenceApiTestCase {
    protected function setUp(): void {
      parent::setUp();

      $this->actingAs(new ApiUser());
    }

    /**
     * Los `id` de un linkage, en orden.
     * The ids of a linkage, in order.
     *
     * @param  array<int, array{type: string, id: string}>  $linkage
     * @return list<string>
     */
    private function ids(array $linkage): array {
      return array_column($linkage, 'id');
    }

    #[Test]
    public function a_client_creates_reads_changes_finds_and_deletes_an_article_following_the_links(): void {
      // 1. Crea el artículo con autor y etiquetas: 201 y dónde quedó.
      // 1. Creates the article with an author and tags: 201 and where it ended up.
      $created = $this->jsonApi('POST', 'api/articles', ['data' => [
        'type' => 'articles',
        'attributes' => ['title' => 'Journey', 'status' => 'published'],
        'relationships' => [
          'author' => ['data' => ['type' => 'authors', 'id' => '3']],
          'tags' => ['data' => [['type' => 'labels', 'id' => 'tag-1'], ['type' => 'labels', 'id' => 'tag-2']]],
        ],
      ]]);

      $created->assertCreated();
      $location = (string) $created->headers->get('Location');
      $links = $created->json('data.relationships');

      $this->assertSame($created->json('data.links.self'), $location);

      // 2. Lo lee por el `Location`, pidiendo el autor y las etiquetas.
      // 2. Reads it through the `Location`, asking for the author and the tags.
      $article = $this->jsonApi('GET', $location . '?include=author,tags');

      $this->assertSame('Journey', $article->json('data.attributes.title'));
      $this->assertSame(['authors:3', 'labels:tag-1', 'labels:tag-2'], array_map(fn(array $r) => $r['type'] . ':' . $r['id'], $article->json('included')));

      // 3. Lee la relación de etiquetas por el link que dio la respuesta.
      // 3. Reads the tags relationship through the link the response gave.
      $this->assertSame(['tag-1', 'tag-2'], $this->ids($this->jsonApi('GET', $links['tags']['links']['self'])->json('data')));

      // 4. Agrega una etiqueta, quita otra y por último reemplaza el conjunto.
      // 4. Adds a tag, removes another and finally replaces the set.
      $tags = $links['tags']['links']['self'];

      $this->assertSame(['tag-1', 'tag-2', 'tag-4'], $this->ids($this->jsonApi('POST', $tags, ['data' => [['type' => 'labels', 'id' => 'tag-4']]])->json('data')));
      $this->assertSame(['tag-2', 'tag-4'], $this->ids($this->jsonApi('DELETE', $tags, ['data' => [['type' => 'labels', 'id' => 'tag-1']]])->json('data')));
      $this->assertSame(['tag-5'], $this->ids($this->jsonApi('PATCH', $tags, ['data' => [['type' => 'labels', 'id' => 'tag-5']]])->json('data')));

      // 5. Vacía el autor por su endpoint de relación.
      // 5. Empties the author through its relationship endpoint.
      $author = $this->jsonApi('PATCH', $links['author']['links']['self'], ['data' => null]);

      $this->assertArrayHasKey('data', (array) $author->json());
      $this->assertNull($author->json('data'));

      // 6. Actualiza el título.
      // 6. Updates the title.
      $id = (string) $created->json('data.id');
      $updated = $this->jsonApi('PATCH', $location, ['data' => ['type' => 'articles', 'id' => $id, 'attributes' => ['title' => 'Renamed']]]);

      $this->assertSame('Renamed', $updated->json('data.attributes.title'));

      // 7. Lo encuentra con filtros y lee lo que sus relaciones apuntan.
      // 7. Finds it with filters and reads what its relationships point at.
      $found = $this->jsonApi('GET', 'api/articles?filter[title]=Renamed&filter[status]=published');

      $this->assertSame([$id], array_column($found->json('data'), 'id'));
      $this->assertSame(1, $found->json('meta.page.total'));
      $this->assertNull($this->jsonApi('GET', $links['author']['links']['related'])->json('data'));
      $this->assertSame(['tag-5'], array_column($this->jsonApi('GET', $links['tags']['links']['related'])->json('data'), 'id'));

      // 8. Lo borra: 204, y ya no está para nadie.
      // 8. Deletes it: 204, and it is gone for everybody.
      $this->jsonApi('DELETE', $location)->assertNoContent();

      $this->jsonApi('GET', $location)->assertNotFound();
      $this->jsonApi('GET', $links['tags']['links']['self'])->assertNotFound();
      $this->assertSame([], $this->jsonApi('GET', 'api/articles?filter[title]=Renamed')->json('data'));
    }
  }
