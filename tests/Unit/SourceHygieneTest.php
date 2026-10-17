<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use FilesystemIterator;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  use RecursiveDirectoryIterator;
  use RecursiveIteratorIterator;

  class SourceHygieneTest extends TestCase {
    private const FORBIDDEN = ['dd', 'dump', 'ray', 'var_dump', 'print_r'];

    #[Test]
    public function src_has_no_debug_calls(): void {
      $found = [];
      $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)
      );

      foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
          continue;
        }

        foreach ($this->findDebugCalls(file_get_contents($file->getPathname())) as $call) {
          $found[] = $file->getPathname() . ': ' . $call . '()';
        }
      }

      $this->assertSame([], $found);
    }

    #[Test]
    public function the_detector_finds_debug_calls_and_ignores_methods_with_the_same_name(): void {
      $code = '<?php dd($a); $this->dump($b); Foo::ray($c); function dump() {} var_dump($d);';

      $this->assertSame(['dd', 'var_dump'], $this->findDebugCalls($code));
    }

    /**
     * @return list<string>
     */
    private function findDebugCalls(string $code): array {
      $tokens = array_values(array_filter(
        token_get_all($code),
        fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
      ));
      $calls = [];

      foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::FORBIDDEN, true)) {
          continue;
        }

        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $isMethodOrDefinition = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

        if ($next === '(' && !$isMethodOrDefinition) {
          $calls[] = strtolower($token[1]);
        }
      }

      return $calls;
    }

    #[Test]
    public function the_json_api_media_type_and_version_are_written_in_one_place(): void {
      // El texto `application/vnd.api+json` y la versión `1.1` viven en MediaType; el resto del código usa esas constantes.
      // The `application/vnd.api+json` text and the `1.1` version live in MediaType; the rest of the code uses those constants.
      $found = [];
      $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)
      );

      foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Support' . DIRECTORY_SEPARATOR . 'MediaType.php')) {
          continue;
        }

        foreach ($this->findStringLiterals(file_get_contents($file->getPathname())) as $literal) {
          if (in_array($literal, ['application/vnd.api+json', '1.1'], true)) {
            $found[] = $file->getPathname() . ": '$literal'";
          }
        }
      }

      $this->assertSame([], $found);
    }

    #[Test]
    public function the_literal_detector_finds_strings_and_ignores_comments(): void {
      $code = "<?php // 'application/vnd.api+json'\n\$a = 'application/vnd.api+json'; \$b = \"1.1\"; /* '1.1' */";

      $this->assertSame(['application/vnd.api+json', '1.1'], $this->findStringLiterals($code));
    }

    /**
     * Los textos entre comillas del código, sin los comentarios.
     * The quoted strings of the code, without the comments.
     *
     * @return list<string>
     */
    private function findStringLiterals(string $code): array {
      $literals = [];

      foreach (token_get_all($code) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literals[] = substr($token[1], 1, -1);
        }
      }

      return $literals;
    }
  }
