<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Tests\Support\ChangesEnvironment;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  class ConfigEnvTest extends TestCase {
    use ChangesEnvironment;

    /**
     * @return array<string, array{string, string, string, mixed}>
     */
    public static function environment(): array {
      // [variable, valor en el entorno, clave de configuración, valor esperado con su tipo]
      // [variable, value in the environment, config key, expected value with its type]
      return [
        'mode' => ['JSON_API_MODE', 'global', 'mode', 'global'],
        'path_prefix' => ['JSON_API_PATH_PREFIX', 'v2', 'path_prefix', 'v2'],
        'strict_headers false' => ['JSON_API_STRICT_HEADERS', 'false', 'strict_headers', false],
        'member_case' => ['JSON_API_MEMBER_CASE', 'snake', 'member_case', 'snake'],
        'jsonapi_object 0' => ['JSON_API_JSONAPI_OBJECT', '0', 'jsonapi_object', false],
        'allow_client_ids true' => ['JSON_API_ALLOW_CLIENT_IDS', 'true', 'allow_client_ids', true],
        'allow_put 1' => ['JSON_API_ALLOW_PUT', '1', 'allow_put', true],
        'reject_unknown_query_params' => ['JSON_API_REJECT_UNKNOWN_QUERY_PARAMS', 'true', 'reject_unknown_query_params', true],
        'pagination.size' => ['JSON_API_PAGE_SIZE', '25', 'pagination.size', 25],
        'pagination.max_size' => ['JSON_API_PAGE_MAX_SIZE', '50', 'pagination.max_size', 50],
        'pagination.on_exceed' => ['JSON_API_PAGE_ON_EXCEED', 'error', 'pagination.on_exceed', 'error'],
        'query.max_include_depth' => ['JSON_API_MAX_INCLUDE_DEPTH', '5', 'query.max_include_depth', 5],
        'links.route_name_prefix' => ['JSON_API_ROUTE_NAME_PREFIX', 'api.v1.', 'links.route_name_prefix', 'api.v1.'],
        'errors.expose_details true' => ['JSON_API_EXPOSE_DETAILS', 'true', 'errors.expose_details', true],
        'errors.expose_details false' => ['JSON_API_EXPOSE_DETAILS', 'false', 'errors.expose_details', false],
      ];
    }

    #[Test]
    #[DataProvider('environment')]
    public function reads_the_value_from_the_environment(string $variable, string $value, string $key, mixed $expected): void {
      $this->setEnvironmentVariable($variable, $value);

      // Se vuelve a leer el archivo de configuración con la variable ya definida.
      // The config file is read again with the variable already defined.
      $config = require __DIR__ . '/../../config/json-api.php';

      $this->assertSame($expected, Arr::get($config, $key));
    }
  }
