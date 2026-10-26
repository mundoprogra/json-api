<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Routing\Router;
  use MundoProgra\JsonApi\Tests\Fixtures\Middleware\AuthenticateLikeTheSkeleton;

  /**
   * Las rutas de la API de referencia. Crecen punto por punto de la Fase 11; el paquete no registra rutas, esto es lo que haría una app.
   * Las lecturas son públicas y las escrituras exigen estar autenticado, con el middleware `auth` tal como lo deja el esqueleto de Laravel.
   *
   * The routes of the reference API. They grow point by point in Phase 11; the package registers no routes, this is what an app would do.
   * Reads are public and writes require authentication, with the `auth` middleware as the Laravel skeleton leaves it.
   */
  final class Routes {
    public static function register(Router $router): void {
      $router->get('api/articles', [ArticleController::class, 'index'])->name('articles.index');
      $router->get('api/articles/{article}', [ArticleController::class, 'show'])->name('articles.show');

      // Lo que cada relación apunta, como recurso o colección, y la relación en sí (solo el linkage).
      // What each relationship points at, as a resource or collection, and the relationship itself (only the linkage).
      $router->get('api/articles/{article}/author', [ArticleRelatedController::class, 'author'])->name('articles.author');
      $router->get('api/articles/{article}/tags', [ArticleRelatedController::class, 'tags'])->name('articles.tags');
      $router->get('api/articles/{article}/relationships/author', [ArticleRelationshipController::class, 'author'])->name('articles.relationships.author');
      $router->get('api/articles/{article}/relationships/tags', [ArticleRelationshipController::class, 'tags'])->name('articles.relationships.tags');

      $router->middleware(AuthenticateLikeTheSkeleton::class)->group(function (Router $router) {
        $router->post('api/articles', [ArticleController::class, 'store'])->name('articles.store');
        $router->patch('api/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
        $router->delete('api/articles/{article}', [ArticleController::class, 'destroy'])->name('articles.destroy');

        // Escritura de relaciones: a uno solo se reemplaza (`PATCH`); a muchos también se agrega (`POST`) y se quita (`DELETE`).
        // Writing relationships: a to-one is only replaced (`PATCH`); a to-many is also added to (`POST`) and removed from (`DELETE`).
        $router->patch('api/articles/{article}/relationships/author', [ArticleRelationshipController::class, 'updateAuthor'])->name('articles.relationships.author.update');
        $router->patch('api/articles/{article}/relationships/tags', [ArticleRelationshipController::class, 'replaceTags'])->name('articles.relationships.tags.replace');
        $router->post('api/articles/{article}/relationships/tags', [ArticleRelationshipController::class, 'addTags'])->name('articles.relationships.tags.add');
        $router->delete('api/articles/{article}/relationships/tags', [ArticleRelationshipController::class, 'removeTags'])->name('articles.relationships.tags.remove');
      });
    }
  }
