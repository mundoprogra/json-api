<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use Illuminate\Database\Eloquent\Model;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Pointer;

  /**
   * Convierte un `FormRequest` en uno que entiende documentos JSON:API. Tiene tres efectos:
   *  1. Las reglas se escriben sobre los datos planos del documento: los atributos en snake_case y, para cada relación, su objeto
   *     (`author.data.id`, `tags.data.*.id`). Así `validated()` sirve para llenar un modelo y los errores salen con su `source.pointer`.
   *  2. Si el documento no tiene la forma de un recurso, o trae un `id` que no se acepta, se responde antes de correr las reglas (400 o 403).
   *  3. Si el `type` no es el que el endpoint espera (`jsonApiExpectedType()`), o el `id` no es el de la URL (`jsonApiExpectedId()`), es un `409`
   *     que también sale antes de las reglas.
   * Todo ocurre en `validationData()`: si la app sobrescribe ese método, debe devolver lo mismo o llamar a `RequestDocument::of($this)`.
   *
   * Turns a `FormRequest` into one that understands JSON:API documents. It has three effects:
   *  1. The rules are written over the flat data of the document: attributes in snake_case and, for each relationship, its object
   *     (`author.data.id`, `tags.data.*.id`). That way `validated()` can fill a model and the errors come out with their `source.pointer`.
   *  2. If the document does not have the shape of a resource, or carries an `id` that is not accepted, the answer comes before the rules run (400 or 403).
   *  3. If the `type` is not the one the endpoint expects (`jsonApiExpectedType()`), or the `id` is not the one in the URL (`jsonApiExpectedId()`),
   *     it is a `409` that also comes before the rules.
   * It all happens in `validationData()`: if the app overrides that method, it must return the same or call `RequestDocument::of($this)`.
   *
   * Se usa solo en una clase que extiende `FormRequest`, que viene en `laravel/framework` (no es parte de ningún paquete `illuminate/*` suelto).
   * It is only used in a class that extends `FormRequest`, which comes with `laravel/framework` (it is not part of any standalone `illuminate/*` package).
   *
   * @phpstan-require-extends \Illuminate\Foundation\Http\FormRequest
   */
  trait InteractsWithJsonApi {
    /**
     * Los datos que se validan: los atributos (snake_case) y las relaciones (con su objeto), sin la query string.
     * The data that is validated: the attributes (snake_case) and the relationships (with their object), without the query string.
     *
     * @return array<int|string, mixed>
     *
     * @throws JsonApiException
     */
    public function validationData(): array {
      $document = RequestDocument::of($this);

      $conflicts = [];

      $type = $this->jsonApiExpectedType();

      if ($type !== null && $document->type() !== $type) {
        $conflicts[] = ['pointer' => Pointer::from('data', 'type'), 'reason' => 'type_mismatch'];
      }

      $id = in_array($this->method(), ['PATCH', 'PUT'], true) ? $this->jsonApiExpectedId() : null;

      if ($id !== null && $document->id() !== $id) {
        $conflicts[] = ['pointer' => Pointer::from('data', 'id'), 'reason' => 'id_mismatch'];
      }

      if ($conflicts !== []) {
        throw BadDocument::conflictFor($conflicts);
      }

      return [...$document->attributes(), ...$document->relationships()];
    }

    /**
     * El `type` con el que trabaja este endpoint. Si es null (lo normal hasta que la app lo declare) no se compara.
     * The `type` this endpoint works with. If it is null (the default until the app declares it) it is not compared.
     */
    protected function jsonApiExpectedType(): ?string {
      return null;
    }

    /**
     * El `id` que debe traer el documento al actualizar (`PATCH` o `PUT`). Por defecto es el último parámetro de la ruta: la clave de ruta si es un
     * modelo, o el valor si es un texto o un número. Si la ruta no tiene parámetros, o la app devuelve null, no se compara.
     *
     * The `id` the document must carry when updating (`PATCH` or `PUT`). By default it is the last route parameter: the route key if it is a model,
     * or the value if it is a string or a number. If the route has no parameters, or the app returns null, it is not compared.
     */
    protected function jsonApiExpectedId(): ?string {
      $parameters = $this->route()?->parameters() ?? [];

      if ($parameters === []) {
        return null;
      }

      $last = end($parameters);

      if ($last instanceof Model) {
        return (string) $last->getRouteKey();
      }

      return is_scalar($last) ? (string) $last : null;
    }
  }
