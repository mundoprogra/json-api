<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Support\Settings;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  class SettingsBindingTest extends TestCase {
    #[Test]
    public function the_container_resolves_the_same_settings_instance_every_time(): void {
      $this->assertInstanceOf(Settings::class, $this->app->make(Settings::class));
      $this->assertSame($this->app->make(Settings::class), $this->app->make(Settings::class));
    }

    #[Test]
    public function the_shared_instance_sees_config_changes_made_at_runtime(): void {
      $settings = $this->app->make(Settings::class);

      config()->set('json-api.pagination.size', 40);

      $this->assertSame(40, $settings->paginationSize());
      $this->assertSame(40, $this->app->make(Settings::class)->paginationSize());
    }

    #[Test]
    public function reads_the_values_merged_by_the_provider(): void {
      // El valor por defecto que no tocó la app llega a través del provider.
      // A default value the app did not touch arrives through the provider.
      config()->set('json-api', ['pagination' => ['size' => 40]]);
      $this->app->register(JsonApiServiceProvider::class, true);

      $settings = $this->app->make(Settings::class);

      $this->assertSame(40, $settings->paginationSize());
      $this->assertSame(100, $settings->paginationMaxSize());
    }
  }
