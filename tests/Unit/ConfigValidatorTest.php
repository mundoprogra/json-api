<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Support\Arr;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Exceptions\InvalidConfigurationException;
  use MundoProgra\JsonApi\Support\ConfigValidator;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ConfigValidatorTest extends TestCase {
    #[Test]
    public function accepts_the_default_config(): void {
      (new ConfigValidator())->validate($this->config());

      $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function validValues(): array {
      // [clave de configuración, valor válido distinto al de por defecto]
      // [config key, valid value different from the default]
      return [
        'mode global' => ['mode', 'global'],
        'member_case kebab' => ['member_case', 'kebab'],
        'member_case snake' => ['member_case', 'snake'],
        'path_prefix as a string' => ['path_prefix', 'v2'],
        'path_prefix as a list' => ['path_prefix', ['api', 'v2']],
        'except as an empty list' => ['except', []],
        'strict_headers false' => ['strict_headers', false],
        'pagination.size equal to max_size' => ['pagination.size', 100],
        'pagination.on_exceed error' => ['pagination.on_exceed', 'error'],
        'links.route_name_prefix' => ['links.route_name_prefix', 'api.v1.'],
        'errors.expose_details true' => ['errors.expose_details', true],
        'errors.expose_details false' => ['errors.expose_details', false],
      ];
    }

    #[Test]
    #[DataProvider('validValues')]
    public function accepts_valid_values(string $key, mixed $value): void {
      (new ConfigValidator())->validate($this->config([$key => $value]));

      $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidValues(): array {
      return [
        'mode unknown' => ['mode', 'banana'],
        'member_case unknown' => ['member_case', 'pascal'],
        'pagination.size zero' => ['pagination.size', 0],
        'pagination.size negative' => ['pagination.size', -5],
        'pagination.size as text' => ['pagination.size', '15'],
        'pagination.size greater than max_size' => ['pagination.size', 200],
        'pagination.max_size zero' => ['pagination.max_size', 0],
        'pagination.max_size smaller than size' => ['pagination.max_size', 10],
        'pagination.on_exceed unknown' => ['pagination.on_exceed', 'ignore'],
        'query.max_include_depth zero' => ['query.max_include_depth', 0],
        'strict_headers as text' => ['strict_headers', 'yes'],
        'strict_headers null' => ['strict_headers', null],
        'jsonapi_object as number' => ['jsonapi_object', 1],
        'allow_client_ids as text' => ['allow_client_ids', 'true'],
        'allow_put null' => ['allow_put', null],
        'reject_unknown_query_params as number' => ['reject_unknown_query_params', 0],
        'path_prefix empty text' => ['path_prefix', ''],
        'path_prefix empty list' => ['path_prefix', []],
        'path_prefix as number' => ['path_prefix', 5],
        'path_prefix with a number inside' => ['path_prefix', ['api', 7]],
        'path_prefix with an empty item' => ['path_prefix', ['api', '']],
        'except as text' => ['except', 'up'],
        'except with a number inside' => ['except', [5]],
        'links.route_name_prefix as number' => ['links.route_name_prefix', 5],
        'errors.expose_details as text' => ['errors.expose_details', 'yes'],
      ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function rejects_an_invalid_value_and_names_the_key(string $key, mixed $value): void {
      try {
        (new ConfigValidator())->validate($this->config([$key => $value]));

        $this->fail("Se esperaba una excepción para '$key' / An exception was expected for '$key'");
      } catch (InvalidConfigurationException $exception) {
        $this->assertStringContainsString("\"$key\"", $exception->getMessage());
      }
    }

    #[Test]
    public function the_message_names_the_key_the_allowed_values_and_the_given_value(): void {
      try {
        (new ConfigValidator())->validate($this->config(['mode' => 'banana']));

        $this->fail('Se esperaba una excepción / An exception was expected');
      } catch (InvalidConfigurationException $exception) {
        $this->assertStringContainsString('"mode"', $exception->getMessage());
        $this->assertStringContainsString('prefix', $exception->getMessage());
        $this->assertStringContainsString('global', $exception->getMessage());
        $this->assertStringContainsString('banana', $exception->getMessage());
      }
    }

    #[Test]
    public function reports_every_problem_at_once_instead_of_only_the_first(): void {
      try {
        (new ConfigValidator())->validate($this->config(['mode' => 'banana', 'member_case' => 'pascal']));

        $this->fail('Se esperaba una excepción / An exception was expected');
      } catch (InvalidConfigurationException $exception) {
        $this->assertCount(2, $exception->errors());
        $this->assertStringContainsString('"mode"', $exception->getMessage());
        $this->assertStringContainsString('"member_case"', $exception->getMessage());
      }
    }

    #[Test]
    public function the_exception_is_an_invalid_argument_exception(): void {
      $this->assertInstanceOf(InvalidArgumentException::class, new InvalidConfigurationException(['x']));
    }

    /**
     * Configuración por defecto del paquete con los cambios indicados (claves con punto).
     * The package default config with the given changes (dotted keys).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array {
      $config = require __DIR__ . '/../../config/json-api.php';

      foreach ($overrides as $key => $value) {
        Arr::set($config, $key, $value);
      }

      return $config;
    }
  }
