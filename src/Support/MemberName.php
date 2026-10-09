<?php

  namespace MundoProgra\JsonApi\Support;

  use InvalidArgumentException;

  /**
   * Convierte los nombres de atributos y relaciones entre el estilo que ve el cliente (`member_case`) y snake_case, que es el de Eloquent.
   * Converts attribute and relationship names between the style the client sees (`member_case`) and snake_case, which is Eloquent's.
   */
  final class MemberName {
    private const CASES = ['camel', 'kebab', 'snake'];

    private function __construct(private readonly string $case) {
    }

    /**
     * @param  string  $case  'camel', 'kebab' o 'snake' / 'camel', 'kebab' or 'snake'
     *
     * @throws InvalidArgumentException
     */
    public static function for(string $case): self {
      if (!in_array($case, self::CASES, true)) {
        throw new InvalidArgumentException(sprintf(
          'Unknown member case "%s", expected one of [%s].',
          $case,
          implode(', ', self::CASES)
        ));
      }

      return new self($case);
    }

    public function case(): string {
      return $this->case;
    }

    /**
     * Del estilo del cliente a snake_case. Un nombre que ya está en snake_case no cambia.
     * From the client style to snake_case. A name that is already in snake_case does not change.
     */
    public function toSnake(string $name): string {
      return match ($this->case) {
        'camel' => $this->camelToSnake($name),
        'kebab' => str_replace('-', '_', $name),
        default => $name,
      };
    }

    /**
     * De snake_case al estilo del cliente.
     * From snake_case to the client style.
     */
    public function fromSnake(string $name): string {
      return match ($this->case) {
        'camel' => $this->snakeToCamel($name),
        'kebab' => str_replace('_', '-', $name),
        default => $name,
      };
    }

    /**
     * Convierte solo las claves del primer nivel; los valores anidados son datos del cliente y no se tocan.
     * Converts only the first-level keys; nested values are client data and are not touched.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     *
     * @throws InvalidArgumentException si dos claves terminan con el mismo nombre / if two keys end up with the same name
     */
    public function keysToSnake(array $data): array {
      return $this->convertKeys($data, fn(string $key) => $this->toSnake($key));
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     *
     * @throws InvalidArgumentException si dos claves terminan con el mismo nombre / if two keys end up with the same name
     */
    public function keysFromSnake(array $data): array {
      return $this->convertKeys($data, fn(string $key) => $this->fromSnake($key));
    }

    /**
     * Se separa una palabra nueva antes de cada mayúscula que sigue a una minúscula o dígito (firstName), y antes de la última
     * mayúscula de una sigla seguida de una palabra (HTMLParser). Los dígitos se quedan pegados a la palabra anterior.
     * A new word starts before each capital that follows a lowercase letter or digit (firstName), and before the last capital
     * of an acronym followed by a word (HTMLParser). Digits stay attached to the previous word.
     */
    private function camelToSnake(string $name): string {
      $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name);

      return mb_strtolower($separated ?? $name);
    }

    private function snakeToCamel(string $name): string {
      $camel = preg_replace_callback(
        '/(?<=[a-z0-9])_([a-z0-9])/',
        fn(array $match) => strtoupper($match[1]),
        $name
      );

      return $camel ?? $name;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  callable(string): string  $convert
     * @return array<int|string, mixed>
     */
    private function convertKeys(array $data, callable $convert): array {
      $converted = [];
      $origins = [];

      foreach ($data as $key => $value) {
        $newKey = is_string($key) ? $convert($key) : $key;

        if (array_key_exists($newKey, $converted)) {
          throw new InvalidArgumentException(sprintf(
            'The keys "%s" and "%s" would both become "%s".',
            $origins[$newKey],
            $key,
            $newKey
          ));
        }

        $converted[$newKey] = $value;
        $origins[$newKey] = $key;
      }

      return $converted;
    }
  }
