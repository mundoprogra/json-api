<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Auth\AuthenticationException;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Routing\Exceptions\InvalidSignatureException;
  use Illuminate\Validation\ValidationException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Settings;
  use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
  use Throwable;

  /**
   * Convierte una excepción en la respuesta de error de JSON:API. Devuelve null cuando no la maneja, para que Laravel siga con su comportamiento normal.
   * Turns an exception into the JSON:API error response. It returns null when it does not handle it, so Laravel continues with its normal behavior.
   */
  final class ExceptionRenderer {
    public function __construct(
      private readonly Settings $settings,
      private readonly ErrorCodes $codes,
      private readonly ErrorMessages $messages,
    ) {
    }

    public function render(Throwable $e, Request $request): ?JsonResponse {
      // Por ahora quedan para Laravel; los puntos 3.8b (validación) y 3.8c (autenticación) los convertirán.
      // For now they are left to Laravel; points 3.8b (validation) and 3.8c (authentication) will convert them.
      if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
        return null;
      }

      if ($e instanceof JsonApiException) {
        $errors = array_map(fn(Error $error) => $this->complete($error, $request), $e->errors());

        return ErrorDocument::make($errors, $this->settings->jsonApiObject())->toResponse($e->headers());
      }

      $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

      return ErrorDocument::make($this->errorFor($e, $request, $headers), $this->settings->jsonApiObject())->toResponse($headers);
    }

    /**
     * El error de una excepción cualquiera: el estado sale de la excepción HTTP (o es 500), y los textos y el código, del catálogo.
     * The error of any exception: the status comes from the HTTP exception (or is 500), and the texts and code, from the catalog.
     *
     * @param  array<string, string>  $headers
     */
    private function errorFor(Throwable $e, Request $request, array $headers): Error {
      $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
      $base = $e instanceof InvalidSignatureException ? ErrorCodes::INVALID_SIGNATURE : $this->baseFor($status, $request);

      $detail = $this->messages->detail($base, [
        'method' => $request->method(),
        'allowed' => $headers['Allow'] ?? '',
      ]);
      $meta = [];

      // Solo los errores del servidor pueden mostrar el detalle real: en los del cliente es donde se filtran nombres de clases.
      // Only server errors may show the real detail: client errors are where class names leak.
      if ($status >= 500 && $this->settings->exposeErrorDetails()) {
        $detail = $e->getMessage() !== '' ? $e->getMessage() : $detail;
        $meta = ['exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()];
      }

      return Error::make(
        status: $status,
        title: $this->messages->title($base),
        detail: $detail,
        code: $this->codes->resolve($base),
        meta: $meta,
      );
    }

    /**
     * Completa lo que falte en un error de JsonApiException (título, detalle y código) a partir de su estado.
     * Un código explícito se respeta tal cual, y un texto escrito por el desarrollador nunca se oculta.
     * Completes what is missing in a JsonApiException error (title, detail and code) from its status.
     * An explicit code is kept as written, and a text written by the developer is never hidden.
     */
    private function complete(Error $error, Request $request): Error {
      $base = $this->baseFor($error->statusCode(), $request);
      $members = $error->toArray();

      $completed = Error::make(
        status: $error->statusCode(),
        title: $error->title() ?? $this->messages->title($base),
        detail: $error->detail() ?? $this->messages->detail($base),
        code: $error->code() ?? $this->codes->resolve($base),
        id: $error->id(),
        pointer: $members['source']['pointer'] ?? null,
        parameter: $members['source']['parameter'] ?? null,
        header: $members['source']['header'] ?? null,
        meta: $members['meta'] ?? [],
      );

      return $this->codes->apply($completed);
    }

    /**
     * El código base de un estado. Un 404 es de ruta si ninguna ruta coincidió, y de recurso si la ruta existe (no se usa el mensaje, que cambia entre versiones).
     * The base code for a status. A 404 is a route one if no route matched, and a resource one if the route exists (the message is not used, it changes between versions).
     */
    private function baseFor(int $status, Request $request): string {
      if ($status === 404 && $request->route() === null) {
        return ErrorCodes::ROUTE_NOT_FOUND;
      }

      return ErrorCodes::forStatus($status);
    }
  }
