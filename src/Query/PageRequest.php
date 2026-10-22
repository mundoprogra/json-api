<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\Middleware\Options;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Qué página y de qué tamaño hay que traer: el número y el tamaño efectivos de una petición.
   * El número es `page[number]` o 1. El tamaño es `page[size]` si el cliente lo pidió; si no, el argumento del controlador (`perPage`), las
   * opciones del middleware `json-api.options` (`per_page`) y, si tampoco, `pagination.size`. Después se acota por el máximo (`maxPerPage`,
   * `max_per_page` o `pagination.max_size`, por ese orden): lo que pide el cliente por encima
   * del máximo se recorta (`pagination.on_exceed = clamp`) o es un `400` con `source.parameter = page[size]` (`error`). Lo que pone el
   * programador por encima del máximo se recorta siempre, sin error: no es culpa del cliente. La configuración se lee en cada llamada.
   * Which page and of what size to fetch: the effective number and size of a request.
   * The number is `page[number]` or 1. The size is `page[size]` if the client asked for it; otherwise the controller argument (`perPage`), the
   * `json-api.options` middleware options (`per_page`) and, failing that, `pagination.size`. Then it is capped by the maximum (`maxPerPage`,
   * `max_per_page` or `pagination.max_size`, in that order): what the client asks for above
   * the maximum is trimmed (`pagination.on_exceed = clamp`) or is a `400` with `source.parameter = page[size]` (`error`). What the developer
   * sets above the maximum is always trimmed, with no error: it is not the client's fault. The config is read on every call.
   */
  final class PageRequest {
    private function __construct(
      private readonly int $number,
      private readonly int $size,
    ) {
    }

    /**
     * @param  int|null  $perPage  tamaño por defecto cuando el cliente no pide uno / default size when the client asks for none
     * @param  int|null  $maxPerPage  máximo que reemplaza a `pagination.max_size` / maximum that replaces `pagination.max_size`
     *
     * @throws InvalidArgumentException si `$perPage` o `$maxPerPage` no son enteros positivos / if `$perPage` or `$maxPerPage` are not positive integers
     * @throws JsonApiException si la consulta está mal formada, o si el tamaño pedido pasa del máximo y `on_exceed` es `error` / if the query is malformed, or the size asked for is above the maximum and `on_exceed` is `error`
     */
    public static function of(Request $request, ?int $perPage = null, ?int $maxPerPage = null): self {
      foreach (['perPage' => $perPage, 'maxPerPage' => $maxPerPage] as $name => $value) {
        if ($value !== null && $value < 1) {
          throw new InvalidArgumentException(sprintf('%s must be a positive integer, %d given.', $name, $value));
        }
      }

      $settings = app(Settings::class);
      $query = QueryParameters::of($request);

      // La cascada de lo que decide el programador: el argumento, las opciones del middleware y la configuración.
      // The cascade of what the developer decides: the argument, the middleware options and the config.
      $options = $request->attributes->get(Options::ATTRIBUTE);
      $options = is_array($options) ? $options : [];

      $max = $maxPerPage ?? $options['max_per_page'] ?? $settings->paginationMaxSize();
      $default = $perPage ?? $options['per_page'] ?? $settings->paginationSize();
      $asked = $query->pageSize();

      if ($asked !== null && $asked > $max && $settings->paginationOnExceed() === 'error') {
        throw QueryParameters::exceptionFor([
          ['parameter' => 'page[size]', 'reason' => 'page_size_too_large', 'replace' => ['max' => (string) $max]],
        ]);
      }

      return new self($query->pageNumber() ?? 1, min($asked ?? $default, $max));
    }

    public function number(): int {
      return $this->number;
    }

    public function size(): int {
      return $this->size;
    }
  }
