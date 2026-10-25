<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;

  /**
   * Los endpoints de relación de un artículo (`/articles/{id}/relationships/author` y `/tags`): solo el linkage (`type` e `id`) de lo que la relación
   * apunta, con los links `self` y `related`. Una `Relationship` devuelta desde el controlador ya es la respuesta. Tampoco soportan `include`.
   *
   * The relationship endpoints of an article (`/articles/{id}/relationships/author` and `/tags`): only the linkage (`type` and `id`) of what the
   * relationship points at, with the `self` and `related` links. A `Relationship` returned from the controller is already the response. They do not
   * support `include` either.
   */
  class ArticleRelationshipController {
    public function author(string $article): Relationship {
      $model = Article::query()->jsonApiInclude([])->with('author')->findOrFail($article);

      return Relationship::one($model->author, AuthorResource::class)->links(...$this->links($model, 'author'));
    }

    public function tags(string $article): Relationship {
      $model = Article::query()->jsonApiInclude([])->findOrFail($article);

      return Relationship::many($model->tags()->orderBy('slug')->get(), TagResource::class)->links(...$this->links($model, 'tags'));
    }

    /**
     * Los links de una relación del artículo: la propia relación (`self`) y el recurso relacionado (`related`).
     * The links of a relationship of the article: the relationship itself (`self`) and the related resource (`related`).
     *
     * @return array{self: string, related: string}
     */
    private function links(Article $article, string $name): array {
      return [
        'self' => route("articles.relationships.$name", [$article->getRouteKey()]),
        'related' => route("articles.$name", [$article->getRouteKey()]),
      ];
    }
  }
