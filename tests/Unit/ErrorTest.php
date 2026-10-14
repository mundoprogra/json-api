<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use InvalidArgumentException;
  use JsonSerializable;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Support\Pointer;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ErrorTest extends TestCase {
    use AssertsJsonApi;

    #[Test]
    public function a_minimal_error_only_has_a_status(): void {
      $this->assertSame(['status' => '422'], Error::make(status: 422)->toArray());
    }

    /**
     * @return array<string, array{int|string, string, int}>
     */
    public static function statuses(): array {
      // [estado recibido, estado escrito (texto), estado numérico] / [given status, written status (text), numeric status]
      return [
        'an integer' => [422, '422', 422],
        'a numeric string' => ['404', '404', 404],
        'the lowest client error' => [400, '400', 400],
        'the highest server error' => [599, '599', 599],
      ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function the_status_is_always_written_as_text_and_also_available_as_a_number(int|string $given, string $written, int $number): void {
      $error = Error::make(status: $given);

      $this->assertSame($written, $error->toArray()['status']);
      $this->assertSame($written, $error->status());
      $this->assertSame($number, $error->statusCode());
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function invalidStatuses(): array {
      return [
        'a success status' => [200],
        'a redirect status' => [302],
        'just below the errors' => [399],
        'just above the errors' => [600],
        'a very low number' => [99],
        'a success status as text' => ['200'],
        'text that is not a number' => ['abc'],
        'an empty text' => [''],
        'two digits' => ['42'],
        'four digits' => ['4220'],
        'a leading zero' => ['0422'],
        'a status class instead of a status' => ['4xx'],
      ];
    }

    #[Test]
    #[DataProvider('invalidStatuses')]
    public function rejects_a_status_that_is_not_a_client_or_server_error(int|string $status): void {
      $this->expectException(InvalidArgumentException::class);

      Error::make(status: $status);
    }

    #[Test]
    public function the_error_message_names_the_status_that_was_rejected(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('200');

      Error::make(status: 200);
    }

    #[Test]
    public function writes_every_member_in_the_order_of_the_specification(): void {
      $error = Error::make(
        status: 422,
        title: 'Validation failed',
        detail: 'The title is required.',
        code: 'mundoprogra.json-api.validation.required',
        id: '01JBX3F7K2',
        pointer: Pointer::from('data', 'attributes', 'title'),
        meta: ['rule' => 'required'],
      );

      $this->assertSame([
        'id' => '01JBX3F7K2',
        'status' => '422',
        'code' => 'mundoprogra.json-api.validation.required',
        'title' => 'Validation failed',
        'detail' => 'The title is required.',
        'source' => ['pointer' => '/data/attributes/title'],
        'meta' => ['rule' => 'required'],
      ], $error->toArray());
    }

    #[Test]
    public function leaves_out_the_members_that_were_not_given(): void {
      $error = Error::make(status: 400, title: 'Bad request', detail: null, code: null, id: null, meta: []);

      $this->assertSame(['status' => '400', 'title' => 'Bad request'], $error->toArray());
    }

    #[Test]
    public function accepts_a_pointer_written_as_text_and_validates_it(): void {
      $this->assertSame(
        ['status' => '422', 'source' => ['pointer' => '/data/attributes/a~1b']],
        Error::make(status: 422, pointer: '/data/attributes/a~1b')->toArray()
      );

      $this->expectException(InvalidArgumentException::class);

      Error::make(status: 422, pointer: 'data/attributes');
    }

    #[Test]
    public function keeps_the_pointer_to_the_whole_document_even_though_it_is_an_empty_text(): void {
      // El puntero raíz es el texto vacío y significa "todo el documento": no debe tratarse como si faltara.
      // The root pointer is the empty string and means "the whole document": it must not be treated as missing.
      $error = Error::make(status: 400, pointer: Pointer::root());

      $this->assertSame(['status' => '400', 'source' => ['pointer' => '']], $error->toArray());
    }

    #[Test]
    public function describes_where_the_problem_is_with_a_parameter_or_a_header(): void {
      $this->assertSame(
        ['status' => '400', 'source' => ['parameter' => 'page[size]']],
        Error::make(status: 400, parameter: 'page[size]')->toArray()
      );
      $this->assertSame(
        ['status' => '406', 'source' => ['header' => 'Accept']],
        Error::make(status: 406, header: 'Accept')->toArray()
      );
    }

    #[Test]
    public function writes_the_source_members_in_a_fixed_order_when_several_are_given(): void {
      $error = Error::make(status: 400, header: 'Accept', parameter: 'sort', pointer: '/data');

      $this->assertSame(['pointer' => '/data', 'parameter' => 'sort', 'header' => 'Accept'], $error->toArray()['source']);
    }

    #[Test]
    public function keeps_nested_values_in_meta(): void {
      $meta = ['rule' => 'max', 'limits' => ['min' => 1, 'max' => 10], 'tags' => ['a', 'b']];

      $this->assertSame($meta, Error::make(status: 422, meta: $meta)->toArray()['meta']);
    }

    #[Test]
    public function rejects_a_meta_that_is_a_list_because_it_must_be_an_object(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('meta');

      Error::make(status: 422, meta: [1, 2]);
    }

    #[Test]
    public function changing_the_code_returns_a_new_error_and_leaves_the_original_untouched(): void {
      $original = Error::make(status: 404, code: 'original');

      $changed = $original->withCode('changed');

      $this->assertSame('original', $original->toArray()['code']);
      $this->assertSame('changed', $changed->toArray()['code']);
      $this->assertNotSame($original, $changed);
    }

    #[Test]
    public function a_null_code_removes_the_code(): void {
      $this->assertSame(['status' => '404'], Error::make(status: 404, code: 'x')->withCode(null)->toArray());
    }

    #[Test]
    public function changing_the_id_returns_a_new_error_and_a_null_id_removes_it(): void {
      $original = Error::make(status: 500);

      $withId = $original->withId('01JBX3F7K2');

      $this->assertArrayNotHasKey('id', $original->toArray());
      $this->assertSame('01JBX3F7K2', $withId->toArray()['id']);
      $this->assertArrayNotHasKey('id', $withId->withId(null)->toArray());
    }

    #[Test]
    public function exposes_its_members_through_accessors(): void {
      $error = Error::make(status: 422, title: 'T', detail: 'D', code: 'C', id: 'I');

      $this->assertSame(['T', 'D', 'C', 'I'], [$error->title(), $error->detail(), $error->code(), $error->id()]);
      $this->assertNull(Error::make(status: 500)->code());
    }

    #[Test]
    public function is_written_as_json_with_the_same_content_as_toArray(): void {
      $error = Error::make(status: 422, title: 'T', pointer: Pointer::root(), meta: ['k' => 'v']);

      $this->assertInstanceOf(JsonSerializable::class, $error);
      $this->assertSame(
        '{"status":"422","title":"T","source":{"pointer":""},"meta":{"k":"v"}}',
        json_encode($error)
      );
    }

    #[Test]
    public function a_full_error_is_a_valid_json_api_error_object(): void {
      $errors = [
        Error::make(status: 422, title: 'Validation failed', detail: 'Required.', code: 'c', id: 'i', pointer: '/data/attributes/title', meta: ['a' => 1]),
        Error::make(status: 400, parameter: 'page[size]'),
        Error::make(status: 406, header: 'Accept'),
        Error::make(status: 400, pointer: Pointer::root()),
        Error::make(status: 500),
      ];

      $this->assertJsonApiDocument(['errors' => array_map(fn(Error $error) => $error->toArray(), $errors)]);
    }
  }
