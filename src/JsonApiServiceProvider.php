<?php

  namespace MundoProgra\JsonApi;

  use Illuminate\Support\ServiceProvider;

  class JsonApiServiceProvider extends ServiceProvider {
    public function register(): void {
      $this->mergeConfigFrom(__DIR__ . '/../config/json-api.php', 'json-api');
    }

    public function boot(): void {
      $this->publishes([
        __DIR__ . '/../config/json-api.php' => config_path('json-api.php'),
      ], 'json-api-config');
    }
  }
