<?php

  namespace MundoProgra\JsonApi\Support;

  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Exceptions\InvalidConfigurationException;

  /**
   * Comprueba que la configuración del paquete tenga valores válidos y del tipo correcto.
   * Verifies that the package configuration has valid values of the right type.
   */
  class ConfigValidator {
    private const BOOLEANS = [
      'strict_headers',
      'jsonapi_object',
      'allow_client_ids',
      'allow_put',
      'reject_unknown_query_params',
      'errors.include_code',
      'errors.include_id',
    ];

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidConfigurationException
     */
    public function validate(array $config): void {
      $errors = [
        ...$this->oneOf($config, 'mode', ['prefix', 'global']),
        ...$this->pathPrefix($config),
        ...$this->listOfStrings($config, 'except'),
        ...$this->oneOf($config, 'member_case', ['camel', 'kebab', 'snake']),
        ...$this->oneOf($config, 'pagination.on_exceed', ['clamp', 'error']),
        ...$this->positiveInteger($config, 'pagination.size'),
        ...$this->positiveInteger($config, 'pagination.max_size'),
        ...$this->sizeNotGreaterThanMax($config),
        ...$this->positiveInteger($config, 'query.max_include_depth'),
        ...$this->string($config, 'links.route_name_prefix'),
        ...$this->nullableBoolean($config, 'errors.expose_details'),
        ...$this->string($config, 'errors.code_prefix'),
        ...$this->codeReplacements($config),
      ];

      foreach (self::BOOLEANS as $key) {
        $errors = [...$errors, ...$this->boolean($config, $key)];
      }

      if ($errors !== []) {
        throw new InvalidConfigurationException($errors);
      }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function oneOf(array $config, string $key, array $allowed): array {
      $value = Arr::get($config, $key);

      return is_string($value) && in_array($value, $allowed, true)
        ? []
        : [sprintf('"%s" must be one of [%s], %s given.', $key, implode(', ', $allowed), $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function pathPrefix(array $config): array {
      $value = Arr::get($config, 'path_prefix');
      $items = is_array($value) && array_is_list($value) ? $value : [$value];
      $valid = $items !== [] && array_reduce(
        $items,
        fn(bool $carry, mixed $item) => $carry && is_string($item) && trim($item, '/') !== '',
        true
      );

      return $valid
        ? []
        : [sprintf('"path_prefix" must be a non-empty string or a list of non-empty strings, %s given.', $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function listOfStrings(array $config, string $key): array {
      $value = Arr::get($config, $key);
      $valid = is_array($value)
        && array_is_list($value)
        && array_reduce($value, fn(bool $carry, mixed $item) => $carry && is_string($item), true);

      return $valid
        ? []
        : [sprintf('"%s" must be a list of strings, %s given.', $key, $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function boolean(array $config, string $key): array {
      $value = Arr::get($config, $key);

      return is_bool($value)
        ? []
        : [sprintf('"%s" must be true or false, %s given.', $key, $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function nullableBoolean(array $config, string $key): array {
      $value = Arr::get($config, $key);

      return $value === null || is_bool($value)
        ? []
        : [sprintf('"%s" must be true, false or null, %s given.', $key, $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function positiveInteger(array $config, string $key): array {
      $value = Arr::get($config, $key);

      return is_int($value) && $value >= 1
        ? []
        : [sprintf('"%s" must be an integer greater than or equal to 1, %s given.', $key, $this->describe($value))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function string(array $config, string $key): array {
      $value = Arr::get($config, $key);

      return is_string($value)
        ? []
        : [sprintf('"%s" must be a string, %s given.', $key, $this->describe($value))];
    }

    /**
     * Un mapa de textos no vacíos: el código por defecto (sin prefijo) y el código que lo reemplaza. Puede estar vacío.
     * A map of non-empty texts: the default code (without prefix) and the code that replaces it. It may be empty.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function codeReplacements(array $config): array {
      $value = Arr::get($config, 'errors.codes');
      $valid = is_array($value) && ($value === [] || !array_is_list($value));

      if ($valid) {
        foreach ($value as $key => $replacement) {
          if (!is_string($key) || $key === '' || !is_string($replacement) || $replacement === '') {
            $valid = false;

            break;
          }
        }
      }

      return $valid
        ? []
        : [sprintf('"errors.codes" must be a map of non-empty texts (default code => your code), %s given.', $this->describe($value))];
    }

    /**
     * Solo se compara cuando ambos valores ya son enteros válidos; si no, el error de tipo ya se reportó.
     * It is only compared when both values are already valid integers; otherwise the type error was already reported.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function sizeNotGreaterThanMax(array $config): array {
      $size = Arr::get($config, 'pagination.size');
      $max = Arr::get($config, 'pagination.max_size');

      if (!is_int($size) || !is_int($max) || $size < 1 || $max < 1) {
        return [];
      }

      return $size <= $max
        ? []
        : [sprintf('"pagination.size" (%d) must not be greater than "pagination.max_size" (%d).', $size, $max)];
    }

    private function describe(mixed $value): string {
      return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'an unprintable value';
    }
  }
