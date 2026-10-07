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
  }
