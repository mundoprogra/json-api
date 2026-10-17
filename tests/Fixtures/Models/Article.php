<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Model;

  /**
   * Un artículo de prueba que no toca la base de datos: sus relaciones se asignan a mano con `setRelation()` para simular que están cargadas.
   * A test article that does not touch the database: its relations are set by hand with `setRelation()` to simulate them being loaded.
   */
  class Article extends Model {
    protected $guarded = [];
  }
