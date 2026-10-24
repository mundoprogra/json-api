<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Http\Requests\RequestDocument;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  /**
   * `RequestDocument` lee el cuerpo de una petición JSON:API (`POST` o `PATCH` de un recurso) y lo entrega como una estructura tipada e inmutable:
   * `type`, `id`, `attributes` y `relationships`. Los nombres del primer nivel de `attributes` y de `relationships` pasan del estilo de
   * `member_case` a snake_case; los valores y lo anidado no se tocan. Este punto (9.1) cubre la forma del documento; el linkage de cada relación
   * es del 9.2 y las reglas de `id` y el JSON mal formado, del 9.3. Los errores de un documento mal formado se prueban en
   * `RequestDocumentErrorsTest`.
   *
   * `RequestDocument` reads the body of a JSON:API request (a `POST` or `PATCH` of a resource) and gives it back as a typed, immutable structure:
   * `type`, `id`, `attributes` and `relationships`. The first-level names of `attributes` and `relationships` go from the `member_case` style to
   * snake_case; the values and anything nested are not touched. This point (9.1) covers the shape of the document; the linkage of each
   * relationship is 9.2 and the `id` rules and malformed JSON are 9.3. The errors of a malformed document are tested in
   * `RequestDocumentErrorsTest`.
   */
  class RequestDocumentTest extends TestCase {
    private function document(string $json, string $memberCase = 'camel'): RequestDocument {
      $request = Request::create('/api/articles', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/vnd.api+json'], $json);

      return RequestDocument::from($request, $this->settings($memberCase));
    }

    private function settings(string $memberCase = 'camel'): Settings {
      $config = ['json-api' => require __DIR__ . '/../../config/json-api.php', 'app' => ['debug' => false]];
      Arr::set($config, 'json-api.member_case', $memberCase);

      return new Settings(new Repository($config));
    }

    // ---------------------------------------------------------------------------------------------
    // type e id / type and id
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_gives_the_type_of_the_resource(): void {
      $this->assertSame('articles', $this->document('{"data":{"type":"articles"}}')->type());
    }

    #[Test]
    public function the_id_is_null_when_the_client_did_not_send_one(): void {
      $this->assertNull($this->document('{"data":{"type":"articles"}}')->id());
    }

    #[Test]
    public function it_gives_the_id_as_text(): void {
      $this->assertSame('42', $this->document('{"data":{"type":"articles","id":"42"}}')->id());
    }

    #[Test]
    public function the_type_and_the_id_are_kept_exactly_as_sent(): void {
      $document = $this->document('{"data":{"type":"blogPosts","id":"A-1_b"}}');

      $this->assertSame('blogPosts', $document->type());
      $this->assertSame('A-1_b', $document->id());
    }

    // ---------------------------------------------------------------------------------------------
    // attributes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_gives_the_attributes(): void {
      $document = $this->document('{"data":{"type":"articles","attributes":{"title":"Hello","body":"World"}}}');

      $this->assertSame(['title' => 'Hello', 'body' => 'World'], $document->attributes());
    }

    #[Test]
    public function without_attributes_or_with_an_empty_object_there_are_none(): void {
      $this->assertSame([], $this->document('{"data":{"type":"articles"}}')->attributes());
      $this->assertSame([], $this->document('{"data":{"type":"articles","attributes":{}}}')->attributes());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function attributeStyles(): array {
      return [
        'camel' => ['camel', '{"data":{"type":"a","attributes":{"homeAddress":"x","createdAt":"y"}}}'],
        'kebab' => ['kebab', '{"data":{"type":"a","attributes":{"home-address":"x","created-at":"y"}}}'],
        'snake' => ['snake', '{"data":{"type":"a","attributes":{"home_address":"x","created_at":"y"}}}'],
      ];
    }

    #[Test]
    #[DataProvider('attributeStyles')]
    public function attribute_names_are_converted_to_snake_case(string $style, string $json): void {
      $this->assertSame(['home_address' => 'x', 'created_at' => 'y'], $this->document($json, $style)->attributes());
    }

    #[Test]
    public function only_the_first_level_of_names_is_converted(): void {
      $document = $this->document('{"data":{"type":"a","attributes":{"homeAddress":{"streetName":"Main","zipCode":"1"}}}}');

      $this->assertSame(['home_address' => ['streetName' => 'Main', 'zipCode' => '1']], $document->attributes());
    }

    #[Test]
    public function values_of_any_json_type_are_kept_as_sent(): void {
      $document = $this->document('{"data":{"type":"a","attributes":{"a":null,"b":false,"c":0,"d":"","e":[1,2],"f":{"g":1.5}}}}');

      $this->assertSame(['a' => null, 'b' => false, 'c' => 0, 'd' => '', 'e' => [1, 2], 'f' => ['g' => 1.5]], $document->attributes());
    }

    #[Test]
    public function the_order_of_the_attributes_is_the_one_the_client_used(): void {
      $document = $this->document('{"data":{"type":"a","attributes":{"zeta":1,"alpha":2,"mid":3}}}');

      $this->assertSame(['zeta', 'alpha', 'mid'], array_keys($document->attributes()));
    }

    // ---------------------------------------------------------------------------------------------
    // relationships
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_gives_the_relationships_with_the_object_the_client_sent(): void {
      $document = $this->document('{"data":{"type":"a","relationships":{"author":{"data":{"type":"people","id":"9"}},"editor":{"data":null}}}}');

      $this->assertSame(
        ['author' => ['data' => ['type' => 'people', 'id' => '9']], 'editor' => ['data' => null]],
        $document->relationships(),
      );
    }

    #[Test]
    public function without_relationships_or_with_an_empty_object_there_are_none(): void {
      $this->assertSame([], $this->document('{"data":{"type":"articles"}}')->relationships());
      $this->assertSame([], $this->document('{"data":{"type":"articles","relationships":{}}}')->relationships());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function relationshipStyles(): array {
      return [
        'camel' => ['camel', '{"data":{"type":"a","relationships":{"blogPost":{"data":null}}}}'],
        'kebab' => ['kebab', '{"data":{"type":"a","relationships":{"blog-post":{"data":null}}}}'],
        'snake' => ['snake', '{"data":{"type":"a","relationships":{"blog_post":{"data":null}}}}'],
      ];
    }

    #[Test]
    #[DataProvider('relationshipStyles')]
    public function relationship_names_are_converted_to_snake_case(string $style, string $json): void {
      $this->assertSame(['blog_post' => ['data' => null]], $this->document($json, $style)->relationships());
    }

    #[Test]
    public function attributes_and_relationships_come_apart(): void {
      $document = $this->document('{"data":{"type":"a","attributes":{"title":"T"},"relationships":{"author":{"data":null}}}}');

      $this->assertSame(['title' => 'T'], $document->attributes());
      $this->assertSame(['author' => ['data' => null]], $document->relationships());
    }

    // ---------------------------------------------------------------------------------------------
    // Lo que se ignora / What is ignored
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function other_top_level_members_are_ignored(): void {
      $document = $this->document('{"jsonapi":{"version":"1.1"},"meta":{"a":1},"links":{"self":"x"},"data":{"type":"articles","attributes":{"title":"T"}}}');

      $this->assertSame('articles', $document->type());
      $this->assertSame(['title' => 'T'], $document->attributes());
    }

    #[Test]
    public function other_members_of_the_resource_object_are_ignored(): void {
      $document = $this->document('{"data":{"type":"articles","links":{"self":"x"},"meta":{"a":1},"attributes":{"title":"T"}}}');

      $this->assertSame(['title' => 'T'], $document->attributes());
      $this->assertSame([], $document->relationships());
    }
  }
