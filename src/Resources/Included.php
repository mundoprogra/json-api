<?php

  namespace MundoProgra\JsonApi\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Query\QueryParameters;

  /**
   * El `included` de un documento de JSON:API: los recursos relacionados que el cliente pidió con `include`, a partir de los recursos principales.
   * Se recorre cada ruta pedida (`comments.author`) un nivel a la vez, en el orden en que el cliente las dio; pedir una ruta incluye también sus
   * prefijos. Solo se siguen relaciones ya cargadas y que el recurso muestra (las que un `fields[tipo]` oculta no cuentan), así que nunca se dispara una
   * consulta y se cumple el *full linkage* de la spec: todo recurso incluido se alcanza por el linkage de otro recurso del documento.
   * Cada recurso incluido aparece una sola vez (por `type` e `id`) y nunca si ya es un recurso principal; se recorre igual, para seguir sus rutas.
   * Una ruta que el recurso no tiene se ignora (rechazar rutas no permitidas es de `jsonApiInclude()`). Fuera del alcance del paquete no hay `included`.
   *
   * The `included` of a JSON:API document: the related resources the client asked for with `include`, starting from the primary resources.
   * Each path asked for (`comments.author`) is walked one level at a time, in the order the client gave them; asking for a path also includes its
   * prefixes. Only relationships that are already loaded and that the resource shows are followed (those a `fields[type]` hides do not count), so a
   * query is never fired and the *full linkage* of the spec holds: every included resource is reachable through the linkage of another resource in
   * the document. Each included resource appears once (by `type` and `id`) and never if it is already a primary resource; it is walked anyway, to
   * follow its paths. A path the resource does not have is ignored (rejecting paths that are not allowed is `jsonApiInclude()`'s job). Outside the
   * package scope there is no `included`.
   */
  final class Included {
    /**
     * @param  list<ResourceObject>  $primary  los recursos de `data` / the resources in `data`
     * @return list<array<string, mixed>> los objetos de recurso incluidos, en orden / the included resource objects, in order
     *
     * @throws JsonApiException si la consulta está mal formada / if the query is malformed
     */
    public static function of(array $primary, Request $request): array {
      if ($primary === [] || !app(RequestScope::class)->appliesTo($request)) {
        return [];
      }

      $paths = QueryParameters::of($request)->includes();

      if ($paths === []) {
        return [];
      }

      $seen = [];

      foreach ($primary as $resource) {
        $seen[self::key($resource)] = true;
      }

      // Las relaciones de cada recurso se calculan una sola vez aunque varias rutas pasen por él.
      // The relationships of each resource are worked out once even if several paths go through it.
      $relationships = [];
      $included = [];

      foreach ($paths as $path) {
        $level = $primary;

        foreach ($path as $segment) {
          $next = [];

          foreach ($level as $resource) {
            $relationships[spl_object_id($resource)] ??= $resource->loadedRelationships($request);

            foreach (($relationships[spl_object_id($resource)][$segment] ?? null)?->resources() ?? [] as $target) {
              $key = self::key($target);

              if (!isset($seen[$key])) {
                $seen[$key] = true;
                $included[] = $target->resolve($request);
              }

              $next[$key] = $target;
            }
          }

          $level = array_values($next);
        }
      }

      return $included;
    }

    private static function key(ResourceObject $resource): string {
      return $resource->type() . ':' . $resource->id();
    }
  }
