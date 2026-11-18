<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;

  /**
   * Carga por anticipado (`with()`) las relaciones que el cliente pide en `include`, con una lista blanca explícita de rutas.
   * Las rutas van en `snake_case` y con puntos para los niveles (`comments.author`); permitir una ruta permite también sus prefijos
   * (`comments`). Una ruta fuera de la lista es un `400` con `source.parameter = include`. Solo carga: el `included` del documento llega con las
   * colecciones. Como la carga es anticipada, el número de consultas depende de cuántas relaciones se piden y no de cuántas filas hay.
   *
   * Eager loads (`with()`) the relations the client asks for in `include`, with an explicit whitelist of paths.
   * Paths are in `snake_case` and use dots for the levels (`comments.author`); allowing a path also allows its prefixes (`comments`).
   * A path outside the list is a `400` with `source.parameter = include`. It only loads: the document `included` arrives with collections.
   * Since loading is eager, the number of queries depends on how many relations are asked for and not on how many rows there are.
   */
  final class Including {
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $allowed  rutas como `author` o `comments.author` / paths like `author` or `comments.author`
     * @return Builder<TModel>
     *
     * @throws InvalidArgumentException si una ruta de la lista se repite o no es válida / if a path in the list is repeated or not valid
     * @throws JsonApiException si el cliente pide una ruta que no está en la lista / if the client asks for a path that is not in the list
     */
    public static function apply(Builder $query, array $allowed): Builder {
      // `Allowed` revisa las rutas (los errores del programador salen aquí) y sabe que permitir una ruta permite también sus prefijos.
      // `Allowed` checks the paths (developer errors come out here) and knows that allowing a path also allows its prefixes.
      $list = Allowed::make(include: $allowed);
      $asked = array_map(fn(array $segments) => implode('.', $segments), QueryParameters::of(app('request'))->includes());

      foreach ($asked as $path) {
        if (!$list->allowsInclude($path)) {
          throw QueryParameters::exceptionFor([['parameter' => 'include', 'reason' => 'include_not_allowed']]);
        }
      }

      return $asked === [] ? $query : $query->with($asked);
    }
  }
