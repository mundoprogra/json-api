<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class SettingsTest extends TestCase {
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function defaults(): array {
      // [método de Settings, valor por defecto con su tipo]
      // [Settings method, default value with its type]
      return [
        'mode' => ['mode', 'prefix'],
        'pathPrefixes' => ['pathPrefixes', ['api']],
        'except' => ['except', ['up']],
        'strictHeaders' => ['strictHeaders', true],
        'memberCase' => ['memberCase', 'camel'],
        'jsonApiObject' => ['jsonApiObject', true],
        'allowClientIds' => ['allowClientIds', false],
        'allowPut' => ['allowPut', false],
        'rejectUnknownQueryParams' => ['rejectUnknownQueryParams', false],
        'paginationSize' => ['paginationSize', 15],
        'paginationMaxSize' => ['paginationMaxSize', 100],
        'paginationOnExceed' => ['paginationOnExceed', 'clamp'],
        'maxIncludeDepth' => ['maxIncludeDepth', 3],
        'routeNamePrefix' => ['routeNamePrefix', ''],
      ];
    }

    #[Test]
    #[DataProvider('defaults')]
    public function returns_the_typed_default_value(string $method, mixed $expected): void {
      $this->assertSame($expected, $this->settings()->$method());
    }

    #[Test]
    public function reads_the_config_at_call_time_instead_of_copying_it_at_construction(): void {
      // Si alguien cambia la configuración después de crear Settings (por ejemplo, un test o un middleware), el cambio debe verse.
      // If the config changes after Settings is created (for example, a test or a middleware), the change must be visible.
      $config = new Repository(['json-api' => $this->defaultConfig(), 'app' => ['debug' => false]]);
      $settings = new Settings($config);

      $config->set('json-api.pagination.size', 40);
      $config->set('json-api.mode', 'global');

      $this->assertSame(40, $settings->paginationSize());
      $this->assertSame('global', $settings->mode());
    }

    /**
     * @return array<string, array{string|list<string>, list<string>}>
     */
    public static function pathPrefixes(): array {
      return [
        'a string becomes a list' => ['api', ['api']],
        'a list stays a list' => [['api', 'v2'], ['api', 'v2']],
        'slashes around a prefix are trimmed' => ['/api/', ['api']],
        'slashes are trimmed in every item' => [['/api/', 'v2/'], ['api', 'v2']],
      ];
    }

    /**
     * @param  string|list<string>  $configured
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('pathPrefixes')]
    public function normalizes_the_path_prefix_into_a_list_of_clean_prefixes(string|array $configured, array $expected): void {
      $this->assertSame($expected, $this->settings(['path_prefix' => $configured])->pathPrefixes());
    }

    /**
     * @return array<string, array{bool|null, bool, bool}>
     */
    public static function exposeDetails(): array {
      // [errors.expose_details, app.debug, resultado esperado / expected result]
      return [
        'null follows app.debug when it is on' => [null, true, true],
        'null follows app.debug when it is off' => [null, false, false],
        'true wins over app.debug off' => [true, false, true],
        'false wins over app.debug on' => [false, true, false],
      ];
    }

    #[Test]
    #[DataProvider('exposeDetails')]
    public function decides_whether_to_expose_error_details(?bool $configured, bool $debug, bool $expected): void {
      $settings = $this->settings(['errors.expose_details' => $configured], $debug);

      $this->assertSame($expected, $settings->exposeErrorDetails());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array {
      return require __DIR__ . '/../../config/json-api.php';
    }

    /**
     * Crea un Settings con la configuración por defecto del paquete y los cambios indicados (claves con punto).
     * Creates a Settings with the package default config and the given changes (dotted keys).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function settings(array $overrides = [], bool $debug = false): Settings {
      $config = $this->defaultConfig();

      foreach ($overrides as $key => $value) {
        Arr::set($config, $key, $value);
      }

      return new Settings(new Repository(['json-api' => $config, 'app' => ['debug' => $debug]]));
    }
  }
