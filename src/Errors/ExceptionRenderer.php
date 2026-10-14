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
   * Convierte una excepción en la respuesta de error de JSON:API. Siempre devuelve una respuesta; decidir si el paquete debe actuar
   * en una petición (y ceder a Laravel devolviendo null) es cosa de quien lo invoca, y llega con el modo prefix/global de la Fase 4.
   * Turns an exception into the JSON:API error response. It always returns a response; deciding whether the package should act on a
   * request (and yield to Laravel by returning null) is up to the caller, and arrives with the prefix/global mode of Phase 4.
   */
  final class ExceptionRenderer {
    public function __construct(
      private readonly Settings $settings,
      private readonly ErrorCodes $codes,
      private readonly ErrorMessages $messages,
      private readonly ValidationSource $validationSource,
    ) {
    }

    public function render(Throwable $e, Request $request): JsonResponse {
      if ($e instanceof ValidationException) {
        return ErrorDocument::make($this->validationErrors($e, $request), $this->settings->jsonApiObject())->toResponse();
      }

      if ($e instanceof JsonApiException) {
        $errors = array_map(fn(Error $error) => $this->complete($error, $request), $e->errors());

        return ErrorDocument::make($errors, $this->settings->jsonApiObject())->toResponse($e->headers());
      }

      $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

      return ErrorDocument::make($this->errorFor($e, $request, $headers), $this->settings->jsonApiObject())->toResponse($headers);
    }

    /**
     * Un error por cada mensaje de cada campo. El mensaje es el que Laravel ya generó (en el idioma de la app) y el código sale de la regla
     * que falló: Laravel guarda las reglas fallidas en el mismo orden que los mensajes. Una excepción hecha a mano no tiene reglas.
     * One error for each message of each field. The message is the one Laravel already produced (in the app language) and the code comes from
     * the rule that failed: Laravel stores the failed rules in the same order as the messages. A hand-made exception has no rules.
     *
     * @return list<Error>
     */
    private function validationErrors(ValidationException $e, Request $request): array {
      $failed = $e->validator->failed();
      $title = $this->messages->title(ErrorCodes::VALIDATION_FAILED);
      $errors = [];

      foreach ($e->errors() as $key => $messages) {
        $rules = array_keys($failed[$key] ?? []);
        $source = $this->validationSource->resolve((string) $key, $request);

        foreach (array_values($messages) as $index => $message) {
          $errors[] = Error::make(
            status: $e->status,
            title: $title,
            detail: $message,
            code: $this->codes->validation($rules[$index] ?? null),
            pointer: $source['pointer'] ?? null,
            parameter: $source['parameter'] ?? null,
          );
        }
      }

      // Una excepción sin mensajes igual debe producir un error.
      // An exception with no messages must still produce an error.
      return $errors !== [] ? $errors : [Error::make(
        status: $e->status,
        title: $title,
        detail: $this->messages->detail(ErrorCodes::VALIDATION_FAILED),
        code: $this->codes->validation(null),
      )];
    }

    /**
     * El error de una excepción cualquiera: el estado sale de la excepción HTTP (o es 500), y los textos y el código, del catálogo.
     * The error of any exception: the status comes from the HTTP exception (or is 500), and the texts and code, from the catalog.
     *
     * @param  array<string, string>  $headers
     */
    private function errorFor(Throwable $e, Request $request, array $headers): Error {
      // Una AuthenticationException siempre es un 401 y nunca redirige al login, como haría Laravel con quien no pide JSON.
      // An AuthenticationException is always a 401 and never redirects to the login, as Laravel would for whoever does not ask for JSON.
      $status = match (true) {
        $e instanceof HttpExceptionInterface => $e->getStatusCode(),
        $e instanceof AuthenticationException => 401,
        default => 500,
      };
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
