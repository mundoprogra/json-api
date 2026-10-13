<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use MundoProgra\JsonApi\Support\ContentNegotiator;
  use MundoProgra\JsonApi\Support\NegotiationFailure;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ContentNegotiatorTest extends TestCase {
    // ---------------------------------------------------------------------------------------------
    // Content-Type de la petición (415) / Request Content-Type (415)
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function contentTypesAcceptedInBothModes(): array {
      return [
        'the json:api media type' => ['application/vnd.api+json'],
        'in uppercase' => ['APPLICATION/VND.API+JSON'],
        'with a profile, which is ignored' => ['application/vnd.api+json; profile="https://example.com/p"'],
        'with several profiles' => ['application/vnd.api+json; profile="https://a.test/1 https://b.test/2"'],
        'with an empty ext, which names no extension' => ['application/vnd.api+json; ext=""'],
      ];
    }

    #[Test]
    #[DataProvider('contentTypesAcceptedInBothModes')]
    public function accepts_these_content_types_in_both_modes(string $header): void {
      foreach ([true, false] as $strict) {
        $this->assertNull(
          $this->negotiator($strict)->validateContentType($header),
          "strict=" . var_export($strict, true) . ", Content-Type: $header"
        );
      }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function contentTypesRejectedInBothModes(): array {
      // [cabecera, motivo] / [header, reason]
      return [
        'a parameter that is not ext or profile' => ['application/vnd.api+json; charset=utf-8', 'unsupported_parameter'],
        'an extension, because none is supported' => ['application/vnd.api+json; ext="https://example.com/a"', 'unsupported_extension'],
        'an extension next to a profile' => ['application/vnd.api+json; ext="https://example.com/a"; profile="https://x.test/p"', 'unsupported_extension'],
        'a foreign parameter wins over an extension' => ['application/vnd.api+json; charset=utf-8; ext="https://a.test"', 'unsupported_parameter'],
        // En un Content-Type, `q` no es un peso (eso solo existe en Accept): es un parámetro ajeno más.
        // In a Content-Type, `q` is not a weight (that only exists in Accept): it is just another foreign parameter.
        'a weight is just a foreign parameter in a Content-Type' => ['application/vnd.api+json; q=0.5', 'unsupported_parameter'],
        'plain text' => ['text/plain', 'not_json_api'],
        'xml' => ['application/xml', 'not_json_api'],
        'text that is not a media type' => ['garbage', 'malformed'],
        'a parameter without value' => ['application/vnd.api+json; ext', 'malformed'],
        'a list of media types' => ['application/vnd.api+json, application/json', 'malformed'],
      ];
    }

    #[Test]
    #[DataProvider('contentTypesRejectedInBothModes')]
    public function rejects_these_content_types_in_both_modes_with_415(string $header, string $reason): void {
      foreach ([true, false] as $strict) {
        $this->assertFailure(
          $this->negotiator($strict)->validateContentType($header),
          415,
          'Content-Type',
          $reason,
          "strict=" . var_export($strict, true) . ", Content-Type: $header"
        );
      }
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function contentTypesRejectedOnlyInStrictMode(): array {
      return [
        'plain json' => ['application/json', 'not_json_api'],
        'plain json with a charset' => ['application/json; charset=utf-8', 'not_json_api'],
        'no header' => [null, 'missing'],
        'an empty header' => ['', 'missing'],
        'a header with only blanks' => ['   ', 'missing'],
      ];
    }

    #[Test]
    #[DataProvider('contentTypesRejectedOnlyInStrictMode')]
    public function strict_mode_rejects_these_content_types_with_415(?string $header, string $reason): void {
      $this->assertFailure($this->negotiator(true)->validateContentType($header), 415, 'Content-Type', $reason);
    }

    /**
     * Las mismas cabeceras que el modo estricto rechaza, sin el motivo.
     * The same headers that strict mode rejects, without the reason.
     *
     * @return array<string, array{?string}>
     */
    public static function contentTypesAcceptedOnlyInPermissiveMode(): array {
      return array_map(fn(array $case) => [$case[0]], self::contentTypesRejectedOnlyInStrictMode());
    }

    #[Test]
    #[DataProvider('contentTypesAcceptedOnlyInPermissiveMode')]
    public function permissive_mode_accepts_the_same_content_types(?string $header): void {
      $this->assertNull($this->negotiator(false)->validateContentType($header));
    }

    // ---------------------------------------------------------------------------------------------
    // Accept de la petición (406) / Request Accept (406)
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{?string}>
     */
    public static function acceptsAcceptedInBothModes(): array {
      return [
        'no header' => [null],
        'an empty header' => [''],
        'a header with only blanks' => ['   '],
        'the json:api media type' => ['application/vnd.api+json'],
        'with another type after it' => ['application/vnd.api+json, text/html;q=0.8'],
        'with another type before it' => ['text/html, application/vnd.api+json;q=0.5'],
        'with a weight, which is not a media type parameter' => ['application/vnd.api+json;q=0.9'],
        'with the weight written in uppercase' => ['application/vnd.api+json;Q=0.9'],
        'with a profile, which is ignored' => ['application/vnd.api+json;profile="https://example.com/p"'],
        'a profile with a comma inside quotes is one value' => ['application/vnd.api+json; profile="https://a.test/x,y"'],
        'one clean instance is enough next to a foreign parameter' => ['application/vnd.api+json;charset=utf-8, application/vnd.api+json'],
        'one clean instance is enough next to an extension' => ['application/vnd.api+json;ext="https://x.test", application/vnd.api+json'],
        'any type' => ['*/*'],
        'any application type' => ['application/*'],
        'any type with a weight' => ['*/*;q=0.8'],
        'any type as a last resort' => ['text/html, */*;q=0.1'],
        'an unreadable item is skipped when another one is valid' => ['application/vnd.api+json, garbage'],
      ];
    }

    #[Test]
    #[DataProvider('acceptsAcceptedInBothModes')]
    public function accepts_these_accept_headers_in_both_modes(?string $header): void {
      foreach ([true, false] as $strict) {
        $this->assertNull(
          $this->negotiator($strict)->validateAccept($header),
          "strict=" . var_export($strict, true) . ", Accept: " . var_export($header, true)
        );
      }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptsRejectedInBothModes(): array {
      // [cabecera, motivo] / [header, reason]
      return [
        'the only instance has a foreign parameter' => ['application/vnd.api+json;charset=utf-8', 'unsupported_parameter'],
        'a foreign parameter and another unacceptable type' => ['application/vnd.api+json;charset=utf-8, text/html', 'unsupported_parameter'],
        'the only instance has an extension' => ['application/vnd.api+json;ext="https://x.test"', 'unsupported_extension'],
        'an extension with a comma inside quotes is one value' => ['application/vnd.api+json;ext="https://a.test/x,y"', 'unsupported_extension'],
        'every instance has an extension' => ['application/vnd.api+json;ext="https://a.test", application/vnd.api+json;ext="https://b.test"', 'unsupported_extension'],
        'only html' => ['text/html', 'not_json_api'],
        'only types that are not json:api' => ['text/html, application/xml;q=0.9', 'not_json_api'],
        'the json:api type is refused with a zero weight' => ['application/vnd.api+json;q=0', 'refused'],
        'any type is refused with a zero weight' => ['*/*;q=0', 'refused'],
        'a header with nothing readable' => ['garbage', 'malformed'],
        'several items and none readable' => ['garbage, more garbage', 'malformed'],
        'a weight that is not a number' => ['application/vnd.api+json;q=abc', 'malformed'],
      ];
    }

    #[Test]
    #[DataProvider('acceptsRejectedInBothModes')]
    public function rejects_these_accept_headers_in_both_modes_with_406(string $header, string $reason): void {
      foreach ([true, false] as $strict) {
        $this->assertFailure(
          $this->negotiator($strict)->validateAccept($header),
          406,
          'Accept',
          $reason,
          "strict=" . var_export($strict, true) . ", Accept: $header"
        );
      }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptsOnlyPlainJson(): array {
      return [
        'plain json' => ['application/json'],
        'plain json with a weight' => ['application/json;q=0.5'],
        'plain json next to another type' => ['text/html, application/json'],
        'plain json with a charset' => ['application/json; charset=utf-8'],
      ];
    }

    #[Test]
    #[DataProvider('acceptsOnlyPlainJson')]
    public function strict_mode_rejects_an_accept_that_only_allows_plain_json_with_406(string $header): void {
      $this->assertFailure($this->negotiator(true)->validateAccept($header), 406, 'Accept', 'not_json_api');
    }

    #[Test]
    #[DataProvider('acceptsOnlyPlainJson')]
    public function permissive_mode_accepts_an_accept_that_allows_plain_json(string $header): void {
      $this->assertNull($this->negotiator(false)->validateAccept($header));
    }

    #[Test]
    public function permissive_mode_does_not_relax_the_json_api_parameter_rules(): void {
      $negotiator = $this->negotiator(false);

      $this->assertFailure($negotiator->validateAccept('application/vnd.api+json;charset=utf-8'), 406, 'Accept', 'unsupported_parameter');
      $this->assertFailure($negotiator->validateAccept('text/html'), 406, 'Accept', 'not_json_api');
    }

    // ---------------------------------------------------------------------------------------------
    // Respuesta / Response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function always_answers_with_the_plain_json_api_media_type(): void {
      // Aunque el cliente pida application/json en modo permisivo, la respuesta no cambia de tipo.
      // Even if the client asks for application/json in permissive mode, the response type does not change.
      $this->assertSame('application/vnd.api+json', $this->negotiator(true)->responseContentType());
      $this->assertSame('application/vnd.api+json', $this->negotiator(false)->responseContentType());
    }

    // ---------------------------------------------------------------------------------------------
    // Apoyo / Helpers
    // ---------------------------------------------------------------------------------------------

    private function negotiator(bool $strict): ContentNegotiator {
      return new ContentNegotiator(strict: $strict);
    }

    private function assertFailure(?NegotiationFailure $failure, int $status, string $header, string $reason, string $context = ''): void {
      $this->assertNotNull($failure, "Se esperaba un fallo / A failure was expected. $context");
      $this->assertSame($status, $failure->status(), $context);
      $this->assertSame($header, $failure->header(), $context);
      $this->assertSame($reason, $failure->reason(), $context);
    }
  }
