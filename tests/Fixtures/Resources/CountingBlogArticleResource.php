<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;

  /**
   * Un artículo del blog que cuenta cuántas veces se calculan sus relaciones: sirve para comprobar que armar un documento (y su `included`) hace una
   * cantidad fija de trabajo por recurso y no crece con el número de rutas pedidas ni con el de recursos.
   *
   * A blog article that counts how many times its relationships are worked out: it serves to check that building a document (and its `included`)
   * does a fixed amount of work per resource and does not grow with the number of paths asked for or with the number of resources.
   */
  class CountingBlogArticleResource extends BlogArticleResource {
    public static int $relationshipCalls = 0;

    protected function jsonApiRelationships(Request $request): array {
      self::$relationshipCalls++;

      return parent::jsonApiRelationships($request);
    }
  }
