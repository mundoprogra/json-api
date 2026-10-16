<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Model;

  /**
   * Un modelo de prueba con tabla propia (`labels`, no `tags`) y con el `slug` como clave de ruta.
   * A test model with its own table (`labels`, not `tags`) and the `slug` as route key.
   */
  class Tag extends Model {
    protected $table = 'labels';

    protected $guarded = [];

    public function getRouteKeyName(): string {
      return 'slug';
    }
  }
