<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Model;

  /**
   * Un usuario de prueba como el de `example-app`: la clave primaria es un `uuid` de texto y hay datos que no se deben mostrar.
   * A test user like the one in `example-app`: the primary key is a text `uuid` and there is data that must not be shown.
   */
  class User extends Model {
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['password'];
  }
