<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ErrorCodesTest extends TestCase {
    // ---------------------------------------------------------------------------------------------
    // El catálogo por defecto / The default catalog
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function catalog(): array {
      // [constante, código base]. Son un contrato con los clientes de la API: cambiarlos rompe a quien los lea.
      // [constant, base code]. They are a contract with API clients: changing them breaks whoever reads them.
      return [
        'BAD_REQUEST' => ['BAD_REQUEST', 'request.bad_request'],
        'UNAUTHENTICATED' => ['UNAUTHENTICATED', 'auth.unauthenticated'],
        'FORBIDDEN' => ['FORBIDDEN', 'auth.forbidden'],
        'INVALID_SIGNATURE' => ['INVALID_SIGNATURE', 'auth.invalid_signature'],
        'RESOURCE_NOT_FOUND' => ['RESOURCE_NOT_FOUND', 'resource.not_found'],
        'ROUTE_NOT_FOUND' => ['ROUTE_NOT_FOUND', 'route.not_found'],
        'METHOD_NOT_ALLOWED' => ['METHOD_NOT_ALLOWED', 'request.method_not_allowed'],
        'NOT_ACCEPTABLE' => ['NOT_ACCEPTABLE', 'request.not_acceptable'],
        'CONFLICT' => ['CONFLICT', 'resource.conflict'],
        'PAYLOAD_TOO_LARGE' => ['PAYLOAD_TOO_LARGE', 'request.payload_too_large'],
        'UNSUPPORTED_MEDIA_TYPE' => ['UNSUPPORTED_MEDIA_TYPE', 'request.unsupported_media_type'],
        'TOKEN_MISMATCH' => ['TOKEN_MISMATCH', 'request.token_mismatch'],
        'VALIDATION_FAILED' => ['VALIDATION_FAILED', 'validation.failed'],
        'TOO_MANY_REQUESTS' => ['TOO_MANY_REQUESTS', 'request.too_many_requests'],
        'SERVER_ERROR' => ['SERVER_ERROR', 'server.error'],
        'SERVICE_UNAVAILABLE' => ['SERVICE_UNAVAILABLE', 'server.unavailable'],
        'BAD_QUERY' => ['BAD_QUERY', 'request.bad_query'],
        'BAD_DOCUMENT' => ['BAD_DOCUMENT', 'request.bad_document'],
      ];
    }

    #[Test]
    #[DataProvider('catalog')]
    public function the_catalog_keeps_these_stable_base_codes(string $constant, string $expected): void {
      $this->assertSame($expected, constant(ErrorCodes::class . '::' . $constant));
    }

    #[Test]
    public function the_catalog_lists_every_base_code_once_and_with_a_consistent_shape(): void {
      $listed = ErrorCodes::catalog();

      $this->assertEqualsCanonicalizing(array_column(self::catalog(), 1), $listed);
      $this->assertSame($listed, array_values(array_unique($listed)));

      foreach ($listed as $code) {
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $code);
      }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function statuses(): array {
      // [estado HTTP, código base] / [HTTP status, base code]. Los estados sin código propio dan `http.<estado>`.
      return [
        '400' => [400, ErrorCodes::BAD_REQUEST],
        '401' => [401, ErrorCodes::UNAUTHENTICATED],
        '403' => [403, ErrorCodes::FORBIDDEN],
        '404' => [404, ErrorCodes::RESOURCE_NOT_FOUND],
        '405' => [405, ErrorCodes::METHOD_NOT_ALLOWED],
        '406' => [406, ErrorCodes::NOT_ACCEPTABLE],
        '409' => [409, ErrorCodes::CONFLICT],
        '413' => [413, ErrorCodes::PAYLOAD_TOO_LARGE],
        '415' => [415, ErrorCodes::UNSUPPORTED_MEDIA_TYPE],
        '419' => [419, ErrorCodes::TOKEN_MISMATCH],
        '422' => [422, ErrorCodes::VALIDATION_FAILED],
        '429' => [429, ErrorCodes::TOO_MANY_REQUESTS],
        '500' => [500, ErrorCodes::SERVER_ERROR],
        '503' => [503, ErrorCodes::SERVICE_UNAVAILABLE],
        '402 has no code of its own' => [402, 'http.402'],
        '418 has no code of its own' => [418, 'http.418'],
        '502 has no code of its own' => [502, 'http.502'],
      ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function maps_an_http_status_to_its_base_code(int $status, string $expected): void {
      $this->assertSame($expected, ErrorCodes::forStatus($status));
    }

    // ---------------------------------------------------------------------------------------------
    // Prefijo / Prefix
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_default_code_gets_the_library_prefix(): void {
      $this->assertSame(
        'mundoprogra.json-api.resource.not_found',
        $this->codes()->resolve(ErrorCodes::RESOURCE_NOT_FOUND)
      );
    }

    #[Test]
    public function the_prefix_can_be_changed(): void {
      $this->assertSame('acme.resource.not_found', $this->codes(['code_prefix' => 'acme'])->resolve(ErrorCodes::RESOURCE_NOT_FOUND));
    }

    #[Test]
    public function an_empty_prefix_gives_the_bare_base_code(): void {
      $this->assertSame('resource.not_found', $this->codes(['code_prefix' => ''])->resolve(ErrorCodes::RESOURCE_NOT_FOUND));
    }

    // ---------------------------------------------------------------------------------------------
    // Reemplazos / Replacements
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_replacement_is_used_as_written_without_the_prefix(): void {
      $codes = $this->codes(['codes' => ['resource.not_found' => 'ERR-4040']]);

      $this->assertSame('ERR-4040', $codes->resolve(ErrorCodes::RESOURCE_NOT_FOUND));
    }

    #[Test]
    public function codes_without_a_replacement_keep_their_default_with_the_prefix(): void {
      $codes = $this->codes(['codes' => ['resource.not_found' => 'ERR-4040']]);

      $this->assertSame('mundoprogra.json-api.auth.forbidden', $codes->resolve(ErrorCodes::FORBIDDEN));
    }

    #[Test]
    public function a_replacement_also_works_with_an_empty_prefix(): void {
      $codes = $this->codes(['code_prefix' => '', 'codes' => ['auth.forbidden' => 'NO-PERMISSION']]);

      $this->assertSame('NO-PERMISSION', $codes->resolve(ErrorCodes::FORBIDDEN));
    }

    // ---------------------------------------------------------------------------------------------
    // Interruptor / Switch
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function nothing_is_resolved_when_the_codes_are_turned_off(): void {
      $codes = $this->codes(['include_code' => false, 'codes' => ['resource.not_found' => 'ERR-4040']]);

      $this->assertNull($codes->resolve(ErrorCodes::RESOURCE_NOT_FOUND));
      $this->assertNull($codes->resolve(ErrorCodes::FORBIDDEN));
      $this->assertNull($codes->validation('Required'));
      $this->assertNull($codes->http(402));
    }

    // ---------------------------------------------------------------------------------------------
    // Validación: un código por regla / Validation: one code per rule
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{?string, string}>
     */
    public static function rules(): array {
      // [nombre de la regla como lo entrega Laravel, código base] / [rule name as Laravel gives it, base code]
      return [
        'a studly rule' => ['Required', 'validation.required'],
        'email' => ['Email', 'validation.email'],
        'max' => ['Max', 'validation.max'],
        'a rule with several words' => ['RequiredIf', 'validation.required_if'],
        'a rule already in snake case' => ['required_if', 'validation.required_if'],
        'a rule class, by its short name' => ['App\\Rules\\Uppercase', 'validation.uppercase'],
        'a rule class with several words' => ['App\\Rules\\StrongPassword', 'validation.strong_password'],
        'no rule available' => [null, 'validation.failed'],
        'an empty rule' => ['', 'validation.failed'],
      ];
    }

    #[Test]
    #[DataProvider('rules')]
    public function turns_the_name_of_a_failed_rule_into_a_validation_code(?string $rule, string $base): void {
      $this->assertSame('mundoprogra.json-api.' . $base, $this->codes()->validation($rule));
    }

    #[Test]
    public function a_validation_code_can_be_replaced_like_any_other(): void {
      $codes = $this->codes(['codes' => ['validation.required' => 'ERR-1001']]);

      $this->assertSame('ERR-1001', $codes->validation('Required'));
      $this->assertSame('mundoprogra.json-api.validation.email', $codes->validation('Email'));
    }

    // ---------------------------------------------------------------------------------------------
    // Cualquier otro estado HTTP / Any other HTTP status
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function builds_a_generic_code_from_the_http_status(): void {
      $this->assertSame('mundoprogra.json-api.http.402', $this->codes()->http(402));
      $this->assertSame('http.402', $this->codes(['code_prefix' => ''])->http(402));
    }

    #[Test]
    public function a_generic_http_code_can_be_replaced_too(): void {
      $this->assertSame('PAYMENT', $this->codes(['codes' => ['http.402' => 'PAYMENT']])->http(402));
    }

    // ---------------------------------------------------------------------------------------------
    // Un error que ya trae su código / An error that already carries its code
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_explicit_code_is_kept_exactly_as_written(): void {
      // Quien lanza su propio error con un código lo usa tal cual: sin prefijo y sin pasar por el mapa.
      // Whoever throws their own error with a code gets it as written: no prefix and not through the map.
      $codes = $this->codes(['codes' => ['MY-1' => 'REPLACED']]);

      $error = Error::make(status: 402, code: 'MY-1');

      $this->assertSame('MY-1', $codes->apply($error)->code());
    }

    #[Test]
    public function the_codes_being_off_removes_even_an_explicit_code(): void {
      $codes = $this->codes(['include_code' => false]);

      $this->assertNull($codes->apply(Error::make(status: 402, code: 'MY-1'))->code());
    }

    #[Test]
    public function an_error_without_a_code_stays_without_one(): void {
      $this->assertNull($this->codes()->apply(Error::make(status: 500))->code());
    }

    // ---------------------------------------------------------------------------------------------
    // Configuración al momento / Config at call time
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function reads_the_settings_at_call_time(): void {
      $config = new Repository(['json-api' => $this->defaultConfig(), 'app' => ['debug' => false]]);
      $codes = new ErrorCodes(new Settings($config));

      $config->set('json-api.errors.code_prefix', 'later');

      $this->assertSame('later.auth.forbidden', $codes->resolve(ErrorCodes::FORBIDDEN));
    }

    // ---------------------------------------------------------------------------------------------
    // Apoyo / Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array {
      return require __DIR__ . '/../../config/json-api.php';
    }

    /**
     * Crea el resolutor con la configuración por defecto y los cambios indicados dentro de `errors` (claves con punto).
     * Creates the resolver with the default config and the given changes inside `errors` (dotted keys).
     *
     * @param  array<string, mixed>  $errors
     */
    private function codes(array $errors = []): ErrorCodes {
      $config = $this->defaultConfig();

      foreach ($errors as $key => $value) {
        Arr::set($config, "errors.$key", $value);
      }

      return new ErrorCodes(new Settings(new Repository(['json-api' => $config, 'app' => ['debug' => false]])));
    }
  }
