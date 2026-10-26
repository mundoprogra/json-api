<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Http\Request;
  use Illuminate\Pagination\LengthAwarePaginator;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Los links y el `meta` de paginación de JSON:API, armados a partir de la página que trajo Eloquent y de la petición.
   * El paginador de Laravel usa `?page=2`; aquí se usa `page[number]` y `page[size]`. Los links conservan lo que mandó el cliente tal como lo
   * mandó (`sort`, `filter`, `include`, `fields` y lo ajeno), cambian solo el número de página y siempre llevan el tamaño efectivo.
   * La URL base sale de la petición, así que respeta los proxies de confianza. Es inmutable.
   *
   * The JSON:API pagination links and `meta`, built from the page Eloquent fetched and from the request.
   * Laravel's paginator uses `?page=2`; here `page[number]` and `page[size]` are used. The links keep what the client sent exactly as sent
   * (`sort`, `filter`, `include`, `fields` and anything else), change only the page number and always carry the effective size.
   * The base URL comes from the request, so it respects trusted proxies. It is immutable.
   */
  final class Pagination {
    /**
     * @param  array{first: string, last: string, prev: string|null, next: string|null}  $links
     * @param  array{page: array<string, int>}  $meta
     */
    private function __construct(
      private readonly array $links,
      private readonly array $meta,
    ) {
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    public static function of(LengthAwarePaginator $paginator, Request $request): self {
      $current = $paginator->currentPage();
      $last = $paginator->lastPage();
      $size = $paginator->perPage();
      $names = MemberName::for(app(Settings::class)->memberCase());

      // `prev` de una página mayor que la última lleva a la última que existe, no a una vacía; en la primera no hay `prev`.
      // The `prev` of a page beyond the last leads to the last one that exists, not to an empty one; the first page has no `prev`.
      $links = [
        'first' => self::url($request, 1, $size),
        'last' => self::url($request, $last, $size),
        'prev' => $current > 1 ? self::url($request, min($current - 1, $last), $size) : null,
        'next' => $current < $last ? self::url($request, $current + 1, $size) : null,
      ];

      /** @var array<string, int> $page */
      $page = $names->keysFromSnake([
        'current_page' => $current,
        'per_page' => $size,
        'last_page' => $last,
        'total' => $paginator->total(),
      ]);

      return new self($links, ['page' => $page]);
    }

    /**
     * @return array{first: string, last: string, prev: string|null, next: string|null}
     */
    public function links(): array {
      return $this->links;
    }

    /**
     * @return array{page: array<string, int>}
     */
    public function meta(): array {
      return $this->meta;
    }

    /**
     * La URL de una página: la ruta de la petición, todo lo que mandó el cliente menos `page`, y `page` con sus otras claves, el número y el tamaño.
     * Los corchetes y los caracteres especiales van codificados (RFC 3986).
     *
     * The URL of a page: the request path, everything the client sent except `page`, and `page` with its other keys, the number and the size.
     * Brackets and special characters are encoded (RFC 3986).
     */
    private static function url(Request $request, int $number, int $size): string {
      $query = $request->query->all();
      $asked = $query['page'] ?? [];
      unset($query['page']);

      $other = is_array($asked) ? array_diff_key($asked, ['number' => true, 'size' => true]) : [];
      $query['page'] = [...$other, 'number' => $number, 'size' => $size];

      return $request->url() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
  }
