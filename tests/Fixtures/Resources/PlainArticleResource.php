<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un artículo que NO es un modelo de Eloquent sino un objeto simple, con un autor y etiquetas que tampoco lo son. Como un objeto simple no
   * tiene `whenLoaded()`, el propio recurso decide si una relación está presente: sale solo si el objeto trae esa propiedad (aunque sea `null`).
   * An article that is NOT an Eloquent model but a plain object, with an author and tags that are not models either. Since a plain object has
   * no `whenLoaded()`, the resource itself decides whether a relationship is present: it comes out only if the object carries that property
   * (even as `null`).
   */
  class PlainArticleResource extends ResourceObject {
    public function type(): string {
      return 'articles';
    }

    public function id(): string {
      return (string) $this->resource->id;
    }

    protected function jsonApiAttributes(Request $request): array {
      return ['title' => $this->resource->title];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->when(
          property_exists($this->resource, 'author'),
          fn() => Relationship::one($this->resource->author, PlainAuthorResource::class),
        ),
        'tags' => $this->when(
          property_exists($this->resource, 'tags'),
          fn() => Relationship::many($this->resource->tags, PlainTagResource::class),
        ),
      ];
    }
  }
