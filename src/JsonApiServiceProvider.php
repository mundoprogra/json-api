<?php

  namespace MundoProgra\JsonApi;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Contracts\Foundation\CachesConfiguration;
  use Illuminate\Contracts\Http\Kernel as HttpKernel;
  use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
  use Illuminate\Http\Request;
  use Illuminate\Support\ServiceProvider;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorIds;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Errors\ExceptionRenderer;
  use MundoProgra\JsonApi\Errors\ValidationSource;
  use MundoProgra\JsonApi\Http\Middleware\ApplyJsonApi;
  use MundoProgra\JsonApi\Http\Middleware\NegotiateContent;
  use MundoProgra\JsonApi\Http\Middleware\Options;
  use MundoProgra\JsonApi\Http\Middleware\ValidateQuery;
  use MundoProgra\JsonApi\Http\RequestScope;
  use MundoProgra\JsonApi\Query\BuilderMixin;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Support\ConfigValidator;
  use MundoProgra\JsonApi\Support\Settings;
  use Throwable;

  /**
   * Conecta el paquete con la aplicación: combina y valida la configuración, registra los servicios, se engancha al manejo de excepciones
   * y agrega a la pila global los middleware que se aplican solos dentro del alcance. No hace falta tocar `Kernel`, `Handler` ni `bootstrap/app.php`.
   *
   * Connects the package to the application: it merges and validates the configuration, registers the services, hooks into the exception
   * handling and adds to the global stack the middleware that apply on their own inside the scope. There is no need to touch `Kernel`, `Handler` or `bootstrap/app.php`.
   */
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
      $this->app->singleton(RequestScope::class, fn($app) => new RequestScope($app->make(Settings::class)));
      $this->app->singleton(ErrorIds::class, fn($app) => new ErrorIds($app->make(Settings::class), $app, $app->make(RequestScope::class)));
      $this->app->singleton(ExceptionRenderer::class, fn($app) => new ExceptionRenderer(
        $app->make(Settings::class),
        $app->make(ErrorCodes::class),
        $app->make(ErrorMessages::class),
        $app->make(ValidationSource::class),
        $app->make(ErrorIds::class),
      ));

      // Se engancha cuando la app ya terminó de arrancar, para quedar al final de la fila de funciones de renderizado:
      // así cualquier respuesta propia que la app haya registrado (en bootstrap/app.php, en Handler o en el boot() de sus providers) gana.
      // It hooks in once the app has finished booting, to end up last in the line of render callbacks:
      // that way any response of its own the app registered (in bootstrap/app.php, in Handler or in the boot() of its providers) wins.
      $this->app->booted(fn() => $this->hookIntoTheExceptionHandler());

      // Los middleware se agregan al inicio de la fila global cuando se crea el kernel HTTP: corren antes de que se busque la ruta
      // (así cubren las rutas que no existen) y antes que `auth`. En consola el kernel HTTP no se crea, así que no hay nada que tocar.
      // Cada `prependMiddleware` queda por delante del anterior, así que se agregan en orden inverso: primero ApplyJsonApi (guarda el Accept
      // original) y justo después NegotiateContent, que lo lee.
      // The middleware are added at the start of the global stack when the HTTP kernel is created: they run before the route is looked up
      // (so they cover routes that do not exist) and before `auth`. In the console the HTTP kernel is not created, so there is nothing to touch.
      // Each `prependMiddleware` ends up in front of the previous one, so they are added in reverse order: ApplyJsonApi first (it keeps the
      // original Accept) and right after it NegotiateContent, which reads it.
      $this->callAfterResolving(HttpKernel::class, function ($kernel) {
        if (method_exists($kernel, 'prependMiddleware')) {
          $kernel->prependMiddleware(ValidateQuery::class . ':scope');
          $kernel->prependMiddleware(NegotiateContent::class . ':scope');
          $kernel->prependMiddleware(ApplyJsonApi::class . ':scope');
        }
      });
    }

    public function boot(): void {
      // Se valida en boot() y no en register(): para entonces otros providers ya pudieron modificar la configuración.
      // It is validated in boot() rather than register(): by then other providers may have modified the config.
      (new ConfigValidator())->validate((array) $this->app->make('config')->get('json-api', []));

      // Para marcar a mano una ruta fuera del alcance como de JSON:API y arreglar su Accept: `->middleware('json-api.apply')`.
      // To mark a route outside the scope by hand as JSON:API and fix its Accept: `->middleware('json-api.apply')`.
      $this->app->make('router')->aliasMiddleware('json-api.apply', ApplyJsonApi::class);

      // Para negociar el contenido (Content-Type y Accept) en una ruta: `->middleware('json-api.negotiate')`.
      // To negotiate the content (Content-Type and Accept) on a route: `->middleware('json-api.negotiate')`.
      $this->app->make('router')->aliasMiddleware('json-api.negotiate', NegotiateContent::class);

      // Para validar la consulta (include, fields, sort, filter, page) en una ruta fuera del alcance: `->middleware('json-api.query')`.
      // To validate the query (include, fields, sort, filter, page) on a route outside the scope: `->middleware('json-api.query')`.
      $this->app->make('router')->aliasMiddleware('json-api.query', ValidateQuery::class);

      // Para cambiar la paginación de una ruta o un grupo: `->middleware('json-api.options:per_page=25,max_per_page=50')`.
      // To change the pagination of a route or a group: `->middleware('json-api.options:per_page=25,max_per_page=50')`.
      $this->app->make('router')->aliasMiddleware('json-api.options', Options::class);

      // El grupo `json-api` junta los tres anteriores, en este orden, para una ruta suelta fuera del alcance: `->middleware('json-api')`.
      // `json-api.options` no entra porque exige valores; se agrega aparte cuando hace falta.
      // The `json-api` group puts together the first three, in this order, for a single route outside the scope: `->middleware('json-api')`.
      // `json-api.options` is not in because it needs values; it is added separately when needed.
      $this->app->make('router')->middlewareGroup('json-api', ['json-api.apply', 'json-api.negotiate', 'json-api.query']);

      // Las macros del constructor de Eloquent (`jsonApiPaginate()`, y las que siguen): aplican la consulta de JSON:API a una consulta de la base de datos.
      // The Eloquent builder macros (`jsonApiPaginate()`, and the ones that follow): they apply the JSON:API query to a database query.
      EloquentBuilder::mixin(new BuilderMixin());

      // `$request->jsonApiQuery()`: la consulta ya analizada, una sola vez por petición.
      // `$request->jsonApiQuery()`: the already parsed query, once per request.
      Request::macro('jsonApiQuery', function (): QueryParameters {
        /** @var Request $this */
        return QueryParameters::of($this);
      });

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
     * También anota el id del error justo antes de que Laravel lo escriba en el log (`reportable`); esa función no devuelve nada, así que el log sigue igual.
     *
     * It hooks into Laravel's exception handling with a render callback, which works the same from Laravel 10 to 13.
     * If the handler has no `renderable` (a hand-made one), nothing is done. If the callback returns null, Laravel carries on as usual.
     * It also notes the error id right before Laravel writes it to the log (`reportable`); that callback returns nothing, so logging carries on as usual.
     */
    private function hookIntoTheExceptionHandler(): void {
      $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
        if (method_exists($handler, 'reportable')) {
          $handler->reportable(function (Throwable $e) {
            $this->app->make(ErrorIds::class)->reported($e);
          });
        }

        if (!method_exists($handler, 'renderable')) {
          return;
        }

        // Fuera del alcance devuelve null: Laravel responde como siempre.
        // Outside the scope it returns null: Laravel answers as usual.
        $handler->renderable(fn(Throwable $e, Request $request) => $this->app->make(RequestScope::class)->shouldAnswer($e, $request)
          ? $this->app->make(ExceptionRenderer::class)->render($e, $request)
          : null);
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
