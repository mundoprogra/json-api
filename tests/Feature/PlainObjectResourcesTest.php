<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use LogicException;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\AuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\PlainArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\PlainAuthorResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\PlainTagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Recursos cuyo origen NO es un modelo de Eloquent sino un objeto simple (una entidad de dominio, un DTO). El paquete no debe exigir
   * `Illuminate\Database\Eloquent\Model` para armar un recurso: `type()` e `id()` los declara el recurso, `Relationship::one()` y
   * `Relationship::many()` aceptan cualquier objeto, y un `201` se pide con `asCreated()` porque un objeto simple no tiene
   * `wasRecentlyCreated`. Lo que ya funcionaba con modelos sigue igual (hay pruebas de regresión).
   * Resources whose source is NOT an Eloquent model but a plain object (a domain entity, a DTO). The package must not require
   * `Illuminate\Database\Eloquent\Model` to build a resource: the resource declares `type()` and `id()`, `Relationship::one()` and
   * `Relationship::many()` accept any object, and a `201` is asked for with `asCreated()` because a plain object has no
   * `wasRecentlyCreated`. What already worked with models stays the same (there are regression tests).
   */
  class PlainObjectResourcesTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private function plainAuthor(): object {
      return (object) ['id' => 7, 'name' => 'Ada'];
    }

    /**
     * @return list<object>
     */
    private function plainTags(): array {
      return [
        (object) ['id' => 1, 'name' => 'PHP'],
        (object) ['id' => 2, 'name' => 'Laravel'],
      ];
    }

    /**
     * Un artículo simple con las propiedades indicadas además de `id` y `title`.
     * A plain article with the given properties besides `id` and `title`.
     *
     * @param  array<string, mixed>  $extra
     */
    private function plainArticle(array $extra = []): object {
      return (object) ($extra + ['id' => 1, 'title' => 'Hello']);
    }

    protected function defineRoutes($router): void {
      // La ruta de consulta que declara `PlainAuthorResource`, y una de alta que pide el 201 con `asCreated()`.
      // The show route declared by `PlainAuthorResource`, and a create route that asks for the 201 with `asCreated()`.
      $router->get('api/authors/{author}', fn() => PlainAuthorResource::make($this->plainAuthor()))->name('authors.show');
      $router->post('api/authors', fn() => PlainAuthorResource::make($this->plainAuthor())->asCreated());
      $router->post('api/authors-without-flag', fn() => PlainAuthorResource::make($this->plainAuthor()));

      // Un modelo que NO es recién creado, pero al que se le pide el 201: el `asCreated()` gana.
      // A model that is NOT recently created, but asked for a 201: `asCreated()` wins.
      $router->post('api/model-authors', fn() => AuthorResource::make((new Author())->forceFill(['id' => 7, 'name' => 'Ada']))->asCreated());

      $router->get('api/plain-tags', fn() => PlainTagResource::collection($this->plainTags()));
      $router->get('api/articles/{article}', fn() => PlainArticleResource::make($this->plainArticle([
        'author' => $this->plainAuthor(),
        'tags' => $this->plainTags(),
      ])));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(ResourceObject $resource): array {
      return $resource->resolve(Request::create('/api/articles/1'));
    }

    // ---------------------------------------------------------------------------------------------
    // El recurso de un objeto simple / The resource of a plain object
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_plain_object_resource_has_the_declared_type_and_id_and_its_attributes(): void {
      $data = $this->resolve(PlainTagResource::make($this->plainTags()[0]));

      $this->assertSame(['type' => 'tags', 'id' => '1', 'attributes' => ['name' => 'PHP']], $data);
    }

    #[Test]
    public function the_self_link_of_a_plain_object_is_built_with_the_declared_id(): void {
      $data = $this->resolve(PlainAuthorResource::make($this->plainAuthor()));

      $this->assertSame(['self' => 'http://localhost/api/authors/7'], $data['links']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingDeclarations(): array {
      return [
        'type' => ['type'],
        'id' => ['id'],
      ];
    }

    #[Test]
    #[DataProvider('missingDeclarations')]
    public function a_plain_object_resource_that_does_not_declare_type_or_id_is_a_programmer_error(string $missing): void {
      // Sin tabla ni clave de ruta no hay de dónde sacarlos: se dice claro qué falta en vez de inventar un valor.
      // Without a table or a route key there is nowhere to work them out from: it says plainly what is missing instead of making a value up.
      $resource = new class($this->plainTags()[0], $missing) extends ResourceObject {
        public function __construct(mixed $resource, private readonly string $missing) {
          parent::__construct($resource);
        }

        public function type(): string {
          return $this->missing === 'type' ? parent::type() : 'tags';
        }

        public function id(): string {
          return $this->missing === 'id' ? parent::id() : '1';
        }

        protected function jsonApiAttributes(Request $request): array {
          return [];
        }
      };

      $this->expectException(LogicException::class);
      $this->expectExceptionMessageMatches('/must define ' . $missing . '\(\)/');

      $this->resolve($resource);
    }

    #[Test]
    public function a_collection_of_plain_objects_is_a_valid_document(): void {
      $response = $this->get('api/plain-tags', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame([['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']], array_map(
        fn(array $tag) => ['type' => $tag['type'], 'id' => $tag['id']],
        $response->json('data'),
      ));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Relaciones a objetos simples / Relationships to plain objects
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_one_relationship_accepts_a_plain_object(): void {
      $relationship = Relationship::one($this->plainAuthor(), PlainAuthorResource::class);

      $this->assertSame(['data' => ['type' => 'authors', 'id' => '7']], $relationship->toArray());
    }

    #[Test]
    public function a_to_many_relationship_accepts_plain_objects(): void {
      $relationship = Relationship::many($this->plainTags(), PlainTagResource::class);

      $this->assertSame(
        ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']]],
        $relationship->toArray(),
      );
    }

    #[Test]
    public function an_empty_to_one_relationship_is_still_data_null(): void {
      // Regresión: ampliar el tipo a `object` no cambia lo que ya hacía `null`.
      // Regression: widening the type to `object` does not change what `null` already did.
      $this->assertSame(['data' => null], Relationship::one(null, PlainAuthorResource::class)->toArray());
    }

    #[Test]
    public function a_plain_article_with_plain_relationships_is_a_valid_document(): void {
      $response = $this->get('api/articles/1', ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertSame(['data' => ['type' => 'authors', 'id' => '7']], $response->json('data.relationships.author'));
      $this->assertSame(
        ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']]],
        $response->json('data.relationships.tags'),
      );
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_relationship_the_plain_object_does_not_carry_is_left_out(): void {
      // Sin la propiedad, el recurso decide no mostrar la relación (el equivalente a `whenLoaded` sin cargar).
      // Without the property, the resource decides not to show the relationship (the equivalent of an unloaded `whenLoaded`).
      $data = $this->resolve(PlainArticleResource::make($this->plainArticle()));

      $this->assertArrayNotHasKey('relationships', $data);
    }

    // ---------------------------------------------------------------------------------------------
    // El 201 con asCreated() / The 201 with asCreated()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function as_created_gives_a_201_for_a_plain_object(): void {
      $response = $this->post('api/authors', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_201_for_a_plain_object_carries_the_location_of_the_self_link(): void {
      $response = $this->post('api/authors', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
      $this->assertSame('http://localhost/api/authors/7', $response->headers->get('Location'));
      $this->assertSame($response->json('data.links.self'), $response->headers->get('Location'));
    }

    #[Test]
    public function without_as_created_a_plain_object_stays_a_200(): void {
      // Un objeto simple no tiene `wasRecentlyCreated`: sin pedirlo, nunca es un 201.
      // A plain object has no `wasRecentlyCreated`: unless asked for, it is never a 201.
      $response = $this->post('api/authors-without-flag', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertOk();
      $this->assertFalse($response->headers->has('Location'));
    }

    #[Test]
    public function as_created_wins_over_a_model_that_was_not_recently_created(): void {
      $response = $this->post('api/model-authors', [], ['Accept' => self::MEDIA_TYPE]);

      $response->assertStatus(201);
    }
  }
