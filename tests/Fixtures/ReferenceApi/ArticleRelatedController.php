<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use MundoProgra\JsonApi\Resources\ResourceCollection;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;

  /**
   * Los recursos relacionados de un artículo (`GET /articles/{id}/author` y `/tags`): lo que la relación apunta, como recurso completo o como
   * colección. Estos endpoints no soportan `include` ni `filter`: las macros con la lista vacía los rechazan con `400`, como pide la spec para un
   * parámetro que el endpoint no soporta. La colección de etiquetas sí admite `sort` y la paginación.
   *
   * The related resources of an article (`GET /articles/{id}/author` and `/tags`): what the relationship points at, as a full resource or as a
   * collection. These endpoints support neither `include` nor `filter`: the macros with an empty list reject them with `400`, as the spec asks for
   * a parameter the endpoint does not support. The tags collection does admit `sort` and pagination.
   */
  class ArticleRelatedController {
    /**
     * El autor, o `data: null` si el artículo no tiene.
     * The author, or `data: null` if the article has none.
     */
    public function author(string $article): AuthorResource {
      $model = Article::query()->jsonApiInclude([])->with('author')->findOrFail($article);

      return AuthorResource::make($model->author);
    }

    public function tags(string $article): ResourceCollection {
      $model = Article::query()->jsonApiInclude([])->findOrFail($article);

      return TagResource::collection(
        Tag::query()
          ->whereIn('id', fn($query) => $query->select('tag_id')->from('article_tag')->where('article_id', $model->getKey()))
          ->jsonApiFilter()
          ->jsonApiSort(['name', 'slug'], default: 'slug')
          ->jsonApiInclude([])
          ->jsonApiPaginate()
      );
    }
  }
