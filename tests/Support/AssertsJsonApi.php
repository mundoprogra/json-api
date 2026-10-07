<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Opis\JsonSchema\Errors\ErrorFormatter;
  use Opis\JsonSchema\Errors\ValidationError;
  use Opis\JsonSchema\Validator;

  /**
   * Valida un documento contra el JSON Schema oficial de JSON:API (versión 1.0).
   * El schema solo cubre respuestas; los puntos propios de 1.1 se comprueban con asserts adicionales.
   */
  trait AssertsJsonApi {
    protected function assertJsonApiDocument(array|string $document, string $message = ''): void {
      $data = json_decode(is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

      $result = (new Validator())->validate($data, $this->jsonApiSchema());

      $this->assertTrue(
        $result->isValid(),
        $result->isValid() ? '' : $this->describeSchemaErrors($result->error(), $message)
      );
    }

    private function describeSchemaErrors(ValidationError $error, string $message): string {
      return ($message !== '' ? $message . "\n" : '')
        . "The document is not a valid JSON:API document:\n"
        . json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function jsonApiSchema(): object {
      static $schema = null;

      return $schema ??= json_decode(
        file_get_contents(__DIR__ . '/../Fixtures/schema/jsonapi-1.0.json'),
        false,
        512,
        JSON_THROW_ON_ERROR
      );
    }
  }
