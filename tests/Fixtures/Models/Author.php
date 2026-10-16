<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Model;

  /**
   * Un modelo de prueba que no toca la base de datos: la tabla sale del nombre de la clase (`authors`) y la clave de ruta es el `id`.
   * A test model that does not touch the database: the table comes from the class name (`authors`) and the route key is the `id`.
   */
  class Author extends Model {
    protected $guarded = [];
  }
