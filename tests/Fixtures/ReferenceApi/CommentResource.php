<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * El comentario de la API de referencia: su texto y, si está cargado, su autor.
   * The comment of the reference API: its text and, if loaded, its author.
   */
  class CommentResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['body' => $this->body];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
      ];
    }
  }
