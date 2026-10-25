<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
  use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
  use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;
  use Illuminate\Pagination\LengthAwarePaginator;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Query\Pagination;
  use MundoProgra\JsonApi\Support\MediaType;
  use MundoProgra\JsonApi\Support\Settings;
  use Traversable;

  /**
   * Una colección de recursos de JSON:API: `{"jsonapi": ..., "data": [...]}` con un objeto de recurso completo por elemento, en el orden recibido y
   * siempre como lista (`data: []` si está vacía). Se crea con `MiResource::collection($modelos)`. Acepta un arreglo o una colección; con un paginador
   * de Laravel (`jsonApiPaginate()` o `paginate()`) suma los `links` de paginación y el `meta.page` (ver `Pagination`). Un paginador simple o de
   * cursor se rechaza, porque no sabe cuántas páginas hay y el documento saldría a medias. La respuesta lleva el `Content-Type` exacto de JSON:API y
   * `200`. `with()` y `additional()` siguen funcionando, pero nunca pisan `jsonapi`, `data` ni los `links` y `meta` de la paginación.
   *
   * A collection of JSON:API resources: `{"jsonapi": ..., "data": [...]}` with a full resource object per element, in the order received and always
   * as a list (`data: []` if empty). It is created with `MyResource::collection($models)`. It accepts an array or a collection; with a Laravel
   * paginator (`jsonApiPaginate()` or `paginate()`) it adds the pagination `links` and `meta.page` (see `Pagination`). A simple or cursor paginator
   * is rejected, because it does not know how many pages there are and the document would come out half done. The response carries the exact
   * JSON:API `Content-Type` and `200`. `with()` and `additional()` keep working, but never override `jsonapi`, `data` or the pagination `links` and `meta`.
   * With `include` it adds the `included` of all its elements right after `data` (see `Included`).
   */
  final class ResourceCollection extends JsonResource {
    /** @var iterable<mixed> */
    private readonly iterable $items;

    /** @var list<ResourceObject>|null */
    private ?array $built = null;

    /**
     * @param  class-string<ResourceObject>  $resourceClass  la clase que representa cada elemento / the class that represents each element
     *
     * @throws InvalidArgumentException si no es un arreglo ni algo que se pueda recorrer / if it is neither an array nor something traversable
     * @throws LogicException si es un paginador simple o de cursor / if it is a simple or cursor paginator
     */
    public function __construct(mixed $resources, private readonly string $resourceClass) {
      if ($resources instanceof CursorPaginatorContract || ($resources instanceof PaginatorContract && !$resources instanceof LengthAwarePaginatorContract)) {
        throw new LogicException(sprintf('%s cannot take a simple or cursor paginator: it does not know how many pages there are. Use a length-aware paginator (paginate() or jsonApiPaginate()).', self::class));
      }

      if (!is_array($resources) && !$resources instanceof Traversable) {
        throw new InvalidArgumentException(sprintf('A collection of %s needs an array or a collection of models, %s given.', $resourceClass, get_debug_type($resources)));
      }

      parent::__construct($resources);

      $this->items = $resources;
    }

    /**
     * Los objetos de recurso de cada elemento, como lista y en su orden.
     * The resource objects of each element, as a list and in their order.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array {
      return array_map(fn(ResourceObject $resource) => $resource->resolve($request), $this->resourceObjects());
    }

    /**
     * Un recurso por elemento, creados una sola vez: los usan `data` y el `included`.
     * One resource per element, created once: `data` and `included` both use them.
     *
     * @return list<ResourceObject>
     */
    private function resourceObjects(): array {
      if ($this->built === null) {
        $this->built = [];

        foreach ($this->items as $item) {
          $this->built[] = $this->resourceClass::make($item);
        }
      }

      return $this->built;
    }

    /**
     * El cuerpo completo del documento de JSON:API como arreglo: `jsonapi` (si está activo), `data` y los miembros que agreguen `with()` y
     * `additional()`, que nunca pueden pisar `jsonapi` ni `data`. No necesita una petición HTTP: sin ella usa la que haya en el contenedor y,
     * si no hay ninguna, una vacía.
     * The full JSON:API document body as an array: `jsonapi` (if on), `data` and the members `with()` and `additional()` add, which can never
     * override `jsonapi` or `data`. It needs no HTTP request: without one it uses the one in the container and, if there is none, an empty one.
     *
     * @return array<string, mixed>
     */
    public function document(?Request $request = null): array {
      // Se lee del contenedor directamente: `resolveRequestFromContainer()` de JsonResource solo existe desde Laravel 12.
      // It is read from the container directly: JsonResource's `resolveRequestFromContainer()` only exists since Laravel 12.
      $request ??= app()->bound('request') ? app('request') : Request::create('/');

      $document = [];

      if (app(Settings::class)->jsonApiObject()) {
        $document['jsonapi'] = ['version' => MediaType::JSON_API_VERSION];
      }

      $document['data'] = $this->resolve($request);

      // Los recursos relacionados que el cliente pidió con `include`, de todos los elementos; si no hay ninguno, no sale el miembro.
      // The related resources the client asked for with `include`, from all the elements; if there are none, the member does not come out.
      $included = Included::of($this->resourceObjects(), $request);

      if ($included !== []) {
        $document['included'] = $included;
      }

      $extra = array_merge_recursive($this->with($request), $this->additional);
      unset($extra['jsonapi'], $extra['data']);

      // Con un paginador de Laravel se suman los links y el `meta.page`. Los que agregue `additional()` van después y no pueden pisarlos.
      // With a Laravel paginator the links and `meta.page` are added. Those `additional()` adds come after and cannot override them.
      if ($this->resource instanceof LengthAwarePaginator) {
        $pagination = Pagination::of($this->resource, $request);

        $document['links'] = $pagination->links() + (array) ($extra['links'] ?? []);
        $document['meta'] = $pagination->meta() + (array) ($extra['meta'] ?? []);
        unset($extra['links'], $extra['meta']);
      }

      return $document + $extra;
    }

    /**
     * La respuesta HTTP: el documento con el `Content-Type` exacto de JSON:API y `200`.
     * The HTTP response: the document with the exact JSON:API `Content-Type` and `200`.
     *
     * @param  Request  $request
     */
    final public function toResponse($request): JsonResponse {
      $response = new JsonResponse($this->document($request), 200, [], $this->jsonOptions());
      $response->headers->set('Content-Type', MediaType::JSON_API);

      $this->withResponse($request, $response);

      return $response;
    }
  }
