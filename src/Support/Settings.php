<?php

  namespace MundoProgra\JsonApi\Support;

  use Illuminate\Contracts\Config\Repository;

  /**
   * Acceso tipado a la configuración del paquete (config/json-api.php).
   * Lee el valor en cada llamada, por lo que refleja los cambios hechos en ejecución.
   * Typed access to the package configuration (config/json-api.php).
   * It reads the value on every call, so it reflects changes made at runtime.
   */
  class Settings {
    public function __construct(private readonly Repository $config) {
    }

    public function mode(): string {
      return (string) $this->get('mode');
    }

    /**
     * Prefijos de ruta limpios (sin barras al inicio ni al final), aunque la configuración sea un solo texto.
     * Clean route prefixes (no leading or trailing slashes), even if the config is a single string.
     *
     * @return list<string>
     */
    public function pathPrefixes(): array {
      return array_values(array_map(
        fn($prefix) => trim((string) $prefix, '/'),
        (array) $this->get('path_prefix')
      ));
    }

    /**
     * @return list<string>
     */
    public function except(): array {
      return array_values(array_map('strval', (array) $this->get('except')));
    }

    public function strictHeaders(): bool {
      return (bool) $this->get('strict_headers');
    }

    public function memberCase(): string {
      return (string) $this->get('member_case');
    }

    public function jsonApiObject(): bool {
      return (bool) $this->get('jsonapi_object');
    }

    public function allowClientIds(): bool {
      return (bool) $this->get('allow_client_ids');
    }

    public function allowPut(): bool {
      return (bool) $this->get('allow_put');
    }

    public function rejectUnknownQueryParams(): bool {
      return (bool) $this->get('reject_unknown_query_params');
    }

    public function paginationSize(): int {
      return (int) $this->get('pagination.size');
    }

    public function paginationMaxSize(): int {
      return (int) $this->get('pagination.max_size');
    }

    public function paginationOnExceed(): string {
      return (string) $this->get('pagination.on_exceed');
    }

    public function maxIncludeDepth(): int {
      return (int) $this->get('query.max_include_depth');
    }

    public function routeNamePrefix(): string {
      return (string) $this->get('links.route_name_prefix');
    }

    public function includeErrorCode(): bool {
      return (bool) $this->get('errors.include_code');
    }

    /**
     * El prefijo de los códigos por defecto; vacío significa sin prefijo.
     * The prefix of the default codes; empty means no prefix.
     */
    public function errorCodePrefix(): string {
      return (string) $this->get('errors.code_prefix');
    }

    /**
     * Los códigos que reemplazan a los del paquete: código base (sin prefijo) => código propio.
     * The codes that replace the package ones: base code (without prefix) => own code.
     *
     * @return array<string, string>
     */
    public function errorCodes(): array {
      return array_map('strval', (array) $this->get('errors.codes'));
    }

    public function includeErrorId(): bool {
      return (bool) $this->get('errors.include_id');
    }

    /**
     * Si `errors.expose_details` es null, se sigue el valor de `app.debug`; si no, manda lo configurado.
     * If `errors.expose_details` is null, the value of `app.debug` is followed; otherwise the configured value wins.
     */
    public function exposeErrorDetails(): bool {
      $configured = $this->get('errors.expose_details');

      return $configured === null
        ? (bool) $this->config->get('app.debug')
        : (bool) $configured;
    }

    private function get(string $key): mixed {
      return $this->config->get("json-api.$key");
    }
  }
