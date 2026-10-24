<?php

  namespace MundoProgra\JsonApi\Query;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;

  /**
   * Lo que comparten las listas blancas de `jsonApiSort()` y `jsonApiFilter()`: cómo se escribe un nombre que apunta a una columna y cómo se
   * califica esa columna con su tabla.
   *
   * What the whitelists of `jsonApiSort()` and `jsonApiFilter()` share: how a name that points to a column is written and how that column is
   * qualified with its table.
   */
  final class Columns {
    /**
     * El nombre del cliente → el destino (una columna o un scope). Una entrada sin nombre (`'title'`) es su propio destino.
     *
     * The client name → the target (a column or a scope). An entry without a name (`'title'`) is its own target.
     *
     * @param  array<int|string, string>  $allowed
     * @return array<string, string>
     */
    public static function map(array $allowed): array {
      $targets = [];

      foreach ($allowed as $name => $target) {
        $targets[is_int($name) ? $target : $name] = $target;
      }

      return $targets;
    }

    /**
     * La columna con el nombre de la tabla, salvo que ya lo traiga (`authors.name`).
     *
     * The column with the table name, unless it already has one (`authors.name`).
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function qualify(Builder $query, string $column): string {
      return str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column);
    }
  }
