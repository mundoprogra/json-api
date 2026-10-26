<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Database\Eloquent\Collection;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;

  /**
   * Los endpoints de relación de un artículo (`/articles/{id}/relationships/author` y `/tags`). Las lecturas devuelven solo el linkage (`type` e `id`)
   * de lo que la relación apunta, con los links `self` y `related`: una `Relationship` devuelta desde el controlador ya es la respuesta. Las
   * escrituras leen el linkage con `RelationshipDocument::of()` (el paquete ya validó su forma) y revisan lo que es de la app: que el endpoint reciba
   * un identificador o una lista según su cardinalidad (`422`), que el `type` sea el de la relación (`409`) y que los recursos existan (`404`, con el
   * puntero al identificador). Todo se revisa antes de escribir, y cada escritura responde `200` con el linkage resultante. Ningún endpoint de relación
   * soporta `include`.
   *
   * The relationship endpoints of an article (`/articles/{id}/relationships/author` and `/tags`). Reads return only the linkage (`type` and `id`)
   * of what the relationship points at, with the `self` and `related` links: a `Relationship` returned from the controller is already the response.
   * Writes read the linkage with `RelationshipDocument::of()` (the package already validated its shape) and check what belongs to the app: that the
   * endpoint receives an identifier or a list according to its cardinality (`422`), that the `type` is the relationship's (`409`) and that the
   * resources exist (`404`, with the pointer to the identifier). Everything is checked before writing, and each write answers `200` with the resulting
   * linkage. No relationship endpoint supports `include`.
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
     * `PATCH /articles/{id}/relationships/author`: asocia el autor del identificador, o quita el autor si es `null`.
     * `PATCH /articles/{id}/relationships/author`: sets the author of the identifier, or removes the author if it is `null`.
     */
    public function updateAuthor(Request $request, string $article): Relationship {
      $model = Article::query()->findOrFail($article);
      $author = $this->oneAuthor(RelationshipDocument::of($request));

      $model->author_id = $author?->getKey();
      $model->save();

      return $this->author($article);
    }

    /**
     * `PATCH /articles/{id}/relationships/tags`: reemplaza todas las etiquetas por las de la lista.
     * `PATCH /articles/{id}/relationships/tags`: replaces all the tags with those of the list.
     */
    public function replaceTags(Request $request, string $article): Relationship {
      $model = Article::query()->findOrFail($article);
      $tags = $this->manyTags(RelationshipDocument::of($request));

      DB::transaction(fn() => $model->tags()->sync($tags->modelKeys()));

      return $this->tags($article);
    }

    /**
     * `POST /articles/{id}/relationships/tags`: agrega las etiquetas de la lista; las que ya estaban se quedan, sin repetirse.
     * `POST /articles/{id}/relationships/tags`: adds the tags of the list; those already there stay, without repeating.
     */
    public function addTags(Request $request, string $article): Relationship {
      $model = Article::query()->findOrFail($article);
      $tags = $this->manyTags(RelationshipDocument::of($request));

      DB::transaction(fn() => $model->tags()->syncWithoutDetaching($tags->modelKeys()));

      return $this->tags($article);
    }

    /**
     * `DELETE /articles/{id}/relationships/tags`: quita las etiquetas de la lista; las que no estaban se ignoran.
     * `DELETE /articles/{id}/relationships/tags`: removes the tags of the list; those that were not there are ignored.
     */
    public function removeTags(Request $request, string $article): Relationship {
      $model = Article::query()->findOrFail($article);
      $tags = $this->manyTags(RelationshipDocument::of($request));

      DB::transaction(fn() => $model->tags()->detach($tags->modelKeys()));

      return $this->tags($article);
    }

    /**
     * El autor que nombra un linkage a uno, o null si el linkage es `null`.
     * The author a to-one linkage names, or null if the linkage is `null`.
     *
     * @throws JsonApiException `422` si es una lista, `409` si el tipo no es `authors`, `404` si el autor no existe
     */
    private function oneAuthor(RelationshipDocument $document): ?Author {
      if ($document->isList()) {
        throw JsonApiException::make(status: 422, detail: 'A to-one relationship takes one identifier or null, not a list.', pointer: '/data');
      }

      $identifier = $document->data();

      if ($identifier === null) {
        return null;
      }

      if ($identifier['type'] !== 'authors') {
        throw JsonApiException::make(status: 409, pointer: '/data/type');
      }

      return Author::query()->find($identifier['id']) ?? throw JsonApiException::make(status: 404, pointer: '/data');
    }

    /**
     * Las etiquetas que nombra un linkage a muchos (por su `slug`).
     * The tags a to-many linkage names (by their `slug`).
     *
     * @return Collection<int, Tag>
     *
     * @throws JsonApiException `422` si no es una lista, `409` si algún tipo no es `labels`, `404` por la primera etiqueta que no existe
     */
    private function manyTags(RelationshipDocument $document): Collection {
      if (!$document->isList()) {
        throw JsonApiException::make(status: 422, detail: 'A to-many relationship takes a list of identifiers.', pointer: '/data');
      }

      /** @var list<array{type: string, id: string}> $identifiers */
      $identifiers = $document->data();

      foreach ($identifiers as $index => $identifier) {
        if ($identifier['type'] !== 'labels') {
          throw JsonApiException::make(status: 409, pointer: "/data/$index/type");
        }
      }

      $found = Tag::query()->whereIn('slug', array_column($identifiers, 'id'))->get();

      foreach ($identifiers as $index => $identifier) {
        if (!$found->contains('slug', $identifier['id'])) {
          throw JsonApiException::make(status: 404, pointer: "/data/$index");
        }
      }

      return $found;
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
