<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Illuminate\Testing\TestResponse;

  /**
   * Hace una petición JSON:API (con el `Accept` y el `Content-Type` del media type) y valida su respuesta contra el JSON Schema oficial antes de
   * devolverla: así "cada respuesta se valida" no depende de acordarse de hacerlo. Una respuesta sin cuerpo (un `204`) no se valida. Es solo para las
   * pruebas del paquete: un ayudante para quien consume el paquete sería parte de lo posterior al MVP.
   *
   * Makes a JSON:API request (with the media type `Accept` and `Content-Type`) and validates its response against the official JSON Schema before
   * returning it: that way "every response is validated" does not depend on remembering to do it. A response without a body (a `204`) is not validated.
   * It is only for the package tests: a helper for whoever consumes the package would be part of the post-MVP work.
   */
  trait MakesJsonApiRequests {
    use AssertsJsonApi;

    /**
     * @param  array<string, mixed>|string|null  $body  el documento: un arreglo se codifica como JSON / the document: an array is encoded as JSON
     * @param  array<string, string>  $server  cabeceras (en formato `HTTP_ACCEPT`) que reemplazan a las de siempre / headers (in `HTTP_ACCEPT` form) that replace the usual ones
     */
    protected function jsonApi(string $method, string $uri, array|string|null $body = null, array $server = []): TestResponse {
      $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;

      $response = $this->call($method, $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/vnd.api+json', 'HTTP_ACCEPT' => 'application/vnd.api+json'], $content);

      if ($response->getStatusCode() !== 204 && (string) $response->getContent() !== '') {
        $this->assertJsonApiDocument((string) $response->getContent(), "$method $uri answered " . $response->getStatusCode());
      }

      return $response;
    }
  }
