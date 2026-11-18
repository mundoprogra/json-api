<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Una etiqueta que NO es un modelo de Eloquent sino un objeto simple: declara su tipo y su id, porque no hay tabla ni clave de ruta de
   * las que sacarlos. No declara ruta, así que no tiene `links.self`.
   * A tag that is NOT an Eloquent model but a plain object: it declares its type and its id, because there is no table or route key to
   * work them out from. It declares no route, so it has no `links.self`.
   */
  class PlainTagResource extends ResourceObject {
    public function type(): string {
      return 'tags';
    }

    public function id(): string {
      return (string) $this->resource->id;
    }

    protected function jsonApiAttributes(Request $request): array {
      return ['name' => $this->resource->name];
    }
  }
