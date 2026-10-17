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
      $attributes = $this->memberNames($this->filter($this->jsonApiAttributes($request)));

      $data = ['type' => $this->type(), 'id' => $id];

      if ($attributes !== []) {
        $data['attributes'] = $attributes;
      }

      return $data;
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
    private function memberNames(array $attributes): array {
      if ($attributes !== [] && array_is_list($attributes)) {
        throw new LogicException(sprintf('The attributes of %s must have names (an object), not be a list of values.', static::class));
      }

      foreach (['id', 'type'] as $reserved) {
        if (array_key_exists($reserved, $attributes)) {
          throw new LogicException(sprintf('%s has an attribute named "%s", which the JSON:API spec reserves for the resource object.', static::class, $reserved));
        }
      }

      try {
        return MemberName::for(app(Settings::class)->memberCase())->keysFromSnake($attributes);
      } catch (InvalidArgumentException $e) {
        throw new LogicException(sprintf('The attributes of %s are repeated: %s', static::class, $e->getMessage()), 0, $e);
      }
    }
  }
