<?php

  namespace MundoProgra\JsonApi\Support;

  use InvalidArgumentException;

  /**
   * Aplica las reglas de negociación de contenido de JSON:API 1.1 a las cabeceras Content-Type y Accept.
   * Devuelve null si la cabecera es aceptable, o un NegotiationFailure con el estado 415 o 406.
   * Applies the JSON:API 1.1 content negotiation rules to the Content-Type and Accept headers.
   * It returns null if the header is acceptable, or a NegotiationFailure with status 415 or 406.
   */
  final class ContentNegotiator {
    private const RESPONSE_MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Parámetros que JSON:API permite en el tipo de medio.
     * Parameters JSON:API allows on the media type.
     */
    private const ALLOWED_PARAMETERS = ['ext', 'profile'];

    /**
     * @param  bool  $strict  true: solo JSON:API. false: también se acepta application/json. / true: JSON:API only. false: application/json is also accepted.
     */
    public function __construct(private readonly bool $strict) {
    }

    /**
     * La respuesta siempre usa el tipo sin parámetros: no se aplica ninguna extensión ni perfil.
     * The response always uses the type without parameters: no extension or profile is applied.
     */
    public function responseContentType(): string {
      return self::RESPONSE_MEDIA_TYPE;
    }

    /**
     * Valida el Content-Type de una petición con cuerpo. Falla con 415.
     * Validates the Content-Type of a request with a body. It fails with 415.
     */
    public function validateContentType(?string $header): ?NegotiationFailure {
      $header = trim((string) $header, " \t");

      if ($header === '') {
        return $this->strict ? $this->contentTypeFailure('missing') : null;
      }

      try {
        $mediaType = MediaType::parse($header);
      } catch (InvalidArgumentException) {
        return $this->contentTypeFailure('malformed');
      }

      if ($mediaType->isJsonApi()) {
        $reason = $this->jsonApiProblem($mediaType);

        return $reason === null ? null : $this->contentTypeFailure($reason);
      }

      if (!$this->strict && $mediaType->essence() === 'application/json') {
        return null;
      }

      return $this->contentTypeFailure('not_json_api');
    }

    /**
     * Valida el Accept de una petición. Falla con 406.
     * Validates the Accept of a request. It fails with 406.
     */
    public function validateAccept(?string $header): ?NegotiationFailure {
      $header = trim((string) $header, " \t");

      if ($header === '') {
        return null;
      }

      $instances = $this->acceptInstances($header);

      if ($instances === []) {
        return $this->acceptFailure('malformed');
      }

      $refused = false;
      $problems = [];

      foreach ($instances as [$mediaType, $weight]) {
        $problem = $this->acceptProblem($mediaType);

        if ($problem === null) {
          if ($weight > 0) {
            return null;
          }

          $refused = true;
        } elseif ($problem !== 'not_json_api') {
          $problems[] = $problem;
        }
      }

      return $this->acceptFailure(match (true) {
        $refused => 'refused',
        in_array('unsupported_parameter', $problems, true) => 'unsupported_parameter',
        in_array('unsupported_extension', $problems, true) => 'unsupported_extension',
        default => 'not_json_api',
      });
    }

    /**
     * El problema de un tipo JSON:API según la spec: un parámetro distinto de ext o profile, o una extensión (ninguna está soportada).
     * Los perfiles se ignoran. `$ignored` son parámetros que no cuentan: en un Accept, el peso `q` no es un parámetro del tipo.
     * The problem of a JSON:API type per the spec: a parameter other than ext or profile, or an extension (none is supported).
     * Profiles are ignored. `$ignored` are parameters that do not count: in an Accept, the `q` weight is not a parameter of the type.
     *
     * @param  list<string>  $ignored
     */
    private function jsonApiProblem(MediaType $mediaType, array $ignored = []): ?string {
      $foreign = array_diff(array_keys($mediaType->parameters()), self::ALLOWED_PARAMETERS, $ignored);

      if ($foreign !== []) {
        return 'unsupported_parameter';
      }

      return $mediaType->extensions() !== [] ? 'unsupported_extension' : null;
    }

    /**
     * Null si el tipo del Accept sirve para responder; si no, el motivo por el que no sirve.
     * Null if the Accept type can be used to respond; otherwise the reason it cannot.
     */
    private function acceptProblem(MediaType $mediaType): ?string {
      if ($mediaType->isJsonApi()) {
        return $this->jsonApiProblem($mediaType, ['q']);
      }

      $essence = $mediaType->essence();

      if ($essence === '*/*' || $essence === 'application/*') {
        return null;
      }

      if (!$this->strict && $essence === 'application/json') {
        return null;
      }

      return 'not_json_api';
    }

    /**
     * Lee cada elemento del Accept con su peso. Los elementos ilegibles se omiten.
     * Reads each Accept item with its weight. Unreadable items are skipped.
     *
     * @return list<array{MediaType, float}>
     */
    private function acceptInstances(string $header): array {
      $instances = [];

      foreach ($this->splitList($header) as $item) {
        try {
          $mediaType = MediaType::parse($item);
        } catch (InvalidArgumentException) {
          continue;
        }

        $weight = $mediaType->parameter('q');

        // El peso válido (RFC 7231) va de 0 a 1 con hasta tres decimales.
        // A valid weight (RFC 7231) goes from 0 to 1 with up to three decimals.
        if ($weight !== null && preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $weight) !== 1) {
          continue;
        }

        $instances[] = [$mediaType, $weight === null ? 1.0 : (float) $weight];
      }

      return $instances;
    }

    /**
     * Separa una lista por comas, sin cortar las que están dentro de comillas.
     * Splits a list on commas, without cutting the ones inside quotes.
     *
     * @return list<string>
     */
    private function splitList(string $header): array {
      $items = [];
      $current = '';
      $inQuotes = false;
      $length = strlen($header);

      for ($i = 0; $i < $length; $i++) {
        $char = $header[$i];

        if ($inQuotes) {
          $current .= $char;

          if ($char === '\\' && $i + 1 < $length) {
            $current .= $header[++$i];
          } elseif ($char === '"') {
            $inQuotes = false;
          }

          continue;
        }

        if ($char === ',') {
          $items[] = $current;
          $current = '';

          continue;
        }

        $inQuotes = $char === '"';
        $current .= $char;
      }

      $items[] = $current;

      return array_values(array_filter(array_map('trim', $items), fn(string $item) => $item !== ''));
    }

    private function contentTypeFailure(string $reason): NegotiationFailure {
      return new NegotiationFailure(415, 'Content-Type', $reason);
    }

    private function acceptFailure(string $reason): NegotiationFailure {
      return new NegotiationFailure(406, 'Accept', $reason);
    }
  }
