<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * La etiqueta de la API de referencia: tipo `labels` (la tabla del modelo), id igual a su `slug` y solo su nombre.
   * The tag of the reference API: type `labels` (the table of the model), id equal to its `slug` and only its name.
   */
  class TagResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['name' => $this->name];
    }
  }
