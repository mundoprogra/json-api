<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * El artículo de la API de referencia: `links.self` de la ruta `articles.show` y tres relaciones (autor, etiquetas y comentarios) que salen siempre:
   * con su linkage si están cargadas y solo con sus `links` si no, calculados desde el `self` del propio artículo.
   *
   * The article of the reference API: `links.self` from the `articles.show` route and three relationships (author, tags and comments) that always
   * come out: with their linkage if loaded and with only their `links` if not, worked out from the `self` of the article itself.
   */
  class ArticleResource extends ResourceObject {
    protected function jsonApiRoute(): ?string {
      return 'articles.show';
    }

    protected function jsonApiAttributes(Request $request): array {
      return [
        'title' => $this->title,
        'status' => $this->status,
        'created_at' => $this->created_at?->toIso8601String(),
        'updated_at' => $this->updated_at?->toIso8601String(),
      ];
    }

    protected function jsonApiRelationships(Request $request): array {
      $links = fn(string $name) => $this->relationshipLinks($request, $name);

      return [
        'author' => $this->relationLoaded('author')
          ? Relationship::one($this->author, AuthorResource::class)->links(...$links('author'))
          : Relationship::unloaded()->links(...$links('author')),
        'tags' => $this->relationLoaded('tags')
          ? Relationship::many($this->tags, TagResource::class)->links(...$links('tags'))
          : Relationship::unloaded()->links(...$links('tags')),
        'comments' => $this->relationLoaded('comments')
          ? Relationship::many($this->comments, CommentResource::class)->links(...$links('comments'))
          : Relationship::unloaded()->links(...$links('comments')),
      ];
    }
  }
