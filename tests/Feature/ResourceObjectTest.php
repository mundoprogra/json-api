<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use LogicException;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Un `ResourceObject` produce el objeto de recurso de JSON:API: `type`, `id` (siempre texto) y `attributes`.
   * Quien lo escribe solo declara `jsonApiAttributes()`; el tipo sale de la tabla del modelo y el id de su clave de ruta, y ambos se pueden sobrescribir.
   * Aquí se prueba el objeto de recurso solo: el documento completo y la respuesta llegan en el punto 6.3.
   * A `ResourceObject` produces the JSON:API resource object: `type`, `id` (always text) and `attributes`.
   * Whoever writes it only declares `jsonApiAttributes()`; the type comes from the model table and the id from its route key, and both can be overridden.
   * Only the resource object is tested here: the full document and the response arrive in point 6.3.
   */
  class ResourceObjectTest extends TestCase {
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function author(array $attributes): Author {
      return (new Author())->forceFill($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(ResourceObject $resource): array {
      return $resource->resolve(Request::create('/api/authors'));
    }

    // ---------------------------------------------------------------------------------------------
    // type, id y attributes / type, id and attributes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_produces_the_type_the_id_and_the_attributes_in_that_order(): void {
      $resource = AuthorResource::make($this->author(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']));

      $this->assertSame([
        'type' => 'authors',
        'id' => '7',
        'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com'],
      ], $this->resolve($resource));
    }

    // ---------------------------------------------------------------------------------------------
    // El id / The id
    // ---------------------------------------------------------------------------------------------

    /**
     * El último valor indica si el modelo tiene clave de texto (como uno con uuid, que declara `keyType = 'string'`); si no, Eloquent la convierte a número.
     * The last value says whether the model has a text key (like one with a uuid, which declares `keyType = 'string'`); otherwise Eloquent casts it to a number.
     *
     * @return array<string, array{mixed, string, bool}>
     */
    public static function keys(): array {
      return [
        'an integer' => [7, '7', false],
        'a big integer' => [9007199254740993, '9007199254740993', false],
        'zero' => [0, '0', false],
        'a text' => ['abc', 'abc', true],
        'a uuid' => ['7b0f3f0e-6c1b-4a58-9d63-0c2a0d5f0a11', '7b0f3f0e-6c1b-4a58-9d63-0c2a0d5f0a11', true],
        'a numeric text' => ['42', '42', true],
      ];
    }

    #[Test]
    #[DataProvider('keys')]
    public function the_id_is_always_a_string(mixed $key, string $expected, bool $textKey): void {
      $author = $this->author(['id' => $key, 'name' => 'Ada']);

      if ($textKey) {
        $author = $author->setKeyType('string')->setIncrementing(false)->forceFill(['id' => $key]);
      }

      $data = $this->resolve(AuthorResource::make($author));

      $this->assertSame($expected, $data['id']);
      $this->assertIsString($data['id']);
    }

    #[Test]
    public function the_id_is_the_route_key_of_the_model(): void {
      $tag = (new Tag())->forceFill(['id' => 5, 'slug' => 'php']);

      $resource = new class($tag) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->assertSame('php', $resource->id());
    }

    #[Test]
    public function a_model_without_an_id_is_an_error_for_the_developer(): void {
      // Un recurso sin id no es válido en una respuesta: se avisa en lugar de mandar un id vacío.
      // A resource without an id is not valid in a response: it is reported instead of sending an empty id.
      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/id/');

      $this->resolve(AuthorResource::make($this->author(['name' => 'Ada'])));
    }

    #[Test]
    public function the_id_can_be_overridden(): void {
      $resource = new class($this->author(['id' => 7, 'uuid' => 'u-1'])) extends ResourceObject {
        public function id(): string {
          return (string) $this->resource->uuid;
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->assertSame('u-1', $this->resolve($resource)['id']);
    }

    // ---------------------------------------------------------------------------------------------
    // El type / The type
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_type_is_the_table_of_the_model_not_the_name_of_the_class(): void {
      $resource = new class((new Tag())->forceFill(['id' => 1, 'slug' => 'php'])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->assertSame('labels', $this->resolve($resource)['type']);
    }

    #[Test]
    public function the_type_can_be_overridden(): void {
      $resource = new class($this->author(['id' => 7])) extends ResourceObject {
        public function type(): string {
          return 'writers';
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->assertSame('writers', $this->resolve($resource)['type']);
    }

    #[Test]
    public function type_and_id_can_be_read_directly_to_point_at_the_resource_from_a_relationship(): void {
      $resource = AuthorResource::make($this->author(['id' => 7, 'name' => 'Ada']));

      $this->assertSame('authors', $resource->type());
      $this->assertSame('7', $resource->id());
    }

    // ---------------------------------------------------------------------------------------------
    // Cuando el recurso no es un modelo / When the resource is not a model
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_resource_that_is_not_a_model_must_say_its_type(): void {
      $resource = new class(['id' => 1]) extends ResourceObject {
        public function id(): string {
          return '1';
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/type\(\)/');

      $this->resolve($resource);
    }

    #[Test]
    public function a_resource_that_is_not_a_model_must_say_its_id(): void {
      $resource = new class(['id' => 1]) extends ResourceObject {
        public function type(): string {
          return 'widgets';
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/id\(\)/');

      $this->resolve($resource);
    }

    #[Test]
    public function a_resource_that_is_not_a_model_works_once_it_says_both(): void {
      $resource = new class(['id' => 3, 'label' => 'Gear']) extends ResourceObject {
        public function type(): string {
          return 'widgets';
        }

        public function id(): string {
          return (string) $this->resource['id'];
        }

        protected function jsonApiAttributes(Request $request): array {
          return ['label' => $this->resource['label']];
        }
      };

      $this->assertSame(['type' => 'widgets', 'id' => '3', 'attributes' => ['label' => 'Gear']], $this->resolve($resource));
    }

    // ---------------------------------------------------------------------------------------------
    // Los atributos / The attributes
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_null_attribute_is_kept(): void {
      $data = $this->resolve(AuthorResource::make($this->author(['id' => 7, 'name' => 'Ada'])));

      // `email` no existe en el modelo: llega como null y debe seguir en el documento.
      // `email` does not exist on the model: it arrives as null and must stay in the document.
      $this->assertArrayHasKey('email', $data['attributes']);
      $this->assertNull($data['attributes']['email']);
    }

    #[Test]
    public function an_attribute_marked_as_missing_is_left_out_and_one_that_is_not_stays(): void {
      $resource = new class($this->author(['id' => 7, 'name' => 'Ada', 'secret' => 'x'])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return [
            'name' => $this->name,
            'secret' => $this->when(false, $this->secret),
            'visible' => $this->when(true, 'yes'),
          ];
        }
      };

      $this->assertSame(['name' => 'Ada', 'visible' => 'yes'], $this->resolve($resource)['attributes']);
    }

    #[Test]
    public function a_nested_value_is_kept_as_it_is(): void {
      $resource = new class($this->author(['id' => 7])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return ['address' => ['street' => 'Main', 'geo' => ['lat' => 1.5, 'lng' => -2.5]], 'tags' => ['a', 'b']];
        }
      };

      $this->assertSame(
        ['address' => ['street' => 'Main', 'geo' => ['lat' => 1.5, 'lng' => -2.5]], 'tags' => ['a', 'b']],
        $this->resolve($resource)['attributes'],
      );
    }

    #[Test]
    public function falsy_values_are_kept(): void {
      $resource = new class($this->author(['id' => 7])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return ['zero' => 0, 'empty' => '', 'no' => false];
        }
      };

      $this->assertSame(['zero' => 0, 'empty' => '', 'no' => false], $this->resolve($resource)['attributes']);
    }

    #[Test]
    public function without_attributes_the_member_is_left_out_because_an_empty_list_would_not_be_an_object(): void {
      $resource = new class($this->author(['id' => 7])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->assertSame(['type' => 'authors', 'id' => '7'], $this->resolve($resource));
    }

    #[Test]
    public function the_attributes_method_receives_the_request(): void {
      $resource = new class($this->author(['id' => 7])) extends ResourceObject {
        protected function jsonApiAttributes(Request $request): array {
          return ['path' => $request->path()];
        }
      };

      $this->assertSame('api/authors', $this->resolve($resource)['attributes']['path']);
    }

    #[Test]
    public function it_is_the_resource_object_alone_not_wrapped_in_data(): void {
      $data = $this->resolve(AuthorResource::make($this->author(['id' => 7, 'name' => 'Ada'])));

      $this->assertSame(['type', 'id', 'attributes'], array_keys($data));
    }
  }
