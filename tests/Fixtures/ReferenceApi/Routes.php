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

      $router->middleware(AuthenticateLikeTheSkeleton::class)->group(function (Router $router) {
        $router->post('api/articles', [ArticleController::class, 'store'])->name('articles.store');
        $router->patch('api/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
        $router->delete('api/articles/{article}', [ArticleController::class, 'destroy'])->name('articles.destroy');
      });
    }
  }
