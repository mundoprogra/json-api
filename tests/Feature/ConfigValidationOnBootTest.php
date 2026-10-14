<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\Exceptions\InvalidConfigurationException;
  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Tests\Support\ChangesEnvironment;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  class ConfigValidationOnBootTest extends TestCase {
    use ChangesEnvironment;

    #[Test]
    public function boots_normally_with_the_default_config(): void {
      $this->bootProviderWith([]);

      $this->assertSame('prefix', config('json-api.mode'));
    }

    #[Test]
    public function fails_to_boot_when_a_value_is_invalid(): void {
      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"mode"/');

      $this->bootProviderWith(['mode' => 'banana']);
    }

    #[Test]
    public function fails_to_boot_when_a_nested_value_is_invalid(): void {
      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"pagination.size"/');

      $this->bootProviderWith(['pagination' => ['size' => 0]]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notCleanIntegers(): array {
      return [
        'text' => ['abc'],
        'a number followed by text' => ['12abc'],
        'a decimal number' => ['1.5'],
      ];
    }

    #[Test]
    #[DataProvider('notCleanIntegers')]
    public function fails_to_boot_when_a_number_in_the_environment_is_not_a_clean_integer(string $value): void {
      $this->setEnvironmentVariable('JSON_API_PAGE_SIZE', $value);

      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"pagination.size"/');

      $this->bootProviderWith([]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notBooleans(): array {
      return [
        'text' => ['abc'],
        'an ambiguous word' => ['maybe'],
      ];
    }

    #[Test]
    #[DataProvider('notBooleans')]
    public function fails_to_boot_when_a_boolean_in_the_environment_is_not_a_valid_boolean(string $value): void {
      $this->setEnvironmentVariable('JSON_API_ALLOW_PUT', $value);

      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"allow_put"/');

      $this->bootProviderWith([]);
    }

    #[Test]
    public function fails_to_boot_when_the_error_code_replacements_are_not_a_map_of_texts(): void {
      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"errors.codes"/');

      $this->bootProviderWith(['errors' => ['codes' => ['validation.required' => 1001]]]);
    }

    #[Test]
    public function fails_to_boot_when_the_include_code_environment_variable_is_not_a_valid_boolean(): void {
      $this->setEnvironmentVariable('JSON_API_INCLUDE_CODE', 'maybe');

      $this->expectException(InvalidConfigurationException::class);
      $this->expectExceptionMessageMatches('/"errors.include_code"/');

      $this->bootProviderWith([]);
    }

    #[Test]
    public function boots_with_an_empty_code_prefix_and_with_custom_code_replacements(): void {
      $this->bootProviderWith(['errors' => ['code_prefix' => '', 'codes' => ['resource.not_found' => 'ERR-4040']]]);

      $this->assertSame('', config('json-api.errors.code_prefix'));
      $this->assertSame(['resource.not_found' => 'ERR-4040'], config('json-api.errors.codes'));
      $this->assertTrue(config('json-api.errors.include_code'));
    }

    /**
     * Simula el arranque de una app cuyo config/json-api.php ya tenía estos valores: se registra de nuevo el provider.
     * Simulates booting an app whose config/json-api.php already had these values: the provider is registered again.
     *
     * @param  array<string, mixed>  $appConfig
     */
    private function bootProviderWith(array $appConfig): void {
      config()->set('json-api', $appConfig);

      $this->app->register(JsonApiServiceProvider::class, true);
    }
  }
