<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use LogicException;

  /**
   * Una relación de un recurso: su *resource linkage* (solo `type` e `id` del recurso relacionado) y, si se piden, `links` y `meta`.
   * Se crea con el modelo (o `null`) y la clase de recurso que lo representa; el `type` y el `id` salen de esa clase, con lo que ella sobrescriba.
   * Guarda los recursos relacionados para que el `included` de la Fase 10 se arme con ellos sin cambiar esta API.
   * A relationship of a resource: its *resource linkage* (only the `type` and `id` of the related resource) and, if asked for, `links` and `meta`.
   * It is created with the model (or `null`) and the resource class that represents it; the `type` and `id` come from that class, with whatever it overrides.
   * It keeps the related resources so the Phase 10 `included` can be built from them without changing this API.
   */
  final class Relationship {
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
