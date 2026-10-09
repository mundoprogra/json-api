<?php

  namespace MundoProgra\JsonApi\Support;

  use InvalidArgumentException;
  use Stringable;

  /**
   * Un JSON Pointer (RFC 6901): la ruta a un valor dentro de un documento, como /data/attributes/title.
   * Se usa en `source.pointer` para indicar dónde está el problema. Es inmutable.
   * A JSON Pointer (RFC 6901): the path to a value inside a document, like /data/attributes/title.
   * It is used in `source.pointer` to say where the problem is. It is immutable.
   */
  final class Pointer implements Stringable {
    /**
     * @param  list<string>  $segments
     */
    private function __construct(private readonly array $segments) {
    }

    /**
     * El puntero a todo el documento (texto vacío).
     * The pointer to the whole document (empty string).
     */
    public static function root(): self {
      return new self([]);
    }

    /**
     * Construye un puntero a partir de sus segmentos, sin escapar: el escape se hace al convertirlo a texto.
     * Builds a pointer from its segments, unescaped: escaping happens when it is converted to text.
     */
    public static function from(string|int ...$segments): self {
      return new self(array_map('strval', array_values($segments)));
    }

    /**
     * Lee un puntero escrito como texto, por ejemplo "/data/attributes/a~1b".
     * Reads a pointer written as text, for example "/data/attributes/a~1b".
     *
     * @throws InvalidArgumentException si el texto no es un JSON Pointer válido / if the text is not a valid JSON Pointer
     */
    public static function parse(string $pointer): self {
      if ($pointer === '') {
        return self::root();
      }

      if (!str_starts_with($pointer, '/')) {
        throw new InvalidArgumentException("A JSON Pointer must be empty or start with \"/\", \"$pointer\" given.");
      }

      // Según la RFC, "~" solo puede ir seguido de "0" o de "1".
      // Per the RFC, "~" can only be followed by "0" or "1".
      if (preg_match('/~(?![01])/', $pointer) === 1) {
        throw new InvalidArgumentException("The JSON Pointer \"$pointer\" has an invalid escape sequence: \"~\" must be followed by 0 or 1.");
      }

      return new self(array_map(
        fn(string $segment) => str_replace(['~1', '~0'], ['/', '~'], $segment),
        explode('/', substr($pointer, 1))
      ));
    }

    /**
     * Devuelve un puntero nuevo con los segmentos agregados al final; el original no cambia.
     * Returns a new pointer with the segments added at the end; the original does not change.
     */
    public function append(string|int ...$segments): self {
      return new self([...$this->segments, ...array_map('strval', array_values($segments))]);
    }

    /**
     * @return list<string>
     */
    public function segments(): array {
      return $this->segments;
    }

    public function __toString(): string {
      // Primero se escapa "~" y después "/": al revés, el "~" que agrega el escape de "/" se volvería a escapar.
      // "~" is escaped first and "/" second: the other way round, the "~" added by the "/" escape would be escaped again.
      return implode('', array_map(
        fn(string $segment) => '/' . str_replace(['~', '/'], ['~0', '~1'], $segment),
        $this->segments
      ));
    }
  }
