<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use InvalidArgumentException;
  use MundoProgra\JsonApi\Support\MediaType;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  use Stringable;

  class MediaTypeTest extends TestCase {
    #[Test]
    public function reads_the_type_the_subtype_and_the_essence(): void {
      $mediaType = MediaType::parse('application/vnd.api+json');

      $this->assertSame('application', $mediaType->type());
      $this->assertSame('vnd.api+json', $mediaType->subtype());
      $this->assertSame('application/vnd.api+json', $mediaType->essence());
      $this->assertSame([], $mediaType->parameters());
    }

    #[Test]
    public function lowercases_the_type_the_subtype_and_the_parameter_names_but_keeps_the_values_as_written(): void {
      $mediaType = MediaType::parse('Application/VND.API+JSON; Charset=UTF-8');

      $this->assertSame('application/vnd.api+json', $mediaType->essence());
      $this->assertSame(['charset' => 'UTF-8'], $mediaType->parameters());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spacingAroundTheSemicolon(): array {
      return [
        'no spaces' => ['application/json;charset=utf-8'],
        'a space before' => ['application/json ;charset=utf-8'],
        'a space after' => ['application/json; charset=utf-8'],
        'spaces on both sides' => ['application/json ; charset=utf-8'],
        'several spaces' => ['application/json   ;    charset=utf-8'],
        'a tab' => ["application/json;\tcharset=utf-8"],
      ];
    }

    #[Test]
    #[DataProvider('spacingAroundTheSemicolon')]
    public function accepts_optional_whitespace_around_the_semicolon(string $value): void {
      $this->assertSame(['charset' => 'utf-8'], MediaType::parse($value)->parameters());
    }

    #[Test]
    public function reads_quoted_parameter_values(): void {
      $this->assertSame('hello world', MediaType::parse('text/plain; title="hello world"')->parameter('title'));
    }

    #[Test]
    public function unescapes_a_quote_inside_a_quoted_value(): void {
      $this->assertSame('say "hi"', MediaType::parse('text/plain; title="say \"hi\""')->parameter('title'));
    }

    #[Test]
    public function does_not_split_on_a_semicolon_inside_quotes(): void {
      $mediaType = MediaType::parse('text/plain; first="1;2"; second=3');

      $this->assertSame(['first' => '1;2', 'second' => '3'], $mediaType->parameters());
    }

    #[Test]
    public function looks_up_a_parameter_ignoring_the_case_of_its_name(): void {
      $mediaType = MediaType::parse('text/plain; Charset=utf-8');

      $this->assertSame('utf-8', $mediaType->parameter('charset'));
      $this->assertSame('utf-8', $mediaType->parameter('CHARSET'));
      $this->assertNull($mediaType->parameter('missing'));
    }

    #[Test]
    public function keeps_the_parameters_in_the_order_they_were_written(): void {
      $mediaType = MediaType::parse('text/plain; b=2; a=1; c=3');

      $this->assertSame(['b', 'a', 'c'], array_keys($mediaType->parameters()));
    }

    #[Test]
    public function reads_the_ext_and_profile_lists_as_separate_uris(): void {
      $mediaType = MediaType::parse(
        'application/vnd.api+json; ext="https://example.com/a https://example.com/b"; profile="https://example.com/p"'
      );

      $this->assertSame(['https://example.com/a', 'https://example.com/b'], $mediaType->extensions());
      $this->assertSame(['https://example.com/p'], $mediaType->profiles());
    }

    #[Test]
    public function the_ext_and_profile_lists_are_empty_when_the_parameters_are_missing_or_empty(): void {
      $this->assertSame([], MediaType::parse('application/vnd.api+json')->extensions());
      $this->assertSame([], MediaType::parse('application/vnd.api+json')->profiles());
      $this->assertSame([], MediaType::parse('application/vnd.api+json; ext=""')->extensions());
    }

    #[Test]
    public function ignores_extra_spaces_between_the_uris_of_a_list(): void {
      $mediaType = MediaType::parse('application/vnd.api+json; ext="  https://a.test/x    https://b.test/y  "');

      $this->assertSame(['https://a.test/x', 'https://b.test/y'], $mediaType->extensions());
    }

    #[Test]
    public function reads_media_ranges_with_wildcards(): void {
      $any = MediaType::parse('*/*');
      $anyApplication = MediaType::parse('application/*');

      $this->assertSame(['*', '*'], [$any->type(), $any->subtype()]);
      $this->assertSame(['application', '*'], [$anyApplication->type(), $anyApplication->subtype()]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jsonApiDetection(): array {
      return [
        'the json:api media type' => ['application/vnd.api+json', true],
        'in uppercase' => ['APPLICATION/VND.API+JSON', true],
        'with parameters' => ['application/vnd.api+json; profile="https://example.com/p"', true],
        'plain json' => ['application/json', false],
        'another suffix' => ['application/vnd.api+xml', false],
        'any media type' => ['*/*', false],
        'any application media type' => ['application/*', false],
      ];
    }

    #[Test]
    #[DataProvider('jsonApiDetection')]
    public function detects_whether_it_is_the_json_api_media_type(string $value, bool $expected): void {
      $this->assertSame($expected, MediaType::parse($value)->isJsonApi());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array {
      return [
        'empty' => [''],
        'only blanks' => ['   '],
        'no subtype separator' => ['application'],
        'empty type' => ['/json'],
        'empty subtype' => ['application/'],
        'more than one slash' => ['application/vnd/api'],
        'whitespace inside the type' => ['application /json'],
        'a parameter without value' => ['application/json; charset'],
        'a parameter without name' => ['application/json; =utf-8'],
        'an unterminated quote' => ['application/json; title="open'],
        'a repeated parameter' => ['application/json; a=1; a=2'],
        'a repeated parameter with another case' => ['application/json; a=1; A=2'],
        'a list of media types' => ['application/json, text/html'],
        'an invalid character in the type' => ['app@lication/json'],
        'an invalid character in the subtype' => ['application/js(on'],
        'an invalid character in a parameter name' => ['application/json; na@me=1'],
        'a space inside a parameter name' => ['application/json; na me=1'],
        // Un valor sin comillas solo puede tener caracteres de "token" (RFC 7230); cualquier otro exige comillas.
        // An unquoted value may only have "token" characters (RFC 7230); anything else requires quotes.
        'an unquoted uri' => ['application/vnd.api+json; ext=https://example.com/a'],
        'an unquoted value with a space' => ['text/plain; title=hello world'],
        'an unquoted value with a comma' => ['text/plain; list=1,2'],
        'an unquoted value with an at sign' => ['text/plain; mail=a@b'],
        'an empty unquoted value' => ['text/plain; title='],
      ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tokenValues(): array {
      return [
        'letters, digits and a hyphen' => ['utf-8'],
        'dots' => ['abc.def'],
        'underscores and pluses' => ['a_b+c'],
        'every symbol a token allows' => ["!#$%&'*+-.^_`|~09AZaz"],
      ];
    }

    #[Test]
    #[DataProvider('tokenValues')]
    public function accepts_unquoted_values_made_only_of_token_characters(string $value): void {
      $this->assertSame($value, MediaType::parse("text/plain; title=$value")->parameter('title'));
    }

    #[Test]
    public function accepts_an_empty_quoted_value(): void {
      $this->assertSame('', MediaType::parse('text/plain; title=""')->parameter('title'));
    }

    #[Test]
    #[DataProvider('invalid')]
    public function rejects_text_that_is_not_a_single_media_type(string $value): void {
      $this->expectException(InvalidArgumentException::class);

      MediaType::parse($value);
    }

    #[Test]
    public function the_error_message_includes_the_text_that_could_not_be_read(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('application/json; charset');

      MediaType::parse('application/json; charset');
    }

    #[Test]
    public function two_media_types_are_equal_when_they_have_the_same_essence_and_parameters(): void {
      $this->assertTrue(
        MediaType::parse('application/vnd.api+json; charset=utf-8')
          ->equals(MediaType::parse('Application/Vnd.Api+Json;CHARSET=utf-8'))
      );
    }

    #[Test]
    public function the_order_of_the_parameters_does_not_matter_when_comparing(): void {
      $this->assertTrue(MediaType::parse('a/b; x=1; y=2')->equals(MediaType::parse('a/b; y=2; x=1')));
    }

    #[Test]
    public function the_order_of_the_uris_inside_ext_and_profile_does_not_matter_when_comparing(): void {
      $this->assertTrue(
        MediaType::parse('application/vnd.api+json; profile="https://a.test/1 https://b.test/2"')
          ->equals(MediaType::parse('application/vnd.api+json; profile="https://b.test/2 https://a.test/1"'))
      );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function different(): array {
      return [
        'another subtype' => ['application/json', 'application/vnd.api+json'],
        'another parameter value' => ['a/b; x=1', 'a/b; x=2'],
        'an extra parameter' => ['a/b; x=1', 'a/b; x=1; y=2'],
        'a missing parameter' => ['a/b; x=1; y=2', 'a/b; x=1'],
        'different uris' => [
          'application/vnd.api+json; ext="https://a.test/1"',
          'application/vnd.api+json; ext="https://a.test/2"',
        ],
        'a different amount of uris' => [
          'application/vnd.api+json; ext="https://a.test/1"',
          'application/vnd.api+json; ext="https://a.test/1 https://a.test/2"',
        ],
      ];
    }

    #[Test]
    #[DataProvider('different')]
    public function media_types_that_differ_are_not_equal(string $first, string $second): void {
      $this->assertFalse(MediaType::parse($first)->equals(MediaType::parse($second)));
    }

    #[Test]
    public function is_written_back_in_a_canonical_form(): void {
      $mediaType = MediaType::parse('Application/VND.API+JSON ;  PROFILE="https://example.com/p"; Charset=utf-8');

      $this->assertSame(
        'application/vnd.api+json; profile="https://example.com/p"; charset=utf-8',
        (string) $mediaType
      );
    }

    #[Test]
    public function always_quotes_ext_and_profile_and_quotes_any_other_value_that_needs_it(): void {
      $this->assertSame(
        'text/plain; ext="x"; title="hello world"; plain=value',
        (string) MediaType::parse('text/plain; ext=x; title="hello world"; plain=value')
      );
    }

    #[Test]
    public function escapes_a_quote_when_writing_a_value_back(): void {
      $this->assertSame('text/plain; title="say \"hi\""', (string) MediaType::parse('text/plain; title="say \"hi\""'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTrips(): array {
      return [
        'a plain media type' => ['application/vnd.api+json'],
        'with a charset' => ['application/json; charset=utf-8'],
        'with ext and profile' => ['application/vnd.api+json; ext="https://a.test/x https://b.test/y"; profile="https://c.test/p"'],
        'with a quoted value' => ['text/plain; title="hello; world"'],
        'a wildcard' => ['*/*'],
      ];
    }

    #[Test]
    #[DataProvider('roundTrips')]
    public function reading_the_written_text_gives_back_an_equal_media_type(string $value): void {
      $original = MediaType::parse($value);

      $this->assertTrue(MediaType::parse((string) $original)->equals($original));
    }

    #[Test]
    public function can_be_used_wherever_a_string_is_expected(): void {
      $this->assertInstanceOf(Stringable::class, MediaType::parse('application/json'));
    }

    #[Test]
    public function it_is_the_single_source_of_the_json_api_media_type_and_version(): void {
      $this->assertSame('application/vnd.api+json', MediaType::JSON_API);
      $this->assertSame('1.1', MediaType::JSON_API_VERSION);
      $this->assertTrue(MediaType::parse(MediaType::JSON_API)->isJsonApi());
    }
  }
