<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Support\MediaType;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Un objeto de recurso de JSON:API: `type`, `id` (siempre texto) y `attributes`.
   * Quien lo escribe solo declara `jsonApiAttributes()`. El tipo sale de la tabla del modelo y el id de su clave de ruta; ambos se sobrescriben
   * con `type()` e `id()`, y un recurso que no es un modelo tiene que declararlos. Los métodos son públicos porque una relación los lee.
   * A JSON:API resource object: `type`, `id` (always text) and `attributes`.
   * Whoever writes it only declares `jsonApiAttributes()`. The type comes from the model table and the id from its route key; both are overridden
   * with `type()` and `id()`, and a resource that is not a model has to declare them. They are public because a relationship reads them.
   */
  abstract class ResourceObject extends JsonResource {
    /**
     * Los atributos del recurso. Un valor `null` se conserva y un `$this->when(...)` que no aplica se omite.
     * The attributes of the resource. A `null` value is kept and a `$this->when(...)` that does not apply is left out.
     *
     * @return array<string, mixed>
     */
    abstract protected function jsonApiAttributes(Request $request): array;

    /**
     * Una colección de recursos de esta clase como documento de JSON:API (ver `ResourceCollection`). Reemplaza a la de `JsonResource`, que no daría
     * un documento JSON:API completo.
     * A collection of resources of this class as a JSON:API document (see `ResourceCollection`). It replaces the one in `JsonResource`, which would
     * not give a full JSON:API document.
     *
     * @param  mixed  $resource  un arreglo o una colección de modelos / an array or a collection of models
     *
     * @throws InvalidArgumentException si no es un arreglo ni algo que se pueda recorrer / if it is neither an array nor something traversable
     * @throws LogicException si es un paginador simple o de cursor / if it is a simple or cursor paginator
     */
    public static function collection($resource): ResourceCollection {
      return new ResourceCollection($resource, static::class);
    }

    /**
     * Por defecto, la tabla del modelo.
     * By default, the table of the model.
     *
     * @throws LogicException si el recurso no es un modelo y no se sobrescribió / if the resource is not a model and it was not overridden
     */
    public function type(): string {
      if ($this->resource instanceof Model) {
        return $this->resource->getTable();
      }

      throw new LogicException(sprintf('%s must define type(): its resource is not an Eloquent model, so the type cannot be worked out.', static::class));
    }

    /**
     * Por defecto, la clave de ruta del modelo, siempre como texto.
     * By default, the route key of the model, always as text.
     *
     * @throws LogicException si el recurso no es un modelo y no se sobrescribió / if the resource is not a model and it was not overridden
     */
    public function id(): string {
      if ($this->resource instanceof Model) {
        return (string) $this->resource->getRouteKey();
      }

      throw new LogicException(sprintf('%s must define id(): its resource is not an Eloquent model, so the id cannot be worked out.', static::class));
    }

    /**
     * El objeto de recurso, sin envolver en `data` (el documento completo lo arma el paquete).
     * The resource object, not wrapped in `data` (the package builds the full document).
     *
     * @return array<string, mixed>
     *
     * @throws LogicException si el id queda vacío / if the id ends up empty
     */
    final public function toArray(Request $request): array {
      $id = $this->id();

      if ($id === '') {
        throw new LogicException(sprintf('The resource object of %s has no id: a resource in a response must have one (is the model saved?).', static::class));
      }

      // Se quitan antes los valores omitidos para saber si quedó algún atributo: una lista vacía no sería un objeto.
      // The omitted values are removed first to know whether any attribute is left: an empty list would not be an object.
      $attributes = $this->memberNames($this->filter($this->jsonApiAttributes($request)), 'attribute');
      $relationships = $this->memberNames($this->relationshipObjects($this->filter($this->jsonApiRelationships($request))), 'relationship');

      // La spec comparte un solo espacio de nombres entre atributos y relaciones: se compara ya convertido.
      // The spec shares a single namespace between attributes and relationships: they are compared already converted.
      foreach (array_keys(array_intersect_key($attributes, $relationships)) as $shared) {
        throw new LogicException(sprintf('%s has an attribute and a relationship both named "%s": the JSON:API spec makes them share one namespace.', static::class, $shared));
      }

      $type = $this->type();

      // Los sparse fieldsets se aplican al final: las revisiones de arriba son sobre el recurso completo, así un error del programador
      // salta siempre y no solo cuando el cliente pide justo esos campos.
      // Sparse fieldsets are applied last: the checks above are on the whole resource, so a developer error always shows up and not only
      // when the client happens to ask for those fields.
      $requested = $this->requestedFields($request, $type);

      if ($requested !== null) {
        $names = MemberName::for(app(Settings::class)->memberCase());
        $kept = array_flip(array_map(fn(string $field) => $names->fromSnake($field), $requested));

        $attributes = array_intersect_key($attributes, $kept);
        $relationships = array_intersect_key($relationships, $kept);
      }

      $data = ['type' => $type, 'id' => $id];

      if ($attributes !== []) {
        $data['attributes'] = $attributes;
      }

      if ($relationships !== []) {
        $data['relationships'] = $relationships;
      }

      // Igual que `attributes`: un miembro vacío se omite porque una lista vacía no sería un objeto.
      // Like `attributes`: an empty member is left out because an empty list would not be an object.
      $links = $this->filter(array_merge($this->selfLink(), $this->resourceLinks($request)));
      $meta = $this->filter($this->jsonApiMeta($request));

      if ($links !== []) {
        $data['links'] = $links;
      }

      if ($meta !== []) {
        $data['meta'] = $meta;
      }

      return $data;
    }

    /**
     * Los campos que el cliente pidió para este tipo con `fields[tipo]=a,b`, en snake_case; null si no hay restricción. Una lista vacía
     * (`fields[tipo]=`) significa "ninguno". Solo cuenta dentro del alcance del paquete (modo, prefijo, `except` o una ruta marcada con el
     * alias `json-api.apply` o el grupo `json-api`): fuera de él el recurso sale completo y `fields` se ignora, aunque esté mal formado.
     * The fields the client asked for this type with `fields[type]=a,b`, in snake_case; null if there is no restriction. An empty list
     * (`fields[type]=`) means "none". It only counts inside the package scope (mode, prefix, `except` or a route marked with the
     * `json-api.apply` alias or the `json-api` group): outside it the resource comes out whole and `fields` is ignored, even if it is malformed.
     *
     * @return list<string>|null
     *
     * @throws \MundoProgra\JsonApi\Exceptions\JsonApiException si la consulta está mal formada / if the query is malformed
     */
    private function requestedFields(Request $request, string $type): ?array {
      if (!app(RequestScope::class)->appliesTo($request)) {
        return null;
      }

      return QueryParameters::of($request)->fields()[$type] ?? null;
    }

    /**
     * El nombre corto de la ruta de consulta de este recurso (por ejemplo `authors.show`), de donde sale su `links.self`.
     * Si es null no hay `links.self`. Se le antepone `links.route_name_prefix`.
     * The short name of the show route of this resource (for example `authors.show`), where its `links.self` comes from.
     * If it is null there is no `links.self`. `links.route_name_prefix` is put in front of it.
     */
    protected function jsonApiRoute(): ?string {
      return null;
    }

    /**
     * Los links del recurso. La spec define solo `self` para un recurso (y no admite miembros adicionales), así que cualquier otro nombre es un
     * error. Sirve para escribir el `self` a mano, o como objeto de link (`href`, `title`, `meta`...); reemplaza al que armó el paquete.
     * The links of the resource. The spec defines only `self` for a resource (and admits no additional members), so any other name is an
     * error. It serves to write the `self` by hand, or as a link object (`href`, `title`, `meta`...); it replaces the one the package built.
     *
     * @return array<string, mixed>
     */
    protected function jsonApiLinks(Request $request): array {
      return [];
    }

    /**
     * Los links que devuelve `jsonApiLinks()`, ya revisados.
     * The links `jsonApiLinks()` returns, already checked.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException si hay un link que no se llama `self` / if there is a link not named `self`
     */
    private function resourceLinks(Request $request): array {
      $links = $this->jsonApiLinks($request);

      foreach (array_keys($links) as $name) {
        if ($name !== 'self') {
          throw new LogicException(sprintf('%s has a link named "%s": the JSON:API spec defines only "self" for the links of a resource object.', static::class, $name));
        }
      }

      return $links;
    }

    /**
     * El `meta` del recurso. Es de formato libre: sus nombres no se convierten.
     * The `meta` of the resource. It is free-form: its names are not converted.
     *
     * @return array<string, mixed>
     */
    protected function jsonApiMeta(Request $request): array {
      return [];
    }

    /**
     * Las relaciones del recurso, por nombre. Cada valor es un `Relationship`. Se usa `whenLoaded` para que solo salgan las cargadas: una
     * relación sin cargar se omite, y una a uno cargada pero vacía llega como `null` (así lo entrega `whenLoaded`) y sale como `data: null`.
     * The relationships of the resource, by name. Each value is a `Relationship`. `whenLoaded` is used so only the loaded ones come out: a
     * relation that is not loaded is left out, and a to-one that is loaded but empty arrives as `null` (that is how `whenLoaded` hands it over) and comes out as `data: null`.
     *
     * @return array<string, mixed>
     */
    protected function jsonApiRelationships(Request $request): array {
      return [];
    }

    /**
     * Pasa cada relación a su objeto de JSON:API. Un `null` es una relación a uno vacía.
     * Turns each relationship into its JSON:API object. A `null` is an empty to-one relationship.
     *
     * @param  array<int|string, mixed>  $relationships
     * @return array<int|string, mixed>
     *
     * @throws LogicException si un valor no es un Relationship ni null / if a value is neither a Relationship nor null
     */
    private function relationshipObjects(array $relationships): array {
      return array_map(function ($relationship) {
        if ($relationship === null) {
          return ['data' => null];
        }

        if (!$relationship instanceof Relationship) {
          throw new LogicException(sprintf('The relationships of %s must be %s objects (or null for an empty to-one), %s given.', static::class, Relationship::class, get_debug_type($relationship)));
        }

        return $relationship->toArray();
      }, $relationships);
    }

    /**
     * Los links de una relación de este recurso, a partir de su `links.self`: `{self}/relationships/{nombre}` y `{self}/{nombre}`, con el nombre en el
     * estilo de `member_case`. Se le pasan a `Relationship::links()`. El `self` es el que armó el paquete con la ruta o el que se escribió a mano en
     * `jsonApiLinks()` (si es un objeto de link, su `href`). Si el recurso no tiene `self`, da `[]`: el programador no está obligado a usarlo.
     * The links of a relationship of this resource, from its `links.self`: `{self}/relationships/{name}` and `{self}/{name}`, with the name in the
     * `member_case` style. They are handed to `Relationship::links()`. The `self` is the one the package built from the route or the one written by
     * hand in `jsonApiLinks()` (if it is a link object, its `href`). If the resource has no `self`, it gives `[]`: the developer is not obliged to use it.
     *
     * @param  string  $name  el nombre de la relación en snake_case / the relationship name in snake_case
     * @return array<string, string>
     */
    protected function relationshipLinks(Request $request, string $name): array {
      $self = array_merge($this->selfLink(), $this->resourceLinks($request))['self'] ?? null;
      $base = is_array($self) ? ($self['href'] ?? null) : $self;

      if (!is_string($base)) {
        return [];
      }

      $member = MemberName::for(app(Settings::class)->memberCase())->fromSnake($name);

      return ['self' => "$base/relationships/$member", 'related' => "$base/$member"];
    }

    /**
     * El link `self` armado con la ruta declarada. Si no se declaró, o si no existe una ruta con ese nombre (ya con el prefijo), no hay link.
     * El `id` del recurso es el parámetro de la ruta.
     * The `self` link built from the declared route. If none was declared, or no route with that name exists (prefix included), there is no link.
     * The id of the resource is the route parameter.
     *
     * @return array<string, string>
     */
    private function selfLink(): array {
      $name = $this->jsonApiRoute();

      if ($name === null) {
        return [];
      }

      $route = app(Settings::class)->routeNamePrefix() . $name;

      return app('router')->has($route) ? ['self' => route($route, [$this->id()])] : [];
    }

    /**
     * El cuerpo completo del documento de JSON:API como arreglo: `jsonapi` (si está activo), `data` y los miembros que agreguen
     * `with()` y `additional()`, que nunca pueden pisar `jsonapi` ni `data`. No necesita una petición HTTP (sirve para un job, un comando
     * o una prueba): sin ella usa la que haya en el contenedor y, si no hay ninguna, una vacía. Es el mismo cuerpo que la respuesta; el
     * estado y las cabeceras son solo de la respuesta.
     * The full JSON:API document body as an array: `jsonapi` (if on), `data` and the members `with()` and `additional()` add, which can
     * never override `jsonapi` or `data`. It needs no HTTP request (it serves a job, a command or a test): without one it uses the one in
     * the container and, if there is none, an empty one. It is the same body as the response; the status and the headers are the response's alone.
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

      // Un recurso vacío (`AuthorResource::make(null)`) es el recurso relacionado de una relación a uno vacía: la spec pide `data: null`.
      // An empty resource (`AuthorResource::make(null)`) is the related resource of an empty to-one relationship: the spec asks for `data: null`.
      $document['data'] = $this->resource === null ? null : $this->resolve($request);

      $extra = array_merge_recursive($this->with($request), $this->additional);
      unset($extra['jsonapi'], $extra['data']);

      return $document + $extra;
    }

    /**
     * La respuesta HTTP: el documento con el `Content-Type` exacto de JSON:API, `200`, o `201` si el modelo se acaba de crear.
     * The HTTP response: the document with the exact JSON:API `Content-Type`, `200`, or `201` if the model was just created.
     *
     * @param  Request  $request
     */
    final public function toResponse($request): JsonResponse {
      $document = $this->document($request);

      $status = $this->resource instanceof Model && $this->resource->wasRecentlyCreated ? 201 : 200;
      $response = new JsonResponse($document, $status, [], $this->jsonOptions());
      $response->headers->set('Content-Type', MediaType::JSON_API);

      // Un recurso recién creado dice dónde quedó: su propio `links.self`.
      // A newly created resource says where it ended up: its own `links.self`.
      $self = $document['data']['links']['self'] ?? null;
      $location = is_array($self) ? ($self['href'] ?? null) : $self;

      if ($status === 201 && is_string($location)) {
        $response->headers->set('Location', $location);
      }

      $this->withResponse($request, $response);

      return $response;
    }

    /**
     * Revisa los nombres de los atributos y los convierte del snake_case en que se escriben al estilo de `member_case`, solo en el primer nivel.
     * La spec prohíbe llamar `id` o `type` a un atributo, que `attributes` sea una lista sin nombres y que dos nombres terminen iguales:
     * son errores del programador y se avisan en lugar de mandar un documento inválido.
     * Checks the attribute names and converts them from the snake_case they are written in to the `member_case` style, first level only.
     * The spec forbids naming an attribute `id` or `type`, `attributes` being a list without names, and two names ending up equal:
     * those are developer errors and are reported instead of sending an invalid document.
     *
     * @param  array<int|string, mixed>  $attributes
     * @return array<int|string, mixed>
     *
     * @throws LogicException
     */
    private function memberNames(array $attributes, string $label): array {
      if ($attributes !== [] && array_is_list($attributes)) {
        throw new LogicException(sprintf('The %ss of %s must have names (an object), not be a list of values.', $label, static::class));
      }

      foreach (['id', 'type'] as $reserved) {
        if (array_key_exists($reserved, $attributes)) {
          throw new LogicException(sprintf('%s has %s named "%s", which the JSON:API spec reserves for the resource object.', static::class, $label === 'attribute' ? 'an attribute' : 'a relationship', $reserved));
        }
      }

      try {
        return MemberName::for(app(Settings::class)->memberCase())->keysFromSnake($attributes);
      } catch (InvalidArgumentException $e) {
        throw new LogicException(sprintf('The %ss of %s are repeated: %s', $label, static::class, $e->getMessage()), 0, $e);
      }
    }
  }
