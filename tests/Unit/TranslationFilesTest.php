<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class TranslationFilesTest extends TestCase {
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array {
      return [
        'english' => ['en'],
        'spanish' => ['es'],
      ];
    }

    #[Test]
    public function both_languages_have_exactly_the_same_keys(): void {
      $this->assertEqualsCanonicalizing(
        array_keys(Arr::dot($this->lines('en'))),
        array_keys(Arr::dot($this->lines('es')))
      );
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_catalog_code_has_a_title_and_a_detail(string $locale): void {
      foreach (ErrorCodes::catalog() as $base) {
        $this->assertNotEmpty($this->text($locale, "$base.title"), "$locale: falta el título de / missing title of $base");
        $this->assertNotEmpty($this->text($locale, "$base.detail"), "$locale: falta el detalle de / missing detail of $base");
      }
    }

    #[Test]
    #[DataProvider('locales')]
    public function the_generic_texts_for_any_other_status_exist(string $locale): void {
      $this->assertNotEmpty($this->text($locale, 'http.generic.title'));
      $this->assertNotEmpty($this->text($locale, 'http.generic.detail'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function negotiationReasons(): array {
      // [código base, motivo] / [base code, reason]. Los motivos salen de NegotiationFailure.
      $cases = [];

      foreach (['missing', 'malformed', 'not_json_api', 'unsupported_parameter', 'unsupported_extension'] as $reason) {
        $cases["unsupported_media_type: $reason"] = [ErrorCodes::UNSUPPORTED_MEDIA_TYPE, $reason];
      }

      foreach (['malformed', 'not_json_api', 'unsupported_parameter', 'unsupported_extension', 'refused'] as $reason) {
        $cases["not_acceptable: $reason"] = [ErrorCodes::NOT_ACCEPTABLE, $reason];
      }

      foreach (['malformed', 'invalid_name', 'empty_item', 'invalid_page', 'include_too_deep', 'unknown_parameter'] as $reason) {
        $cases["bad_query: $reason"] = [ErrorCodes::BAD_QUERY, $reason];
      }

      return $cases;
    }

    #[Test]
    #[DataProvider('negotiationReasons')]
    public function every_negotiation_reason_has_its_own_detail_in_both_languages(string $base, string $reason): void {
      foreach (['en', 'es'] as $locale) {
        $this->assertNotEmpty($this->text($locale, "$base.reasons.$reason"), "$locale: $base / $reason");
      }
    }

    #[Test]
    public function no_text_is_empty_and_all_are_plain_strings(): void {
      foreach (['en', 'es'] as $locale) {
        foreach (Arr::dot($this->lines($locale)) as $key => $text) {
          $this->assertIsString($text, "$locale: $key");
          $this->assertNotSame('', trim($text), "$locale: $key");
        }
      }
    }

    #[Test]
    public function both_languages_use_the_same_placeholders_in_every_text(): void {
      $english = Arr::dot($this->lines('en'));
      $spanish = Arr::dot($this->lines('es'));

      foreach ($english as $key => $text) {
        $this->assertEqualsCanonicalizing(
          $this->placeholders($text),
          $this->placeholders((string) ($spanish[$key] ?? '')),
          "Los marcadores de $key no coinciden entre idiomas / The placeholders of $key differ between languages"
        );
      }
    }

    /**
     * @return array<string, mixed>
     */
    private function lines(string $locale): array {
      return require __DIR__ . '/../../lang/' . $locale . '/errors.php';
    }

    private function text(string $locale, string $key): mixed {
      return Arr::get($this->lines($locale), $key);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array {
      preg_match_all('/:[a-z_]+/', $text, $matches);

      return $matches[0];
    }
  }
