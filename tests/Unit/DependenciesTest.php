<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use FilesystemIterator;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  use RecursiveDirectoryIterator;
  use RecursiveIteratorIterator;

  /**
   * Cada paquete `illuminate/*` cuyas clases usa el código de `src/` está declarado en `composer.json`. Una app tiene el framework completo y
   * por eso no se notaría, pero el paquete es público: quien lo instale solo con sus dependencias declaradas debe tener todo lo que usa.
   * Every `illuminate/*` package whose classes the `src/` code uses is declared in `composer.json`. An app has the whole framework so it would
   * go unnoticed, but the package is public: whoever installs it with only its declared dependencies must have everything it uses.
   */
  class DependenciesTest extends TestCase {
    #[Test]
    public function every_illuminate_package_the_source_uses_is_declared_in_composer_json(): void {
      $declared = array_keys((array) (json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true)['require'] ?? []));
      $missing = [];

      foreach ($this->usedNamespaces() as $namespace => $file) {
        // `Illuminate\Foundation` no es un paquete aparte: solo existe en laravel/framework, que el paquete no puede exigir.
        // `Illuminate\Foundation` is not a package of its own: it only exists in laravel/framework, which the package cannot require.
        $package = 'illuminate/' . strtolower($namespace);

        if (!in_array($package, $declared, true)) {
          $missing[] = "$package (used in $file)";
        }
      }

      $this->assertSame([], $missing);
    }

    #[Test]
    public function the_scanner_finds_the_namespaces_of_the_use_lines(): void {
      $this->assertSame(['Auth', 'Http'], $this->namespacesIn("<?php\n  use Illuminate\\Auth\\AuthenticationException;\n  use Illuminate\\Http\\Request;\n  use Other\\Thing;\n"));
    }

    /**
     * @return array<string, string> el primer archivo donde aparece cada espacio de nombres / the first file where each namespace shows up
     */
    private function usedNamespaces(): array {
      $used = [];
      $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS));

      foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
          continue;
        }

        foreach ($this->namespacesIn((string) file_get_contents($file->getPathname())) as $namespace) {
          $used[$namespace] ??= basename($file->getPathname());
        }
      }

      ksort($used);

      return $used;
    }

    /**
     * @return list<string>
     */
    private function namespacesIn(string $code): array {
      $namespaces = [];

      foreach (explode("\n", $code) as $line) {
        $line = trim($line);

        if (str_starts_with($line, 'use Illuminate\\')) {
          $namespaces[] = explode('\\', $line)[1];
        }
      }

      return array_values(array_unique($namespaces));
    }
  }
