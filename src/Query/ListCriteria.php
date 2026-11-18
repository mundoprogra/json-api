<?php

  namespace MundoProgra\JsonApi\Query;

  use InvalidArgumentException;

  /**
   * La consulta de un listado ya validada y sin nada de Laravel: los filtros, el orden, las inclusiones y la página (número y tamaño) que una
   * app con Clean Architecture le pasa a su repositorio. Los nombres van en `snake_case`, los valores de los filtros son texto y no vacíos, y la
   * página ya viene calculada (cliente, argumento, opciones y configuración, acotada por el máximo). Se obtiene de la petición con
   * `$request->jsonApiQuery()->criteria(Allowed $allowed)`, y también se puede construir a mano (en una prueba, desde una consola). Es inmutable.
   * Cómo se aplica a una base de datos lo decide la app: este objeto solo dice qué se pidió.
   * The already validated query of a listing, with nothing from Laravel in it: the filters, the sort, the includes and the page (number and size)
   * that a Clean Architecture app hands to its repository. Names are in `snake_case`, the filter values are non-empty text and the page comes
   * already worked out (client, argument, options and config, capped by the maximum). It comes from the request with
   * `$request->jsonApiQuery()->criteria(Allowed $allowed)`, and it can also be built by hand (in a test, from a console). It is immutable.
   * How it is applied to a database is up to the app: this object only says what was asked for.
   */
  final class ListCriteria {
    /**
     * @param  int  $pageSize  filas por página, 1 o más / rows per page, 1 or more
     * @param  int  $pageNumber  número de página, 1 o más / page number, 1 or more
     * @param  array<string, string>  $filters  nombre → valor del cliente / name → the client's value
     * @param  list<array{field: string, descending: bool}>  $sort  en el orden en que se aplica / in the order it is applied
     * @param  list<string>  $includes  rutas con puntos, como `roles.permissions` / dotted paths, like `roles.permissions`
     *
     * @throws InvalidArgumentException si el número o el tamaño de página es menor a 1 / if the page number or size is below 1
     */
    public function __construct(
      private readonly int $pageSize,
      private readonly int $pageNumber = 1,
      private readonly array $filters = [],
      private readonly array $sort = [],
      private readonly array $includes = [],
    ) {
      if ($pageNumber < 1) {
        throw new InvalidArgumentException(sprintf('The page number must be 1 or more, %d given.', $pageNumber));
      }

      if ($pageSize < 1) {
        throw new InvalidArgumentException(sprintf('The page size must be 1 or more, %d given.', $pageSize));
      }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array {
      return $this->filters;
    }

    /**
     * El valor de un filtro, o `null` si el cliente no lo pidió.
     * The value of a filter, or `null` if the client did not ask for it.
     */
    public function filter(string $name): ?string {
      return $this->filters[$name] ?? null;
    }

    /**
     * @return list<array{field: string, descending: bool}>
     */
    public function sort(): array {
      return $this->sort;
    }

    /**
     * @return list<string>
     */
    public function includes(): array {
      return $this->includes;
    }

    public function pageNumber(): int {
      return $this->pageNumber;
    }

    public function pageSize(): int {
      return $this->pageSize;
    }

    /**
     * Las filas que quedan antes de la página: la página 3 de 20 en 20 empieza después de 40.
     * The rows before the page: page 3 at 20 per page starts after 40.
     */
    public function offset(): int {
      return ($this->pageNumber - 1) * $this->pageSize;
    }
  }
