<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Http\JsonResponse;
  use InvalidArgumentException;
  use JsonSerializable;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Errors\ErrorDocument;
  use MundoProgra\JsonApi\Support\Pointer;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ErrorDocumentTest extends TestCase {
    use AssertsJsonApi;

    #[Test]
    public function wraps_the_errors_in_an_errors_list_with_the_jsonapi_member(): void {
      $error = Error::make(status: 422, title: 'Validation failed');

      $document = ErrorDocument::make([$error]);

      $this->assertSame([
        'jsonapi' => ['version' => '1.1'],
        'errors' => [['status' => '422', 'title' => 'Validation failed']],
      ], $document->toArray());
    }

    #[Test]
    public function keeps_the_errors_in_the_order_they_were_given(): void {
      $document = ErrorDocument::make([
        Error::make(status: 422, detail: 'first'),
        Error::make(status: 422, detail: 'second'),
        Error::make(status: 422, detail: 'third'),
      ]);

      $this->assertSame(['first', 'second', 'third'], array_column($document->toArray()['errors'], 'detail'));
    }

    #[Test]
    public function accepts_a_single_error_without_a_list(): void {
      $document = ErrorDocument::make(Error::make(status: 404));

      $this->assertSame([['status' => '404']], $document->toArray()['errors']);
      $this->assertCount(1, $document->errors());
    }

    #[Test]
    public function leaves_out_the_jsonapi_member_when_it_is_turned_off(): void {
      $document = ErrorDocument::make([Error::make(status: 500)], jsonApiObject: false);

      $this->assertSame(['errors' => [['status' => '500']]], $document->toArray());
    }

    #[Test]
    public function rejects_an_empty_list_of_errors(): void {
      $this->expectException(InvalidArgumentException::class);
      $this->expectExceptionMessage('at least one');

      ErrorDocument::make([]);
    }

    #[Test]
    public function exposes_the_errors_it_holds(): void {
      $first = Error::make(status: 400);
      $second = Error::make(status: 404);

      $this->assertSame([$first, $second], ErrorDocument::make([$first, $second])->errors());
    }

    /**
     * @return array<string, array{list<int>, int}>
     */
    public static function httpStatuses(): array {
      // [estados de los errores, estado HTTP de la respuesta] / [statuses of the errors, HTTP status of the response]
      return [
        'a single error' => [[401], 401],
        'the same client status repeated' => [[422, 422, 422], 422],
        'the same server status repeated' => [[503, 503], 503],
        'different client statuses' => [[403, 404], 400],
        'three different client statuses' => [[401, 403, 422], 400],
        'a 400 next to another client status' => [[400, 422], 400],
        'a client and a server status' => [[422, 500], 500],
        'different server statuses' => [[502, 503], 500],
        'a server status among client statuses' => [[404, 503, 422], 500],
      ];
    }

    /**
     * @param  list<int>  $statuses
     */
    #[Test]
    #[DataProvider('httpStatuses')]
    public function uses_the_most_generally_applicable_http_status(array $statuses, int $expected): void {
      $document = ErrorDocument::make(array_map(fn(int $status) => Error::make(status: $status), $statuses));

      $this->assertSame($expected, $document->httpStatus());
    }

    #[Test]
    public function every_error_keeps_its_own_status_even_when_the_response_uses_another_one(): void {
      $document = ErrorDocument::make([Error::make(status: 403), Error::make(status: 404)]);

      $this->assertSame(400, $document->httpStatus());
      $this->assertSame(['403', '404'], array_column($document->toArray()['errors'], 'status'));
    }

    #[Test]
    public function is_written_as_json_with_the_same_content_as_toArray(): void {
      $document = ErrorDocument::make([Error::make(status: 400, title: 'T')]);

      $this->assertInstanceOf(JsonSerializable::class, $document);
      $this->assertSame('{"jsonapi":{"version":"1.1"},"errors":[{"status":"400","title":"T"}]}', json_encode($document));
    }

    #[Test]
    public function the_documents_are_valid_json_api_documents(): void {
      $this->assertJsonApiDocument(ErrorDocument::make(Error::make(status: 404))->toArray());
      $this->assertJsonApiDocument(ErrorDocument::make([
        Error::make(status: 422, title: 'Validation failed', code: 'c', pointer: Pointer::from('data', 'attributes', 'title')),
        Error::make(status: 400, parameter: 'page[size]'),
        Error::make(status: 406, header: 'Accept'),
      ])->toArray());
      $this->assertJsonApiDocument(ErrorDocument::make([Error::make(status: 500)], jsonApiObject: false)->toArray());
    }

    // ---------------------------------------------------------------------------------------------
    // Respuesta HTTP / HTTP response
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function builds_a_json_response_with_the_document_and_the_http_status(): void {
      $document = ErrorDocument::make([Error::make(status: 403), Error::make(status: 404)]);

      $response = $document->toResponse();

      $this->assertInstanceOf(JsonResponse::class, $response);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame($document->toArray(), json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function the_response_uses_the_json_api_media_type_without_parameters(): void {
      $response = ErrorDocument::make(Error::make(status: 404))->toResponse();

      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_caller_cannot_change_the_content_type_of_the_response(): void {
      $response = ErrorDocument::make(Error::make(status: 404))->toResponse(['Content-Type' => 'text/html']);

      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function keeps_the_extra_headers_such_as_allow_and_retry_after(): void {
      $response = ErrorDocument::make(Error::make(status: 405))->toResponse(['Allow' => 'GET, POST', 'Retry-After' => '60']);

      $this->assertSame('GET, POST', $response->headers->get('Allow'));
      $this->assertSame('60', $response->headers->get('Retry-After'));
    }

    #[Test]
    public function writes_slashes_and_accents_readably_in_the_body(): void {
      $document = ErrorDocument::make(Error::make(status: 422, detail: 'El título es obligatorio.', pointer: '/data/attributes/title'));

      $body = (string) $document->toResponse()->getContent();

      $this->assertStringContainsString('"/data/attributes/title"', $body);
      $this->assertStringContainsString('El título es obligatorio.', $body);
    }
  }
