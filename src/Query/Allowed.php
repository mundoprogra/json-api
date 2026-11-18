<?php

  namespace MundoProgra\JsonApi\Query;

  use InvalidArgumentException;

  /**
   * Lo que el API permite filtrar, ordenar e incluir, solo con nombres de la API (en `snake_case`): ni columnas, ni scopes, ni Eloquent. A qué
   * columna o scope apunta cada nombre lo decide el adaptador de Infraestructura de la app; aquí solo se dice qué puede pedir el cliente.
   * Permitir una ruta de `include` permite también sus prefijos (`roles.permissions` permite `roles`). Es inmutable y valida al construirse:
   * un nombre repetido, con caracteres no válidos o escrito como par `'nombre' => 'columna'`, y un orden por defecto que no está permitido, son
   * errores del programador (`InvalidArgumentException`), no un `400` del cliente.
   * What the API allows to filter, sort and include, with API names only (in `snake_case`): no columns, no scopes, no Eloquent. Which column or
   * scope each name points to is decided by the app's Infrastructure adapter; here it is only said what the client may ask for.
   * Allowing an `include` path also allows its prefixes (`roles.permissions` allows `roles`). It is immutable and validates when built: a
   * repeated name, one with invalid characters or written as a `'name' => 'column'` pair, and a default sort that is not allowed, are developer
   * errors (`InvalidArgumentException`), not a client `400`.
   */
  final class Allowed {
    /** @var array<string, true> */
    private readonly array $includePaths;

    /**
     * @param  list<string>  $filters
     * @param  list<string>  $sorts
     * @param  list<string>  $includes
     * @param  list<array{field: string, descending: bool}>  $defaultSort
     */
    private function __construct(
      private readonly array $filters,
      private readonly array $sorts,
      private readonly array $includes,
      private readonly array $defaultSort,
    ) {
      $this->includePaths = self::withPrefixes($includes);
    }

    /**
     * @param  array<int|string, mixed>  $filter  los nombres que se pueden filtrar / the names that can be filtered
     * @param  array<int|string, mixed>  $sort  los nombres por los que se puede ordenar / the names that can be sorted by
     * @param  array<int|string, mixed>  $include  las rutas que se pueden incluir, como `roles` o `roles.permissions` / the paths that can be included, like `roles` or `roles.permissions`
     * @param  string|null  $defaultSort  con la sintaxis del parámetro `sort`, por ejemplo `-created_at,title` / with the `sort` parameter syntax, for example `-created_at,title`
     *
     * @throws InvalidArgumentException si una lista no es una lista de nombres válidos y sin repetir, o si el orden por defecto nombra algo que no está permitido / if a list is not a list of valid names without repeats, or if the default sort names something that is not allowed
     */
    public static function make(array $filter = [], array $sort = [], array $include = [], ?string $defaultSort = null): self {
      $sorts = self::names('sort', $sort, false);

      return new self(
        self::names('filter', $filter, false),
        $sorts,
        self::names('include', $include, true),
        self::parseDefaultSort($defaultSort, $sorts),
      );
    }

    /**
     * @return list<string>
     */
    public function filters(): array {
      return $this->filters;
    }

    /**
     * @return list<string>
     */
    public function sorts(): array {
      return $this->sorts;
    }

    /**
     * Las rutas de `include` tal como se dieron, sin sus prefijos.
     * The `include` paths as they were given, without their prefixes.
     *
     * @return list<string>
     */
    public function includes(): array {
      return $this->includes;
    }

    /**
     * El orden por defecto, ya interpretado: `-created_at,title` es descendente por `created_at` y luego ascendente por `title`.
     * The default sort, already parsed: `-created_at,title` is descending by `created_at` and then ascending by `title`.
     *
     * @return list<array{field: string, descending: bool}>
     */
    public function defaultSort(): array {
      return $this->defaultSort;
    }

    public function allowsFilter(string $name): bool {
      return in_array($name, $this->filters, true);
    }

    public function allowsSort(string $name): bool {
      return in_array($name, $this->sorts, true);
    }

    /**
     * Una ruta de `include` está permitida si se dio tal cual o es el prefijo de una que se dio.
     * An `include` path is allowed if it was given as is or it is the prefix of one that was given.
     */
    public function allowsInclude(string $path): bool {
      return isset($this->includePaths[$path]);
    }

    /**
     * Revisa una lista de nombres: que sea una lista (no pares `'nombre' => 'columna'`), que cada nombre sea válido y que ninguno se repita.
     * Checks a list of names: that it is a list (not `'name' => 'column'` pairs), that each name is valid and that none is repeated.
     *
     * @param  array<int|string, mixed>  $names
     * @param  bool  $dotted  true si el nombre es una ruta con niveles separados por puntos / true if the name is a path with levels separated by dots
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function names(string $kind, array $names, bool $dotted): array {
      if (!array_is_list($names)) {
        throw new InvalidArgumentException(sprintf('The %s names must be a list of names, not "name" => "target" pairs: the name to column or scope mapping belongs to the infrastructure adapter.', $kind));
      }

      $seen = [];

      foreach ($names as $name) {
        $text = is_string($name) ? $name : get_debug_type($name);

        if (!is_string($name) || !self::isValid($name, $dotted)) {
          throw new InvalidArgumentException(sprintf('The %s name "%s" is not valid: it must be letters and digits, with hyphens or underscores in the middle%s.', $kind, $text, $dotted ? ', and dots between the levels' : ''));
        }

        if (isset($seen[$name])) {
          throw new InvalidArgumentException(sprintf('The %s name "%s" is repeated.', $kind, $name));
        }

        $seen[$name] = true;
      }

      return array_keys($seen);
    }

    private static function isValid(string $name, bool $dotted): bool {
      foreach ($dotted ? explode('.', $name) : [$name] as $segment) {
        if (preg_match(QueryParameters::NAME, $segment) !== 1) {
          return false;
        }
      }

      return true;
    }

    /**
     * El orden por defecto ya interpretado, o una lista vacía si no hay.
     * The already parsed default sort, or an empty list if there is none.
     *
     * @param  list<string>  $sorts
     * @return list<array{field: string, descending: bool}>
     *
     * @throws InvalidArgumentException si nombra algo que no está en la lista de orden / if it names something that is not in the sort list
     */
    private static function parseDefaultSort(?string $default, array $sorts): array {
      if ($default === null || $default === '') {
        return [];
      }

      $parsed = [];

      foreach (explode(',', $default) as $item) {
        $descending = str_starts_with($item, '-');
        $field = $descending ? substr($item, 1) : $item;

        if (!in_array($field, $sorts, true)) {
          throw new InvalidArgumentException(sprintf('The default sort "%s" is not in the list of allowed sort names.', $field));
        }

        $parsed[] = ['field' => $field, 'descending' => $descending];
      }

      return $parsed;
    }

    /**
     * Las rutas permitidas con todos sus prefijos: `comments.author` permite `comments` y `comments.author`.
     * The allowed paths with all their prefixes: `comments.author` allows `comments` and `comments.author`.
     *
     * @param  list<string>  $includes
     * @return array<string, true>
     */
    private static function withPrefixes(array $includes): array {
      $paths = [];

      foreach ($includes as $path) {
        $segments = explode('.', $path);

        foreach (array_keys($segments) as $index) {
          $paths[implode('.', array_slice($segments, 0, $index + 1))] = true;
        }
      }

      return $paths;
    }
  }
