<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un comentario: su tipo es la tabla del modelo (`comments`) y su autor sale solo si está cargado.
   * A comment: its type is the model table (`comments`) and its author only comes out when loaded.
   */
  class CommentResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['body' => $this->body];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorWithArticlesResource::class)),
      ];
    }
  }
