<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un artículo con sus relaciones, que solo salen si están cargadas (`whenLoaded`). Una relación a uno cargada pero vacía llega como `null`.
   * An article with its relationships, which only come out when loaded (`whenLoaded`). A to-one relation that is loaded but empty arrives as `null`.
   */
  class ArticleResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['title' => $this->title];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
        'editor' => $this->whenLoaded('editor', fn() => Relationship::one($this->editor, AuthorResource::class)),
        'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
      ];
    }
  }
