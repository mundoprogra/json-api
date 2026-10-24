<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;

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
     * @throws InvalidArgumentException si el `default` nombra algo que no está en la lista / if the `default` names something that is not in the list
     * @throws JsonApiException si el cliente pide ordenar por algo que no está en la lista / if the client asks to sort by something that is not in the list
     */
    public static function apply(Builder $query, array $allowed, ?string $default = null): Builder {
      $columns = Columns::map($allowed);
      $sort = self::fromDefault($default, $columns);

      $asked = QueryParameters::of(app('request'))->sort();

      foreach ($asked as $item) {
        if (!array_key_exists($item['field'], $columns)) {
          throw QueryParameters::exceptionFor([['parameter' => 'sort', 'reason' => 'sort_not_allowed']]);
        }
      }

      $sort = $asked !== [] ? $asked : $sort;

      if ($sort === []) {
        return $query;
      }

      $key = $query->getModel()->getQualifiedKeyName();
      $applied = [];

      foreach ($sort as $item) {
        $column = Columns::qualify($query, $columns[$item['field']]);

        if (in_array($column, $applied, true)) {
          continue;
        }

        $applied[] = $column;
        $query->orderBy($column, $item['descending'] ? 'desc' : 'asc');
      }

      // El desempate por la clave primaria: sin él, las filas con el mismo valor pueden repetirse o saltarse entre páginas.
      // The tie-break on the primary key: without it, rows with the same value may repeat or be skipped between pages.
      if (!in_array($key, $applied, true)) {
        $query->orderBy($key, 'asc');
      }

      return $query;
    }

    /**
     * El `default` ya analizado, o una lista vacía si no hay.
     * The already parsed `default`, or an empty list if there is none.
     *
     * @param  array<string, string>  $columns
     * @return list<array{field: string, descending: bool}>
     */
    private static function fromDefault(?string $default, array $columns): array {
      if ($default === null || $default === '') {
        return [];
      }

      $sort = [];

      foreach (explode(',', $default) as $item) {
        $descending = str_starts_with($item, '-');
        $field = $descending ? substr($item, 1) : $item;

        if (!array_key_exists($field, $columns)) {
          throw new InvalidArgumentException(sprintf('The default sort "%s" is not in the list of allowed names.', $field));
        }

        $sort[] = ['field' => $field, 'descending' => $descending];
      }

      return $sort;
    }
  }
