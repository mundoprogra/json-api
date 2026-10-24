<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Aplica el `filter` del cliente a una consulta de Eloquent, con listas blancas explícitas de tres tipos:
   * `exact` (igualdad; una lista con comas es un `IN`), `partial` (`LIKE %valor%` con `%` y `_` escapados) y `scopes` (llama a un scope del
   * modelo con el valor). Los nombres van en `snake_case`; una entrada `'nombre' => 'destino'` mapea el nombre a otra columna o a otro scope.
   * Varios filtros se combinan con AND. Un valor vacío se ignora, uno que no es texto es un `400` mal formado, y un nombre fuera de las listas
   * es un `400` con `source.parameter = filter[nombre]` (el nombre como lo escribió el cliente). Los valores siempre van enlazados.
   *
   * Applies the client `filter` to an Eloquent query, with explicit whitelists of three kinds:
   * `exact` (equality; a comma list is an `IN`), `partial` (`LIKE %value%` with `%` and `_` escaped) and `scopes` (calls a model scope with the
   * value). The names are in `snake_case`; a `'name' => 'target'` entry maps the name to another column or another scope.
   * Several filters combine with AND. An empty value is ignored, one that is not text is a malformed `400`, and a name outside the lists is a
   * `400` with `source.parameter = filter[name]` (the name as the client wrote it). The values are always bound.
   */
  final class Filtering {
    // El carácter de escape del LIKE: `!` funciona igual en todas las bases de datos, a diferencia de `\`, que MySQL y otras tratan aparte.
    // The LIKE escape character: `!` works the same in every database, unlike `\`, which MySQL and others treat separately.
    private const ESCAPE = '!';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string>  $exact
     * @param  array<int|string, string>  $partial
     * @param  array<int|string, string>  $scopes
     * @return Builder<TModel>
     *
     * @throws InvalidArgumentException si un mismo nombre está en más de una lista / if the same name is in more than one list
     * @throws JsonApiException si el cliente manda un filtro que no está permitido o tiene un valor que no es texto / if the client sends a filter that is not allowed or has a value that is not text
     */
    public static function apply(Builder $query, array $exact = [], array $partial = [], array $scopes = []): Builder {
      $kinds = ['exact' => Columns::map($exact), 'partial' => Columns::map($partial), 'scopes' => Columns::map($scopes)];
      $kindOf = self::kindOf($kinds);

      $request = app('request');
      $filters = QueryParameters::of($request)->filter();

      self::reject($request->query->all()['filter'] ?? [], $kindOf);

      foreach ($filters as $name => $value) {
        if (!is_string($value) || $value === '') {
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
     * Revisa lo que mandó el cliente tal como lo escribió: un nombre fuera de las listas o un valor que no es texto es un `400`. Todos los
     * problemas salen juntos, en el orden recibido.
     *
     * Checks what the client sent exactly as written: a name outside the lists or a value that is not text is a `400`. All the problems come
     * out together, in the order received.
     *
     * @param  array<int|string, mixed>|mixed  $raw
     * @param  array<string, string>  $kindOf
     */
    private static function reject(mixed $raw, array $kindOf): void {
      $names = MemberName::for(app(Settings::class)->memberCase());
      $violations = [];

      foreach (is_array($raw) ? $raw : [] as $key => $value) {
        $parameter = "filter[$key]";

        if (!isset($kindOf[$names->toSnake((string) $key)])) {
          $violations[] = ['parameter' => $parameter, 'reason' => 'filter_not_allowed'];
        } elseif ($value !== null && !is_string($value)) {
          // Un valor vacío llega como `null` cuando está el middleware `ConvertEmptyStringsToNull` de Laravel, y se ignora igual que `''`.
          // An empty value arrives as `null` when Laravel's `ConvertEmptyStringsToNull` middleware is on, and it is ignored just like `''`.
          $violations[] = ['parameter' => $parameter, 'reason' => 'malformed'];
        }
      }

      if ($violations !== []) {
        throw QueryParameters::exceptionFor($violations);
      }
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

      $query->whereRaw($wrapped . " like ? escape '" . self::ESCAPE . "'", ['%' . $escaped . '%']);
    }
  }
