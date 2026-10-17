<?php

  namespace MundoProgra\JsonApi\Http;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\MediaType;
  use MundoProgra\JsonApi\Support\Settings;
  use Throwable;

  /**
   * La única regla que decide si el paquete actúa sobre una petición. De mayor a menor:
   * 1) la ruta marcada a mano con el middleware, 2) `except`, 3) una petición que ya pide el media type de JSON:API, 4) `mode`.
   * Lee la configuración en cada llamada y compara contra la ruta de la URL (no la ruta registrada), así también decide sobre rutas inexistentes.
   * The one rule that decides whether the package acts on a request. From highest to lowest:
   * 1) the route marked by hand with the middleware, 2) `except`, 3) a request that already asks for the JSON:API media type, 4) `mode`.
   * It reads the config on every call and compares against the URL path (not the registered route), so it also decides on routes that do not exist.
   */
  final class RequestScope {
    private const MARK = 'json-api.marked';

    public function __construct(private readonly Settings $settings) {
    }

    /**
     * Si el paquete debe responder a esta excepción. Un JsonApiException lanzado a mano siempre se responde en JSON:API: quien lo lanza ya eligió ese formato.
     * Whether the package should answer this exception. A JsonApiException thrown by hand is always answered as JSON:API: whoever throws it already chose that format.
     */
    public function shouldAnswer(Throwable $e, Request $request): bool {
      return $e instanceof JsonApiException || $this->appliesTo($request);
    }

    public function appliesTo(Request $request): bool {
      if ($request->attributes->get(self::MARK) === true) {
        return true;
      }

      if ($request->is(...$this->normalized($this->settings->except()))) {
        return false;
      }

      if ($this->asksForTheMediaType($request)) {
        return true;
      }

      return $this->settings->mode() === 'global' || $this->isUnderThePrefix($request);
    }

    /**
     * Marca la petición como de JSON:API sin importar el modo ni `except`. Lo usa el middleware que se pone a mano en una ruta.
     * Marks the request as JSON:API regardless of the mode and `except`. It is used by the middleware put by hand on a route.
     */
    public function mark(Request $request): void {
      $request->attributes->set(self::MARK, true);
    }

    /**
     * Por segmentos completos: `api` cubre `/api` y `/api/users`, pero no `/apix`.
     * By whole segments: `api` covers `/api` and `/api/users`, but not `/apix`.
     */
    private function isUnderThePrefix(Request $request): bool {
      foreach ($this->settings->pathPrefixes() as $prefix) {
        if ($request->is($prefix, $prefix . '/*')) {
          return true;
        }
      }

      return false;
    }

    /**
     * Cuenta aunque traiga parámetros: así la negociación puede responder 406 en JSON:API. Symfony deja los parámetros (salvo `q`) pegados al tipo.
     * It counts even with parameters: that way the negotiation can answer 406 as JSON:API. Symfony leaves the parameters (except `q`) attached to the type.
     */
    private function asksForTheMediaType(Request $request): bool {
      foreach ($request->getAcceptableContentTypes() as $type) {
        if (strtolower(trim(explode(';', $type)[0])) === MediaType::JSON_API) {
          return true;
        }
      }

      return false;
    }

    /**
     * Sin barras al inicio ni al final, como los patrones de `Request::is()`.
     * Without leading or trailing slashes, like the patterns of `Request::is()`.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function normalized(array $patterns): array {
      return array_map(fn(string $pattern) => trim($pattern, '/'), $patterns);
    }
  }
