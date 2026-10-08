<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  class ConfigOverrideTest extends TestCase {
    #[Test]
    public function keeps_the_nested_defaults_when_the_app_overrides_only_some_nested_keys(): void {
      // La app publicó el archivo y solo cambió `pagination.size`: el resto de `pagination` debe conservar su valor por defecto.
      // The app published the file and only changed `pagination.size`: the rest of `pagination` must keep its default value.
      $this->mergeAppConfig(['pagination' => ['size' => 40]]);

      $this->assertSame(40, config('json-api.pagination.size'));
      $this->assertSame(100, config('json-api.pagination.max_size'));
      $this->assertSame('clamp', config('json-api.pagination.on_exceed'));
      $this->assertSame('prefix', config('json-api.mode'));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function lists(): array {
      return [
        'an empty list' => [[]],
        'a shorter list' => [['health']],
        'a longer list' => [['health', 'status', 'metrics']],
      ];
    }

    /**
     * @param  list<string>  $except
     */
    #[Test]
    #[DataProvider('lists')]
    public function a_list_override_replaces_the_default_list_instead_of_merging_with_it(array $except): void {
      // Una lista no se mezcla elemento por elemento: la que define la app reemplaza a la de por defecto (['up']).
      // A list is not merged element by element: the one defined by the app replaces the default (['up']).
      $this->mergeAppConfig(['except' => $except]);

      $this->assertSame($except, config('json-api.except'));
    }

    /**
     * Simula una app cuyo config/json-api.php ya estaba cargado cuando el paquete fusiona sus valores por defecto.
     * Simulates an app whose config/json-api.php was already loaded when the package merges its defaults.
     *
     * @param  array<string, mixed>  $appConfig
     */
    private function mergeAppConfig(array $appConfig): void {
      config()->set('json-api', $appConfig);

      $this->app->register(JsonApiServiceProvider::class, true);
    }
  }
