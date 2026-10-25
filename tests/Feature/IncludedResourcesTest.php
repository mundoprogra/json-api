<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Database\Eloquent\Collection as EloquentCollection;
  use Illuminate\Http\Request;
  use Illuminate\Testing\TestResponse;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Comment;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\BlogArticleResource;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\TagResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `included` de un recurso: los recursos relacionados que el cliente pidió con `include`, en un arreglo después de `data`. Se resuelve de forma
   * recursiva por cada ruta pedida (`comments.author`), y pedir una ruta incluye también sus prefijos. Solo se recorren las relaciones ya cargadas:
   * nunca se dispara una consulta para completar el documento. Cada recurso incluido es un objeto de recurso completo, con sus propios atributos,
   * relaciones y sparse fieldsets, y aparece una sola vez (por `type` e `id`) y nunca si ya es el recurso principal. Se cumple el *full linkage*:
   * todo recurso incluido se alcanza por el linkage de otro recurso del documento, por eso lo que oculta un `fields[tipo]` tampoco se incluye.
   * Sin `include`, sin nada que incluir o fuera del alcance del paquete, no hay `included`.
   *
   * `included` of a resource: the related resources the client asked for with `include`, in an array after `data`. It is resolved recursively for
   * each path asked for (`comments.author`), and asking for a path also includes its prefixes. Only relationships that are already loaded are
   * walked: a query is never fired to complete the document. Each included resource is a full resource object, with its own attributes,
   * relationships and sparse fieldsets, and it appears once (by `type` and `id`) and never if it is already the primary resource. *Full linkage*
   * holds: every included resource is reachable through the linkage of another resource in the document, so what a `fields[type]` hides is not
   * included either. Without `include`, with nothing to include or outside the package scope, there is no `included`.
   */
  class IncludedResourcesTest extends TestCase {
    use AssertsJsonApi;

    private const MEDIA_TYPE = 'application/vnd.api+json';

    private function author(int $id, string $name): Author {
      return (new Author())->forceFill(['id' => $id, 'name' => $name, 'email' => strtolower($name) . '@example.com']);
    }

    private function comment(int $id, string $body, Author $author): Comment {
      return (new Comment())->forceFill(['id' => $id, 'body' => $body])->setRelation('author', $author);
    }

    /**
     * El artículo 10, con todo cargado: el autor Ada, las etiquetas php y laravel, y dos comentarios (de Ada y de Bob).
     * Article 10, with everything loaded: the author Ada, the tags php and laravel, and two comments (by Ada and by Bob).
     */
    private function article(): Article {
      $ada = $this->author(1, 'Ada');
      $bob = $this->author(2, 'Bob');
      $first = $this->comment(100, 'First', $ada);

      return (new Article())->forceFill(['id' => 10, 'title' => 'Hello'])
        ->setRelation('author', $ada)
        ->setRelation('tags', new EloquentCollection([
          (new Tag())->forceFill(['slug' => 'php', 'name' => 'PHP']),
          (new Tag())->forceFill(['slug' => 'laravel', 'name' => 'Laravel']),
        ]))
        ->setRelation('comments', new EloquentCollection([$first, $this->comment(101, 'Second', $bob)]))
        ->setRelation('featured_comment', $first);
    }

    protected function defineRoutes($router): void {
      $router->get('api/article', fn() => BlogArticleResource::make($this->article()));
      $router->get('web/article', fn() => BlogArticleResource::make($this->article()));
      $router->get('api/bare', fn() => BlogArticleResource::make((new Article())->forceFill(['id' => 10, 'title' => 'Hello'])));
      $router->get('api/emptied', fn() => BlogArticleResource::make(
        (new Article())->forceFill(['id' => 10, 'title' => 'Hello'])->setRelation('editor', null)->setRelation('tags', new EloquentCollection())
      ));

      // Un ciclo: el artículo → su autor → los artículos del autor (que incluyen al propio artículo 10).
      // A cycle: the article → its author → the author's articles (which include article 10 itself).
      $router->get('api/cycle', function () {
        $ada = $this->author(1, 'Ada')->setRelation('articles', new EloquentCollection([
          (new Article())->forceFill(['id' => 10, 'title' => 'Hello']),
          (new Article())->forceFill(['id' => 11, 'title' => 'Another']),
        ]));

        return BlogArticleResource::make((new Article())->forceFill(['id' => 10, 'title' => 'Hello'])->setRelation('author', $ada));
      });
    }

    private function fetch(string $query = '', string $uri = 'api/article'): TestResponse {
      return $this->get($uri . ($query === '' ? '' : '?' . $query), ['Accept' => self::MEDIA_TYPE]);
    }

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
     * Comprueba el *full linkage* y que no hay duplicados ni se repite el recurso principal.
     * Checks *full linkage* and that there are no duplicates and the primary resource is not repeated.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertCompound(array $document): void {
      $linked = [];

      foreach (array_merge([$document['data']], $document['included'] ?? []) as $resource) {
        foreach ($resource['relationships'] ?? [] as $relationship) {
          $data = $relationship['data'] ?? null;

          foreach ($data === null ? [] : (array_is_list($data) ? $data : [$data]) as $identifier) {
            $linked[$identifier['type'] . ':' . $identifier['id']] = true;
          }
        }
      }

      $included = $this->included($document);

      $this->assertSame($included, array_values(array_unique($included)), 'An included resource must appear once.');
      $this->assertNotContains($document['data']['type'] . ':' . $document['data']['id'], $included, 'The primary resource must not be included.');

      foreach ($included as $key) {
        $this->assertArrayHasKey($key, $linked, "$key is included but no resource links to it.");
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Sin include / Without include
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function without_include_there_is_no_included_even_with_everything_loaded(): void {
      $response = $this->fetch();

      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('data.relationships.author.data'));
    }

    #[Test]
    public function an_empty_include_includes_nothing(): void {
      $this->assertArrayNotHasKey('included', (array) $this->fetch('include=')->json());
    }

    // ---------------------------------------------------------------------------------------------
    // Una ruta / One path
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_to_one_path_includes_that_resource_in_full(): void {
      $response = $this->fetch('include=author');

      $response->assertOk();
      $this->assertSame(['authors:1'], $this->included((array) $response->json()));
      $this->assertSame(
        ['type' => 'authors', 'id' => '1', 'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com']],
        $response->json('included.0')
      );
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function a_to_many_path_includes_every_related_resource_in_order(): void {
      $response = $this->fetch('include=tags');

      $this->assertSame(['labels:php', 'labels:laravel'], $this->included((array) $response->json()));
      $this->assertSame(TagResource::make((new Tag())->forceFill(['slug' => 'php', 'name' => 'PHP']))->document()['data'], $response->json('included.0'));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function included_comes_right_after_data(): void {
      $this->assertSame(['jsonapi', 'data', 'included'], array_keys((array) $this->fetch('include=author')->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Varias rutas y su orden / Several paths and their order
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function several_paths_follow_the_order_the_client_gave(): void {
      $this->assertSame(['authors:1', 'labels:php', 'labels:laravel'], $this->included((array) $this->fetch('include=author,tags')->json()));
      $this->assertSame(['labels:php', 'labels:laravel', 'authors:1'], $this->included((array) $this->fetch('include=tags,author')->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Rutas anidadas / Nested paths
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_nested_path_includes_every_level_one_level_at_a_time(): void {
      $response = $this->fetch('include=comments.author');

      $this->assertSame(['comments:100', 'comments:101', 'authors:1', 'authors:2'], $this->included((array) $response->json()));
      $this->assertJsonApiDocument((string) $response->getContent());
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function asking_for_a_path_asks_for_its_prefixes_too_but_not_what_hangs_below(): void {
      $response = $this->fetch('include=comments');

      $this->assertSame(['comments:100', 'comments:101'], $this->included((array) $response->json()));
      // Los comentarios traen el linkage de su autor porque está cargado, aunque el autor no se incluya.
      // The comments carry the linkage of their author because it is loaded, even though the author is not included.
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('included.0.relationships.author.data'));
    }

    #[Test]
    public function a_resource_reached_by_two_paths_appears_once_where_it_was_found_first(): void {
      $response = $this->fetch('include=author,comments.author');

      $this->assertSame(['authors:1', 'comments:100', 'comments:101', 'authors:2'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function a_to_one_relationship_with_a_different_name_goes_by_that_name(): void {
      $this->assertSame(['comments:100'], $this->included((array) $this->fetch('include=featuredComment')->json()));
    }

    #[Test]
    public function relationship_names_in_include_follow_the_member_case_setting(): void {
      config()->set('json-api.member_case', 'kebab');

      $this->assertSame(['comments:100'], $this->included((array) $this->fetch('include=featured-comment')->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Ciclos / Cycles
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_cycle_back_to_the_primary_resource_does_not_repeat_it(): void {
      $response = $this->fetch('include=author.articles', 'api/cycle');

      $this->assertSame(['authors:1', 'articles:11'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // Nada que incluir / Nothing to include
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_relationship_that_is_not_loaded_is_never_loaded_to_include_it(): void {
      // El artículo no está en la base de datos: si el paquete intentara cargar la relación, fallaría la consulta.
      // The article is not in the database: if the package tried to load the relationship, the query would fail.
      $response = $this->fetch('include=author,tags,comments.author', 'api/bare');

      $response->assertOk();
      $this->assertArrayNotHasKey('included', (array) $response->json());
      $this->assertArrayNotHasKey('relationships', $response->json('data'));
    }

    #[Test]
    public function an_empty_relationship_includes_nothing(): void {
      $response = $this->fetch('include=editor,tags', 'api/emptied');

      $response->assertOk();
      $this->assertArrayNotHasKey('included', (array) $response->json());
      $this->assertNull($response->json('data.relationships.editor.data'));
    }

    #[Test]
    public function a_path_the_resource_does_not_have_is_ignored(): void {
      $this->assertSame(['authors:1'], $this->included((array) $this->fetch('include=nope,author.nope')->json()));
    }

    // ---------------------------------------------------------------------------------------------
    // Sparse fieldsets
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_sparse_fieldsets_of_the_included_type_apply_to_each_included_resource(): void {
      $response = $this->fetch('include=author&fields[authors]=name');

      $this->assertSame(['name' => 'Ada'], $response->json('included.0.attributes'));
    }

    #[Test]
    public function a_relationship_hidden_by_the_fieldsets_is_not_included(): void {
      $response = $this->fetch('include=author&fields[articles]=title');

      $this->assertArrayNotHasKey('included', (array) $response->json());
      $this->assertArrayNotHasKey('relationships', $response->json('data'));
    }

    #[Test]
    public function only_the_relationships_the_fieldsets_keep_are_included(): void {
      $response = $this->fetch('include=author,tags&fields[articles]=title,author');

      $this->assertSame(['authors:1'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    #[Test]
    public function what_hangs_below_a_relationship_the_fieldsets_hide_is_not_included(): void {
      $response = $this->fetch('include=comments.author&fields[comments]=body');

      $this->assertSame(['comments:100', 'comments:101'], $this->included((array) $response->json()));
      $this->assertCompound((array) $response->json());
    }

    // ---------------------------------------------------------------------------------------------
    // Fuera del alcance / Outside the scope
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function outside_the_package_scope_include_is_ignored(): void {
      $response = $this->get('web/article?include=author', ['Accept' => 'text/html']);

      $response->assertOk();
      $this->assertArrayNotHasKey('included', (array) $response->json());
      $this->assertSame(['type' => 'authors', 'id' => '1'], $response->json('data.relationships.author.data'));
    }

    // ---------------------------------------------------------------------------------------------
    // document() / document()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_document_takes_the_include_from_the_request_it_is_given(): void {
      $inside = BlogArticleResource::make($this->article())->document(Request::create('/api/article?include=author'));
      $outside = BlogArticleResource::make($this->article())->document(Request::create('/web/article?include=author'));

      $this->assertSame(['authors:1'], $this->included($inside));
      $this->assertSame([], $this->included($outside));
    }
  }
