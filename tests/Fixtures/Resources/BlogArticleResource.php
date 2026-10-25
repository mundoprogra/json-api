<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un artículo con todas las relaciones que hacen falta para probar el `included`: a uno (`author`, `editor`, `featured_comment`) y a muchos
   * (`tags`, `comments`), con autores que a su vez tienen artículos. Todas salen solo si están cargadas (`whenLoaded`).
   *
   * An article with all the relationships needed to test `included`: to-one (`author`, `editor`, `featured_comment`) and to-many (`tags`,
   * `comments`), with authors who in turn have articles. All of them only come out when loaded (`whenLoaded`).
   */
  class BlogArticleResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return ['title' => $this->title];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorWithArticlesResource::class)),
        'editor' => $this->whenLoaded('editor', fn() => Relationship::one($this->editor, AuthorWithArticlesResource::class)),
        'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
        'comments' => $this->whenLoaded('comments', fn() => Relationship::many($this->comments, CommentResource::class)),
        'featured_comment' => $this->whenLoaded('featured_comment', fn() => Relationship::one($this->featured_comment, CommentResource::class)),
      ];
    }
  }
