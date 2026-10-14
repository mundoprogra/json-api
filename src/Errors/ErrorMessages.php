<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Translation\Translator;

  /**
   * Busca el `title` y el `detail` traducidos de un error, a partir de su código base (ver ErrorCodes).
   * Usa el idioma que tenga Laravel en cada llamada; el paquete no lo decide.
   * Looks up the translated `title` and `detail` of an error from its base code (see ErrorCodes).
   * It uses the language Laravel has at each call; the package does not decide it.
   */
  final class ErrorMessages {
    private const FALLBACK_LOCALE = 'en';

    private const GENERIC = 'http.generic';

    public function __construct(private readonly Translator $translator) {
    }

    public function title(string $base): string {
      return $this->line($this->textBase($base), 'title');
    }

    /**
     * @param  array<string, string>  $replace  marcadores como `method` para `:method` / placeholders such as `method` for `:method`
     * @param  string|null  $reason  motivo concreto (por ejemplo de la negociación) con un mensaje más específico / specific reason (for example from negotiation) with a more specific message
     */
    public function detail(string $base, array $replace = [], ?string $reason = null): string {
      $base = $this->textBase($base);

      if ($reason !== null) {
        $specific = $this->lookup("$base.reasons.$reason", $replace);

        if ($specific !== null) {
          return $specific;
        }
      }

      return $this->line($base, 'detail', $replace);
    }

    /**
     * El código cuyo texto se usa: el mismo si está en el catálogo, `validation.failed` para cualquier regla
     * de validación, y el texto genérico para todo lo demás.
     * The code whose text is used: the same one if it is in the catalog, `validation.failed` for any validation
     * rule, and the generic text for anything else.
     */
    private function textBase(string $base): string {
      if (str_starts_with($base, 'validation.')) {
        return ErrorCodes::VALIDATION_FAILED;
      }

      return in_array($base, ErrorCodes::catalog(), true) || $base === self::GENERIC ? $base : self::GENERIC;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $base, string $part, array $replace = []): string {
      return $this->lookup("$base.$part", $replace)
        ?? $this->lookup(self::GENERIC . ".$part", $replace)
        ?? '';
    }

    /**
     * Prueba el idioma actual, luego su idioma base (es_MX -> es) y por último inglés, que siempre está completo.
     * No se usa el idioma de respaldo de Laravel: si la app lo cambia a uno sin traducciones, igual se llega al inglés.
     * Tries the current language, then its base language (es_MX -> es) and finally English, which is always complete.
     * Laravel's fallback language is not used: if the app sets it to one without translations, English is still reached.
     *
     * @param  array<string, string>  $replace
     */
    private function lookup(string $path, array $replace): ?string {
      $key = "json-api::errors.$path";

      foreach ($this->locales() as $locale) {
        if ($this->translator->has($key, $locale, false)) {
          return (string) $this->translator->get($key, $replace, $locale, false);
        }
      }

      return null;
    }

    /**
     * @return list<string>
     */
    private function locales(): array {
      $current = $this->translator->getLocale();
      $language = preg_split('/[_-]/', $current)[0] ?? $current;

      return array_values(array_unique([$current, $language, self::FALLBACK_LOCALE]));
    }
  }
