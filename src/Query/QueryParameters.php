<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Los parámetros de consulta de JSON:API de una petición: `include`, `fields`, `sort`, `filter` y `page`. Es inmutable y no toca Eloquent.
   * Los nombres de campo, de relación y de ordenamiento, y las claves del primer nivel de `filter`, pasan del estilo de `member_case` a
   * snake_case; el tipo de `fields[tipo]`, los valores de `filter` y lo anidado no se tocan. Solo lee la query string, no el cuerpo.
   * Un parámetro mal formado es un `400` con `source.parameter` exacto: todos los problemas de la petición salen juntos, en el orden
   * `include`, `fields`, `sort`, `filter`, `page`, y los textos nunca repiten lo que mandó el cliente.
   * The JSON:API query parameters of a request: `include`, `fields`, `sort`, `filter` and `page`. It is immutable and does not touch Eloquent.
   * Field, relationship and sort names, and the first-level keys of `filter`, go from the `member_case` style to snake_case; the type
   * in `fields[type]`, the `filter` values and anything nested are not touched. It only reads the query string, not the body.
   * A malformed parameter is a `400` with an exact `source.parameter`: all the problems of the request come out together, in the order
   * `include`, `fields`, `sort`, `filter`, `page`, and the texts never repeat what the client sent.
   */
  final class QueryParameters {
    // Una letra o un dígito al inicio y al final, y entre ellos también guiones y guiones bajos. Es más estricto que la spec (que tolera espacios)
    // porque estos nombres terminan en consultas de Eloquent y en URL.
    // A letter or digit at the start and the end, and hyphens and underscores between them too. It is stricter than the spec (which tolerates
    // spaces) because these names end up in Eloquent queries and URLs.
    private const NAME = '/^[\p{L}\p{N}](?:[\p{L}\p{N}_-]*[\p{L}\p{N}])?$/u';

    // Los cinco parámetros que define la spec; cualquier otro nombre de solo `a-z` es desconocido.
    // The five parameters the spec defines; any other name of only `a-z` is unknown.
    private const ATTRIBUTE = 'json-api.query';

    private const STANDARD =['include', 'fields', 'sort', 'filter', 'page'];

    /**
     * @param  list<list<string>>  $includes
     * @param  array<string, list<string>>  $fields
     * @param  list<array{field: string, descending: bool}>  $sort
     * @param  array<int|string, mixed>  $filter
     * @param  array<string, mixed>  $page
     */
    private function __construct(
      private readonly array $includes,
      private readonly array $fields,
      private readonly array $sort,
      private readonly array $filter,
      private readonly array $page,
    ) {
    }

    /**
     * @throws JsonApiException con un error `400` por cada parámetro mal formado / with a `400` error for each malformed parameter
     */
    public static function from(Request $request, Settings $settings): self {
      $query = $request->query->all();
      $names = MemberName::for($settings->memberCase());
      $violations = [];

      $parameters = new self(
        self::parseIncludes($query['include'] ?? null, $names, $settings->maxIncludeDepth(), $violations),
        self::parseFields($query['fields'] ?? null, $names, $violations),
        self::parseSort($query['sort'] ?? null, $names, $violations),
        self::parseFilter($query['filter'] ?? null, $names, $violations),
        self::parsePage($query['page'] ?? null, $violations),
      );

      if ($settings->rejectUnknownQueryParams()) {
        self::rejectUnknown(array_keys($query), $violations);
      }

      if ($violations !== []) {
        throw self::exceptionFor($violations);
      }

      return $parameters;
    }

    /**
     * La consulta de la petición, analizada una sola vez: la primera llamada la valida y la guarda en la petición, y las demás la reutilizan.
     * Es lo que usa la macro `$request->jsonApiQuery()`. Si la consulta está mal formada lanza el `400` cada vez que se pide.
     * The request query, parsed once: the first call validates it and keeps it on the request, and the others reuse it.
     * It is what the `$request->jsonApiQuery()` macro uses. If the query is malformed it throws the `400` every time it is asked for.
     *
     * @throws JsonApiException
     */
    public static function of(Request $request): self {
      $parsed = $request->attributes->get(self::ATTRIBUTE);

      if (!$parsed instanceof self) {
        $parsed = self::from($request, app(Settings::class));
        $request->attributes->set(self::ATTRIBUTE, $parsed);
      }

      return $parsed;
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
     * @return array<int|string, mixed>
     */
    public function filter(): array {
      return $this->filter;
    }

    /**
     * La paginación pedida, con las claves en el orden dado: `number` y `size` como enteros positivos, y cualquier otra clave de la familia
     * `page` tal como la escribió el cliente (la estrategia es libre y la interpreta la aplicación).
     * The requested pagination, with the keys in the order given: `number` and `size` as positive integers, and any other key of the `page`
     * family as the client wrote it (the strategy is free and the application interprets it).
     *
     * @return array<string, mixed>
     */
    public function page(): array {
      return $this->page;
    }

    public function pageNumber(): ?int {
      $number = $this->page['number'] ?? null;

      return is_int($number) ? $number : null;
    }

    public function pageSize(): ?int {
      $size = $this->page['size'] ?? null;

      return is_int($size) ? $size : null;
    }

    /**
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return list<list<string>>
     */
    private static function parseIncludes(mixed $value, MemberName $names, int $maxDepth, array &$violations): array {
      $items = self::items($value, 'include', $violations);
      $paths = [];

      foreach ($items as $path) {
        $segments = explode('.', $path);

        if (self::firstInvalidName($segments) !== null) {
          $violations[] = ['parameter' => 'include', 'reason' => 'invalid_name'];

          return [];
        }

        $paths[] = array_map(fn(string $name) => $names->toSnake($name), $segments);
      }

      // La profundidad se revisa después de la sintaxis, y da un solo error aunque varias rutas se pasen.
      // The depth is checked after the syntax, and gives a single error even if several paths go over.
      foreach ($paths as $path) {
        if (count($path) > $maxDepth) {
          $violations[] = ['parameter' => 'include', 'reason' => 'include_too_deep', 'replace' => ['max' => (string) $maxDepth]];

          return [];
        }
      }

      return $paths;
    }

    /**
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return array<string, list<string>>
     */
    private static function parseFields(mixed $value, MemberName $names, array &$violations): array {
      if ($value === null) {
        return [];
      }

      if (!is_array($value)) {
        $violations[] = ['parameter' => 'fields', 'reason' => 'malformed'];

        return [];
      }

      $fields = [];

      foreach ($value as $type => $list) {
        $parameter = "fields[$type]";

        if (!self::isName((string) $type)) {
          $violations[] = ['parameter' => $parameter, 'reason' => 'invalid_name'];

          continue;
        }

        $before = count($violations);
        $items = self::items($list, $parameter, $violations);

        if (count($violations) === $before && self::firstInvalidName($items) !== null) {
          $violations[] = ['parameter' => $parameter, 'reason' => 'invalid_name'];
        }

        if (count($violations) === $before) {
          $fields[(string) $type] = array_map(fn(string $name) => $names->toSnake($name), $items);
        }
      }

      return $fields;
    }

    /**
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return list<array{field: string, descending: bool}>
     */
    private static function parseSort(mixed $value, MemberName $names, array &$violations): array {
      $sort = [];

      foreach (self::items($value, 'sort', $violations) as $field) {
        $descending = str_starts_with($field, '-');
        $name = $descending ? substr($field, 1) : $field;

        if (!self::isName($name)) {
          $violations[] = ['parameter' => 'sort', 'reason' => 'invalid_name'];

          return [];
        }

        $sort[] = ['field' => $names->toSnake($name), 'descending' => $descending];
      }

      return $sort;
    }

    /**
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return array<int|string, mixed>
     */
    private static function parseFilter(mixed $value, MemberName $names, array &$violations): array {
      if ($value === null) {
        return [];
      }

      if (!is_array($value)) {
        $violations[] = ['parameter' => 'filter', 'reason' => 'malformed'];

        return [];
      }

      $valid = true;

      foreach (array_keys($value) as $key) {
        if (!self::isName((string) $key)) {
          $violations[] = ['parameter' => "filter[$key]", 'reason' => 'invalid_name'];
          $valid = false;
        }
      }

      if (!$valid) {
        return [];
      }

      try {
        return $names->keysToSnake($value);
      } catch (InvalidArgumentException) {
        // Dos claves terminarían con el mismo nombre una vez convertidas.
        // Two keys would end up with the same name once converted.
        $violations[] = ['parameter' => 'filter', 'reason' => 'malformed'];

        return [];
      }
    }

    /**
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return array<string, mixed>
     */
    private static function parsePage(mixed $value, array &$violations): array {
      if ($value === null) {
        return [];
      }

      if (!is_array($value)) {
        $violations[] = ['parameter' => 'page', 'reason' => 'malformed'];

        return [];
      }

      $page = [];

      foreach ($value as $key => $item) {
        if ($key !== 'number' && $key !== 'size') {
          $page[(string) $key] = $item;

          continue;
        }

        $integer = self::positiveInteger($item);

        if ($integer === null) {
          $violations[] = ['parameter' => "page[$key]", 'reason' => 'invalid_page'];

          continue;
        }

        $page[$key] = $integer;
      }

      return $page;
    }

    /**
     * Los parámetros que no son de la spec y cuyo nombre es solo `a-z` minúsculas están reservados a la spec: dan `400`. Los que llevan otro
     * carácter (`utm_source`, `sortBy`) son propios de la implementación y se ignoran siempre. Una familia (`foo[bar]`) se informa por su nombre base.
     * Parameters that are not in the spec and whose name is only lowercase `a-z` are reserved to the spec: they give `400`. Those with another
     * character (`utm_source`, `sortBy`) are implementation-specific and are always ignored. A family (`foo[bar]`) is reported by its base name.
     *
     * @param  list<int|string>  $names
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     */
    private static function rejectUnknown(array $names, array &$violations): void {
      foreach ($names as $name) {
        if (is_string($name) && !in_array($name, self::STANDARD, true) && preg_match('/^[a-z]+$/D', $name) === 1) {
          $violations[] = ['parameter' => $name, 'reason' => 'unknown_parameter'];
        }
      }
    }

    /**
     * Un entero positivo escrito solo con dígitos; todo lo demás (letras, signo, espacios, cero o un número demasiado grande) es null.
     * A positive integer written with digits only; anything else (letters, a sign, spaces, zero or a number too big) is null.
     */
    private static function positiveInteger(mixed $value): ?int {
      if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
        return null;
      }

      $integer = (int) $value;

      return $integer >= 1 && (string) $integer === ltrim($value, '0') ? $integer : null;
    }

    /**
     * Separa una lista por comas. Un valor vacío no tiene elementos; uno que no es texto está mal formado, y un elemento vacío (`a,,b`) también.
     * Splits a comma-separated list. An empty value has no items; one that is not text is malformed, and so is an empty item (`a,,b`).
     *
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     * @return list<string>
     */
    private static function items(mixed $value, string $parameter, array &$violations): array {
      if ($value === null || $value === '') {
        return [];
      }

      if (!is_string($value)) {
        $violations[] = ['parameter' => $parameter, 'reason' => 'malformed'];

        return [];
      }

      $items = explode(',', $value);

      if (in_array('', $items, true)) {
        $violations[] = ['parameter' => $parameter, 'reason' => 'empty_item'];

        return [];
      }

      return $items;
    }

    /**
     * @param  list<string>  $names
     */
    private static function firstInvalidName(array $names): ?string {
      foreach ($names as $name) {
        if (!self::isName($name)) {
          return $name;
        }
      }

      return null;
    }

    private static function isName(string $name): bool {
      return preg_match(self::NAME, $name) === 1;
    }

    /**
     * El `400` de la familia `request.bad_query` con un error por cada problema. Lo usan también las clases que descubren un problema de la
     * consulta después de analizarla (por ejemplo `PageRequest`, con un tamaño de página mayor al máximo), para que todos salgan igual.
     * The `400` of the `request.bad_query` family with an error for each problem. Classes that find a problem in the query after it has been
     * parsed also use it (for example `PageRequest`, with a page size above the maximum), so they all come out the same.
     *
     * @param  list<array{parameter: string, reason: string, replace?: array<string, string>}>  $violations
     */
    public static function exceptionFor(array $violations): JsonApiException {
      $messages = app(ErrorMessages::class);
      $codes = app(ErrorCodes::class);

      return new JsonApiException(array_map(fn(array $violation) => Error::make(
        status: 400,
        title: $messages->title(ErrorCodes::BAD_QUERY),
        detail: $messages->detail(ErrorCodes::BAD_QUERY, $violation['replace'] ?? [], $violation['reason']),
        code: $codes->resolve(ErrorCodes::BAD_QUERY),
        parameter: $violation['parameter'],
      ), $violations));
    }
  }
