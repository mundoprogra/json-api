<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Pointer;
  use stdClass;

  /**
   * El documento que llega en el cuerpo de una petición a un endpoint de relación (`/articles/1/relationships/tags`). Ahí el documento no es un
   * recurso sino su linkage: `{"data": null}`, `{"data": {"type", "id"}}` o `{"data": [{"type", "id"}, ...]}`. Cada identificador sale solo con
   * `type` e `id`. Un linkage mal formado es un `400` de `request.bad_document` con el `source.pointer` exacto, todos los problemas juntos.
   *
   * The document that arrives in the body of a request to a relationship endpoint (`/articles/1/relationships/tags`). There the document is not a
   * resource but its linkage: `{"data": null}`, `{"data": {"type", "id"}}` or `{"data": [{"type", "id"}, ...]}`. Each identifier comes out with only
   * `type` and `id`. A malformed linkage is a `400` of `request.bad_document` with the exact `source.pointer`, all problems together.
   */
  final class RelationshipDocument {
    // Dónde se guarda el documento ya leído dentro de la petición.
    // Where the already read document is kept inside the request.
    private const ATTRIBUTE = 'json-api.document.relationship';

    /**
     * @param  array<int|string, mixed>|null  $data
     */
    private function __construct(private readonly ?array $data, private readonly bool $list) {
    }

    /**
     * El documento de la petición, leído una sola vez: la primera llamada lo valida y lo guarda en la petición, y las demás lo reutilizan.
     * Si el documento está mal formado lanza el error cada vez que se pide.
     * The document of the request, read once: the first call validates it and keeps it on the request, and the others reuse it.
     * If the document is malformed it throws the error every time it is asked for.
     *
     * @throws JsonApiException
     */
    public static function of(Request $request): self {
      $parsed = $request->attributes->get(self::ATTRIBUTE);

      if (!$parsed instanceof self) {
        $parsed = self::from($request);
        $request->attributes->set(self::ATTRIBUTE, $parsed);
      }

      return $parsed;
    }

    /**
     * @throws JsonApiException con un error `400` por cada miembro mal formado / with a `400` error for each malformed member
     */
    public static function from(Request $request): self {
      [$object] = JsonBody::read($request);

      if (!$object instanceof stdClass) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::root(), 'reason' => 'not_an_object']]);
      }

      $document = get_object_vars($object);

      if (!array_key_exists('data', $document)) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::root(), 'reason' => 'member_required']]);
      }

      $violations = [];
      Linkage::check($document['data'], Pointer::from('data'), $violations);

      if ($violations !== []) {
        throw BadDocument::exceptionFor($violations);
      }

      $data = $document['data'];

      if ($data instanceof stdClass) {
        return new self(Linkage::identifier($data), false);
      }

      if (is_array($data)) {
        return new self(array_map(fn(stdClass $item) => Linkage::identifier($item), array_values($data)), true);
      }

      return new self(null, false);
    }

    /**
     * ¿La URL de la petición es la de un endpoint de relación? Lo es si el penúltimo segmento es `relationships`
     * (`/api/articles/1/relationships/tags`); no depende del prefijo ni del método.
     *
     * Is the URL of the request that of a relationship endpoint? It is if the second-to-last segment is `relationships`
     * (`/api/articles/1/relationships/tags`); it depends neither on the prefix nor on the method.
     */
    public static function appliesTo(Request $request): bool {
      $segments = explode('/', trim($request->path(), '/'));

      return count($segments) >= 2 && $segments[count($segments) - 2] === 'relationships';
    }

    /**
     * El linkage: null (vaciar una relación a uno), un identificador (`type` e `id`) o una lista de identificadores.
     * The linkage: null (clear a to-one relationship), an identifier (`type` and `id`) or a list of identifiers.
     *
     * @return array<int|string, mixed>|null
     */
    public function data(): ?array {
      return $this->data;
    }

    /**
     * ¿El linkage es una lista (relación a muchos)?
     * Is the linkage a list (a to-many relationship)?
     */
    public function isList(): bool {
      return $this->list;
    }
  }
