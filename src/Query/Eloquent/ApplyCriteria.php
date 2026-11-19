<?php

  namespace MundoProgra\JsonApi\Query\Eloquent;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Pagination\LengthAwarePaginator;
  use InvalidArgumentException;
  use LogicException;
  use MundoProgra\JsonApi\Query\Columns;
  use MundoProgra\JsonApi\Query\ListCriteria;

  /**
   * El puente entre un `ListCriteria` y una consulta de Eloquent: lo usa el repositorio de Infraestructura de una app con Clean Architecture.
   * El `ListCriteria` dice QUÉ pidió el cliente; el mapa que se le pasa aquí (que vive en el repositorio, no en la API) dice a qué columna o
   * scope apunta cada nombre. No lee la petición ni `Settings`: todo viene en sus argumentos. Un nombre que el mapa no conoce es un error del
   * programador (`LogicException`), porque `Allowed` y el repositorio no coinciden y conviene verlo en la primera prueba en vez de ignorar el
   * filtro en silencio y devolver filas de más.
   * The bridge between a `ListCriteria` and an Eloquent query: the Infrastructure repository of a Clean Architecture app uses it. The
   * `ListCriteria` says WHAT the client asked for; the map passed in here (which lives in the repository, not in the API) says which column
   * or scope each name points to. It reads neither the request nor `Settings`: everything comes in its arguments. A name the map does not
   * know is a developer error (`LogicException`), because `Allowed` and the repository do not match and it is better to see it in the first
   * test than to ignore the filter silently and return too many rows.
   */
  final class ApplyCriteria {
    // El carácter de escape del LIKE: `!` funciona igual en todas las bases de datos, a diferencia de `\`, que MySQL y otras tratan aparte.
    // The LIKE escape character: `!` works the same in every database, unlike `\`, which MySQL and others treat separately.
    private const ESCAPE = '!';

    /**
     * Aplica los filtros del criterio con tres listas de nombres: `exact` (igualdad; una lista con comas es un `IN`), `partial` (`LIKE %valor%` con
     * `%` y `_` escapados) y `scopes` (llama a un scope del modelo con el valor). Una entrada `'nombre' => 'destino'` mapea el nombre a otra
     * columna o a otro scope. Varios filtros se combinan con AND, un valor vacío se ignora y los valores siempre van enlazados.
     * Applies the criteria filters with three lists of names: `exact` (equality; a comma list is an `IN`), `partial` (`LIKE %value%` with `%` and
     * `_` escaped) and `scopes` (calls a model scope with the value). A `'name' => 'target'` entry maps the name to another column or another
     * scope. Several filters combine with AND, an empty value is ignored and the values are always bound.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $exact
     * @param  array<int|string, string>  $partial
     * @param  array<int|string, string>  $scopes
     * @return Builder<TModel>
     *
     * @throws InvalidArgumentException si un mismo nombre está en más de una lista / if the same name is in more than one list
     * @throws LogicException si el criterio trae un filtro que ninguna lista conoce / if the criteria carries a filter no list knows
     */
    public static function filters(Builder $query, ListCriteria $criteria, array $exact = [], array $partial = [], array $scopes = []): Builder {
      $kinds = self::kinds($exact, $partial, $scopes);

      return self::applyFilters($query, $criteria->filters(), $kinds, self::kindOf($kinds));
    }

    /**
     * Aplica el orden del criterio, en el orden dado y con su dirección. Cada nombre se mapea a su columna con `$columns` (`'nombre' => 'columna'`;
     * una entrada sin nombre es su propia columna), una columna a la que apuntan dos nombres se ordena una sola vez y al final se agrega la clave
     * primaria (si no está ya) para que las páginas sean estables. Sin orden en el criterio, la consulta queda como está.
     * Applies the criteria sort, in the order given and with its direction. Each name is mapped to its column with `$columns` (`'name' =>
     * 'column'`; an entry without a name is its own column), a column two names point to is ordered only once and the primary key is added at
     * the end (if it is not there already) so the pages are stable. With no sort in the criteria, the query is left as it is.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $columns
     * @return Builder<TModel>
     *
     * @throws LogicException si el criterio ordena por un nombre que el mapa no conoce / if the criteria sorts by a name the map does not know
     */
    public static function sort(Builder $query, ListCriteria $criteria, array $columns = []): Builder {
      return self::applySort($query, $criteria->sort(), $columns);
    }

    /**
     * Carga por anticipado las rutas de `include` del criterio, tal cual como nombres de relación de Eloquent. Sin inclusiones, la consulta queda
     * como está.
     * Eager loads the criteria `include` paths, as they are, as Eloquent relation names. With no includes, the query is left as it is.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function includes(Builder $query, ListCriteria $criteria): Builder {
      return self::applyIncludes($query, $criteria->includes());
    }

    /**
     * Trae la página que dice el criterio, con su tamaño. Una página fuera de rango es una página vacía.
     * Fetches the page the criteria says, with its size. A page out of range is an empty page.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, ListCriteria $criteria): LengthAwarePaginator {
      return self::applyPage($query, $criteria->pageSize(), $criteria->pageNumber());
    }

    /**
     * Aplica el criterio completo a la consulta: los filtros, el orden y las inclusiones (en ese orden). No pagina: la página se pide aparte con
     * `paginate()`, para que el repositorio pueda también contar o recorrer sin ella. Devuelve la misma consulta.
     * Applies the whole criteria to the query: the filters, the sort and the includes (in that order). It does not paginate: the page is asked
     * for separately with `paginate()`, so the repository can also count or walk without it. It returns the same query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $exact
     * @param  array<int|string, string>  $partial
     * @param  array<int|string, string>  $scopes
     * @param  array<int|string, string>  $sort
     * @return Builder<TModel>
     *
     * @throws InvalidArgumentException si un mismo nombre de filtro está en más de una lista / if the same filter name is in more than one list
     * @throws LogicException si el criterio trae un filtro o un orden que el mapa no conoce / if the criteria carries a filter or a sort the map does not know
     */
    public static function to(Builder $query, ListCriteria $criteria, array $exact = [], array $partial = [], array $scopes = [], array $sort = []): Builder {
      self::filters($query, $criteria, $exact, $partial, $scopes);
      self::sort($query, $criteria, $sort);

      return self::includes($query, $criteria);
    }

    /**
     * El núcleo de los filtros, con los filtros ya como arreglo: lo comparten el puente y la macro `jsonApiFilter()`, que revisa lo que mandó el
     * cliente por su cuenta. Un nombre que ninguna lista conoce es un error del programador (`LogicException`).
     * The core of the filters, with the filters already as an array: the bridge and the `jsonApiFilter()` macro share it, the macro checking what
     * the client sent on its own. A name no list knows is a developer error (`LogicException`).
     *
     * @internal
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $filters
     * @param  array<string, array<string, string>>  $kinds  lo que devuelve `kinds()` / what `kinds()` returns
     * @param  array<string, string>  $kindOf  lo que devuelve `kindOf()` / what `kindOf()` returns
     * @return Builder<TModel>
     *
     * @throws LogicException si un filtro no está en ninguna lista / if a filter is in no list
     */
    public static function applyFilters(Builder $query, array $filters, array $kinds, array $kindOf): Builder {
      foreach ($filters as $key => $value) {
        // Un nombre de solo dígitos llega como clave numérica: se vuelve a texto para buscarlo en las listas.
        // A name made only of digits arrives as a numeric key: it is turned back into text to look it up in the lists.
        $name = (string) $key;

        if (!isset($kindOf[$name])) {
          throw new LogicException(sprintf('The criteria has a filter "%s" that the repository does not map: add it to exact, partial or scopes, or take it out of Allowed.', $name));
        }

        if ($value === '') {
          continue;
        }

        $target = $kinds[$kindOf[$name]][$name];

        // Solo hay tres clases de lista: lo que no es `exact` ni `partial` es un scope.
        // There are only three kinds of list: whatever is not `exact` or `partial` is a scope.
        match ($kindOf[$name]) {
          'exact' => self::exact($query, Columns::qualify($query, $target), $value),
          'partial' => self::partial($query, Columns::qualify($query, $target), $value),
          default => $query->{$target}($value),
        };
      }

      return $query;
    }

    /**
     * El núcleo del orden, con el orden ya como arreglo (ver `applyFilters()`): lo comparten el puente y la macro `jsonApiSort()`.
     * The core of the sort, with the sort already as an array (see `applyFilters()`): the bridge and the `jsonApiSort()` macro share it.
     *
     * @internal
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<array{field: string, descending: bool}>  $sort
     * @param  array<int|string, string>  $columns
     * @return Builder<TModel>
     *
     * @throws LogicException si se ordena por un nombre que el mapa no conoce / if it sorts by a name the map does not know
     */
    public static function applySort(Builder $query, array $sort, array $columns): Builder {
      if ($sort === []) {
        return $query;
      }

      $map = Columns::map($columns);
      $key = $query->getModel()->getQualifiedKeyName();
      $applied = [];

      foreach ($sort as $item) {
        if (!array_key_exists($item['field'], $map)) {
          throw new LogicException(sprintf('The criteria sorts by "%s" but the repository does not map it: add it to the sort map, or take it out of Allowed.', $item['field']));
        }

        $column = Columns::qualify($query, $map[$item['field']]);

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
     * El núcleo de las inclusiones (ver `applyFilters()`): lo comparten el puente y la macro `jsonApiInclude()`.
     * The core of the includes (see `applyFilters()`): the bridge and the `jsonApiInclude()` macro share it.
     *
     * @internal
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $paths
     * @return Builder<TModel>
     */
    public static function applyIncludes(Builder $query, array $paths): Builder {
      return $paths === [] ? $query : $query->with($paths);
    }

    /**
     * El núcleo de la página (ver `applyFilters()`): lo comparten el puente y la macro `jsonApiPaginate()`.
     * The core of the page (see `applyFilters()`): the bridge and the `jsonApiPaginate()` macro share it.
     *
     * @internal
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function applyPage(Builder $query, int $size, int $number): LengthAwarePaginator {
      return $query->paginate($size, ['*'], 'page', $number);
    }

    /**
     * Las tres listas de filtros como mapas `'nombre' => 'destino'`, por clase.
     * The three filter lists as `'name' => 'target'` maps, by kind.
     *
     * @internal
     *
     * @param  array<int|string, string>  $exact
     * @param  array<int|string, string>  $partial
     * @param  array<int|string, string>  $scopes
     * @return array<string, array<string, string>>
     */
    public static function kinds(array $exact, array $partial, array $scopes): array {
      return ['exact' => Columns::map($exact), 'partial' => Columns::map($partial), 'scopes' => Columns::map($scopes)];
    }

    /**
     * En cuál lista está cada nombre; un nombre en más de una lista es un error del programador.
     * Which list each name is in; a name in more than one list is a developer error.
     *
     * @internal
     *
     * @param  array<string, array<string, string>>  $kinds
     * @return array<string, string>
     */
    public static function kindOf(array $kinds): array {
      $kindOf = [];

      foreach ($kinds as $kind => $names) {
        foreach (array_keys($names) as $name) {
          if (isset($kindOf[$name])) {
            throw new InvalidArgumentException(sprintf('The filter name "%s" is in more than one list (%s and %s).', $name, $kindOf[$name], $kind));
          }

          $kindOf[$name] = $kind;
        }
      }

      return $kindOf;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private static function exact(Builder $query, string $column, string $value): void {
      $values = array_values(array_filter(explode(',', $value), fn(string $item) => $item !== ''));

      match (count($values)) {
        0 => null,
        1 => $query->where($column, '=', $values[0]),
        default => $query->whereIn($column, $values),
      };
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private static function partial(Builder $query, string $column, string $value): void {
      $escaped = str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'], $value);
      $wrapped = $query->getQuery()->getGrammar()->wrap($column);

      // La columna sale del mapa del repositorio (nunca del cliente) y va escapada con `Grammar::wrap()`; el valor del cliente va como parámetro.
      // PHPStan solo ve un texto armado con una variable y no puede saber que es seguro.
      // The column comes from the repository map (never from the client) and is escaped with `Grammar::wrap()`; the client value goes as a binding.
      // PHPStan only sees a string built with a variable and cannot know it is safe.
      $query->whereRaw($wrapped . " like ? escape '" . self::ESCAPE . "'", ['%' . $escaped . '%']); // @phpstan-ignore argument.type
    }
  }
