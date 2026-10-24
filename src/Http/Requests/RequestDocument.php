<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Pointer;
  use MundoProgra\JsonApi\Support\Settings;
  use stdClass;

  /**
   * El documento que llega en el cuerpo de una petición JSON:API que crea o actualiza un recurso, leído como una estructura tipada e inmutable:
   * `type`, `id`, `attributes` y `relationships`. Los nombres del primer nivel de `attributes` y de `relationships` pasan del estilo de
   * `member_case` a snake_case; los valores y lo anidado no se tocan.
   * Un documento mal formado es un `400` de la familia `request.bad_document` con el `source.pointer` exacto del miembro que falla: todos los
   * problemas salen juntos, en el orden `type`, `id`, `attributes`, `relationships`, y los textos nunca repiten lo que mandó el cliente.
   * Revisa solo la forma del recurso; no conoce los tipos de la app.
   *
   * The document that arrives in the body of a JSON:API request that creates or updates a resource, read as a typed, immutable structure:
   * `type`, `id`, `attributes` and `relationships`. The first-level names of `attributes` and `relationships` go from the `member_case` style to
   * snake_case; the values and anything nested are not touched.
   * A malformed document is a `400` of the `request.bad_document` family with the exact `source.pointer` of the member that fails: all the problems
   * come out together, in the order `type`, `id`, `attributes`, `relationships`, and the texts never repeat what the client sent.
   * It only checks the shape of the resource; it does not know the types of the app.
   */
  final class RequestDocument {
    // Los campos de un recurso comparten un solo espacio de nombres: estos cuatro no pueden ser atributo ni relación.
    // The fields of a resource share a single namespace: these four cannot be an attribute or a relationship.
    private const RESERVED = ['type', 'id', 'relationships', 'links'];

    /**
     * @param  array<int|string, mixed>  $attributes
     * @param  array<int|string, mixed>  $relationships
     */
    private function __construct(
      private readonly string $type,
      private readonly ?string $id,
      private readonly array $attributes,
      private readonly array $relationships,
    ) {
    }

    /**
     * El `id` depende del método: al actualizar (`PATCH` o `PUT`) es obligatorio, y al crear (`POST`) el cliente solo puede mandarlo si
     * `allow_client_ids` está encendido.
     * The `id` depends on the method: when updating (`PATCH` or `PUT`) it is required, and when creating (`POST`) the client can only send it if
     * `allow_client_ids` is on.
     *
     * @throws JsonApiException un `400` si el cuerpo no es JSON o un error `400` por cada miembro mal formado, y un `403` si un `POST` trae un `id` que no se acepta
     *                          a `400` if the body is not JSON or a `400` error for each malformed member, and a `403` if a `POST` carries an `id` that is not accepted
     */
    public static function from(Request $request, Settings $settings): self {
      [$object, $values] = JsonBody::read($request);
      $names = MemberName::for($settings->memberCase());
      $method = $request->method();

      if (!$object instanceof stdClass) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::root(), 'reason' => 'not_an_object']]);
      }

      $document = get_object_vars($object);

      if (!array_key_exists('data', $document)) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::root(), 'reason' => 'member_required']]);
      }

      if (!$document['data'] instanceof stdClass) {
        throw BadDocument::exceptionFor([['pointer' => Pointer::from('data'), 'reason' => 'not_an_object']]);
      }

      $data = get_object_vars($document['data']);
      $violations = [];
      $taken = [];

      $type = self::text($data, 'type', true, $violations);
      $id = self::text($data, 'id', in_array($method, ['PATCH', 'PUT'], true), $violations);
      $attributes = self::fields($data, 'attributes', false, $values, $names, $taken, $violations);
      $relationships = self::fields($data, 'relationships', true, $values, $names, $taken, $violations);

      if ($violations !== []) {
        throw BadDocument::exceptionFor($violations);
      }

      if ($method === 'POST' && $id !== null && !$settings->allowClientIds()) {
        throw BadDocument::clientIdNotAllowed();
      }

      return new self((string) $type, $id, $attributes, $relationships);
    }

    /**
     * El `type` del recurso, tal como lo mandó el cliente.
     * The `type` of the resource, as the client sent it.
     */
    public function type(): string {
      return $this->type;
    }

    /**
     * El `id` que mandó el cliente, o null si no mandó ninguno.
     * The `id` the client sent, or null if it sent none.
     */
    public function id(): ?string {
      return $this->id;
    }

    /**
     * Los atributos, con los nombres del primer nivel en snake_case y en el orden del cliente.
     * The attributes, with the first-level names in snake_case and in the order of the client.
     *
     * @return array<int|string, mixed>
     */
    public function attributes(): array {
      return $this->attributes;
    }

    /**
     * Las relaciones, con los nombres en snake_case y el objeto de cada una tal como lo mandó el cliente.
     * The relationships, with the names in snake_case and the object of each one as the client sent it.
     *
     * @return array<int|string, mixed>
     */
    public function relationships(): array {
      return $this->relationships;
    }

    /**
     * Lee `type` o `id`: un texto no vacío. Falta o no es texto, y es un problema si es obligatorio o si se mandó.
     * Reads `type` or `id`: a non-empty string. If it is missing or not a string it is a problem when it is required or when it was sent.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     */
    private static function text(array $data, string $member, bool $required, array &$violations): ?string {
      if (!array_key_exists($member, $data)) {
        if ($required) {
          $violations[] = ['pointer' => Pointer::from('data', $member), 'reason' => 'member_required'];
        }

        return null;
      }

      if (!is_string($data[$member]) || $data[$member] === '') {
        $violations[] = ['pointer' => Pointer::from('data', $member), 'reason' => 'not_a_string'];

        return null;
      }

      return $data[$member];
    }

    /**
     * Lee `attributes` o `relationships`: un objeto cuyos nombres pasan a snake_case. Con `$objects` cada valor también debe ser un objeto.
     * Los nombres reservados y los repetidos (aunque se escriban distinto, ya en snake_case) son un problema; `$taken` comparte los nombres entre
     * atributos y relaciones, porque los campos de un recurso tienen un solo espacio de nombres.
     * Reads `attributes` or `relationships`: an object whose names go to snake_case. With `$objects` each value must be an object too.
     * Reserved and repeated names (even if written differently, once in snake_case) are a problem; `$taken` shares the names between attributes and
     * relationships, because the fields of a resource have a single namespace.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int|string, mixed>|null  $values  el documento completo leído como arreglo / the whole document read as an array
     * @param  array<string, true>  $taken
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     * @return array<int|string, mixed>
     */
    private static function fields(array $data, string $member, bool $objects, ?array $values, MemberName $names, array &$taken, array &$violations): array {
      if (!array_key_exists($member, $data)) {
        return [];
      }

      if (!$data[$member] instanceof stdClass) {
        $violations[] = ['pointer' => Pointer::from('data', $member), 'reason' => 'not_an_object'];

        return [];
      }

      $fields = [];

      foreach (get_object_vars($data[$member]) as $key => $node) {
        $name = (string) $key;
        $pointer = Pointer::from('data', $member, $name);

        if (in_array($name, self::RESERVED, true)) {
          $violations[] = ['pointer' => $pointer, 'reason' => 'reserved_member_name'];

          continue;
        }

        $snake = $names->toSnake($name);

        if (isset($taken[$snake])) {
          $violations[] = ['pointer' => $pointer, 'reason' => 'duplicate_member_name'];

          continue;
        }

        $taken[$snake] = true;

        if ($objects && !$node instanceof stdClass) {
          $violations[] = ['pointer' => $pointer, 'reason' => 'not_an_object'];

          continue;
        }

        if ($objects) {
          self::checkRelationship($node, $pointer, $violations);
        }

        $fields[$snake] = $values['data'][$member][$name] ?? null;
      }

      return $fields;
    }

    /**
     * Una relación necesita al menos uno de `links`, `data` o `meta`; su `data`, si viene, es un linkage válido.
     * A relationship needs at least one of `links`, `data` or `meta`; its `data`, if present, is a valid linkage.
     *
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     */
    private static function checkRelationship(stdClass $relationship, Pointer $pointer, array &$violations): void {
      $members = get_object_vars($relationship);

      if (array_intersect(['links', 'data', 'meta'], array_keys($members)) === []) {
        $violations[] = ['pointer' => $pointer, 'reason' => 'member_required'];

        return;
      }

      if (array_key_exists('data', $members)) {
        Linkage::check($members['data'], $pointer->append('data'), $violations);
      }
    }
  }
