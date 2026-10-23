<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Illuminate\Contracts\Http\Kernel as HttpKernel;

  /**
   * Da la fila global de middleware del kernel HTTP, para comprobar qué middleware agrega el paquete y en qué orden.
   * Gives the global middleware stack of the HTTP kernel, to check which middleware the package adds and in what order.
   */
  trait ReadsTheGlobalMiddleware {
    /**
     * La fila global del kernel. Se lee la propiedad porque `getGlobalMiddleware()` no existe en Laravel 10.
     * The kernel global stack. The property is read because `getGlobalMiddleware()` does not exist in Laravel 10.
     *
     * @return list<mixed>
     */
    private function globalMiddleware(): array {
      $kernel = $this->app->make(HttpKernel::class);

      return array_values((fn() => $this->middleware)->call($kernel));
    }
  }
