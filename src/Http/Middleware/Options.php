<?php

  namespace MundoProgra\JsonApi\Http\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use InvalidArgumentException;
  use Symfony\Component\HttpFoundation\Response;

  /**
   * Cambia la paginación de una ruta o de un grupo de rutas: `->middleware('json-api.options:per_page=25,max_per_page=50')`.
   * Es opt-in: no se registra solo. Las opciones quedan en la petición y `PageRequest` las lee después del argumento del controlador y
   * antes de `pagination.*`. Dos aplicaciones se combinan opción por opción y la más interna (la de la ruta) manda.
   * Escribir mal una opción es un error del programador, no del cliente, y lanza `InvalidArgumentException`.
   * Changes the pagination of a route or a group of routes: `->middleware('json-api.options:per_page=25,max_per_page=50')`.
   * It is opt-in: it does not register itself. The options are left on the request and `PageRequest` reads them after the controller
   * argument and before `pagination.*`. Two applications combine option by option and the innermost (the route one) wins.
   * Writing an option wrong is a developer error, not the client's, and throws `InvalidArgumentException`.
   */
  final class Options {
    public const ATTRIBUTE = 'json-api.options';

    /**
     * Las opciones que existen: `per_page` es el tamaño por defecto y `max_per_page` el máximo.
     * The options that exist: `per_page` is the default size and `max_per_page` the maximum.
     */
    private const KNOWN = ['per_page', 'max_per_page'];

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws InvalidArgumentException si no hay opciones, si alguna es desconocida, repetida o no es un entero positivo / if there are no options, or one is unknown, repeated or not a positive integer
     */
    public function handle(Request $request, Closure $next, string ...$options): Response {
      $request->attributes->set(self::ATTRIBUTE, [...$this->current($request), ...$this->parse(array_values($options))]);

      return $next($request);
    }

    /**
     * Las opciones que ya puso un grupo u otra aplicación del middleware sobre esta petición.
     * The options a group or another application of the middleware already set on this request.
     *
     * @return array<string, int>
     */
    private function current(Request $request): array {
      $current = $request->attributes->get(self::ATTRIBUTE, []);

      return is_array($current) ? $current : [];
    }

    /**
     * @param  array<int, string>  $options
     * @return array<string, int>
     */
    private function parse(array $options): array {
      if ($options === []) {
        throw new InvalidArgumentException('json-api.options needs at least one option, for example json-api.options:per_page=25,max_per_page=50.');
      }

      $parsed = [];

      foreach ($options as $option) {
        [$name, $value] = array_pad(explode('=', $option, 2), 2, null);

        if (!in_array($name, self::KNOWN, true)) {
          throw new InvalidArgumentException(sprintf('Unknown option "%s" in json-api.options: use %s, written as name=value.', $option, implode(' or ', self::KNOWN)));
        }

        if (array_key_exists($name, $parsed)) {
          throw new InvalidArgumentException(sprintf('The option "%s" of json-api.options is repeated.', $name));
        }

        // Solo dígitos y mayor que cero: sin signo, espacios ni decimales.
        // Digits only and greater than zero: no sign, spaces or decimals.
        if ($value === null || preg_match('/\A[0-9]+\z/', $value) !== 1 || (int) $value < 1) {
          throw new InvalidArgumentException(sprintf('The option "%s" of json-api.options must be a positive integer, "%s" given.', $name, (string) $value));
        }

        $parsed[$name] = (int) $value;
      }

      return $parsed;
    }
  }
