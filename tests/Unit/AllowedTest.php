<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use InvalidArgumentException;
  use MundoProgra\JsonApi\Query\Allowed;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  /**
   * `Allowed`: lo que una lista del API permite filtrar, ordenar e incluir, solo con nombres de la API (en `snake_case`), sin columnas, scopes
   * ni Eloquent: a qué columna o scope apunta cada nombre lo decide el adaptador de Infraestructura de la app. Permitir una ruta de `include`
   * permite también sus prefijos (`roles.permissions` permite `roles`). Es inmutable y valida al construirse: un nombre repetido, con
   * caracteres no válidos o escrito como par `'nombre' => 'columna'`, y un orden por defecto que no está permitido, son errores del
   * programador (`InvalidArgumentException`), no un `400` del cliente.
   * `Allowed`: what a list of the API allows to filter, sort and include, with API names only (in `snake_case`), without columns, scopes or
   * Eloquent: which column or scope each name points to is decided by the app's Infrastructure adapter. Allowing an `include` path also
   * allows its prefixes (`roles.permissions` allows `roles`). It is immutable and validates when built: a repeated name, one with invalid
   * characters or written as a `'name' => 'column'` pair, and a default sort that is not allowed, are developer errors
   * (`InvalidArgumentException`), not a client `400`.
   */
  class AllowedTest extends TestCase {
    // ---------------------------------------------------------------------------------------------
    // Lo que se permite / What is allowed
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function by_default_nothing_is_allowed(): void {
      $allowed = Allowed::make();

      $this->assertSame([], $allowed->filters());
      $this->assertSame([], $allowed->sorts());
      $this->assertSame([], $allowed->includes());
      $this->assertSame([], $allowed->defaultSort());
      $this->assertFalse($allowed->allowsFilter('status'));
      $this->assertFalse($allowed->allowsSort('name'));
      $this->assertFalse($allowed->allowsInclude('roles'));
    }

    #[Test]
    public function it_keeps_the_names_it_was_given_in_the_order_given(): void {
      $allowed = Allowed::make(
        filter: ['status', 'name', 'blocked'],
        sort: ['created_at', 'name'],
        include: ['roles', 'roles.permissions'],
      );

      $this->assertSame(['status', 'name', 'blocked'], $allowed->filters());
      $this->assertSame(['created_at', 'name'], $allowed->sorts());
      $this->assertSame(['roles', 'roles.permissions'], $allowed->includes());
    }

    #[Test]
    public function it_allows_exactly_the_names_in_each_list_and_the_lists_do_not_mix(): void {
      $allowed = Allowed::make(filter: ['status'], sort: ['name'], include: ['roles']);

      $this->assertTrue($allowed->allowsFilter('status'));
      $this->assertFalse($allowed->allowsFilter('name'));
      $this->assertTrue($allowed->allowsSort('name'));
      $this->assertFalse($allowed->allowsSort('status'));
      $this->assertTrue($allowed->allowsInclude('roles'));
      $this->assertFalse($allowed->allowsInclude('status'));
    }

    #[Test]
    public function names_are_compared_exactly_not_by_case_or_by_part(): void {
      $allowed = Allowed::make(filter: ['created_at'], sort: ['created_at']);

      $this->assertFalse($allowed->allowsFilter('Created_At'));
      $this->assertFalse($allowed->allowsFilter('created'));
      $this->assertFalse($allowed->allowsSort('created_at '));
    }

    #[Test]
    public function allowing_an_include_path_allows_its_prefixes_but_nothing_deeper(): void {
      $allowed = Allowed::make(include: ['roles.permissions']);

      $this->assertTrue($allowed->allowsInclude('roles'));
      $this->assertTrue($allowed->allowsInclude('roles.permissions'));
      $this->assertFalse($allowed->allowsInclude('roles.permissions.groups'));
      $this->assertFalse($allowed->allowsInclude('permissions'));
    }

    #[Test]
    public function allowing_a_prefix_does_not_allow_the_longer_path(): void {
      $allowed = Allowed::make(include: ['roles']);

      $this->assertFalse($allowed->allowsInclude('roles.permissions'));
    }

    // ---------------------------------------------------------------------------------------------
    // El orden por defecto / The default sort
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_default_sort_is_parsed_with_the_syntax_of_the_sort_parameter(): void {
      $allowed = Allowed::make(sort: ['created_at', 'title'], defaultSort: '-created_at,title');

      $this->assertSame([
        ['field' => 'created_at', 'descending' => true],
        ['field' => 'title', 'descending' => false],
      ], $allowed->defaultSort());
    }

    #[Test]
    public function a_default_sort_that_is_not_in_the_sort_list_is_a_programmer_error(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/default sort "title"/');

      Allowed::make(sort: ['created_at'], defaultSort: '-created_at,title');
    }

    #[Test]
    public function a_default_sort_without_a_sort_list_is_a_programmer_error(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/default sort "name"/');

      Allowed::make(defaultSort: 'name');
    }

    #[Test]
    public function a_default_sort_with_an_empty_item_is_a_programmer_error(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/default sort/');

      Allowed::make(sort: ['name'], defaultSort: 'name,,');
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del programador al construir / Developer errors when building
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array<string, list<string>>}>
     */
    public static function repeatedNames(): array {
      return [
        'filter' => ['filter', ['filter' => ['status', 'name', 'status']]],
        'sort' => ['sort', ['sort' => ['name', 'name']]],
        'include' => ['include', ['include' => ['roles', 'roles']]],
      ];
    }

    /**
     * @param  array<string, list<string>>  $lists
     */
    #[Test]
    #[DataProvider('repeatedNames')]
    public function a_name_repeated_in_a_list_is_a_programmer_error(string $kind, array $lists): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/' . $kind . ' name .* is repeated/');

      Allowed::make(...$lists);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, list<string>>, 2: string}>
     */
    public static function invalidNames(): array {
      return [
        'an empty filter name' => ['filter', ['filter' => ['']], '""'],
        'a filter name with a space' => ['filter', ['filter' => ['first name']], '"first name"'],
        'a filter name with a dot' => ['filter', ['filter' => ['roles.name']], '"roles.name"'],
        'a filter name with a bracket' => ['filter', ['filter' => ['a[b]']], '"a\[b\]"'],
        'a sort name starting with a minus' => ['sort', ['sort' => ['-name']], '"-name"'],
        'a sort name with a comma' => ['sort', ['sort' => ['a,b']], '"a,b"'],
        'an include path with an empty level' => ['include', ['include' => ['roles.']], '"roles\."'],
        'an include path starting with a dot' => ['include', ['include' => ['.roles']], '"\.roles"'],
        'an include path with a space' => ['include', ['include' => ['my roles']], '"my roles"'],
      ];
    }

    /**
     * @param  array<string, list<string>>  $lists
     */
    #[Test]
    #[DataProvider('invalidNames')]
    public function a_name_with_invalid_characters_is_a_programmer_error(string $kind, array $lists, string $quoted): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/' . $kind . ' name ' . $quoted . ' is not valid/');

      Allowed::make(...$lists);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, array<string, string>>}>
     */
    public static function pairs(): array {
      return [
        'filter' => ['filter', ['filter' => ['name' => 'full_name']]],
        'sort' => ['sort', ['sort' => ['created' => 'created_at']]],
        'include' => ['include', ['include' => ['owner' => 'author']]],
      ];
    }

    /**
     * @param  array<string, array<string, string>>  $lists
     */
    #[Test]
    #[DataProvider('pairs')]
    public function a_name_to_column_pair_is_a_programmer_error_because_the_mapping_lives_in_the_adapter(string $kind, array $lists): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessageMatches('/' . $kind . ' names must be a list of names/');

      Allowed::make(...$lists);
    }

    #[Test]
    public function the_names_accepted_by_the_query_parser_are_accepted(): void {
      // Letras con acento, dígitos, guion y guion bajo en medio: lo mismo que acepta `QueryParameters` para un nombre.
      // Letters with accents, digits, a hyphen and an underscore in the middle: the same `QueryParameters` accepts for a name.
      $allowed = Allowed::make(filter: ['año', 'a1', 'created_at', 'a-b'], sort: ['año'], include: ['roles', 'role_groups.permissions']);

      $this->assertTrue($allowed->allowsFilter('año'));
      $this->assertTrue($allowed->allowsFilter('a-b'));
      $this->assertTrue($allowed->allowsInclude('role_groups'));
    }
  }
