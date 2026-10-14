<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Providers;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Http\Request;
  use Illuminate\Support\ServiceProvider;
  use Symfony\Component\HttpKernel\Exception\HttpException;

  /**
   * Simula una app que registra su propia respuesta antes que el paquete: con withExceptions() en bootstrap/app.php
   * o en el register() de App\Exceptions\Handler. Responde con su propio formato a los errores 402.
   * Simulates an app that registers its own response before the package: with withExceptions() in bootstrap/app.php
   * or in the register() of App\Exceptions\Handler. It answers 402 errors with its own format.
   */
  class AppRenderableAtRegister extends ServiceProvider {
    public function register(): void {
      $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
        $handler->renderable(function (HttpException $e, Request $request) {
          return $e->getStatusCode() === 402 ? response()->json(['app' => 'registered first'], 402) : null;
        });
      });
    }
  }
