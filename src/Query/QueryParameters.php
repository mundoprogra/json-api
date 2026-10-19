<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Los parámetros de consulta de JSON:API de una petición: `include`, `fields`, `sort` y `filter`. Es inmutable y no toca Eloquent.
   * Los nombres de campo, de relación y de ordenamiento, y las claves del primer nivel de `filter`, pasan del estilo de `member_case` a
   * snake_case; el tipo de `fields[tipo]`, los valores de `filter` y lo anidado no se tocan. Solo lee la query string, no el cuerpo.
   * The JSON:API query parameters of a request: `include`, `fields`, `sort` and `filter`. It is immutable and does not touch Eloquent.
   * Field, relationship and sort names, and the first-level keys of `filter`, go from the `member_case` style to snake_case; the type
   * in `fields[type]`, the `filter` values and anything nested are not touched. It only reads the query string, not the body.
   */
  final class QueryParameters {
    /**
     * @param  list<list<string>>  $includes
     * @param  array<string, list<string>>  $fields
     * @param  list<array{field: string, descending: bool}>  $sort
     * @param  array<string, mixed>  $filter
     */
    private function __construct(
      private readonly array $includes,
      private readonly array $fields,
      private readonly array $sort,
      private readonly array $filter,
    ) {
    }

    public static function from(Request $request, Settings $settings): self {
      $query = $request->query->all();
      $names = MemberName::for($settings->memberCase());

      return new self(
        self::parseIncludes($query['include'] ?? null, $names),
        self::parseFields($query['fields'] ?? null, $names),
        self::parseSort($query['sort'] ?? null, $names),
        self::parseFilter($query['filter'] ?? null, $names),
      );
    }

    /**
     * Las rutas de relaciones pedidas, por niveles: `author,comments.user` es `[['author'], ['comments', 'user']]`.
     * The requested relationship paths, by levels: `author,comments.user` is `[['author'], ['comments', 'user']]`.
     *
     * @return list<list<string>>
     */
    public function includes(): array {
      return $this->includes;
    }

    /**
     * Los campos pedidos por tipo: `fields[articles]=title,body` es `['articles' => ['title', 'body']]`.
     * The requested fields by type: `fields[articles]=title,body` is `['articles' => ['title', 'body']]`.
     *
     * @return array<string, list<string>>
     */
    public function fields(): array {
      return $this->fields;
    }

    /**
     * El orden pedido, en el orden dado: `-createdAt,title` es descendente por `created_at` y luego ascendente por `title`.
     * The requested order, in the order given: `-createdAt,title` is descending by `created_at` and then ascending by `title`.
     *
     * @return list<array{field: string, descending: bool}>
     */
    public function sort(): array {
      return $this->sort;
    }

    /**
     * Las condiciones pedidas: `filter[author]=3` es `['author' => '3']`. Los valores son del cliente y los interpreta la aplicación.
     * The requested conditions: `filter[author]=3` is `['author' => '3']`. The values are the client's and the application interprets them.
     *
     * @return array<string, mixed>
     */
    public function filter(): array {
      return $this->filter;
    }

    /**
     * @return list<list<string>>
     */
    private static function parseIncludes(mixed $value, MemberName $names): array {
      $paths = [];

      foreach (self::split($value) as $path) {
        $paths[] = array_map(fn(string $name) => $names->toSnake($name), explode('.', $path));
      }

      return $paths;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseFields(mixed $value, MemberName $names): array {
      $fields = [];

      foreach (is_array($value) ? $value : [] as $type => $list) {
        $fields[(string) $type] = array_map(fn(string $name) => $names->toSnake($name), self::split($list));
      }

      return $fields;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     */
    private static function parseSort(mixed $value, MemberName $names): array {
      $sort = [];

      foreach (self::split($value) as $field) {
        $descending = str_starts_with($field, '-');

        $sort[] = ['field' => $names->toSnake($descending ? substr($field, 1) : $field), 'descending' => $descending];
      }

      return $sort;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseFilter(mixed $value, MemberName $names): array {
      return is_array($value) ? $names->keysToSnake($value) : [];
    }

    /**
     * Separa una lista por comas; un valor que no es texto o está vacío no tiene elementos.
     * Splits a comma-separated list; a value that is not text or is empty has no items.
     *
     * @return list<string>
     */
    private static function split(mixed $value): array {
      return is_string($value) && $value !== '' ? array_values(array_filter(explode(',', $value), fn(string $item) => $item !== '')) : [];
    }
  }
