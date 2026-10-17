<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use LogicException;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Los nombres de los atributos. Quien escribe el recurso los pone en snake_case (como en Eloquent) y el paquete los convierte al estilo
   * de `member_case` (camel por defecto), solo en el primer nivel. La spec prohíbe llamar `id` o `type` a un atributo, y que un nombre
   * quede repetido: esos son errores del programador y se avisan con un LogicException claro en lugar de mandar un documento inválido.
   * The attribute names. Whoever writes the resource puts them in snake_case (as in Eloquent) and the package converts them to the
   * `member_case` style (camel by default), first level only. The spec forbids naming an attribute `id` or `type`, and repeating a name:
   * those are developer errors and are reported with a clear LogicException instead of sending an invalid document.
   */
  class ResourceMemberNamesTest extends TestCase {
    /**
     * Un recurso cuyos atributos son los indicados.
     * A resource whose attributes are the given ones.
     *
     * @param  array<int|string, mixed>  $attributes
     */
    private function resourceWith(array $attributes): ResourceObject {
      $author = (new Author())->forceFill(['id' => 7]);

      return new class($author, $attributes) extends ResourceObject {
        /**
         * @param  array<int|string, mixed>  $attributesToShow
         */
        public function __construct(mixed $resource, private readonly array $attributesToShow) {
          parent::__construct($resource);
        }

        protected function jsonApiAttributes(Request $request): array {
          return $this->attributesToShow;
        }
      };
    }

    /**
     * @param  array<int|string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributesOf(array $attributes): array {
      return $this->resourceWith($attributes)->resolve(Request::create('/api/authors'))['attributes'];
    }

    // ---------------------------------------------------------------------------------------------
    // member_case
    // ---------------------------------------------------------------------------------------------

    private const NAMES = ['first_name' => 'Ada', 'home_address_line' => 'Main', 'name' => 'x', 'address_2' => 'y'];

    #[Test]
    public function camel_is_the_default_style(): void {
      $this->assertSame(
        ['firstName' => 'Ada', 'homeAddressLine' => 'Main', 'name' => 'x', 'address2' => 'y'],
        $this->attributesOf(self::NAMES),
      );
    }

    #[Test]
    public function kebab_style(): void {
      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(
        ['first-name' => 'Ada', 'home-address-line' => 'Main', 'name' => 'x', 'address-2' => 'y'],
        $this->attributesOf(self::NAMES),
      );
    }

    #[Test]
    public function snake_style_leaves_the_names_as_they_are(): void {
      config()->set('json-api.member_case', 'snake');

      $this->assertSame(self::NAMES, $this->attributesOf(self::NAMES));
    }

    #[Test]
    public function a_name_already_written_in_the_client_style_is_left_as_it_is(): void {
      $this->assertSame(['firstName' => 'Ada'], $this->attributesOf(['firstName' => 'Ada']));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function styles(): array {
      return [
        'camel' => ['camel', ['homeAddress', 'tagList']],
        'kebab' => ['kebab', ['home-address', 'tag-list']],
        'snake' => ['snake', ['home_address', 'tag_list']],
      ];
    }

    /**
     * @param  list<string>  $firstLevelNames
     */
    #[Test]
    #[DataProvider('styles')]
    public function only_the_first_level_is_converted(string $style, array $firstLevelNames): void {
      config()->set('json-api.member_case', $style);

      $attributes = $this->attributesOf(['home_address' => ['street_name' => 'Main', 'geo_point' => ['lat_deg' => 1]], 'tag_list' => ['a_b']]);

      // El primer nivel sí se convierte (sin esto, "lo anidado no cambia" podría ser solo que nada cambia).
      // The first level is converted (without this, "the nested part does not change" could just mean nothing changes).
      $this->assertSame($firstLevelNames, array_keys($attributes));
      $this->assertSame(['street_name' => 'Main', 'geo_point' => ['lat_deg' => 1]], $attributes[$firstLevelNames[0]]);
      $this->assertSame(['a_b'], $attributes[$firstLevelNames[1]]);
    }

    #[Test]
    public function the_style_is_read_on_every_call(): void {
      $resource = $this->resourceWith(['first_name' => 'Ada']);
      $request = Request::create('/api/authors');

      $this->assertSame(['firstName' => 'Ada'], $resource->resolve($request)['attributes']);

      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(['first-name' => 'Ada'], $resource->resolve($request)['attributes']);
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres prohibidos / Forbidden names
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNames(): array {
      return ['id' => ['id'], 'type' => ['type']];
    }

    #[Test]
    #[DataProvider('reservedNames')]
    public function an_attribute_cannot_be_named_id_or_type(string $name): void {
      // La spec los reserva en el objeto de recurso: aparte del error, ningún documento válido puede llevarlos como atributo.
      // The spec reserves them in the resource object: besides the error, no valid document can carry them as an attribute.
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/"' . $name . '"/');
      $this->expectExceptionMessageMatches('/attribute/i');

      $this->resourceWith([$name => 'x', 'other' => 'y'])->resolve(Request::create('/api/authors'));
    }

    #[Test]
    public function a_name_that_only_contains_them_is_fine(): void {
      $this->assertSame(['identifier' => 1, 'typeName' => 'a', 'userId' => 2], $this->attributesOf(['identifier' => 1, 'type_name' => 'a', 'user_id' => 2]));
    }

    #[Test]
    public function the_reserved_names_are_checked_in_every_style(): void {
      foreach (['camel', 'kebab', 'snake'] as $style) {
        config()->set('json-api.member_case', $style);

        try {
          $this->resourceWith(['type' => 'x'])->resolve(Request::create('/api/authors'));
          $this->fail("No error in $style style.");
        } catch (LogicException $e) {
          $this->assertStringContainsString('"type"', $e->getMessage());
        }
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Nombres repetidos / Repeated names
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function two_attributes_that_end_up_with_the_same_name_are_an_error(): void {
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/first_name/');
      $this->expectExceptionMessageMatches('/firstName/');

      $this->attributesOf(['first_name' => 'Ada', 'firstName' => 'Lovelace']);
    }

    #[Test]
    public function the_same_two_names_are_both_kept_when_the_style_keeps_them_apart(): void {
      config()->set('json-api.member_case', 'snake');

      $this->assertSame(['first_name' => 'Ada', 'firstName' => 'Lovelace'], $this->attributesOf(['first_name' => 'Ada', 'firstName' => 'Lovelace']));
    }

    // ---------------------------------------------------------------------------------------------
    // Atributos sin nombre / Attributes without a name
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_list_is_not_a_valid_set_of_attributes(): void {
      // `attributes` es un objeto: una lista de valores sin nombre no se puede mandar.
      // `attributes` is an object: a list of values without a name cannot be sent.
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/attribute/i');

      $this->attributesOf(['Ada', 'Lovelace']);
    }
  }
