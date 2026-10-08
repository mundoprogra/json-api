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
