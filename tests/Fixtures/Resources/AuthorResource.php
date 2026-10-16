<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * El recurso más simple: solo declara sus atributos; el tipo y el id salen del modelo.
   * The simplest resource: it only declares its attributes; the type and the id come from the model.
   */
  class AuthorResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return [
        'name' => $this->name,
        'email' => $this->email,
      ];
    }
  }
