<?php

  namespace MundoProgra\JsonApi\Exceptions;

  use Exception;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorDocument;
  use MundoProgra\JsonApi\Support\Pointer;
  use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
  use Throwable;

  /**
   * Una excepción que representa uno o varios errores de JSON:API. Se lanza desde el código de la app y el paquete la convierte
   * en la respuesta. Se puede extender para definir un error propio en un solo lugar.
   * An exception that represents one or more JSON:API errors. It is thrown from the app code and the package turns it into
   * the response. It can be extended to define an own error in a single place.
   *
   * Solo describe los errores: no escribe logs ni guarda nada.
   * It only describes the errors: it does not write logs or store anything.
   */
  class JsonApiException extends Exception implements HttpExceptionInterface {
    /**
     * @var list<Error>
     */
    private readonly array $errors;

    /**
     * @param  array<int|string, Error>  $errors  al menos uno / at least one
     * @param  array<string, string>  $headers  cabeceras extra de la respuesta, como Retry-After / extra response headers, such as Retry-After
     *
     * @throws InvalidArgumentException si no hay ningún error / if there is no error
     */
    public function __construct(array $errors, private readonly array $headers = [], ?Throwable $previous = null) {
      $list = array_values($errors);

      if ($list === []) {
        throw new InvalidArgumentException('A JSON:API exception needs at least one error, none was given.');
      }

      $this->errors = $list;

      parent::__construct(self::messageFor($list), ErrorDocument::make($list)->httpStatus(), $previous);
    }

    /**
     * Crea la excepción con un solo error. Los parámetros son los de Error::make().
     * Creates the exception with a single error. The parameters are those of Error::make().
     *
     * @param  array<int|string, mixed>  $meta
     * @param  array<string, string>  $headers
     *
     * @throws InvalidArgumentException
     */
    public static function make(
      int|string $status,
      ?string $title = null,
      ?string $detail = null,
      ?string $code = null,
      ?string $id = null,
      Pointer|string|null $pointer = null,
      ?string $parameter = null,
      ?string $header = null,
      array $meta = [],
      array $headers = [],
      ?Throwable $previous = null,
    ): self {
      return new self(
        [Error::make($status, $title, $detail, $code, $id, $pointer, $parameter, $header, $meta)],
        $headers,
        $previous,
      );
    }

    /**
     * @return list<Error>
     */
    public function errors(): array {
      return $this->errors;
    }

    /**
     * El estado HTTP de la respuesta (la misma regla que ErrorDocument).
     * The HTTP status of the response (the same rule as ErrorDocument).
     */
    public function httpStatus(): int {
      return $this->getCode();
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array {
      return $this->headers;
    }

    public function getStatusCode(): int {
      return $this->httpStatus();
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array {
      return $this->headers;
    }

    public function toDocument(bool $jsonApiObject = true): ErrorDocument {
      return ErrorDocument::make($this->errors, $jsonApiObject);
    }

    /**
     * Le dice al reportador de Laravel si ya lo manejó: en Laravel, un valor distinto de false significa "no lo escribas en el log".
     * Los errores del cliente (4xx) son situaciones normales y no se reportan; los del servidor (5xx) sí, como cualquier excepción.
     * El paquete no escribe nada: el log lo escribe Laravel, con la configuración de la app.
     * Tells Laravel's reporter whether it was already handled: in Laravel, any value other than false means "do not write it to the log".
     * Client errors (4xx) are normal situations and are not reported; server errors (5xx) are, like any exception.
     * The package writes nothing: the log is written by Laravel, with the app configuration.
     */
    public function report(): bool {
      return $this->httpStatus() < 500;
    }

    /**
     * El detalle del primer error, o su título, o su estado; si hay más errores se indica cuántos.
     * The detail of the first error, or its title, or its status; if there are more errors it says how many.
     *
     * @param  list<Error>  $errors
     */
    private static function messageFor(array $errors): string {
      $first = $errors[0];
      $message = $first->detail() ?? $first->title() ?? sprintf('JSON:API error (HTTP %d).', $first->statusCode());
      $more = count($errors) - 1;

      return $more > 0 ? sprintf('%s (and %d more)', $message, $more) : $message;
    }
  }
