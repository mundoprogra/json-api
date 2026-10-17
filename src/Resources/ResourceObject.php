<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Errors\ErrorDocument;
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

      $data = ['type' => $this->type(), 'id' => $id];

      if ($attributes !== []) {
        $data['attributes'] = $attributes;
      }

      if ($relationships !== []) {
        $data['relationships'] = $relationships;
      }

      // Igual que `attributes`: un miembro vacío se omite porque una lista vacía no sería un objeto.
      // Like `attributes`: an empty member is left out because an empty list would not be an object.
      $links = $this->filter(array_merge($this->selfLink(), $this->jsonApiLinks($request)));
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
     * El nombre corto de la ruta de consulta de este recurso (por ejemplo `authors.show`), de donde sale su `links.self`.
     * Si es null no hay `links.self`. Se le antepone `links.route_name_prefix`.
     * The short name of the show route of this resource (for example `authors.show`), where its `links.self` comes from.
     * If it is null there is no `links.self`. `links.route_name_prefix` is put in front of it.
     */
    protected function jsonApiRoute(): ?string {
      return null;
    }

    /**
     * Más links del recurso. Se agregan después del `self`, y uno con el mismo nombre reemplaza al que armó el paquete.
     * More links of the resource. They are added after the `self`, and one with the same name replaces the one the package built.
     *
     * @return array<string, mixed>
     */
    protected function jsonApiLinks(Request $request): array {
      return [];
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
     * El documento de JSON:API: `jsonapi` (si está activo), `data` y los miembros que agreguen `with()` y `additional()`.
     * Esos nunca pueden pisar `jsonapi` ni `data`. Sale con el `Content-Type` exacto de JSON:API, `200`, o `201` si el modelo se acaba de crear.
     * The JSON:API document: `jsonapi` (if on), `data` and the members `with()` and `additional()` add.
     * Those can never override `jsonapi` or `data`. It goes out with the exact JSON:API `Content-Type`, `200`, or `201` if the model was just created.
     *
     * @param  Request  $request
     */
    final public function toResponse($request): JsonResponse {
      $document = [];

      if (app(Settings::class)->jsonApiObject()) {
        $document['jsonapi'] = ['version' => ErrorDocument::JSON_API_VERSION];
      }

      $document['data'] = $this->resolve($request);

      $extra = array_merge_recursive($this->with($request), $this->additional);
      unset($extra['jsonapi'], $extra['data']);

      $status = $this->resource instanceof Model && $this->resource->wasRecentlyCreated ? 201 : 200;
      $response = new JsonResponse($document + $extra, $status, [], $this->jsonOptions());
      $response->headers->set('Content-Type', ErrorDocument::MEDIA_TYPE);

      // Un recurso recién creado dice dónde quedó: su propio `links.self`.
      // A newly created resource says where it ended up: its own `links.self`.
      $self = $document['data']['links']['self'] ?? null;

      if ($status === 201 && is_string($self)) {
        $response->headers->set('Location', $self);
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
