<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use Illuminate\Http\Request;
  use JsonException;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Pointer;
  use stdClass;

  /**
   * Lee el cuerpo de una petición como JSON. Un cuerpo que no es JSON (vacío, cortado, con coma final, con UTF-8 inválido, demasiado anidado…) es
   * un `400` de `request.bad_document` con el motivo `malformed_json` y el puntero raíz. Un JSON válido que no es un documento (`null`, `[]`, un
   * texto) no es un error de sintaxis: lo rechaza quien lee el documento.
   *
   * Reads the body of a request as JSON. A body that is not JSON (empty, cut off, with a trailing comma, with invalid UTF-8, nested too deep…) is a
   * `400` of `request.bad_document` with the reason `malformed_json` and the root pointer. Valid JSON that is not a document (`null`, `[]`, a string)
   * is not a syntax error: whoever reads the document rejects it.
   */
  final class JsonBody {
    /**
     * Lo decodifica de dos formas: con objetos `stdClass` (para distinguir `{}` de `[]`) y como arreglo (para entregar los valores).
     * It decodes it in two ways: with `stdClass` objects (to tell `{}` from `[]`) and as an array (to give the values back).
     *
     * @return array{0: stdClass|array<int|string, mixed>|string|int|float|bool|null, 1: mixed}
     *
     * @throws JsonApiException si el cuerpo no es JSON / if the body is not JSON
     */
    public static function read(Request $request): array {
      $content = (string) $request->getContent();

      try {
        return [json_decode($content, false, 512, JSON_THROW_ON_ERROR), json_decode($content, true, 512, JSON_THROW_ON_ERROR)];
      } catch (JsonException) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::root(), 'reason' => 'malformed_json']]);
      }
    }
  }
