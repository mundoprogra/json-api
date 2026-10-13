<?php

  namespace MundoProgra\JsonApi\Support;

  /**
   * Por qué se rechazó una cabecera de negociación: el estado HTTP, la cabecera y un motivo estable que no se traduce.
   * Why a negotiation header was rejected: the HTTP status, the header and a stable reason that is not translated.
   *
   * Motivos / Reasons: missing, malformed, not_json_api, unsupported_parameter, unsupported_extension, refused.
   */
  final class NegotiationFailure {
    public function __construct(
      private readonly int $status,
      private readonly string $header,
      private readonly string $reason,
    ) {
    }

    public function status(): int {
      return $this->status;
    }

    public function header(): string {
      return $this->header;
    }

    public function reason(): string {
      return $this->reason;
    }
  }
