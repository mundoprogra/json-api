<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Query\Eloquent\ApplyCriteria;

  /**
   * Aplica el `sort` del cliente a una consulta de Eloquent, con una lista blanca explícita de nombres.
   * Los nombres de la lista y los del cliente van en `snake_case`; una entrada `'nombre' => 'columna'` mapea el nombre a otra columna. El orden
   * se aplica en el orden pedido, el `default` se usa si el cliente no pide ninguno, y al final se agrega la clave primaria (si no está ya) para
   * que las páginas sean estables. Un nombre fuera de la lista es un `400` con `source.parameter = sort`.
   *
   * Applies the client `sort` to an Eloquent query, with an explicit whitelist of names.
   * The names in the list and the ones from the client are in `snake_case`; a `'name' => 'column'` entry maps the name to another column. The order
   * is applied in the order asked for, the `default` is used if the client asks for none, and the primary key is added at the end (if it is not
   * there already) so the pages are stable. A name outside the list is a `400` with `source.parameter = sort`.
   */
  final class Sorting {
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $allowed  nombres simples o `'nombre' => 'columna'` / plain names or `'name' => 'column'`
     * @param  string|null  $default  con la sintaxis del parámetro `sort`, por ejemplo `-created_at,title` / with the `sort` parameter syntax, for example `-created_at,title`
     * @return Builder<TModel>
     *
     * @throws InvalidArgumentException si un nombre de la lista se repite o no es válido, o si el `default` nombra algo que no está en la lista / if a name in the list is repeated or not valid, or if the `default` names something that is not in the list
     * @throws JsonApiException si el cliente pide ordenar por algo que no está en la lista / if the client asks to sort by something that is not in the list
     */
    public static function apply(Builder $query, array $allowed, ?string $default = null): Builder {
      // `Allowed` revisa los nombres y el `default` (los errores del programador salen aquí, antes de leer la petición).
      // `Allowed` checks the names and the `default` (developer errors come out here, before the request is read).
      $list = Allowed::make(sort: Columns::names($allowed), defaultSort: $default);
      $sort = $list->defaultSort();

      $asked = QueryParameters::of(app('request'))->sort();

      foreach ($asked as $item) {
        if (!$list->allowsSort($item['field'])) {
          throw QueryParameters::exceptionFor([['parameter' => 'sort', 'reason' => 'sort_not_allowed']]);
        }
      }

      // El orden pedido manda; sin él, el `default`. Aplicarlo (con el desempate por la clave primaria) lo hace el puente.
      // The asked order rules; without it, the `default`. Applying it (with the primary key tie-break) is done by the bridge.
      return ApplyCriteria::applySort($query, $asked !== [] ? $asked : $sort, $allowed);
    }
  }
