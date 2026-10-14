<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Providers;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Http\Request;
  use Illuminate\Support\ServiceProvider;
  use Symfony\Component\HttpKernel\Exception\HttpException;

  /**
   * Simula una app que registra su propia respuesta en el boot() de uno de sus providers (por ejemplo, AppServiceProvider),
   * que corre después del provider del paquete. Responde con su propio formato a los errores 409.
   * Simulates an app that registers its own response in the boot() of one of its providers (for example, AppServiceProvider),
   * which runs after the package provider. It answers 409 errors with its own format.
   */
  class AppRenderableAtBoot extends ServiceProvider {
    public function boot(): void {
      $this->app->make(ExceptionHandler::class)->renderable(function (HttpException $e, Request $request) {
        return $e->getStatusCode() === 409 ? response()->json(['app' => 'registered at boot'], 409) : null;
      });
    }
  }
