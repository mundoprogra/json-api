<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\ExpectationFailedException;
  use PHPUnit\Framework\TestCase;

  /**
   * Fija lo que el schema oficial (JSON:API 1.0) acepta y rechaza, para que el helper assertJsonApiDocument() sea predecible
   * y para detectar si una actualización del schema o de la librería cambia su comportamiento. Ver tests/Fixtures/schema/NOTICE.md.
   * Pins what the official schema (JSON:API 1.0) accepts and rejects, so assertJsonApiDocument() is predictable
   * and any change in the schema or the library is noticed. See tests/Fixtures/schema/NOTICE.md.
   */
  class SchemaCoverageTest extends TestCase {
    use AssertsJsonApi;

    private const URL = 'https://example.com';

    // ---------------------------------------------------------------------------------------------
    // Lo que el schema acepta / What the schema accepts
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function accepted(): array {
      $u = self::URL;

      return [
        'a document with only meta' => ['{"meta":{}}'],
        'data null' => ['{"data":null}'],
        'data as an empty list' => ['{"data":[]}'],
        'a resource with attributes' => ['{"data":{"type":"users","id":"1","attributes":{"name":"Ada"}}}'],
        'an attribute with a nested object' => ['{"data":{"type":"a","id":"1","attributes":{"address":{"streetName":"Main"}}}}'],
        // En 1.1 estos nombres de atributo son válidos (la 1.0 los reservaba).
        // In 1.1 these attribute names are valid (1.0 reserved them).
        'an attribute named relationships' => ['{"data":{"type":"users","id":"1","attributes":{"relationships":{}}}}'],
        'an attribute named links' => ['{"data":{"type":"users","id":"1","attributes":{"links":{}}}}'],
        'a to-one relationship' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"data":{"type":"people","id":"9"}}}}}'],
        'a to-many relationship' => ['{"data":{"type":"a","id":"1","relationships":{"tags":{"data":[{"type":"tags","id":"1"}]}}}}'],
        'a relationship with data null' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"data":null}}}}'],
        'a relationship with only links' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"links":{"related":"' . $u . '/a/1/author"}}}}}'],
        'a relationship with self and related links' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"links":{"self":"' . $u . '/a/1/relationships/author","related":"' . $u . '/a/1/author"},"data":null}}}}'],
        'an included resource' => ['{"data":{"type":"a","id":"1"},"included":[{"type":"people","id":"9","attributes":{"name":"Ada"}}]}'],
        'absolute links as text, with null' => ['{"links":{"self":"' . $u . '/x","next":null},"data":null}'],
        'pagination links' => ['{"links":{"first":"' . $u . '/a?page=1","prev":null,"next":"' . $u . '/a?page=3","last":"' . $u . '/a?page=9"},"data":[]}'],
        'a resource link' => ['{"data":{"type":"users","id":"1","links":{"self":"' . $u . '/users/1"}}}'],
        'a link as an object with href' => ['{"links":{"self":{"href":"' . $u . '/x"}},"data":null}'],
        'a link object with meta' => ['{"links":{"self":{"href":"' . $u . '/x","meta":{}}},"data":null}'],
        // El objeto de enlace del schema es permisivo: admite los miembros nuevos de 1.1 (rel, describedby, title, type, hreflang).
        // The schema link object is permissive: it admits the new 1.1 members (rel, describedby, title, type, hreflang).
        'a link object with the 1.1 members' => ['{"links":{"self":{"href":"' . $u . '/x","rel":"self","describedby":"' . $u . '/d","title":"t","type":"text/html","hreflang":"es"}},"data":null}'],
        'the jsonapi member with version 1.1' => ['{"jsonapi":{"version":"1.1"},"data":null}'],
        'a valid error' => ['{"errors":[{"status":"422","title":"x","detail":"y","code":"c","source":{"pointer":"/data"}}]}'],
        'an error with id and meta' => ['{"errors":[{"id":"01J","status":"500","meta":{"k":1}}]}'],
        'an error with source.parameter' => ['{"errors":[{"status":"400","source":{"parameter":"page[size]"}}]}'],
        // source.header es de 1.1 y lo necesitan los errores 415 y 406 del paquete.
        // source.header is a 1.1 member and the package 415 and 406 errors need it.
        'an error with source.header' => ['{"errors":[{"status":"406","source":{"header":"Accept"}}]}'],
        'an error with an about link as text' => ['{"errors":[{"links":{"about":"' . $u . '/e"}}]}'],
        'an error with an about link as an object' => ['{"errors":[{"links":{"about":{"href":"' . $u . '/e"}}}]}'],
      ];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function accepts_the_documents_the_package_is_expected_to_produce(string $json): void {
      $this->assertJsonApiDocument($json);
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que el schema rechaza correctamente / What the schema rightly rejects
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function rightlyRejected(): array {
      return [
        'an empty document' => ['{}'],
        'data and errors together' => ['{"data":null,"errors":[{"status":"500"}]}'],
        'an unknown top-level member' => ['{"data":null,"foo":1}'],
        'included without data' => ['{"meta":{},"included":[]}'],
        'a resource without type' => ['{"data":{"id":"1"}}'],
        'a resource without id' => ['{"data":{"type":"users"}}'],
        'a numeric id' => ['{"data":{"type":"users","id":1}}'],
        'an attribute named id' => ['{"data":{"type":"users","id":"1","attributes":{"id":"x"}}}'],
        'an attribute named type' => ['{"data":{"type":"users","id":"1","attributes":{"type":"x"}}}'],
        'attributes as a list' => ['{"data":{"type":"users","id":"1","attributes":[]}}'],
        'an unknown resource member' => ['{"data":{"type":"users","id":"1","foo":1}}'],
        'an empty relationship object' => ['{"data":{"type":"a","id":"1","relationships":{"author":{}}}}'],
        'a linkage without id' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"data":{"type":"people"}}}}}'],
        'a relationship named id' => ['{"data":{"type":"a","id":"1","relationships":{"id":{"data":null}}}}'],
        'a numeric error status' => ['{"errors":[{"status":422}]}'],
        'an unknown error member' => ['{"errors":[{"foo":1}]}'],
        'a pointer that is not text' => ['{"errors":[{"source":{"pointer":1}}]}'],
        // El schema exige URI absoluta: una dirección relativa se rechaza.
        // The schema requires an absolute URI: a relative address is rejected.
        'a relative link' => ['{"links":{"self":"/x"},"data":null}'],
      ];
    }

    #[Test]
    #[DataProvider('rightlyRejected')]
    public function rejects_documents_that_break_the_specification(string $json): void {
      $this->assertSchemaRejects($json);
    }

    // ---------------------------------------------------------------------------------------------
    // Limitación conocida: el schema rechaza miembros válidos en 1.1 / Known limitation: the schema rejects members that are valid in 1.1
    // ---------------------------------------------------------------------------------------------

    /**
     * Si alguno de estos casos empieza a aceptarse, es una buena noticia: actualiza NOTICE.md y mueve el caso a accepted().
     * If any of these starts being accepted, that is good news: update NOTICE.md and move the case to accepted().
     *
     * @return array<string, array{string}>
     */
    public static function validIn11ButRejectedBySchema(): array {
      return [
        'a resource with lid' => ['{"data":{"type":"users","lid":"tmp-1"}}'],
        'a resource with id and lid' => ['{"data":{"type":"users","id":"1","lid":"tmp-1"}}'],
        'a linkage with lid' => ['{"data":{"type":"a","id":"1","relationships":{"author":{"data":{"type":"people","lid":"tmp-1"}}}}}'],
        'ext and profile in the jsonapi member' => ['{"jsonapi":{"version":"1.1","ext":["https://x.test"],"profile":["https://y.test"]},"data":null}'],
        'a describedby top-level link' => ['{"links":{"describedby":"https://example.com/docs"},"data":null}'],
        'a type link in an error' => ['{"errors":[{"links":{"type":["https://x.test/t"]}}]}'],
        'an extension member at the top level' => ['{"data":null,"atomic:results":[]}'],
        'an extension member in a resource' => ['{"data":{"type":"users","id":"1","ext:foo":1}}'],
      ];
    }

    #[Test]
    #[DataProvider('validIn11ButRejectedBySchema')]
    public function known_limitation_the_schema_rejects_members_that_are_valid_in_1_1(string $json): void {
      $this->assertSchemaRejects($json);
    }

    // ---------------------------------------------------------------------------------------------
    // Huecos: el schema acepta lo que la spec no permite / Gaps: the schema accepts what the spec does not allow
    // ---------------------------------------------------------------------------------------------

    /**
     * Estos casos NO los detecta el schema; se cubren con comprobaciones propias en las fases que los generan.
     * The schema does NOT catch these; they are covered by own checks in the phases that generate them.
     *
     * @return array<string, array{string}>
     */
    public static function invalidButAcceptedBySchema(): array {
      return [
        'any jsonapi version' => ['{"jsonapi":{"version":"9.9"},"data":null}'],
        // Fase 6: un atributo y una relación no pueden compartir nombre (la spec 1.1, sección Fields).
        // Phase 6: an attribute and a relationship cannot share a name (spec 1.1, Fields section).
        'an attribute and a relationship with the same name' => ['{"data":{"type":"a","id":"1","attributes":{"author":"x"},"relationships":{"author":{"data":null}}}}'],
      ];
    }

    #[Test]
    #[DataProvider('invalidButAcceptedBySchema')]
    public function known_gap_the_schema_accepts_these_even_though_the_spec_does_not_allow_them(string $json): void {
      $this->assertJsonApiDocument($json);
    }

    // ---------------------------------------------------------------------------------------------
    // Observado al escribir estas pruebas / Observed while writing these tests: `included`
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function observed_included_with_two_identical_objects_is_rejected(): void {
      $this->assertSchemaRejects(
        '{"data":{"type":"a","id":"1"},"included":[{"type":"people","id":"9"},{"type":"people","id":"9"}]}'
      );
    }

    #[Test]
    public function observed_included_with_the_same_type_and_id_but_different_content_is_accepted(): void {
      // La spec pide que no se repita el mismo tipo e id en included; el schema solo compara objetos completos.
      // The spec requires that the same type and id is not repeated in included; the schema only compares whole objects.
      $this->assertJsonApiDocument(
        '{"data":{"type":"a","id":"1"},"included":[{"type":"people","id":"9"},{"type":"people","id":"9","attributes":{"name":"Ada"}}]}'
      );
    }

    private function assertSchemaRejects(string $json): void {
      try {
        $this->assertJsonApiDocument($json);
      } catch (ExpectationFailedException) {
        $this->addToAssertionCount(1);

        return;
      }

      $this->fail("El schema debía rechazar este documento / The schema was expected to reject this document: $json");
    }
  }
