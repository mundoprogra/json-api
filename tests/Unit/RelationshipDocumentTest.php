<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  /**
   * `RelationshipDocument` lee el cuerpo de una petición a un endpoint de relación (`/articles/1/relationships/tags`). Ahí el documento no es un
   * recurso sino su linkage: `{"data": null}`, `{"data": {"type", "id"}}` o `{"data": [{"type", "id"}, ...]}`. Entrega `data()` y `isList()`.
   * Cada identificador sale solo con `type` e `id`. Los errores de un linkage mal formado se prueban en `RelationshipLinkageErrorsTest`.
   * `appliesTo()` dice si la URL de una petición es la de un endpoint de relación: el penúltimo segmento es `relationships`.
   *
   * `RelationshipDocument` reads the body of a request to a relationship endpoint (`/articles/1/relationships/tags`). There the document is not a
   * resource but its linkage: `{"data": null}`, `{"data": {"type", "id"}}` or `{"data": [{"type", "id"}, ...]}`. It gives `data()` and `isList()`.
   * Each identifier comes out with only `type` and `id`. The errors of a malformed linkage are tested in `RelationshipLinkageErrorsTest`.
   * `appliesTo()` tells whether the URL of a request is a relationship endpoint: the second-to-last segment is `relationships`.
   */
  class RelationshipDocumentTest extends TestCase {
    private function document(string $json): RelationshipDocument {
      return RelationshipDocument::from(Request::create('/api/articles/1/relationships/tags', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/vnd.api+json'], $json));
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function data_null_empties_a_to_one_relationship(): void {
      $document = $this->document('{"data":null}');

      $this->assertNull($document->data());
      $this->assertFalse($document->isList());
    }

    #[Test]
    public function an_identifier_is_a_to_one_linkage(): void {
      $document = $this->document('{"data":{"type":"people","id":"9"}}');

      $this->assertSame(['type' => 'people', 'id' => '9'], $document->data());
      $this->assertFalse($document->isList());
    }

    #[Test]
    public function a_list_of_identifiers_is_a_to_many_linkage_in_the_order_the_client_used(): void {
      $document = $this->document('{"data":[{"type":"tags","id":"3"},{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}');

      $this->assertSame(
        [['type' => 'tags', 'id' => '3'], ['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']],
        $document->data(),
      );
      $this->assertTrue($document->isList());
    }

    #[Test]
    public function an_empty_list_empties_a_to_many_relationship(): void {
      $document = $this->document('{"data":[]}');

      $this->assertSame([], $document->data());
      $this->assertTrue($document->isList());
    }

    #[Test]
    public function an_identifier_comes_out_with_only_type_and_id(): void {
      $document = $this->document('{"data":{"type":"people","id":"9","meta":{"role":"editor"},"attributes":{"name":"Ana"}}}');

      $this->assertSame(['type' => 'people', 'id' => '9'], $document->data());
    }

    #[Test]
    public function the_identifiers_of_a_list_also_come_out_with_only_type_and_id(): void {
      $document = $this->document('{"data":[{"type":"tags","id":"1","meta":{"a":1}}]}');

      $this->assertSame([['type' => 'tags', 'id' => '1']], $document->data());
    }

    #[Test]
    public function other_top_level_members_are_ignored(): void {
      $document = $this->document('{"jsonapi":{"version":"1.1"},"meta":{"a":1},"links":{"self":"x"},"data":{"type":"people","id":"9"}}');

      $this->assertSame(['type' => 'people', 'id' => '9'], $document->data());
    }

    #[Test]
    public function the_type_and_the_id_are_kept_exactly_as_sent(): void {
      $this->assertSame(['type' => 'blogPosts', 'id' => 'A-1_b'], $this->document('{"data":{"type":"blogPosts","id":"A-1_b"}}')->data());
    }

    // ---------------------------------------------------------------------------------------------
    // La URL del endpoint / The endpoint URL
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function urls(): array {
      // [ruta, ¿es un endpoint de relación?] / [path, is it a relationship endpoint?]
      return [
        'a relationship endpoint' => ['/api/articles/1/relationships/tags', true],
        'with a trailing slash' => ['/api/articles/1/relationships/tags/', true],
        'with a query string' => ['/api/articles/1/relationships/tags?include=tags', true],
        'without a prefix' => ['/relationships/tags', true],
        'a deeper prefix' => ['/api/v1/blog/articles/1/relationships/author', true],
        'a kebab-case name' => ['/api/articles/1/relationships/blog-posts', true],
        'the related resource endpoint' => ['/api/articles/1/tags', false],
        'relationships as the last segment' => ['/api/articles/1/relationships', false],
        'a segment after the relationship name' => ['/api/articles/1/relationships/tags/extra', false],
        'relationships is not the second-to-last' => ['/api/relationships/1/tags', false],
        'the segment is case sensitive' => ['/api/articles/1/Relationships/tags', false],
        'a resource called like a relationship' => ['/api/relationships', false],
        'the root' => ['/', false],
        'a plain resource' => ['/api/articles/1', false],
      ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function it_knows_whether_a_url_is_a_relationship_endpoint(string $path, bool $expected): void {
      $this->assertSame($expected, RelationshipDocument::appliesTo(Request::create($path, 'PATCH')));
    }

    #[Test]
    public function the_method_does_not_matter_to_tell_the_endpoint(): void {
      foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
        $this->assertTrue(RelationshipDocument::appliesTo(Request::create('/api/articles/1/relationships/tags', $method)), $method);
      }
    }
  }
