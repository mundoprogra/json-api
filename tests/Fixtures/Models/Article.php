<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;

  /**
   * Un artículo de prueba. En la mayoría de las pruebas no toca la base de datos (sus relaciones se asignan a mano con `setRelation()` para
   * simular que están cargadas); las pruebas de macros del constructor de Eloquent sí usan la tabla `articles`.
   * A test article. In most tests it does not touch the database (its relations are set by hand with `setRelation()` to simulate them being
   * loaded); the tests of the Eloquent builder macros do use the `articles` table.
   */
  class Article extends Model {
    protected $guarded = [];

    /**
     * Un scope de ejemplo para `jsonApiFilter(scopes: [...])`: con `1` trae los publicados y con `0` los que están en borrador.
     * An example scope for `jsonApiFilter(scopes: [...])`: with `1` it brings the published ones and with `0` those that are drafts.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopePublished(Builder $query, mixed $value = true): Builder {
      return filter_var($value, FILTER_VALIDATE_BOOLEAN)
        ? $query->where('status', 'published')
        : $query->where('status', '!=', 'published');
    }
  }
