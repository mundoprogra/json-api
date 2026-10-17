<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * El mismo recurso que `App\Http\Resources\UserResource` de `example-app`, escrito con el paquete: mismos atributos y mismo link `self`.
   * The same resource as `App\Http\Resources\UserResource` in `example-app`, written with the package: same attributes and same `self` link.
   */
  class UserResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return [
        'username' => $this->resource->username,
        'email' => $this->resource->email,
        'phone' => $this->resource->phone,
      ];
    }

    protected function jsonApiRoute(): ?string {
      return 'users.show';
    }
  }
