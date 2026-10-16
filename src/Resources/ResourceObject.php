<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;
  use LogicException;

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
      $attributes = $this->filter($this->jsonApiAttributes($request));

      $data = ['type' => $this->type(), 'id' => $id];

      if ($attributes !== []) {
        $data['attributes'] = $attributes;
      }

      return $data;
    }
  }
