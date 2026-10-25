<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  /**
   * Comprobaciones de un documento compuesto (con `included`): que ningún recurso incluido se repite ni es uno de los principales, y el *full linkage*
   * de la spec, es decir, que todo recurso incluido se alcanza por el linkage de otro recurso del documento. Sirve para un recurso y para una colección.
   * Checks of a compound document (with `included`): that no included resource repeats or is one of the primary ones, and the *full linkage* of the
   * spec, that is, that every included resource is reachable through the linkage of another resource in the document. It serves a resource and a collection.
   */
  trait AssertsCompoundDocuments {
    /**
     * Los recursos incluidos como `tipo:id`, en el orden del documento.
     * The included resources as `type:id`, in the document order.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function included(array $document): array {
      return array_map(fn(array $resource) => $resource['type'] . ':' . $resource['id'], $document['included'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function assertCompound(array $document): void {
      $primary = array_is_list((array) $document['data']) ? (array) $document['data'] : [$document['data']];
      $primaryKeys = array_map(fn(array $resource) => $resource['type'] . ':' . $resource['id'], $primary);

      $linked = [];

      foreach (array_merge($primary, $document['included'] ?? []) as $resource) {
        foreach ($resource['relationships'] ?? [] as $relationship) {
          $data = $relationship['data'] ?? null;

          foreach ($data === null ? [] : (array_is_list($data) ? $data : [$data]) as $identifier) {
            $linked[$identifier['type'] . ':' . $identifier['id']] = true;
          }
        }
      }

      $included = $this->included($document);

      $this->assertSame($included, array_values(array_unique($included)), 'An included resource must appear once.');
      $this->assertSame([], array_values(array_intersect($primaryKeys, $included)), 'A primary resource must not be included.');

      foreach ($included as $key) {
        $this->assertArrayHasKey($key, $linked, "$key is included but no resource links to it.");
      }
    }
  }
