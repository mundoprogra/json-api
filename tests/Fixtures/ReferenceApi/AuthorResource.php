<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * El autor de la API de referencia: tipo `authors`, id numérico y solo su nombre.
   * The author of the reference API: type `authors`, numeric id and only its name.
   */
  class AuthorResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['name' => $this->name];
    }
  }
