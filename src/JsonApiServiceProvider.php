<?php

  namespace MundoProgra\JsonApi;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Contracts\Foundation\CachesConfiguration;
  use Illuminate\Http\Request;
  use Illuminate\Support\ServiceProvider;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Errors\ExceptionRenderer;
  use MundoProgra\JsonApi\Errors\ValidationSource;
  use MundoProgra\JsonApi\Support\ConfigValidator;
  use MundoProgra\JsonApi\Support\Settings;
  use Throwable;

  class JsonApiServiceProvider extends ServiceProvider {
    public function register(): void {
      $this->mergeDefaultConfig(__DIR__ . '/../config/json-api.php', 'json-api');

      // Una sola instancia para todo el paquete; lee la configuración en cada llamada, así que no se queda con valores viejos.
      // A single instance for the whole package; it reads the config on every call, so it never holds stale values.
      $this->app->singleton(Settings::class, fn($app) => new Settings($app->make('config')));

      // Los textos de los errores se buscan en el idioma que tenga Laravel en cada llamada.
      // The error texts are looked up in the language Laravel has at each call.
      $this->app->singleton(ErrorMessages::class, fn($app) => new ErrorMessages($app->make('translator')));
      $this->app->singleton(ErrorCodes::class, fn($app) => new ErrorCodes($app->make(Settings::class)));
      $this->app->singleton(ValidationSource::class, fn($app) => new ValidationSource($app->make(Settings::class)));
      $this->app->singleton(ExceptionRenderer::class, fn($app) => new ExceptionRenderer(
        $app->make(Settings::class),
        $app->make(ErrorCodes::class),
        $app->make(ErrorMessages::class),
        $app->make(ValidationSource::class),
      ));

      // Se engancha cuando la app ya terminó de arrancar, para quedar al final de la fila de funciones de renderizado:
      // así cualquier respuesta propia que la app haya registrado (en bootstrap/app.php, en Handler o en el boot() de sus providers) gana.
      // It hooks in once the app has finished booting, to end up last in the line of render callbacks:
      // that way any response of its own the app registered (in bootstrap/app.php, in Handler or in the boot() of its providers) wins.
      $this->app->booted(fn() => $this->hookIntoTheExceptionHandler());
    }

    public function boot(): void {
      // Se valida en boot() y no en register(): para entonces otros providers ya pudieron modificar la configuración.
      // It is validated in boot() rather than register(): by then other providers may have modified the config.
      (new ConfigValidator())->validate((array) $this->app->make('config')->get('json-api', []));

      $this->publishes([
        __DIR__ . '/../config/json-api.php' => config_path('json-api.php'),
      ], 'json-api-config');

      // Se publican en lang/vendor/json-api: ahí Laravel los lee por encima de los del paquete, y solo reemplaza lo que se edite.
      // They are published to lang/vendor/json-api: Laravel reads them there on top of the package ones, replacing only what is edited.
      $this->loadTranslationsFrom(__DIR__ . '/../lang', 'json-api');
      $this->publishes([
        __DIR__ . '/../lang' => lang_path('vendor/json-api'),
      ], 'json-api-lang');
    }

    /**
     * Se engancha al manejo de excepciones de Laravel con una función de renderizado, que funciona igual de Laravel 10 a 13.
     * Si el manejador no tiene `renderable` (uno hecho a mano), no se hace nada. Si la función devuelve null, Laravel sigue como siempre.
     * It hooks into Laravel's exception handling with a render callback, which works the same from Laravel 10 to 13.
     * If the handler has no `renderable` (a hand-made one), nothing is done. If the callback returns null, Laravel carries on as usual.
     */
    private function hookIntoTheExceptionHandler(): void {
      $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
        if (!method_exists($handler, 'renderable')) {
          return;
        }

        $handler->renderable(fn(Throwable $e, Request $request) => $this->app->make(ExceptionRenderer::class)->render($e, $request));
      });
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
