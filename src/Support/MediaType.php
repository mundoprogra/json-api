<?php

  namespace MundoProgra\JsonApi\Support;

  use InvalidArgumentException;
  use Stringable;

  /**
   * Un tipo de medio de una cabecera HTTP, por ejemplo: application/vnd.api+json; ext="https://a.test/x"; profile="https://a.test/p".
   * Representa un solo tipo (no una lista) y es inmutable.
   * A media type from an HTTP header, for example: application/vnd.api+json; ext="https://a.test/x"; profile="https://a.test/p".
   * It represents a single type (not a list) and is immutable.
   */
  final class MediaType implements Stringable {
    private const JSON_API = 'application/vnd.api+json';

    /**
     * Parámetros de JSON:API cuyo valor es una lista de direcciones separadas por espacios.
     * JSON:API parameters whose value is a list of URIs separated by spaces.
     */
    private const LIST_PARAMETERS = ['ext', 'profile'];

    /**
     * Un "token" según la RFC 7230: los únicos caracteres permitidos sin comillas.
     * A "token" per RFC 7230: the only characters allowed without quotes.
     */
    private const TOKEN = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]+';

    /**
     * @param  array<string, string>  $parameters
     */
    private function __construct(
      private readonly string $type,
      private readonly string $subtype,
      private readonly array $parameters,
    ) {
    }

    /**
     * @throws InvalidArgumentException si el texto no es un solo tipo de medio válido / if the text is not a single valid media type
     */
    public static function parse(string $value): self {
      $text = trim($value, " \t");
      $semicolon = strpos($text, ';');
      $essence = $semicolon === false ? $text : rtrim(substr($text, 0, $semicolon), " \t");

      if (preg_match('/^(' . self::TOKEN . ')\/(' . self::TOKEN . ')$/D', $essence, $parts) !== 1) {
        throw self::invalid($value);
      }

      $parameters = $semicolon === false ? [] : self::parseParameters($text, $semicolon, $value);

      return new self(strtolower($parts[1]), strtolower($parts[2]), $parameters);
    }

    public function type(): string {
      return $this->type;
    }

    public function subtype(): string {
      return $this->subtype;
    }

    public function essence(): string {
      return $this->type . '/' . $this->subtype;
    }

    /**
     * Los nombres van en minúscula; los valores, tal como se escribieron.
     * Names are lowercase; values are kept as written.
     *
     * @return array<string, string>
     */
    public function parameters(): array {
      return $this->parameters;
    }

    public function parameter(string $name): ?string {
      return $this->parameters[strtolower($name)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array {
      return $this->uris('ext');
    }

    /**
     * @return list<string>
     */
    public function profiles(): array {
      return $this->uris('profile');
    }

    public function isJsonApi(): bool {
      return $this->essence() === self::JSON_API;
    }

    /**
     * Igual tipo y mismos parámetros, sin importar el orden de los parámetros ni de las direcciones de `ext` y `profile`.
     * Same type and same parameters, regardless of the order of the parameters or of the URIs in `ext` and `profile`.
     */
    public function equals(self $other): bool {
      return $this->essence() === $other->essence()
        && $this->normalizedParameters() === $other->normalizedParameters();
    }

    public function __toString(): string {
      $text = $this->essence();

      foreach ($this->parameters as $name => $value) {
        $written = in_array($name, self::LIST_PARAMETERS, true)
          ? '"' . implode(' ', $this->uris($name)) . '"'
          : self::writeValue($value);

        $text .= "; $name=$written";
      }

      return $text;
    }

    /**
     * Lee los parámetros que empiezan en `$offset`, donde hay un ";".
     * Reads the parameters starting at `$offset`, where there is a ";".
     *
     * @return array<string, string>
     */
    private static function parseParameters(string $text, int $offset, string $original): array {
      $parameters = [];
      $length = strlen($text);

      while ($offset < $length) {
        $offset++;
        $offset += strspn($text, " \t", $offset);

        if (preg_match('/(' . self::TOKEN . ')=/A', $text, $name, 0, $offset) !== 1) {
          throw self::invalid($original);
        }

        $offset += strlen($name[0]);
        $key = strtolower($name[1]);

        if (array_key_exists($key, $parameters)) {
          throw self::invalid($original);
        }

        if (($text[$offset] ?? '') === '"') {
          [$parameters[$key], $offset] = self::readQuoted($text, $offset, $original);
        } elseif (preg_match('/' . self::TOKEN . '/A', $text, $token, 0, $offset) === 1) {
          $parameters[$key] = $token[0];
          $offset += strlen($token[0]);
        } else {
          throw self::invalid($original);
        }

        $offset += strspn($text, " \t", $offset);

        if ($offset < $length && $text[$offset] !== ';') {
          throw self::invalid($original);
        }
      }

      return $parameters;
    }

    /**
     * Lee un valor entre comillas que empieza en `$offset` y devuelve el valor sin comillas y la posición siguiente.
     * Reads a quoted value starting at `$offset` and returns the unquoted value and the next position.
     *
     * @return array{string, int}
     */
    private static function readQuoted(string $text, int $offset, string $original): array {
      $length = strlen($text);
      $value = '';
      $offset++;

      while ($offset < $length) {
        $char = $text[$offset];

        if ($char === '"') {
          return [$value, $offset + 1];
        }

        if ($char === '\\' && $offset + 1 < $length) {
          $offset++;
          $char = $text[$offset];
        }

        $value .= $char;
        $offset++;
      }

      throw self::invalid($original);
    }

    private static function writeValue(string $value): string {
      if (preg_match('/^' . self::TOKEN . '$/D', $value) === 1) {
        return $value;
      }

      return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @return list<string>
     */
    private function uris(string $name): array {
      $value = $this->parameters[$name] ?? '';

      return preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function normalizedParameters(): array {
      $normalized = [];

      foreach ($this->parameters as $name => $value) {
        if (in_array($name, self::LIST_PARAMETERS, true)) {
          $uris = $this->uris($name);
          sort($uris);
          $normalized[$name] = $uris;
        } else {
          $normalized[$name] = $value;
        }
      }

      ksort($normalized);

      return $normalized;
    }

    private static function invalid(string $value): InvalidArgumentException {
      return new InvalidArgumentException("\"$value\" is not a valid media type.");
    }
  }
