<?php

  namespace MundoProgra\JsonApi\Query\Eloquent;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
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
      $kinds = ['exact' => Columns::map($exact), 'partial' => Columns::map($partial), 'scopes' => Columns::map($scopes)];
      $kindOf = self::kindOf($kinds);

      foreach ($criteria->filters() as $key => $value) {
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
     * En cuál lista está cada nombre; un nombre en más de una lista es un error del programador.
     * Which list each name is in; a name in more than one list is a developer error.
     *
     * @param  array<string, array<string, string>>  $kinds
     * @return array<string, string>
     */
    private static function kindOf(array $kinds): array {
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
