<?php

  namespace MundoProgra\JsonApi\Query;

  use Closure;
  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Pagination\LengthAwarePaginator;

  /**
   * Las macros del constructor de Eloquent que aplican la consulta de JSON:API a una consulta de la base de datos. Se registran de una vez con
   * `Builder::mixin()`: Laravel llama a cada método de esta clase y registra como macro la función que devuelve, que corre con `$this` igual al
   * constructor. Por eso cada método público de esta clase devuelve una función y no hace nada más.
   *
   * The Eloquent builder macros that apply the JSON:API query to a database query. They are registered all at once with `Builder::mixin()`:
   * Laravel calls each method of this class and registers the function it returns as a macro, which runs with `$this` equal to the builder.
   * That is why every public method of this class returns a function and does nothing else.
   */
  final class BuilderMixin {
    /**
     * Trae la página que pidió el cliente (`page[number]` y `page[size]`). El tamaño lo calcula `PageRequest`: cliente, argumento,
     * opciones del middleware y configuración, acotado por el máximo. Una página fuera de rango es una página vacía.
     *
     * Fetches the page the client asked for (`page[number]` and `page[size]`). The size is worked out by `PageRequest`: client, argument,
     * middleware options and config, capped by the maximum. A page out of range is an empty page.
     *
     * @return Closure(int|null, int|null): LengthAwarePaginator<int, Model>
     */
    public function jsonApiPaginate(): Closure {
      return function (?int $perPage = null, ?int $maxPerPage = null): LengthAwarePaginator {
        // Dentro de la macro `$this` es el constructor de Eloquent y no esta clase; PHPStan solo ve la clase, por eso se ignora este aviso.
        // Inside the macro `$this` is the Eloquent builder and not this class; PHPStan only sees the class, which is why this warning is ignored.
        /** @var Builder<Model> $this */ // @phpstan-ignore varTag.nativeType
        $page = PageRequest::of(app('request'), $perPage, $maxPerPage);

        return $this->paginate($page->size(), ['*'], 'page', $page->number());
      };
    }
  }
