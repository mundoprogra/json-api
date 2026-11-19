<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Query\Eloquent\ApplyCriteria;
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
      $kinds = ApplyCriteria::kinds($exact, $partial, $scopes);
      $kindOf = ApplyCriteria::kindOf($kinds);

      $request = app('request');

      self::reject($request->query->all()['filter'] ?? [], $kindOf);

      // Ya revisado lo que mandó el cliente, solo quedan los filtros con texto: lo demás lo hace el puente, igual que para `criteria()`.
      // With what the client sent already checked, only the filters with text remain: the bridge does the rest, just as for `criteria()`.
      $filters = [];

      foreach (QueryParameters::of($request)->filter() as $key => $value) {
        if (is_string($value)) {
          $filters[$key] = $value;
        }
      }

      return ApplyCriteria::applyFilters($query, $filters, $kinds, $kindOf);
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
  }
