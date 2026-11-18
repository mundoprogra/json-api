<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * `jsonApiSort()` y `jsonApiInclude()` revisan su lista blanca con `Allowed`, la misma que usa `criteria()`: así las reglas (nombres válidos y sin
   * repetir, rutas con sus prefijos, el orden por defecto) viven en un solo lugar. Para el cliente no cambia nada; para quien escribe el
   * controlador, un nombre repetido o no válido en la lista, que antes se aceptaba en silencio, ahora es un error del programador claro. Una entrada
   * `'nombre' => 'columna'` sigue mapeando el nombre a otra columna, y permitir una ruta sigue permitiendo sus prefijos.
   * `jsonApiSort()` and `jsonApiInclude()` check their whitelist with `Allowed`, the same one `criteria()` uses: so the rules (valid names without
   * repeats, paths with their prefixes, the default sort) live in one place. Nothing changes for the client; for whoever writes the controller, a
   * repeated or invalid name in the list, which used to be accepted silently, is now a clear developer error. A `'name' => 'column'` entry still
   * maps the name to another column, and allowing a path still allows its prefixes.
   */
  class MacrosShareAllowedTest extends TestCase {
    private function requestWith(string $queryString): void {
      $this->app->instance('request', Request::create('/api/articles?' . $queryString));
    }

    // ---------------------------------------------------------------------------------------------
    // jsonApiSort()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_repeated_name_in_the_sort_list_is_a_developer_error(): void {
      $this->requestWith('');

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/sort name "id" is repeated/');

      Article::query()->jsonApiSort(['id', 'id']);
    }

    #[Test]
    public function an_invalid_name_in_the_sort_list_is_a_developer_error(): void {
      $this->requestWith('');

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/sort name "a b" is not valid/');

      Article::query()->jsonApiSort(['a b']);
    }

    #[Test]
    public function a_default_outside_the_sort_list_says_so_with_the_words_of_allowed(): void {
      $this->requestWith('');

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/default sort "title" is not in the list of allowed sort names/');

      Article::query()->jsonApiSort(['id'], default: '-title');
    }

    #[Test]
    public function a_name_to_column_pair_still_maps_the_name_to_the_other_column(): void {
      $this->requestWith('sort=-owner');

      $sql = Article::query()->jsonApiSort(['owner' => 'author_id'])->toSql();

      // Las comillas del identificador dependen del motor de base de datos, así que se admite cualquiera.
      // The identifier quotes depend on the database engine, so any of them is accepted.
      $this->assertMatchesRegularExpression('/author_id\W{0,2} desc/', $sql);
    }

    // ---------------------------------------------------------------------------------------------
    // jsonApiInclude()
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_repeated_path_in_the_include_list_is_a_developer_error(): void {
      $this->requestWith('');

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/include name "author" is repeated/');

      Article::query()->jsonApiInclude(['author', 'author']);
    }

    #[Test]
    public function an_invalid_path_in_the_include_list_is_a_developer_error(): void {
      $this->requestWith('');

      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/include name "my author" is not valid/');

      Article::query()->jsonApiInclude(['my author']);
    }

    #[Test]
    public function allowing_a_path_still_allows_its_prefixes(): void {
      $this->requestWith('include=author');

      $loads = Article::query()->jsonApiInclude(['author.books'])->getEagerLoads();

      $this->assertSame(['author'], array_keys($loads));
    }
  }
