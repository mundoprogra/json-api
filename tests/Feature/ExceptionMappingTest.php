<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Log\Logger;
  use Monolog\Handler\TestHandler;
  use Monolog\Logger as Monolog;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\PaymentDeclined;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\ThirdPartyPaymentFailed;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;
  use Psr\Log\LoggerInterface;

  /**
   * Para convertir una excepción que no se puede modificar (de un paquete externo, o una que ya usa la app) no hace falta una API propia:
   * map() de Laravel la convierte en una JsonApiException y el renderizador del paquete hace el resto. Estas pruebas fijan ese uso.
   * To convert an exception that cannot be modified (from an external package, or one the app already uses) no API of our own is needed:
   * Laravel's map() turns it into a JsonApiException and the package renderer does the rest. These tests pin down that usage.
   */
  class ExceptionMappingTest extends TestCase {
    use AssertsJsonApi;

    private TestHandler $log;

    protected function setUp(): void {
      parent::setUp();

      config()->set('app.debug', false);

      // Estas pruebas ejercitan el renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');

      $this->log = new TestHandler();
      $this->app->instance(LoggerInterface::class, new Logger(new Monolog('test', [$this->log])));
    }

    protected function defineRoutes($router): void {
      $router->get('payment', fn() => throw new ThirdPartyPaymentFailed('card declined by the gateway'));
      $router->get('other', fn() => throw new \LogicException('something else'));
    }

    #[Test]
    public function without_a_mapping_the_external_exception_is_a_generic_500(): void {
      $response = $this->get('payment');

      $response->assertStatus(500);
      $this->assertSame('mundoprogra.json-api.server.error', $response->json('errors.0.code'));
    }

    #[Test]
    public function maps_an_exception_that_cannot_be_modified_into_a_json_api_error(): void {
      // Se comprueba antes del mapa y después, para que la prueba falle si map() no hiciera nada.
      // It is checked before the mapping and after it, so the test fails if map() did nothing.
      $this->assertSame(500, $this->get('payment')->getStatusCode());

      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(
        status: 402,
        title: 'Payment failed',
        detail: 'The payment was declined.',
        code: 'PAY-1',
      ));

      $response = $this->get('payment');

      $response->assertStatus(402);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame([
        'status' => '402',
        'code' => 'PAY-1',
        'title' => 'Payment failed',
        'detail' => 'The payment was declined.',
      ], $response->json('errors.0'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_mapping_only_affects_the_exception_it_names(): void {
      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(status: 402));

      $this->assertSame(402, $this->get('payment')->getStatusCode());
      $this->assertSame(500, $this->get('other')->getStatusCode());
    }

    #[Test]
    public function a_mapping_can_produce_several_errors_and_headers(): void {
      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => new JsonApiException(
        [Error::make(status: 402, code: 'PAY-1'), Error::make(status: 403, code: 'PAY-2')],
        ['Retry-After' => '30'],
      ));

      $response = $this->get('payment');

      $response->assertStatus(400);
      $this->assertSame(['402', '403'], array_column($response->json('errors'), 'status'));
      $this->assertSame(['PAY-1', 'PAY-2'], array_column($response->json('errors'), 'code'));
      $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    #[Test]
    public function a_mapping_without_texts_gets_them_from_the_status(): void {
      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(status: 409));

      $response = $this->get('payment');

      $response->assertStatus(409);
      $this->assertSame('mundoprogra.json-api.resource.conflict', $response->json('errors.0.code'));
      $this->assertSame('Conflict', $response->json('errors.0.title'));
    }

    #[Test]
    public function an_own_json_api_exception_can_be_given_by_its_class_name(): void {
      $this->handler()->map(ThirdPartyPaymentFailed::class, PaymentDeclined::class);

      $response = $this->get('payment');

      $response->assertStatus(402);
      $this->assertSame('PAYMENT-DECLINED', $response->json('errors.0.code'));
    }

    // ---------------------------------------------------------------------------------------------
    // El log: Laravel reporta la excepción ya convertida / The log: Laravel reports the converted exception
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_original_exception_is_written_to_the_log_when_it_is_not_mapped(): void {
      $this->get('payment');

      $this->assertCount(1, $this->log->getRecords());
    }

    #[Test]
    public function mapping_to_a_client_error_leaves_nothing_in_the_log(): void {
      // Lo que se reporta es la excepción ya convertida, y los 4xx de JsonApiException no se reportan.
      // What is reported is the converted exception, and the 4xx of JsonApiException are not reported.
      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(status: 402));

      $this->get('payment');

      $this->assertCount(0, $this->log->getRecords());
    }

    #[Test]
    public function mapping_to_a_server_error_does_write_it_to_the_log(): void {
      // Para que una falla real no quede oculta, se convierte a un 5xx.
      // So a real failure is not hidden, it is converted to a 5xx.
      $this->handler()->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(status: 503, detail: 'The gateway is down.'));

      $response = $this->get('payment');

      $response->assertStatus(503);
      $this->assertSame('The gateway is down.', $response->json('errors.0.detail'));
      $this->assertCount(1, $this->log->getRecords());
      $this->assertStringContainsString('The gateway is down.', (string) $this->log->getRecords()[0]['message']);
    }

    private function handler(): ExceptionHandler {
      return $this->app->make(ExceptionHandler::class);
    }
  }
