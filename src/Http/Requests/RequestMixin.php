<?php

  namespace MundoProgra\JsonApi\Http\Requests;

  use Closure;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Http\RequestScope;

  /**
   * Los métodos que el paquete le agrega a la petición para que el controlador lea el documento de entrada sin tocar el JSON. Se registran de una
   * vez con `Request::mixin()`: Laravel llama a cada método de esta clase y registra como macro la función que devuelve, que corre con `$this`
   * igual a la petición. Por eso cada método público de esta clase devuelve una función y no hace nada más.
   * Todos se apoyan en el documento ya leído (`RequestDocument::of()` o `RelationshipDocument::of()`): un documento mal formado es el mismo `400`
   * de siempre. Los nombres de atributos y relaciones van en snake_case, sea cual sea el `member_case`.
   *
   * The methods the package adds to the request so the controller reads the input document without touching the JSON. They are registered all at
   * once with `Request::mixin()`: Laravel calls each method of this class and registers the function it returns as a macro, which runs with
   * `$this` equal to the request. That is why every public method of this class returns a function and does nothing else.
   * All of them rely on the already read document (`RequestDocument::of()` or `RelationshipDocument::of()`): a malformed document is the same
   * `400` as ever. Attribute and relationship names are in snake_case, whatever the `member_case` is.
   */
  final class RequestMixin {
    /**
     * ¿El paquete trata esta petición como de JSON:API? Es la misma regla que usan los middleware (prefijo, modo, `except`, media type pedido o
     * ruta marcada a mano).
     *
     * Does the package treat this request as JSON:API? It is the same rule the middleware use (prefix, mode, `except`, requested media type or
     * route marked by hand).
     *
     * @return Closure(): bool
     */
    public function isJsonApi(): Closure {
      return function (): bool {
        // Dentro de la macro `$this` es la petición y no esta clase; PHPStan solo ve la clase, por eso se ignora este aviso.
        // Inside the macro `$this` is the request and not this class; PHPStan only sees the class, which is why this warning is ignored.
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        return app(RequestScope::class)->appliesTo($this);
      };
    }

    /**
     * El `type` del documento.
     * The `type` of the document.
     *
     * @return Closure(): string
     */
    public function jsonApiType(): Closure {
      return function (): string {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        return RequestDocument::of($this)->type();
      };
    }

    /**
     * El `id` del documento, o null si el cliente no mandó ninguno.
     * The `id` of the document, or null if the client sent none.
     *
     * @return Closure(): (string|null)
     */
    public function jsonApiId(): Closure {
      return function (): ?string {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        return RequestDocument::of($this)->id();
      };
    }

    /**
     * Los atributos del documento, con los nombres del primer nivel en snake_case.
     * The attributes of the document, with the first-level names in snake_case.
     *
     * @return Closure(): array<int|string, mixed>
     */
    public function jsonApiAttributes(): Closure {
      return function (): array {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        return RequestDocument::of($this)->attributes();
      };
    }

    /**
     * ¿El cliente mandó esta relación (en snake_case)? Cuenta aunque traiga `data: null` o solo `links` o `meta`.
     * Did the client send this relationship (in snake_case)? It counts even with `data: null` or only `links` or `meta`.
     *
     * @return Closure(string): bool
     */
    public function hasJsonApiRelationship(): Closure {
      return function (string $name): bool {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        return array_key_exists($name, RequestDocument::of($this)->relationships());
      };
    }

    /**
     * El `id` de una relación a uno (en snake_case), o null si el linkage es `null`, una lista o la relación no vino. Sin nombre lee el linkage
     * del cuerpo de un endpoint de relación (`/articles/1/relationships/tags`).
     *
     * The `id` of a to-one relationship (in snake_case), or null if the linkage is `null`, a list or the relationship was not sent. Without a
     * name it reads the linkage of the body of a relationship endpoint (`/articles/1/relationships/tags`).
     *
     * @return Closure(string|null): (string|null)
     */
    public function jsonApiRelationshipId(): Closure {
      return function (?string $name = null): ?string {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        if ($name === null) {
          return Linkage::id(RelationshipDocument::of($this)->data());
        }

        return Linkage::id(RequestDocument::of($this)->relationships()[$name]['data'] ?? null);
      };
    }

    /**
     * Los `id` de una relación (en snake_case), en el orden del cliente: uno si es un identificador, ninguno si es `null`, vacía o no vino.
     * Sin nombre lee el linkage del cuerpo de un endpoint de relación.
     *
     * The `id`s of a relationship (in snake_case), in the order of the client: one if it is an identifier, none if it is `null`, empty or was not
     * sent. Without a name it reads the linkage of the body of a relationship endpoint.
     *
     * @return Closure(string|null): list<string>
     */
    public function jsonApiRelationshipIds(): Closure {
      return function (?string $name = null): array {
        /** @var Request $this */ // @phpstan-ignore varTag.nativeType
        if ($name === null) {
          return Linkage::ids(RelationshipDocument::of($this)->data());
        }

        return Linkage::ids(RequestDocument::of($this)->relationships()[$name]['data'] ?? null);
      };
    }
  }
