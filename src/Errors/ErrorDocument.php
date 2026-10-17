<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Http\JsonResponse;
  use InvalidArgumentException;
  use JsonSerializable;
  use MundoProgra\JsonApi\Support\MediaType;

  /**
   * Un documento de error de JSON:API: la respuesta completa con la lista `errors`. Es inmutable.
   * A JSON:API error document: the complete response with the `errors` list. It is immutable.
   */
  final class ErrorDocument implements JsonSerializable {
    /**
     * @param  list<Error>  $errors
     */
    private function __construct(
      private readonly array $errors,
      private readonly bool $jsonApiObject,
    ) {
    }

    /**
     * @param  Error|array<int|string, Error>  $errors  un error o una lista; las claves se descartan / one error or a list; keys are discarded
     * @param  bool  $jsonApiObject  incluir el miembro `jsonapi` / include the `jsonapi` member
     *
     * @throws InvalidArgumentException si no hay ningún error / if there is no error
     */
    public static function make(Error|array $errors, bool $jsonApiObject = true): self {
      $list = $errors instanceof Error ? [$errors] : array_values($errors);

      if ($list === []) {
        throw new InvalidArgumentException('An error document needs at least one error, none was given.');
      }

      return new self($list, $jsonApiObject);
    }

    /**
     * @return list<Error>
     */
    public function errors(): array {
      return $this->errors;
    }

    /**
     * El estado HTTP "más generalmente aplicable" que pide la spec: si todos los errores comparten estado se usa ese;
     * si no, 500 cuando hay algún 5xx y 400 cuando todos son 4xx. Cada error conserva su propio estado.
     * The "most generally applicable" HTTP status the spec asks for: if all errors share a status it is used;
     * otherwise 500 when there is any 5xx and 400 when all are 4xx. Each error keeps its own status.
     */
    public function httpStatus(): int {
      $statuses = array_unique(array_map(fn(Error $error) => $error->statusCode(), $this->errors));

      if (count($statuses) === 1) {
        return $statuses[array_key_first($statuses)];
      }

      return max($statuses) >= 500 ? 500 : 400;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
      $document = [];

      if ($this->jsonApiObject) {
        $document['jsonapi'] = ['version' => MediaType::JSON_API_VERSION];
      }

      $document['errors'] = array_map(fn(Error $error) => $error->toArray(), $this->errors);

      return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
      return $this->toArray();
    }

    /**
     * La respuesta HTTP lista para enviar. Las cabeceras extra (por ejemplo `Allow` o `Retry-After`) se conservan,
     * pero el `Content-Type` siempre es el de JSON:API sin parámetros.
     * The HTTP response ready to send. Extra headers (for example `Allow` or `Retry-After`) are kept,
     * but the `Content-Type` is always the plain JSON:API one.
     *
     * @param  array<string, string>  $headers
     */
    public function toResponse(array $headers = []): JsonResponse {
      $response = new JsonResponse(
        $this->toArray(),
        $this->httpStatus(),
        $headers,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
      );

      $response->headers->set('Content-Type', MediaType::JSON_API);

      return $response;
    }
  }
