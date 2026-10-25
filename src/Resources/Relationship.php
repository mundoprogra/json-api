<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Contracts\Support\Responsable;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Support\MediaType;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Una relación de un recurso: su *resource linkage* (solo `type` e `id` del recurso relacionado) y, si se piden, `links` y `meta`.
   * Se crea con el modelo (o `null`) y la clase de recurso que lo representa; el `type` y el `id` salen de esa clase, con lo que ella sobrescriba.
   * Guarda los recursos relacionados para que el `included` de la Fase 10 se arme con ellos sin cambiar esta API.
   * A relationship of a resource: its *resource linkage* (only the `type` and `id` of the related resource) and, if asked for, `links` and `meta`.
   * It is created with the model (or `null`) and the resource class that represents it; the `type` and `id` come from that class, with whatever it overrides.
   * It keeps the related resources so the Phase 10 `included` can be built from them without changing this API.
   */
  final class Relationship implements Responsable {
    private ?string $selfLink = null;

    private ?string $relatedLink = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * @param  list<ResourceObject>|ResourceObject|null  $target  uno, ninguno (`null`) o una lista (vacía o no) / one, none (`null`) or a list (empty or not)
     * @param  bool  $loaded  false si la relación no se calculó: entonces no tiene `data` / false if the relationship was not worked out: then it has no `data`
     */
    private function __construct(private readonly array|ResourceObject|null $target, private readonly bool $loaded = true) {
    }

    /**
     * Relación que no se calculó (no está cargada): no tiene `data`, solo `links` y/o `meta`. Sirve para anunciar una relación sin consultar la base
     * de datos. Sin `links` ni `meta` no es un objeto de relación válido y `toArray()` lo avisa como error del programador.
     * A relationship that was not worked out (it is not loaded): it has no `data`, only `links` and/or `meta`. It serves to announce a relationship
     * without querying the database. Without `links` or `meta` it is not a valid relationship object and `toArray()` reports it as a developer error.
     */
    public static function unloaded(): self {
      return new self(null, false);
    }

    /**
     * Relación a uno. Con `null` la relación está vacía y sale como `data: null`.
     * To-one relationship. With `null` the relationship is empty and comes out as `data: null`.
     *
     * @param  class-string<ResourceObject>  $resourceClass
     *
     * @throws InvalidArgumentException si la clase no es un ResourceObject / if the class is not a ResourceObject
     */
    public static function one(?Model $model, string $resourceClass): self {
      self::guard($resourceClass);

      return new self($model === null ? null : $resourceClass::make($model));
    }

    /**
     * Relación a muchos. Con una lista vacía sale como `data: []`.
     * To-many relationship. With an empty list it comes out as `data: []`.
     *
     * @param  iterable<Model>  $models
     * @param  class-string<ResourceObject>  $resourceClass
     *
     * @throws InvalidArgumentException si la clase no es un ResourceObject / if the class is not a ResourceObject
     */
    public static function many(iterable $models, string $resourceClass): self {
      self::guard($resourceClass);

      $resources = [];

      foreach ($models as $model) {
        $resources[] = $resourceClass::make($model);
      }

      return new self($resources);
    }

    /**
     * Devuelve una relación nueva con esos links; los que sean `null` no salen. La original no cambia.
     * Returns a new relationship with those links; those that are `null` do not come out. The original does not change.
     */
    public function links(?string $self = null, ?string $related = null): self {
      $clone = clone $this;
      $clone->selfLink = $self;
      $clone->relatedLink = $related;

      return $clone;
    }

    /**
     * Devuelve una relación nueva con ese `meta`. La original no cambia.
     * Returns a new relationship with that `meta`. The original does not change.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self {
      $clone = clone $this;
      $clone->meta = $meta;

      return $clone;
    }

    /**
     * Los recursos relacionados, como lista: ninguno si la relación está vacía o sin cargar, uno si es a uno. Es lo que recorre el `included`.
     * The related resources, as a list: none if the relationship is empty or unloaded, one if it is to-one. It is what `included` walks.
     *
     * @return list<ResourceObject>
     */
    public function resources(): array {
      if ($this->target === null) {
        return [];
      }

      return is_array($this->target) ? $this->target : [$this->target];
    }

    /**
     * El documento de un endpoint de relación (`GET /articles/1/relationships/author`): `jsonapi` (si está activo) y el objeto de la relación, con
     * `links`, `data` y `meta`. No necesita una petición HTTP.
     * The document of a relationship endpoint (`GET /articles/1/relationships/author`): `jsonapi` (if on) and the relationship object, with `links`,
     * `data` and `meta`. It needs no HTTP request.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException si un recurso relacionado no tiene id, o si una relación sin cargar no tiene ni `data` ni `meta` (un documento de primer nivel necesita uno de los dos) / if a related resource has no id, or if an unloaded relationship has neither `data` nor `meta` (a top-level document needs one of them)
     */
    public function document(): array {
      $object = $this->toArray();

      if (!array_key_exists('data', $object) && !array_key_exists('meta', $object)) {
        throw new LogicException('An unloaded relationship cannot be a response with only links: a JSON:API document needs data or meta at the top level.');
      }

      $document = [];

      if (app(Settings::class)->jsonApiObject()) {
        $document['jsonapi'] = ['version' => MediaType::JSON_API_VERSION];
      }

      return $document + $object;
    }

    /**
     * La respuesta HTTP de un endpoint de relación: el documento con el `Content-Type` exacto de JSON:API y `200`. Por eso se puede devolver una
     * `Relationship` desde un controlador.
     * The HTTP response of a relationship endpoint: the document with the exact JSON:API `Content-Type` and `200`. That is why a `Relationship` can
     * be returned from a controller.
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse {
      $response = new JsonResponse($this->document());
      $response->headers->set('Content-Type', MediaType::JSON_API);

      return $response;
    }

    /**
     * El objeto de relación en el orden `links`, `data`, `meta`; los miembros vacíos de `links` y `meta` se omiten.
     * The relationship object in the order `links`, `data`, `meta`; the empty `links` and `meta` members are left out.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException si un recurso relacionado no tiene id, o si una relación sin cargar no tiene ni links ni meta / if a related resource has no id, or if an unloaded relationship has neither links nor meta
     */
    public function toArray(): array {
      $links = array_filter(['self' => $this->selfLink, 'related' => $this->relatedLink], fn($link) => $link !== null);

      if (!$this->loaded && $links === [] && $this->meta === []) {
        throw new LogicException('An unloaded relationship needs links or meta: the JSON:API spec requires a relationship object to have at least one of links, data or meta.');
      }

      $object = [];

      if ($links !== []) {
        $object['links'] = $links;
      }

      if ($this->loaded) {
        $object['data'] = is_array($this->target)
          ? array_map(fn(ResourceObject $resource) => $this->identifier($resource), $this->target)
          : ($this->target === null ? null : $this->identifier($this->target));
      }

      if ($this->meta !== []) {
        $object['meta'] = $this->meta;
      }

      return $object;
    }

    /**
     * @return array{type: string, id: string}
     */
    private function identifier(ResourceObject $resource): array {
      $id = $resource->id();

      if ($id === '') {
        throw new LogicException(sprintf('A related %s has no id: a relationship can only point at resources that have one (is the model saved?).', $resource::class));
      }

      return ['type' => $resource->type(), 'id' => $id];
    }

    private static function guard(string $resourceClass): void {
      if (!is_subclass_of($resourceClass, ResourceObject::class)) {
        throw new InvalidArgumentException(sprintf('%s is not a %s.', $resourceClass, ResourceObject::class));
      }
    }
  }
