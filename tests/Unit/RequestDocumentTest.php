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
    /**
     * @param  array<string, mixed>  $config  valores de `json-api` que cambian los de fábrica / `json-api` values that change the defaults
     */
    private function document(string $json, string $memberCase = 'camel', string $method = 'POST', array $config = []): RequestDocument {
      $request = Request::create('/api/articles', $method, [], [], [], ['CONTENT_TYPE' => 'application/vnd.api+json'], $json);

      return RequestDocument::from($request, $this->settings($memberCase, $config));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function settings(string $memberCase = 'camel', array $overrides = []): Settings {
      $config = ['json-api' => require __DIR__ . '/../../config/json-api.php', 'app' => ['debug' => false]];
      Arr::set($config, 'json-api.member_case', $memberCase);

      foreach ($overrides as $key => $value) {
        Arr::set($config, "json-api.$key", $value);
      }

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
      $this->assertSame('42', $this->document('{"data":{"type":"articles","id":"42"}}', method: 'PATCH')->id());
    }

    #[Test]
    public function the_type_and_the_id_are_kept_exactly_as_sent(): void {
      $document = $this->document('{"data":{"type":"blogPosts","id":"A-1_b"}}', method: 'PATCH');

      $this->assertSame('blogPosts', $document->type());
      $this->assertSame('A-1_b', $document->id());
    }

    // ---------------------------------------------------------------------------------------------
    // El id según el método / The id by method
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_post_with_a_client_id_is_accepted_when_the_configuration_allows_it(): void {
      $document = $this->document('{"data":{"type":"articles","id":"abc-1"}}', config: ['allow_client_ids' => true]);

      $this->assertSame('abc-1', $document->id());
    }

    #[Test]
    public function a_put_gives_the_id_like_a_patch(): void {
      $this->assertSame('42', $this->document('{"data":{"type":"articles","id":"42"}}', method: 'PUT')->id());
    }

    #[Test]
    public function the_id_does_not_depend_on_allow_client_ids_when_updating(): void {
      foreach (['PATCH', 'PUT'] as $method) {
        foreach ([true, false] as $allowed) {
          $document = $this->document('{"data":{"type":"articles","id":"42"}}', method: $method, config: ['allow_client_ids' => $allowed]);

          $this->assertSame('42', $document->id(), "$method / allow_client_ids = " . var_export($allowed, true));
        }
      }
    }

    #[Test]
    public function a_method_that_neither_creates_nor_updates_has_no_rule_about_the_id(): void {
      // Sin `id` y con `id`, con `allow_client_ids` apagado: ninguna de las dos es un problema fuera de POST, PATCH y PUT.
      // Without an `id` and with an `id`, with `allow_client_ids` off: neither is a problem outside POST, PATCH and PUT.
      $this->assertNull($this->document('{"data":{"type":"articles"}}', method: 'DELETE')->id());
      $this->assertSame('1', $this->document('{"data":{"type":"articles","id":"1"}}', method: 'DELETE')->id());
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
    // Linkage de las relaciones / Relationship linkage
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function validRelationships(): array {
      // [objeto de la relación tal como llega, cómo lo entrega relationships()] / [relationship object as it arrives, how relationships() gives it]
      return [
        'data null (clear a to-one)' => ['{"data":null}', ['data' => null]],
        'data an identifier (a to-one)' => ['{"data":{"type":"people","id":"9"}}', ['data' => ['type' => 'people', 'id' => '9']]],
        'data a list of identifiers (a to-many)' => [
          '{"data":[{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}',
          ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']]],
        ],
        'data an empty list (clear a to-many)' => ['{"data":[]}', ['data' => []]],
        'identifiers keep their meta' => [
          '{"data":{"type":"people","id":"9","meta":{"role":"editor"}}}',
          ['data' => ['type' => 'people', 'id' => '9', 'meta' => ['role' => 'editor']]],
        ],
        'only links' => ['{"links":{"related":"/people/9"}}', ['links' => ['related' => '/people/9']]],
        'only meta' => ['{"meta":{"a":1}}', ['meta' => ['a' => 1]]],
        'links, data and meta' => [
          '{"links":{"self":"/x"},"data":null,"meta":{"a":1}}',
          ['links' => ['self' => '/x'], 'data' => null, 'meta' => ['a' => 1]],
        ],
      ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[Test]
    #[DataProvider('validRelationships')]
    public function a_relationship_with_a_valid_linkage_is_given_as_the_client_sent_it(string $relationship, array $expected): void {
      $document = $this->document('{"data":{"type":"articles","relationships":{"author":' . $relationship . '}}}');

      $this->assertSame(['author' => $expected], $document->relationships());
    }

    #[Test]
    public function the_order_of_the_identifiers_of_a_to_many_relationship_is_kept(): void {
      $document = $this->document('{"data":{"type":"a","relationships":{"tags":{"data":[{"type":"tags","id":"3"},{"type":"tags","id":"1"},{"type":"tags","id":"2"}]}}}}');

      $this->assertSame(['3', '1', '2'], array_column($document->relationships()['tags']['data'], 'id'));
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
