<?php

  namespace MundoProgra\JsonApi\Http\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Support\MediaType;
  use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
  use Symfony\Component\HttpFoundation\Response;

  /**
   * Hace que una petición dentro del alcance del paquete pida JSON:API: si el `Accept` no pide JSON, lo cambia por el media type de JSON:API.
   * Así `expectsJson()` es verdadero y el middleware `auth` de Laravel no calcula la ruta `login` (que lanza un error si la app no la tiene).
   * El `Accept` original se guarda en la petición para la negociación de contenido. Aplicarlo más de una vez es inofensivo.
   * Makes a request inside the package scope ask for JSON:API: if the `Accept` does not ask for JSON, it is replaced by the JSON:API media type.
   * That way `expectsJson()` is true and Laravel's `auth` middleware does not work out the `login` route (which throws if the app has none).
   * The original `Accept` is kept on the request for the content negotiation. Applying it more than once is harmless.
   */
  final class ApplyJsonApi {
    public const ORIGINAL_ACCEPT = 'json-api.original_accept';

    public function __construct(private readonly RequestScope $scope) {
    }

    /**
     * Sin parámetro (el alias `json-api` en una ruta) marca la petición como de JSON:API sin importar el modo ni `except`.
     * Con `scope` (el que registra el paquete para toda la app) solo actúa si la petición está dentro del alcance.
     * Without a parameter (the `json-api` alias on a route) it marks the request as JSON:API regardless of the mode and `except`.
     * With `scope` (the one the package registers for the whole app) it only acts if the request is inside the scope.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $how = 'marked'): Response {
      if ($how === 'scope' ? $this->scope->appliesTo($request) : $this->mark($request)) {
        $this->fixAccept($request);
      }

      return $next($request);
    }

    private function mark(Request $request): bool {
      $this->scope->mark($request);

      return true;
    }

    private function fixAccept(Request $request): void {
      // Solo la primera vez: después el Accept ya puede ser el corregido.
      // Only the first time: after that the Accept may already be the fixed one.
      if (!$request->attributes->has(self::ORIGINAL_ACCEPT)) {
        $request->attributes->set(self::ORIGINAL_ACCEPT, $request->headers->get('Accept'));
      }

      if ($request->expectsJson()) {
        return;
      }

      $request->headers->set('Accept', MediaType::JSON_API);
      $this->forgetParsedAccept($request);
    }

    /**
     * Symfony guarda la lista de tipos aceptados la primera vez que se pide y no la actualiza al cambiar la cabecera:
     * sin esto `expectsJson()` seguiría leyendo el `Accept` viejo.
     * Symfony keeps the list of accepted types the first time it is asked for and does not refresh it when the header changes:
     * without this `expectsJson()` would keep reading the old `Accept`.
     */
    private function forgetParsedAccept(Request $request): void {
      if (!property_exists(SymfonyRequest::class, 'acceptableContentTypes')) {
        return;
      }

      // La propiedad es privada de la clase de Symfony, así que la función se ejecuta con el alcance de esa clase.
      // The property is private to the Symfony class, so the function runs with the scope of that class.
      Closure::bind(function () {
        $this->acceptableContentTypes = null;
      }, $request, SymfonyRequest::class)();
    }
  }
