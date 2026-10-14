<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  class ConfigDefaultsTest extends TestCase {
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function defaults(): array {
      return [
        'mode' => ['mode', 'prefix'],
        'path_prefix' => ['path_prefix', 'api'],
        'except' => ['except', ['up']],
        'strict_headers' => ['strict_headers', true],
        'member_case' => ['member_case', 'camel'],
        'jsonapi_object' => ['jsonapi_object', true],
        'allow_client_ids' => ['allow_client_ids', false],
        'allow_put' => ['allow_put', false],
        'reject_unknown_query_params' => ['reject_unknown_query_params', false],
        'pagination.size' => ['pagination.size', 15],
        'pagination.max_size' => ['pagination.max_size', 100],
        'pagination.on_exceed' => ['pagination.on_exceed', 'clamp'],
        'query.max_include_depth' => ['query.max_include_depth', 3],
        'links.route_name_prefix' => ['links.route_name_prefix', ''],
        'errors.expose_details' => ['errors.expose_details', null],
        'errors.include_code' => ['errors.include_code', true],
        'errors.code_prefix' => ['errors.code_prefix', 'mundoprogra.json-api'],
        'errors.codes' => ['errors.codes', []],
        'errors.include_id' => ['errors.include_id', false],
      ];
    }

    #[Test]
    #[DataProvider('defaults')]
    public function exposes_the_default_value(string $key, mixed $expected): void {
      // has() distingue una clave inexistente de una clave cuyo valor por defecto es null.
      // has() tells a missing key apart from a key whose default value is null.
      $this->assertTrue(config()->has("json-api.$key"), "Falta la clave json-api.$key");
      $this->assertSame($expected, config("json-api.$key"));
    }

    #[Test]
    public function defines_no_top_level_keys_besides_the_documented_ones(): void {
      $this->assertEqualsCanonicalizing(
        [
          'mode',
          'path_prefix',
          'except',
          'strict_headers',
          'member_case',
          'jsonapi_object',
          'allow_client_ids',
          'allow_put',
          'reject_unknown_query_params',
          'pagination',
          'query',
          'links',
          'errors',
        ],
        array_keys(config('json-api'))
      );
    }
  }
