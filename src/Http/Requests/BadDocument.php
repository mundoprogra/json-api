<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Pointer;

  /**
   * El `400` de la familia `request.bad_document` con un error por cada problema, cada uno con el `source.pointer` del miembro que falla y el texto
   * de su motivo. Lo usan las clases que leen el documento de entrada para que todos los errores salgan igual.
   *
   * The `400` of the `request.bad_document` family with an error for each problem, each with the `source.pointer` of the member that fails and the
   * text of its reason. The classes that read the input document use it so that all the errors come out the same.
   */
  final class BadDocument {
    /**
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     */
    public static function exceptionFor(array $violations): JsonApiException {
      $messages = app(ErrorMessages::class);
      $codes = app(ErrorCodes::class);

      return new JsonApiException(array_map(fn(array $violation) => Error::make(
        status: 400,
        title: $messages->title(ErrorCodes::BAD_DOCUMENT),
        detail: $messages->detail(ErrorCodes::BAD_DOCUMENT, [], $violation['reason']),
        code: $codes->resolve(ErrorCodes::BAD_DOCUMENT),
        pointer: $violation['pointer'],
      ), $violations));
    }

    /**
     * El `403` de un `POST` que trae un `id` elegido por el cliente cuando `allow_client_ids` está apagado (lo que pide la spec).
     * The `403` of a `POST` that carries an `id` chosen by the client when `allow_client_ids` is off (what the spec asks for).
     */
    public static function clientIdNotAllowed(): JsonApiException {
      $messages = app(ErrorMessages::class);

      return new JsonApiException([Error::make(
        status: 403,
        title: $messages->title(ErrorCodes::CLIENT_ID_NOT_ALLOWED),
        detail: $messages->detail(ErrorCodes::CLIENT_ID_NOT_ALLOWED),
        code: app(ErrorCodes::class)->resolve(ErrorCodes::CLIENT_ID_NOT_ALLOWED),
        pointer: Pointer::from('data', 'id'),
      )]);
    }
  }
