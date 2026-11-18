<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un autor que NO es un modelo de Eloquent sino un objeto simple. Declara su tipo, su id y la ruta de su consulta (`authors.show`), así
   * que tiene `links.self` y, en un `201`, `Location`.
   * An author that is NOT an Eloquent model but a plain object. It declares its type, its id and the route that shows it (`authors.show`),
   * so it has `links.self` and, on a `201`, `Location`.
   */
  class PlainAuthorResource extends ResourceObject {
    public function type(): string {
      return 'authors';
    }

    public function id(): string {
      return (string) $this->resource->id;
    }

    protected function jsonApiRoute(): ?string {
      return 'authors.show';
    }

    protected function jsonApiAttributes(Request $request): array {
      return ['name' => $this->resource->name];
    }
  }
