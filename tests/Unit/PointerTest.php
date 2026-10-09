<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use InvalidArgumentException;
  use MundoProgra\JsonApi\Support\Pointer;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  use Stringable;

  class PointerTest extends TestCase {
    #[Test]
    public function the_root_pointer_is_the_empty_string_and_has_no_segments(): void {
      $root = Pointer::root();

      $this->assertSame('', (string) $root);
      $this->assertSame([], $root->segments());
    }

    #[Test]
    public function builds_a_pointer_from_segments(): void {
      $pointer = Pointer::from('data', 'attributes', 'title');

      $this->assertSame('/data/attributes/title', (string) $pointer);
    }

    #[Test]
    public function accepts_integers_as_array_positions_and_keeps_every_segment_as_text(): void {
      $pointer = Pointer::from('data', 'relationships', 'tags', 'data', 0);

      $this->assertSame('/data/relationships/tags/data/0', (string) $pointer);
      $this->assertSame(['data', 'relationships', 'tags', 'data', '0'], $pointer->segments());
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function escaping(): array {
      // [segmentos, puntero esperado] según RFC 6901: "~" se escribe "~0" y "/" se escribe "~1".
      // [segments, expected pointer] per RFC 6901: "~" is written "~0" and "/" is written "~1".
      return [
        'a slash' => [['data', 'attributes', 'a/b'], '/data/attributes/a~1b'],
        'a tilde' => [['data', 'attributes', 'a~b'], '/data/attributes/a~0b'],
        'a tilde followed by a slash' => [['a~/b'], '/a~0~1b'],
        'a literal ~1 written by the user' => [['~1'], '/~01'],
        'a literal ~0 written by the user' => [['~0'], '/~00'],
        'an empty segment' => [[''], '/'],
        'a segment with spaces and accents' => [['first name', 'ñandú'], '/first name/ñandú'],
      ];
    }

    /**
     * @param  list<string>  $segments
     */
    #[Test]
    #[DataProvider('escaping')]
    public function escapes_tilde_and_slash_inside_a_segment(array $segments, string $expected): void {
      $this->assertSame($expected, (string) Pointer::from(...$segments));
    }

    #[Test]
    #[DataProvider('escaping')]
    public function parsing_the_text_gives_back_the_original_segments(array $segments, string $text): void {
      $this->assertSame($segments, Pointer::parse($text)->segments());
    }

    #[Test]
    public function parsing_the_empty_string_gives_the_root_pointer(): void {
      $this->assertSame([], Pointer::parse('')->segments());
      $this->assertSame('', (string) Pointer::parse(''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPointers(): array {
      return [
        'does not start with a slash' => ['data/attributes'],
        'a tilde followed by another digit' => ['/a~2b'],
        'a tilde at the end' => ['/a~'],
        'a tilde followed by a letter' => ['/a~x'],
      ];
    }

    #[Test]
    #[DataProvider('invalidPointers')]
    public function rejects_text_that_is_not_a_valid_json_pointer(string $text): void {
      $this->expectException(InvalidArgumentException::class);

      Pointer::parse($text);
    }

    #[Test]
    public function appending_returns_a_new_pointer_and_leaves_the_original_untouched(): void {
      $data = Pointer::from('data');

      $title = $data->append('attributes', 'title');

      $this->assertSame('/data', (string) $data);
      $this->assertSame('/data/attributes/title', (string) $title);
      $this->assertNotSame($data, $title);
    }

    #[Test]
    public function appending_to_the_root_pointer_works(): void {
      $this->assertSame('/data', (string) Pointer::root()->append('data'));
    }

    #[Test]
    public function appending_escapes_the_new_segments_too(): void {
      $this->assertSame('/data/a~1b', (string) Pointer::from('data')->append('a/b'));
    }

    #[Test]
    public function can_be_used_wherever_a_string_is_expected(): void {
      $this->assertInstanceOf(Stringable::class, Pointer::from('data'));
    }
  }
