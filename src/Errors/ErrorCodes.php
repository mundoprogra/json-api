<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Support\Str;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Decide el valor del miembro `code` de un error: el catálogo de códigos base del paquete, con el prefijo,
   * los reemplazos y el interruptor de la configuración.
   * Los códigos base son un contrato con los clientes de la API: no deben cambiar de nombre.
   *
   * Decides the value of the `code` member of an error: the package catalog of base codes, with the prefix,
   * the replacements and the switch from the configuration.
   * Base codes are a contract with API clients: they must not be renamed.
   */
  final class ErrorCodes {
    public const BAD_REQUEST = 'request.bad_request';

    public const BAD_QUERY = 'request.bad_query';

    public const BAD_DOCUMENT = 'request.bad_document';

    public const CLIENT_ID_NOT_ALLOWED = 'request.client_id_not_allowed';

    public const UNAUTHENTICATED = 'auth.unauthenticated';

    public const FORBIDDEN = 'auth.forbidden';

    public const INVALID_SIGNATURE = 'auth.invalid_signature';

    public const RESOURCE_NOT_FOUND = 'resource.not_found';

    public const ROUTE_NOT_FOUND = 'route.not_found';

    public const METHOD_NOT_ALLOWED = 'request.method_not_allowed';

    public const NOT_ACCEPTABLE = 'request.not_acceptable';

    public const CONFLICT = 'resource.conflict';

    public const PAYLOAD_TOO_LARGE = 'request.payload_too_large';

    public const UNSUPPORTED_MEDIA_TYPE = 'request.unsupported_media_type';

    public const TOKEN_MISMATCH = 'request.token_mismatch';

    public const VALIDATION_FAILED = 'validation.failed';

    public const TOO_MANY_REQUESTS = 'request.too_many_requests';

    public const SERVER_ERROR = 'server.error';

    public const SERVICE_UNAVAILABLE = 'server.unavailable';

    public function __construct(private readonly Settings $settings) {
    }

    /**
     * Todos los códigos base del catálogo. Los de validación por regla (`validation.<regla>`) y los genéricos (`http.<estado>`) se construyen al vuelo.
     * Every base code of the catalog. Per-rule validation codes (`validation.<rule>`) and generic ones (`http.<status>`) are built on the fly.
     *
     * @return list<string>
     */
    public static function catalog(): array {
      return [
        self::BAD_REQUEST,
        self::BAD_QUERY,
        self::BAD_DOCUMENT,
        self::CLIENT_ID_NOT_ALLOWED,
        self::UNAUTHENTICATED,
        self::FORBIDDEN,
        self::INVALID_SIGNATURE,
        self::RESOURCE_NOT_FOUND,
        self::ROUTE_NOT_FOUND,
        self::METHOD_NOT_ALLOWED,
        self::NOT_ACCEPTABLE,
        self::CONFLICT,
        self::PAYLOAD_TOO_LARGE,
        self::UNSUPPORTED_MEDIA_TYPE,
        self::TOKEN_MISMATCH,
        self::VALIDATION_FAILED,
        self::TOO_MANY_REQUESTS,
        self::SERVER_ERROR,
        self::SERVICE_UNAVAILABLE,
      ];
    }

    /**
     * El código base que corresponde a un estado HTTP; los estados sin código propio dan `http.<estado>`.
     * The base code for an HTTP status; statuses without a code of their own give `http.<status>`.
     */
    public static function forStatus(int $status): string {
      return match ($status) {
        400 => self::BAD_REQUEST,
        401 => self::UNAUTHENTICATED,
        403 => self::FORBIDDEN,
        404 => self::RESOURCE_NOT_FOUND,
        405 => self::METHOD_NOT_ALLOWED,
        406 => self::NOT_ACCEPTABLE,
        409 => self::CONFLICT,
        413 => self::PAYLOAD_TOO_LARGE,
        415 => self::UNSUPPORTED_MEDIA_TYPE,
        419 => self::TOKEN_MISMATCH,
        422 => self::VALIDATION_FAILED,
        429 => self::TOO_MANY_REQUESTS,
        500 => self::SERVER_ERROR,
        503 => self::SERVICE_UNAVAILABLE,
        default => "http.$status",
      };
    }

    /**
     * El código final de un código base, o null si los códigos están apagados.
     * Un reemplazo se usa tal cual (sin prefijo); si no, se aplica el prefijo, salvo que esté vacío.
     * The final code for a base code, or null if codes are turned off.
     * A replacement is used as is (no prefix); otherwise the prefix is applied, unless it is empty.
     */
    public function resolve(string $base): ?string {
      if (!$this->settings->includeErrorCode()) {
        return null;
      }

      $replacements = $this->settings->errorCodes();

      if (isset($replacements[$base])) {
        return $replacements[$base];
      }

      $prefix = $this->settings->errorCodePrefix();

      return $prefix === '' ? $base : $prefix . '.' . $base;
    }

    /**
     * El código de una regla de validación fallida, a partir del nombre que entrega Laravel (`Required`, `RequiredIf`, `App\Rules\Uppercase`).
     * Sin regla disponible se usa `validation.failed`.
     * The code of a failed validation rule, from the name Laravel gives (`Required`, `RequiredIf`, `App\Rules\Uppercase`).
     * With no rule available `validation.failed` is used.
     */
    public function validation(?string $rule): ?string {
      $name = Str::snake(Str::afterLast((string) $rule, '\\'));

      return $this->resolve($name === '' ? self::VALIDATION_FAILED : 'validation.' . $name);
    }

    /**
     * El código genérico de un estado HTTP que no tiene uno propio en el catálogo, por ejemplo `http.402`.
     * The generic code of an HTTP status that has no code of its own in the catalog, for example `http.402`.
     */
    public function http(int $status): ?string {
      return $this->resolve("http.$status");
    }

    /**
     * Aplica el interruptor a un error que ya trae su código: se conserva tal cual (sin prefijo ni reemplazos),
     * salvo que los códigos estén apagados, en cuyo caso se quita.
     * Applies the switch to an error that already carries its code: it is kept as written (no prefix or replacements),
     * unless codes are turned off, in which case it is removed.
     */
    public function apply(Error $error): Error {
      return $this->settings->includeErrorCode() ? $error : $error->withCode(null);
    }
  }
