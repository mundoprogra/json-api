<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Illuminate\Support\Arr;

  /**
   * Lee un texto de los archivos de idioma del paquete (`lang/en/errors.php`, `lang/es/errors.php`) por su clave con puntos, sin pasar por el traductor.
   * Así una prueba compara la respuesta con el texto escrito en el archivo, no con una copia que tendría que mantener al día.
   *
   * Reads a text from the package language files (`lang/en/errors.php`, `lang/es/errors.php`) by its dotted key, without going through the translator.
   * That way a test compares the response with the text written in the file, not with a copy it would have to keep up to date.
   */
  trait ReadsPackageTexts {
    private function english(string $key): string {
      return Arr::get(require __DIR__ . '/../../lang/en/errors.php', $key);
    }

    private function spanish(string $key): string {
      return Arr::get(require __DIR__ . '/../../lang/es/errors.php', $key);
    }
  }
