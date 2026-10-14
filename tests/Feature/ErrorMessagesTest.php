<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  class ErrorMessagesTest extends TestCase {
    #[Test]
    public function the_container_resolves_the_same_instance_every_time(): void {
      $this->assertInstanceOf(ErrorMessages::class, $this->app->make(ErrorMessages::class));
      $this->assertSame($this->app->make(ErrorMessages::class), $this->app->make(ErrorMessages::class));
    }

    #[Test]
    public function writes_the_title_in_english(): void {
      $this->app->setLocale('en');

      $this->assertSame('Resource not found', $this->messages()->title(ErrorCodes::RESOURCE_NOT_FOUND));
      $this->assertSame('Validation failed', $this->messages()->title(ErrorCodes::VALIDATION_FAILED));
    }

    #[Test]
    public function writes_the_title_in_spanish(): void {
      $this->app->setLocale('es');

      $this->assertSame('Recurso no encontrado', $this->messages()->title(ErrorCodes::RESOURCE_NOT_FOUND));
      $this->assertSame('Falló la validación', $this->messages()->title(ErrorCodes::VALIDATION_FAILED));
    }

    #[Test]
    public function the_detail_changes_with_the_language(): void {
      $this->app->setLocale('en');
      $english = $this->messages()->detail(ErrorCodes::RESOURCE_NOT_FOUND);

      $this->app->setLocale('es');
      $spanish = $this->messages()->detail(ErrorCodes::RESOURCE_NOT_FOUND);

      $this->assertNotSame('', $english);
      $this->assertNotSame('', $spanish);
      $this->assertNotSame($english, $spanish);
    }

    #[Test]
    public function follows_the_language_of_the_application_at_call_time(): void {
      // El paquete no decide el idioma: usa el que tenga Laravel en cada momento (la app lo fija como prefiera).
      // The package does not decide the language: it uses the one Laravel has at each moment (the app sets it as it prefers).
      $messages = $this->messages();

      $this->app->setLocale('es');
      $this->assertSame('Prohibido', $messages->title(ErrorCodes::FORBIDDEN));

      $this->app->setLocale('en');
      $this->assertSame('Forbidden', $messages->title(ErrorCodes::FORBIDDEN));
    }

    #[Test]
    public function falls_back_to_english_when_the_language_has_no_translations(): void {
      // Un idioma sin traducciones del paquete, y un idioma de respaldo de la app que tampoco las tiene.
      // A language the package has no translations for, and an app fallback language that does not have them either.
      $this->app->setLocale('fr');
      $this->app['translator']->setFallback('fr');

      $this->assertSame('Resource not found', $this->messages()->title(ErrorCodes::RESOURCE_NOT_FOUND));
      $this->assertStringNotContainsString('json-api::', $this->messages()->detail(ErrorCodes::RESOURCE_NOT_FOUND));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function regionalSpanish(): array {
      return [
        'with an underscore' => ['es_MX'],
        'with a hyphen' => ['es-MX'],
      ];
    }

    #[Test]
    #[DataProvider('regionalSpanish')]
    public function a_regional_variant_uses_the_base_language(string $locale): void {
      $this->app->setLocale($locale);
      $this->app['translator']->setFallback('fr');

      $this->assertSame('Recurso no encontrado', $this->messages()->title(ErrorCodes::RESOURCE_NOT_FOUND));
    }

    #[Test]
    public function a_code_without_its_own_text_uses_the_generic_one(): void {
      $this->app->setLocale('en');
      $generic = $this->messages()->title('http.generic');

      $this->assertSame('Request failed', $generic);
      $this->assertSame($generic, $this->messages()->title('http.402'));
      $this->assertSame($generic, $this->messages()->title('something.that.does.not.exist'));
    }

    #[Test]
    public function every_validation_rule_shares_the_validation_failed_title(): void {
      $this->app->setLocale('en');

      $this->assertSame(
        $this->messages()->title(ErrorCodes::VALIDATION_FAILED),
        $this->messages()->title('validation.required')
      );
      $this->assertSame(
        $this->messages()->title(ErrorCodes::VALIDATION_FAILED),
        $this->messages()->title('validation.strong_password')
      );
    }

    #[Test]
    public function fills_in_the_placeholders_of_a_detail(): void {
      foreach (['en', 'es'] as $locale) {
        $this->app->setLocale($locale);

        $detail = $this->messages()->detail(ErrorCodes::METHOD_NOT_ALLOWED, ['method' => 'PUT', 'allowed' => 'GET, POST']);

        $this->assertStringContainsString('PUT', $detail, $locale);
        $this->assertStringContainsString('GET, POST', $detail, $locale);
        $this->assertStringNotContainsString(':method', $detail, $locale);
      }
    }

    #[Test]
    public function a_negotiation_reason_gives_a_more_specific_detail(): void {
      $this->app->setLocale('en');

      $general = $this->messages()->detail(ErrorCodes::UNSUPPORTED_MEDIA_TYPE);
      $specific = $this->messages()->detail(ErrorCodes::UNSUPPORTED_MEDIA_TYPE, reason: 'unsupported_extension');

      $this->assertNotSame('', $specific);
      $this->assertNotSame($general, $specific);
    }

    #[Test]
    public function an_unknown_reason_falls_back_to_the_general_detail(): void {
      $this->app->setLocale('en');

      $this->assertSame(
        $this->messages()->detail(ErrorCodes::NOT_ACCEPTABLE),
        $this->messages()->detail(ErrorCodes::NOT_ACCEPTABLE, reason: 'a_reason_nobody_defined')
      );
    }

    private function messages(): ErrorMessages {
      return $this->app->make(ErrorMessages::class);
    }
  }
