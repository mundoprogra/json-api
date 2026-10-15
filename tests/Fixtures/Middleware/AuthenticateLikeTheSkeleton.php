<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Middleware;

  use Illuminate\Auth\Middleware\Authenticate;
  use Illuminate\Http\Request;

  /**
   * El middleware `auth` como lo deja el esqueleto de una app Laravel: si la petición no pide JSON, calcula la ruta `login`.
   * Si la app no tiene esa ruta, lanza RouteNotFoundException antes de que el paquete pueda intervenir.
   * The `auth` middleware as a Laravel app skeleton leaves it: if the request does not ask for JSON, it works out the `login` route.
   * If the app has no such route, it throws RouteNotFoundException before the package can step in.
   */
  class AuthenticateLikeTheSkeleton extends Authenticate {
    protected function redirectTo(Request $request): ?string {
      return $request->expectsJson() ? null : route('login');
    }
  }
