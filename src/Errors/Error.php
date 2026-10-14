<?php

  namespace MundoProgra\JsonApi\Errors;

  use InvalidArgumentException;
  use JsonSerializable;
  use MundoProgra\JsonApi\Support\Pointer;

  /**
   * Un objeto de error de JSON:API: un elemento de la lista `errors` de una respuesta. Es inmutable.
   * A JSON:API error object: one element of the `errors` list of a response. It is immutable.
   */
  final class Error implements JsonSerializable {
    /**
     * @param  array<int|string, mixed>  $meta
     */
    private function __construct(
      private readonly int $status,
      private readonly ?string $title,
      private readonly ?string $detail,
      private readonly ?string $code,
      private readonly ?string $id,
      private readonly ?Pointer $pointer,
      private readonly ?string $parameter,
      private readonly ?string $header,
      private readonly array $meta,
    ) {
    }

    /**
     * @param  int|string  $status  estado HTTP 4xx o 5xx / HTTP status, 4xx or 5xx
     * @param  Pointer|string|null  $pointer  dónde está el problema dentro del documento / where the problem is inside the document
     * @param  array<int|string, mixed>  $meta  información adicional; debe ser un objeto, no una lista / extra information; it must be an object, not a list
     *
     * @throws InvalidArgumentException
     */
    public static function make(
      int|string $status,
      ?string $title = null,
      ?string $detail = null,
      ?string $code = null,
      ?string $id = null,
      Pointer|string|null $pointer = null,
      ?string $parameter = null,
      ?string $header = null,
      array $meta = [],
    ): self {
      if (preg_match('/^[45]\d{2}$/D', (string) $status) !== 1) {
        throw new InvalidArgumentException("Invalid error status \"$status\": it must be a 4xx or 5xx HTTP status.");
      }

      if ($meta !== [] && array_is_list($meta)) {
        throw new InvalidArgumentException('The "meta" member of an error must be an object (an array with keys), a list was given.');
      }

      return new self(
        (int) $status,
        $title,
        $detail,
        $code,
        $id,
        is_string($pointer) ? Pointer::parse($pointer) : $pointer,
        $parameter,
        $header,
        $meta,
      );
    }

    /**
     * El estado escrito como texto, como lo pide la spec (por ejemplo "422").
     * The status written as text, as the spec requires (for example "422").
     */
    public function status(): string {
      return (string) $this->status;
    }

    public function statusCode(): int {
      return $this->status;
    }

    public function title(): ?string {
      return $this->title;
    }

    public function detail(): ?string {
      return $this->detail;
    }

    public function code(): ?string {
      return $this->code;
    }

    public function id(): ?string {
      return $this->id;
    }

    /**
     * Devuelve un error nuevo con ese código; `null` lo quita. El original no cambia.
     * Returns a new error with that code; `null` removes it. The original does not change.
     */
    public function withCode(?string $code): self {
      return new self(
        $this->status,
        $this->title,
        $this->detail,
        $code,
        $this->id,
        $this->pointer,
        $this->parameter,
        $this->header,
        $this->meta,
      );
    }

    /**
     * Devuelve un error nuevo con ese identificador; `null` lo quita. El original no cambia.
     * Returns a new error with that identifier; `null` removes it. The original does not change.
     */
    public function withId(?string $id): self {
      return new self(
        $this->status,
        $this->title,
        $this->detail,
        $this->code,
        $id,
        $this->pointer,
        $this->parameter,
        $this->header,
        $this->meta,
      );
    }

    /**
     * Los miembros en el orden de la spec; los que no se dieron se omiten.
     * Members in the order of the spec; those not given are left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
      // El puntero raíz es el texto vacío y significa "todo el documento": por eso se compara con null y no con vacío.
      // The root pointer is the empty string and means "the whole document": that is why it is compared with null, not with empty.
      $source = array_filter([
        'pointer' => $this->pointer === null ? null : (string) $this->pointer,
        'parameter' => $this->parameter,
        'header' => $this->header,
      ], fn($value) => $value !== null);

      return array_filter([
        'id' => $this->id,
        'status' => $this->status(),
        'code' => $this->code,
        'title' => $this->title,
        'detail' => $this->detail,
        'source' => $source === [] ? null : $source,
        'meta' => $this->meta === [] ? null : $this->meta,
      ], fn($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
      return $this->toArray();
    }
  }
