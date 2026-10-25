<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use MundoProgra\JsonApi\Support\Pointer;
  use stdClass;

  /**
   * El linkage de una relación: `null`, un identificador de recurso (`type` e `id`) o una lista de identificadores. Lo revisan por igual el
   * documento de un recurso (`relationships.autor.data`) y el de un endpoint de relación (`data`); solo cambia el puntero de donde se empieza.
   * Un identificador puede traer más miembros (`meta`), que se aceptan; al leerlo solo se conservan `type` e `id`.
   *
   * The linkage of a relationship: `null`, a resource identifier (`type` and `id`) or a list of identifiers. Both the document of a resource
   * (`relationships.author.data`) and that of a relationship endpoint (`data`) check it the same way; only the pointer it starts from changes.
   * An identifier may carry more members (`meta`), which are accepted; when it is read only `type` and `id` are kept.
   */
  final class Linkage {
    /**
     * Revisa un linkage ya decodificado (objetos como `stdClass`) y anota cada problema con su puntero.
     * Checks a decoded linkage (objects as `stdClass`) and notes each problem with its pointer.
     *
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     */
    public static function check(mixed $data, Pointer $pointer, array &$violations): void {
      if ($data === null) {
        return;
      }

      if ($data instanceof stdClass) {
        self::checkIdentifier($data, $pointer, $violations);

        return;
      }

      if (!is_array($data)) {
        $violations[] = ['pointer' => $pointer, 'reason' => 'invalid_linkage'];

        return;
      }

      foreach (array_values($data) as $index => $item) {
        if ($item instanceof stdClass) {
          self::checkIdentifier($item, $pointer->append($index), $violations);
        } else {
          $violations[] = ['pointer' => $pointer->append($index), 'reason' => 'not_an_object'];
        }
      }
    }

    /**
     * Un identificador revisado, con solo `type` e `id`.
     * A checked identifier, with only `type` and `id`.
     *
     * @return array{type: string, id: string}
     */
    public static function identifier(stdClass $identifier): array {
      $members = get_object_vars($identifier);

      return ['type' => (string) $members['type'], 'id' => (string) $members['id']];
    }

    /**
     * El `id` de un linkage ya leído (como arreglo): el de un solo identificador, o null si es `null`, una lista o no hay linkage.
     * The `id` of an already read linkage (as an array): that of a single identifier, or null if it is `null`, a list or there is no linkage.
     */
    public static function id(mixed $data): ?string {
      return is_array($data) && !array_is_list($data) && isset($data['id']) ? (string) $data['id'] : null;
    }

    /**
     * Los `id` de un linkage ya leído (como arreglo), en su orden: uno si es un identificador, ninguno si es `null` o no hay linkage.
     * The `id`s of an already read linkage (as an array), in its order: one if it is an identifier, none if it is `null` or there is no linkage.
     *
     * @return list<string>
     */
    public static function ids(mixed $data): array {
      if (!is_array($data)) {
        return [];
      }

      return array_map(fn($identifier) => (string) $identifier['id'], array_is_list($data) ? $data : [$data]);
    }

    /**
     * @param  list<array{pointer: Pointer, reason: string}>  $violations
     */
    private static function checkIdentifier(stdClass $identifier, Pointer $pointer, array &$violations): void {
      $members = get_object_vars($identifier);

      foreach (['type', 'id'] as $member) {
        if (!array_key_exists($member, $members)) {
          $violations[] = ['pointer' => $pointer->append($member), 'reason' => 'member_required'];
        } elseif (!is_string($members[$member]) || $members[$member] === '') {
          $violations[] = ['pointer' => $pointer->append($member), 'reason' => 'not_a_string'];
        }
      }
    }
  }
