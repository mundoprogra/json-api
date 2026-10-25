<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Foundation\Auth\User;

  /**
   * El usuario autenticado de la API de referencia: solo hace falta que sea un `Authenticatable` para que el middleware `auth` lo deje pasar.
   * The authenticated user of the reference API: it only has to be an `Authenticatable` for the `auth` middleware to let it through.
   */
  class ApiUser extends User {
    protected $guarded = [];
  }
