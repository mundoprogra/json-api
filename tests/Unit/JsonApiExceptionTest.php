<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Exception;
  use InvalidArgumentException;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorDocument;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Support\Pointer;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\PaymentDeclined;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  use RuntimeException;
  use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

  class JsonApiExceptionTest extends TestCase {
    #[Test]
    public function make_creates_an_exception_that_holds_one_error_with_the_given_members(): void {
      $exception = JsonApiException::make(
        status: 402,
        title: 'Payment declined',
        detail: 'The card was declined.',
        code: 'PAYMENT-DECLINED',
        pointer: '/data/attributes/card',
        meta: ['attempt' => 2],
      );

      $this->assertCount(1, $exception->errors());
      $this->assertSame([
        'status' => '402',
        'code' => 'PAYMENT-DECLINED',
        'title' => 'Payment declined',
        'detail' => 'The card was declined.',
        'source' => ['pointer' => '/data/attributes/card'],
        'meta' => ['attempt' => 2],
      ], $exception->errors()[0]->toArray());
    }

    #[Test]
    public function it_is_a_throwable_exception(): void {
      $this->assertInstanceOf(Exception::class, JsonApiException::make(status: 404));
    }

    #[Test]
    public function can_hold_several_errors_and_keeps_their_order(): void {
      $first = Error::make(status: 422, detail: 'first', pointer: Pointer::from('data', 'attributes', 'a'));
      $second = Error::make(status: 422, detail: 'second', pointer: Pointer::from('data', 'attributes', 'b'));

      $exception = new JsonApiException([$first, $second]);

      $this->assertSame([$first, $second], $exception->errors());
    }

    #[Test]
    public function rejects_an_empty_list_of_errors(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('at least one');

      new JsonApiException([]);
    }

    /**
     * @return array<string, array{list<int>, int}>
     */
    public static function httpStatuses(): array {
      // La misma regla que ErrorDocument: [estados de los errores, estado HTTP] / The same rule as ErrorDocument: [error statuses, HTTP status]
      return [
        'a single error' => [[404], 404],
        'the same status repeated' => [[422, 422], 422],
        'different client statuses' => [[403, 404], 400],
        'a server status among client statuses' => [[404, 503], 500],
      ];
    }

    /**
     * @param  list<int>  $statuses
     */
    #[Test]
    #[DataProvider('httpStatuses')]
    public function the_http_status_follows_the_most_generally_applicable_rule(array $statuses, int $expected): void {
      $exception = new JsonApiException(array_map(fn(int $status) => Error::make(status: $status), $statuses));

      $this->assertSame($expected, $exception->httpStatus());
      $this->assertSame($expected, $exception->getStatusCode());
      $this->assertSame($expected, $exception->getCode());
    }

    #[Test]
    public function the_message_is_the_detail_of_the_first_error(): void {
      $exception = JsonApiException::make(status: 404, title: 'Not found', detail: 'The user does not exist.');

      $this->assertSame('The user does not exist.', $exception->getMessage());
    }

    #[Test]
    public function the_message_falls_back_to_the_title_and_then_to_the_status(): void {
      $this->assertSame('Not found', JsonApiException::make(status: 404, title: 'Not found')->getMessage());
      $this->assertStringContainsString('HTTP 404', JsonApiException::make(status: 404)->getMessage());
    }

    #[Test]
    public function the_message_mentions_how_many_more_errors_there_are(): void {
      $exception = new JsonApiException([
        Error::make(status: 422, detail: 'first problem'),
        Error::make(status: 422, detail: 'second problem'),
        Error::make(status: 422, detail: 'third problem'),
      ]);

      $this->assertSame('first problem (and 2 more)', $exception->getMessage());
    }

    #[Test]
    public function keeps_the_extra_headers_for_the_response(): void {
      $exception = JsonApiException::make(status: 429, headers: ['Retry-After' => '60']);

      $this->assertSame(['Retry-After' => '60'], $exception->headers());
      $this->assertSame(['Retry-After' => '60'], $exception->getHeaders());
    }

    #[Test]
    public function has_no_extra_headers_by_default(): void {
      $this->assertSame([], JsonApiException::make(status: 404)->headers());
    }

    #[Test]
    public function keeps_the_exception_that_caused_it(): void {
      $cause = new RuntimeException('database is down');

      $exception = JsonApiException::make(status: 503, previous: $cause);

      $this->assertSame($cause, $exception->getPrevious());
    }

    #[Test]
    public function laravel_can_treat_it_as_an_http_exception_even_when_the_package_does_not_render_it(): void {
      $exception = JsonApiException::make(status: 403, headers: ['X-Reason' => 'policy']);

      $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
      $this->assertSame(403, $exception->getStatusCode());
      $this->assertSame(['X-Reason' => 'policy'], $exception->getHeaders());
    }

    #[Test]
    public function builds_the_error_document_with_the_same_errors(): void {
      $exception = JsonApiException::make(status: 404, title: 'Not found');

      $document = $exception->toDocument();

      $this->assertInstanceOf(ErrorDocument::class, $document);
      $this->assertSame($exception->errors(), $document->errors());
      $this->assertSame('1.1', $document->toArray()['jsonapi']['version']);
    }

    #[Test]
    public function the_document_can_leave_out_the_jsonapi_member(): void {
      $document = JsonApiException::make(status: 404)->toDocument(jsonApiObject: false);

      $this->assertArrayNotHasKey('jsonapi', $document->toArray());
    }

    #[Test]
    public function can_be_extended_to_define_your_own_error_in_one_place(): void {
      $exception = new PaymentDeclined();

      $this->assertInstanceOf(JsonApiException::class, $exception);
      $this->assertSame(402, $exception->httpStatus());
      $this->assertSame('PAYMENT-DECLINED', $exception->errors()[0]->code());
      $this->assertSame('Payment declined', $exception->errors()[0]->title());
    }

    #[Test]
    public function an_extended_exception_can_be_thrown_and_caught_as_the_base_class(): void {
      $this->expectException(JsonApiException::class);
      $this->expectExceptionMessage('The card was declined.');

      throw new PaymentDeclined();
    }

    #[Test]
    public function an_invalid_error_status_is_rejected_when_the_exception_is_created(): void {
      $this->expectException(InvalidArgumentException::class);

      JsonApiException::make(status: 200);
    }
  }
