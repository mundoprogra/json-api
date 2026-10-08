<?php

  namespace MundoProgra\JsonApi;

  use Illuminate\Contracts\Foundation\CachesConfiguration;
  use Illuminate\Support\ServiceProvider;
  use MundoProgra\JsonApi\Support\ConfigValidator;
  use MundoProgra\JsonApi\Support\Settings;

  class JsonApiServiceProvider extends ServiceProvider {
    public function register(): void {
      $this->mergeDefaultConfig(__DIR__ . '/../config/json-api.php', 'json-api');

      // Una sola instancia para todo el paquete; lee la configuración en cada llamada, así que no se queda con valores viejos.
      // A single instance for the whole package; it reads the config on every call, so it never holds stale values.
      $this->app->singleton(Settings::class, fn($app) => new Settings($app->make('config')));
    }

    public function boot(): void {
      // Se valida en boot() y no en register(): para entonces otros providers ya pudieron modificar la configuración.
      // It is validated in boot() rather than register(): by then other providers may have modified the config.
      (new ConfigValidator())->validate((array) $this->app->make('config')->get('json-api', []));

      $this->publishes([
        __DIR__ . '/../config/json-api.php' => config_path('json-api.php'),
      ], 'json-api-config');
    }

    /**
     * Carga la configuración del paquete y la completa con lo que la app ya haya definido.
     * Se usa en lugar de mergeConfigFrom() porque este solo combina el primer nivel: si la app define `pagination`, se perderían sus demás claves.
     * Loads the package config and completes it with whatever the app has already defined.
     * It is used instead of mergeConfigFrom() because that only merges the first level: if the app defines `pagination`, its other keys would be lost.
     */
    private function mergeDefaultConfig(string $path, string $key): void {
      if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
        return;
      }

      $config = $this->app->make('config');

      $config->set($key, $this->mergeRecursively(require $path, (array) $config->get($key, [])));
    }

    /**
     * Los grupos con claves (como `pagination`) se combinan clave por clave; todo lo demás, incluidas las listas, lo reemplaza el valor de la app.
     * Groups with keys (like `pagination`) are merged key by key; everything else, lists included, is replaced by the app value.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursively(array $defaults, array $overrides): array {
      foreach ($overrides as $key => $value) {
        $default = $defaults[$key] ?? null;

        $defaults[$key] = is_array($value) && is_array($default) && !array_is_list($default)
          ? $this->mergeRecursively($default, $value)
          : $value;
      }

      return $defaults;
    }
  }
