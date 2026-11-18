<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Pagination\LengthAwarePaginator;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\PlainAuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\PlainTagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * La paginación de una colección cuyos elementos NO son modelos de Eloquent sino objetos simples. `Pagination` solo lee el paginador
   * (página, tamaño, total) y la petición, nunca los elementos, así que un `LengthAwarePaginator` de objetos simples (lo que arma el
   * repositorio de una app con Clean Architecture a partir de su consulta) da los mismos `links` y `meta.page` que uno de modelos. Esta
   * prueba no cambia código: fija que ya funciona y que no se rompa.
   * The pagination of a collection whose elements are NOT Eloquent models but plain objects. `Pagination` only reads the paginator (page,
   * size, total) and the request, never the elements, so a `LengthAwarePaginator` of plain objects (what the repository of a Clean
   * Architecture app builds from its query) gives the same `links` and `meta.page` as one of models. This test changes no code: it pins
   * that it already works and keeps it from breaking.
   */
  class PlainObjectPaginationTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param  list<int>  $ids
     * @return list<object>
     */
    private function plainAuthors(array $ids): array {
      return array_map(fn(int $id) => (object) ['id' => $id, 'name' => "Author $id"], $ids);
    }

    protected function defineRoutes($router): void {
      // La segunda página de 7 autores de 2 en 2: trae el 3 y el 4. Los autores son objetos simples, no modelos.
      // The second page of 7 authors, 2 at a time: it carries the 3rd and the 4th. The authors are plain objects, not models.
      $router->get('api/plain-authors', fn() => PlainAuthorResource::collection(new LengthAwarePaginator($this->plainAuthors([3, 4]), 7, 2, 2)));
      $router->get('api/plain-authors-empty', fn() => PlainAuthorResource::collection(new LengthAwarePaginator([], 0, 2, 1)));
      $router->get('api/plain-tags', fn() => PlainTagResource::collection(collect([(object) ['id' => 1, 'name' => 'PHP']])));
    }

    #[Test]
    public function a_paginator_of_plain_objects_gives_the_page_in_data_and_the_links_and_meta(): void {
      $response = $this->get('api/plain-authors?page[number]=2&page[size]=2', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(['3', '4'], array_column($response->json('data'), 'id'));
      $this->assertSame(['page' => ['currentPage' => 2, 'perPage' => 2, 'lastPage' => 4, 'total' => 7]], $response->json('meta'));
      $this->assertSame([
        'first' => 'http://localhost/api/plain-authors?page%5Bnumber%5D=1&page%5Bsize%5D=2',
        'last' => 'http://localhost/api/plain-authors?page%5Bnumber%5D=4&page%5Bsize%5D=2',
        'prev' => 'http://localhost/api/plain-authors?page%5Bnumber%5D=1&page%5Bsize%5D=2',
        'next' => 'http://localhost/api/plain-authors?page%5Bnumber%5D=3&page%5Bsize%5D=2',
      ], $response->json('links'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function an_empty_paginator_of_plain_objects_is_an_empty_list_with_its_meta(): void {
      $response = $this->get('api/plain-authors-empty?page[size]=2', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame([], $response->json('data'));
      $this->assertSame(0, $response->json('meta.page.total'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_collection_of_plain_objects_without_a_paginator_carries_neither_links_nor_meta(): void {
      $response = $this->get('api/plain-tags', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
    }
  }
