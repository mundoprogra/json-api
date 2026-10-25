<?php

  namespace MundoProgra\JsonApi\Http\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;
  use MundoProgra\JsonApi\Http\Requests\RequestDocument;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Support\Settings;
  use Symfony\Component\HttpFoundation\Response;

  /**
   * Valida el documento de entrada antes de que llegue al controlador: un documento mal formado es un `400` (o el `403` de un `id` del cliente)
   * aunque el endpoint no lo lea. Lo que se valida depende de la URL: en un endpoint de relación (`/articles/1/relationships/tags`) el documento
   * es un linkage y se validan `POST`, `PATCH` y `DELETE`; en cualquier otro es un recurso y se validan `POST` y `PATCH`. `PUT` se valida en los
   * dos solo con `allow_put`. No se validan `GET`, un cuerpo vacío ni un cuerpo que no es JSON (un formulario, una subida de archivos).
   * El resultado queda en la petición y el controlador lo lee con `RequestDocument::of()` o `RelationshipDocument::of()`.
   *
   * Validates the input document before it reaches the controller: a malformed document is a `400` (or the `403` of a client `id`) even if the
   * endpoint does not read it. What is validated depends on the URL: at a relationship endpoint (`/articles/1/relationships/tags`) the document
   * is a linkage and `POST`, `PATCH` and `DELETE` are validated; anywhere else it is a resource and `POST` and `PATCH` are validated. `PUT` is
   * validated in both only with `allow_put`. `GET`, an empty body and a body that is not JSON (a form, a file upload) are not validated.
   * The result is kept on the request and the controller reads it with `RequestDocument::of()` or `RelationshipDocument::of()`.
   */
  final class ValidateDocument {
    private const RESOURCE_METHODS = ['POST', 'PATCH'];

    private const RELATIONSHIP_METHODS = ['POST', 'PATCH', 'DELETE'];

    public function __construct(
      private readonly RequestScope $scope,
      private readonly Settings $settings,
    ) {
    }

    /**
     * Sin parámetro (el alias `json-api.document` en una ruta) valida siempre y reconoce el endpoint de relación por la URL.
     * Con `scope` (el que registra el paquete para toda la app) solo valida si la petición está dentro del alcance.
     * Con `relationship` o `resource` valida siempre y trata el documento como un linkage o como un recurso, sin mirar la URL.
     * Without a parameter (the `json-api.document` alias on a route) it always validates and tells a relationship endpoint by the URL.
     * With `scope` (the one the package registers for the whole app) it only validates if the request is inside the scope.
     * With `relationship` or `resource` it always validates and treats the document as a linkage or as a resource, without looking at the URL.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws JsonApiException
     */
    public function handle(Request $request, Closure $next, string $how = 'always'): Response {
      if ($how === 'scope' && !$this->scope->appliesTo($request)) {
        return $next($request);
      }

      $relationship = match ($how) {
        'relationship' => true,
        'resource' => false,
        default => RelationshipDocument::appliesTo($request),
      };

      if ($this->carriesDocument($request, $relationship)) {
        $relationship ? RelationshipDocument::of($request) : RequestDocument::of($request);
      }

      return $next($request);
    }

    /**
     * Si el cliente manda un documento que hay que validar: tiene cuerpo, el cuerpo es JSON y el método es uno de los que lo llevan.
     * Whether the client sends a document that has to be validated: it has a body, the body is JSON and the method is one of those that carry it.
     */
    private function carriesDocument(Request $request, bool $relationship): bool {
      $methods = $relationship ? self::RELATIONSHIP_METHODS : self::RESOURCE_METHODS;

      if ($this->settings->allowPut()) {
        $methods[] = 'PUT';
      }

      return in_array($request->getMethod(), $methods, true) && $request->getContent() !== '' && $request->isJson();
    }
  }
