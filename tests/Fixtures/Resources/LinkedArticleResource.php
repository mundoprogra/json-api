<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un artículo cuyas relaciones siempre salen: con su linkage si están cargadas y solo con sus links si no (`Relationship::unloaded()`). Los links
   * salen de `relationshipLinks()`, a partir del `links.self` del propio artículo (`articles.show`).
   *
   * An article whose relationships always come out: with their linkage if loaded and with only their links if not (`Relationship::unloaded()`). The
   * links come from `relationshipLinks()`, built from the `links.self` of the article itself (`articles.show`).
   */
  class LinkedArticleResource extends ResourceObject {
    protected function jsonApiRoute(): ?string {
      return 'articles.show';
    }

    protected function jsonApiAttributes(Request $request): array {
      return ['title' => $this->title];
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
      ];
    }
  }
