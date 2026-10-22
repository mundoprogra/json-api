<?php

  namespace MundoProgra\JsonApi\Http\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use Symfony\Component\HttpFoundation\Response;

  /**
   * Valida la consulta de la petición antes de que llegue al controlador: un parámetro mal formado es un `400` aunque el endpoint no lo use.
   * El resultado queda en la petición y el controlador lo lee con `$request->jsonApiQuery()`.
   * Validates the request query before it reaches the controller: a malformed parameter is a `400` even if the endpoint does not use it.
   * The result is kept on the request and the controller reads it with `$request->jsonApiQuery()`.
   */
  final class ValidateQuery {
    public function __construct(private readonly RequestScope $scope) {
    }

    /**
     * Sin parámetro (el alias `json-api.query` en una ruta) valida siempre.
     * Con `scope` (el que registra el paquete para toda la app) solo valida si la petición está dentro del alcance.
     * Without a parameter (the `json-api.query` alias on a route) it always validates.
     * With `scope` (the one the package registers for the whole app) it only validates if the request is inside the scope.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws JsonApiException
     */
    public function handle(Request $request, Closure $next, string $how = 'always'): Response {
      if ($how !== 'scope' || $this->scope->appliesTo($request)) {
        QueryParameters::of($request);
      }

      return $next($request);
    }
  }
