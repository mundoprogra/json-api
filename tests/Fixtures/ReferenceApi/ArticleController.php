<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Database\Eloquent\Collection;
  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Http\Response;
  use Illuminate\Support\Arr;
  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Resources\ResourceCollection;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;

  /**
   * El controlador de artículos de la API de referencia. Cada acción es lo que escribiría una app que usa el paquete: las macros del constructor de
   * Eloquent aplican la consulta del cliente con listas blancas, el `FormRequest` valida el documento, y el recurso arma la respuesta.
   * Las relaciones que llegan en un documento (`author`, `tags`) se buscan antes de escribir nada: una que no existe es un `404` con el puntero al
   * identificador, y nada se guarda.
   *
   * The articles controller of the reference API. Each action is what an app using the package would write: the Eloquent builder macros apply the
   * client query with whitelists, the `FormRequest` validates the document, and the resource builds the response.
   * The relationships that arrive in a document (`author`, `tags`) are looked up before anything is written: one that does not exist is a `404`
   * with the pointer to the identifier, and nothing is saved.
   */
  class ArticleController {
    /**
     * Las rutas de relaciones que el cliente puede pedir con `include`.
     * The relationship paths the client can ask for with `include`.
     */
    private const INCLUDES = ['author', 'tags', 'comments', 'comments.author'];

    public function index(): ResourceCollection {
      return ArticleResource::collection(
        Article::query()
          ->jsonApiFilter(exact: ['status', 'author' => 'author_id'], partial: ['title'], scopes: ['published'])
          ->jsonApiSort(['id', 'title', 'status', 'created_at'], default: '-id')
          ->jsonApiInclude(self::INCLUDES)
          ->jsonApiPaginate()
      );
    }

    public function show(string $article): ArticleResource {
      return ArticleResource::make(Article::query()->jsonApiInclude(self::INCLUDES)->findOrFail($article));
    }

    public function store(StoreArticleRequest $request): ArticleResource {
      $author = $this->author($request);
      $tags = $this->tags($request);

      $article = DB::transaction(function () use ($request, $author, $tags) {
        $article = Article::query()->create([
          'title' => $request->validated('title'),
          'status' => $request->validated('status', 'draft'),
          'author_id' => $author?->getKey(),
        ]);

        $article->tags()->sync($tags->modelKeys());

        return $article;
      });

      return ArticleResource::make($article);
    }

    public function update(UpdateArticleRequest $request, string $article): ArticleResource {
      $model = Article::query()->findOrFail($article);

      // Solo se tocan las relaciones que el cliente mandó: `author` con `data: null` la vacía, y si no viene no cambia.
      // Only the relationships the client sent are touched: `author` with `data: null` empties it, and if it is absent it does not change.
      $author = $request->hasJsonApiRelationship('author') ? $this->author($request) : false;
      $tags = $request->hasJsonApiRelationship('tags') ? $this->tags($request) : false;

      DB::transaction(function () use ($request, $model, $author, $tags) {
        $model->fill(Arr::only($request->validated(), ['title', 'status']));

        if ($author !== false) {
          $model->author_id = $author?->getKey();
        }

        $model->save();

        if ($tags !== false) {
          $model->tags()->sync($tags->modelKeys());
        }
      });

      return ArticleResource::make($model);
    }

    public function destroy(string $article): Response {
      $model = Article::query()->findOrFail($article);

      DB::transaction(function () use ($model) {
        $model->tags()->detach();
        $model->comments()->delete();
        $model->delete();
      });

      return response()->noContent();
    }

    /**
     * El autor que el documento nombra, o null si no nombra a ninguno.
     * The author the document names, or null if it names none.
     *
     * @throws JsonApiException un `404` si el autor no existe / a `404` if the author does not exist
     */
    private function author(FormRequest $request): ?Author {
      $id = $request->jsonApiRelationshipId('author');

      if ($id === null) {
        return null;
      }

      return Author::query()->find($id) ?? throw JsonApiException::make(status: 404, pointer: '/data/relationships/author/data');
    }

    /**
     * Las etiquetas que el documento nombra (por su `slug`).
     * The tags the document names (by their `slug`).
     *
     * @return Collection<int, Tag>
     *
     * @throws JsonApiException un `404` por la primera que no existe / a `404` for the first one that does not exist
     */
    private function tags(FormRequest $request): Collection {
      $slugs = $request->jsonApiRelationshipIds('tags');
      $found = Tag::query()->whereIn('slug', $slugs)->get();

      foreach ($slugs as $index => $slug) {
        if (!$found->contains('slug', $slug)) {
          throw JsonApiException::make(status: 404, pointer: "/data/relationships/tags/data/$index");
        }
      }

      return $found;
    }
  }
