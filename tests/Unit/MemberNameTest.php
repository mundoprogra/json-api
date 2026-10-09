<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use InvalidArgumentException;
  use MundoProgra\JsonApi\Support\MemberName;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class MemberNameTest extends TestCase {
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function names(): array {
      // [estilo configurado, nombre como lo escribe el cliente, nombre en snake_case para Eloquent]
      // [configured style, name as the client writes it, snake_case name for Eloquent]
      return [
        'single word camel' => ['camel', 'name', 'name'],
        'two words camel' => ['camel', 'firstName', 'first_name'],
        'three words camel' => ['camel', 'homeAddressLine', 'home_address_line'],
        'digit inside camel' => ['camel', 'address2Line', 'address2_line'],
        'digit at the end camel' => ['camel', 'address2', 'address2'],
        'single word kebab' => ['kebab', 'name', 'name'],
        'two words kebab' => ['kebab', 'first-name', 'first_name'],
        'three words kebab' => ['kebab', 'home-address-line', 'home_address_line'],
        'digit inside kebab' => ['kebab', 'address2-line', 'address2_line'],
        'single word snake' => ['snake', 'name', 'name'],
        'two words snake' => ['snake', 'first_name', 'first_name'],
        'three words snake' => ['snake', 'home_address_line', 'home_address_line'],
      ];
    }

    #[Test]
    #[DataProvider('names')]
    public function converts_the_client_name_to_snake_case(string $case, string $member, string $snake): void {
      $this->assertSame($snake, MemberName::for($case)->toSnake($member));
    }

    #[Test]
    #[DataProvider('names')]
    public function converts_snake_case_back_to_the_client_style(string $case, string $member, string $snake): void {
      $this->assertSame($member, MemberName::for($case)->fromSnake($snake));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acronyms(): array {
      return [
        'an acronym at the end' => ['userID', 'user_id'],
        'an acronym at the start' => ['HTMLParser', 'html_parser'],
        'an acronym in the middle' => ['getURLValue', 'get_url_value'],
        'only an acronym' => ['ID', 'id'],
        'an acronym after a word' => ['parseXML', 'parse_xml'],
      ];
    }

    #[Test]
    #[DataProvider('acronyms')]
    public function treats_a_run_of_capitals_as_one_word_when_going_to_snake_case(string $member, string $snake): void {
      $this->assertSame($snake, MemberName::for('camel')->toSnake($member));
    }

    #[Test]
    public function writes_acronyms_as_ordinary_words_when_coming_back_from_snake_case(): void {
      // Se pierde la distinción: user_id vuelve como userId, no como userID. Es una limitación conocida y documentada.
      // The distinction is lost: user_id comes back as userId, not userID. It is a known, documented limitation.
      $this->assertSame('userId', MemberName::for('camel')->fromSnake('user_id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cases(): array {
      return [
        'camel' => ['camel'],
        'kebab' => ['kebab'],
        'snake' => ['snake'],
      ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function leaves_a_name_that_is_already_in_snake_case_untouched_when_converting_to_snake_case(string $case): void {
      $this->assertSame('first_name', MemberName::for($case)->toSnake('first_name'));
    }

    #[Test]
    #[DataProvider('cases')]
    public function converts_an_empty_name_into_an_empty_name(string $case): void {
      $this->assertSame('', MemberName::for($case)->toSnake(''));
      $this->assertSame('', MemberName::for($case)->fromSnake(''));
    }

    #[Test]
    #[DataProvider('cases')]
    public function reports_the_style_it_was_created_with(string $case): void {
      $this->assertSame($case, MemberName::for($case)->case());
    }

    #[Test]
    public function rejects_an_unknown_style_and_names_it(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('pascal');

      MemberName::for('pascal');
    }

    #[Test]
    public function converts_only_the_first_level_of_keys_to_snake_case(): void {
      // Los valores anidados son datos del cliente (por ejemplo, una columna JSON): sus claves no se tocan.
      // Nested values are client data (for example, a JSON column): their keys are not touched.
      $converted = MemberName::for('camel')->keysToSnake([
        'firstName' => 'Ada',
        'homeAddress' => ['streetName' => 'Main', 'zipCode' => '01000'],
        'tags' => ['a', 'b'],
      ]);

      $this->assertSame([
        'first_name' => 'Ada',
        'home_address' => ['streetName' => 'Main', 'zipCode' => '01000'],
        'tags' => ['a', 'b'],
      ], $converted);
    }

    #[Test]
    public function converts_only_the_first_level_of_keys_from_snake_case(): void {
      $converted = MemberName::for('camel')->keysFromSnake([
        'first_name' => 'Ada',
        'home_address' => ['street_name' => 'Main'],
      ]);

      $this->assertSame([
        'firstName' => 'Ada',
        'homeAddress' => ['street_name' => 'Main'],
      ], $converted);
    }

    #[Test]
    public function leaves_the_numeric_keys_of_a_list_alone(): void {
      $this->assertSame(['x', 'y'], MemberName::for('camel')->keysToSnake(['x', 'y']));
      $this->assertSame(['x', 'y'], MemberName::for('camel')->keysFromSnake(['x', 'y']));
    }

    #[Test]
    public function rejects_two_keys_that_end_up_with_the_same_snake_case_name(): void {
      // Sin esta comprobación, uno de los dos valores se perdería en silencio.
      // Without this check, one of the two values would be silently lost.
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('first_name');

      MemberName::for('camel')->keysToSnake(['firstName' => 1, 'first_name' => 2]);
    }

    #[Test]
    public function rejects_two_keys_that_end_up_with_the_same_client_name(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('firstName');

      MemberName::for('camel')->keysFromSnake(['first_name' => 1, 'firstName' => 2]);
    }
  }
