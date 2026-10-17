<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Una etiqueta: su tipo es la tabla del modelo (`labels`) y su id es el `slug`.
   * A tag: its type is the model table (`labels`) and its id is the `slug`.
   */
  class TagResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['name' => $this->name];
    }
  }
