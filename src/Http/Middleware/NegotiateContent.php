<?php

  namespace MundoProgra\JsonApi\Http\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Support\ContentNegotiator;
  use MundoProgra\JsonApi\Support\NegotiationFailure;
  use MundoProgra\JsonApi\Support\Settings;
  use Symfony\Component\HttpFoundation\Response;

  /**
   * Aplica la negociación de contenido de JSON:API 1.1 a la petición: el `Content-Type` (415) y el `Accept` (406).
   * El `Content-Type` solo se revisa si la petición trae cuerpo y su método es POST, PATCH o DELETE (PUT solo con `allow_put`).
   * El `Accept` se revisa siempre, y se lee el original que guardó ApplyJsonApi: el de la petición ya viene corregido.
   * Applies the JSON:API 1.1 content negotiation to the request: the `Content-Type` (415) and the `Accept` (406).
   * The `Content-Type` is only checked if the request has a body and its method is POST, PATCH or DELETE (PUT only with `allow_put`).
   * The `Accept` is always checked, reading the original that ApplyJsonApi kept: the request one has already been fixed.
   */
  final class NegotiateContent {
    private const BODY_METHODS = ['POST', 'PATCH', 'DELETE'];

    public function __construct(
      private readonly Settings $settings,
      private readonly ErrorMessages $messages,
      private readonly RequestScope $scope,
    ) {
    }

    /**
     * Sin parámetro (el alias `json-api.negotiate` en una ruta) negocia siempre.
     * Con `scope` (el que registra el paquete para toda la app) solo negocia si la petición está dentro del alcance.
     * Without a parameter (the `json-api.negotiate` alias on a route) it always negotiates.
     * With `scope` (the one the package registers for the whole app) it only negotiates if the request is inside the scope.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws JsonApiException
     */
    public function handle(Request $request, Closure $next, string $how = 'always'): Response {
      if ($how === 'scope' && !$this->scope->appliesTo($request)) {
        return $next($request);
      }

      // Se crea en cada petición: lee la configuración al momento, así un cambio de `strict_headers` se nota enseguida.
      // It is created on each request: it reads the config at that moment, so a `strict_headers` change shows up right away.
      $negotiator = new ContentNegotiator($this->settings->strictHeaders());

      $failure = $this->carriesDocument($request) ? $negotiator->validateContentType($request->headers->get('Content-Type')) : null;
      $failure ??= $negotiator->validateAccept($this->clientAccept($request));

      if ($failure !== null) {
        throw $this->exceptionFor($failure);
      }

      return $next($request);
    }

    /**
     * Si el cliente manda un documento: tiene cuerpo y su método es uno de los que lo llevan en JSON:API.
     * Whether the client sends a document: it has a body and its method is one of those that carry it in JSON:API.
     */
    private function carriesDocument(Request $request): bool {
      $method = $request->getMethod();
      $validated = in_array($method, self::BODY_METHODS, true) || ($method === 'PUT' && $this->settings->allowPut());

      return $validated && $request->getContent() !== '';
    }

    /**
     * El `Accept` que mandó el cliente: el que guardó ApplyJsonApi, o la cabecera tal cual si no se aplicó.
     * The `Accept` the client sent: the one ApplyJsonApi kept, or the header as it is if it was not applied.
     */
    private function clientAccept(Request $request): ?string {
      $original = $request->attributes->has(ApplyJsonApi::ORIGINAL_ACCEPT)
        ? $request->attributes->get(ApplyJsonApi::ORIGINAL_ACCEPT)
        : $request->headers->get('Accept');

      return is_string($original) ? $original : null;
    }

    private function exceptionFor(NegotiationFailure $failure): JsonApiException {
      $base = $failure->status() === 415 ? ErrorCodes::UNSUPPORTED_MEDIA_TYPE : ErrorCodes::NOT_ACCEPTABLE;

      return JsonApiException::make(
        status: $failure->status(),
        detail: $this->messages->detail($base, [], $failure->reason()),
        header: $failure->header(),
      );
    }
  }
