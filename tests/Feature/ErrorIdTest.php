<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Support\Facades\Log;
  use Illuminate\Support\Facades\Validator;
  use Monolog\Handler\TestHandler;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Tests\Fixtures\Exceptions\ThirdPartyPaymentFailed;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use RuntimeException;

  /**
   * El paquete solo genera el id y lo entrega (en la respuesta y en el contexto del log); escribir y guardar los logs sigue siendo de la app.
   * Laravel escribe el log antes de armar la respuesta, así que el id debe existir ya cuando se reporta la excepción.
   * The package only generates the id and hands it over (in the response and in the log context); writing and storing logs stays with the app.
   * Laravel writes the log before building the response, so the id must already exist when the exception is reported.
   */
  class ErrorIdTest extends TestCase {
    use AssertsJsonApi;

    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    protected function setUp(): void {
      parent::setUp();

      config()->set('app.debug', false);

      // Estas pruebas ejercitan el renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');
    }

    protected function defineEnvironment($app): void {
      parent::defineEnvironment($app);

      // Un canal de log real (a través de LogManager), porque el id entra por el contexto del canal por defecto, no por un registro suelto.
      // A real log channel (through LogManager), because the id enters through the default channel context, not through a loose logger.
      $app['config']->set('logging.default', 'spike');
      $app['config']->set('logging.channels.spike', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    protected function defineRoutes($router): void {
      $router->get('boom', fn() => throw new RuntimeException('boom'));
      $router->get('payment', fn() => throw new ThirdPartyPaymentFailed('card declined by the gateway'));
      $router->get('missing', fn() => throw JsonApiException::make(status: 404));
      $router->get('own-id', fn() => throw new JsonApiException([Error::make(status: 500, id: 'my-own-id')]));
      $router->get('mixed', fn() => throw new JsonApiException([
        Error::make(status: 404, detail: 'first'),
        Error::make(status: 500, detail: 'second'),
      ]));
      $router->get('invalid', fn() => Validator::make([], ['title' => 'required', 'email' => 'required'])->validate());
    }

    private function enable(): void {
      config()->set('json-api.errors.include_id', true);
    }

    // ---------------------------------------------------------------------------------------------
    // Apagado: nunca se manda ni se anota / Off: it is never sent nor noted
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_off_by_default_so_nothing_is_sent_and_nothing_is_added_to_the_log(): void {
      $response = $this->get('boom');

      // El log sí recibió la excepción: sin esto, "no hay error_id" podría significar que el log no funciona.
      // The log did receive the exception: without this, "no error_id" could just mean the log does not work.
      $this->assertCount(1, $this->records());
      $this->assertArrayNotHasKey('id', $response->json('errors.0'));
      $this->assertArrayNotHasKey('error_id', $this->records()[0]['context']);
    }

    #[Test]
    public function it_is_off_for_client_errors_too(): void {
      $this->assertArrayNotHasKey('id', $this->get('missing')->json('errors.0'));
    }

    // ---------------------------------------------------------------------------------------------
    // Encendido: el mismo id en la respuesta y en el log / On: the same id in the response and in the log
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function an_unexpected_server_error_gets_an_id(): void {
      $this->enable();

      $response = $this->get('boom');

      $response->assertStatus(500);
      $this->assertMatchesRegularExpression(self::ULID, (string) $response->json('errors.0.id'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function the_id_in_the_response_is_the_one_written_to_the_log(): void {
      $this->enable();

      $response = $this->get('boom');

      $this->assertCount(1, $this->records());
      $this->assertNotNull($response->json('errors.0.id'));
      $this->assertSame($response->json('errors.0.id'), $this->records()[0]['context']['error_id'] ?? null);
    }

    #[Test]
    public function every_occurrence_gets_a_different_id(): void {
      $this->enable();

      $first = $this->get('boom')->json('errors.0.id');
      $second = $this->get('boom')->json('errors.0.id');

      $this->assertNotSame($first, $second);
      $this->assertSame([$first, $second], array_column(array_column($this->records(), 'context'), 'error_id'));
    }

    #[Test]
    public function the_id_does_not_stay_in_the_log_context_for_whatever_is_logged_later(): void {
      $this->enable();

      $this->get('boom');
      Log::info('something unrelated');

      $this->assertCount(2, $this->records());
      $this->assertArrayHasKey('error_id', $this->records()[0]['context']);
      $this->assertArrayNotHasKey('error_id', $this->records()[1]['context']);
    }

    #[Test]
    public function an_exception_converted_with_map_keeps_the_same_id_in_the_log_and_in_the_response(): void {
      // Laravel convierte la excepción por separado al reportar y al renderizar: no son el mismo objeto, y aun así el id debe coincidir.
      // Laravel converts the exception separately when reporting and when rendering: they are not the same object, and the id must still match.
      $this->enable();
      $this->app->make(ExceptionHandler::class)->map(fn(ThirdPartyPaymentFailed $e) => JsonApiException::make(status: 503, detail: 'The gateway is down.'));

      $response = $this->get('payment');

      $response->assertStatus(503);
      $this->assertMatchesRegularExpression(self::ULID, (string) $response->json('errors.0.id'));
      $this->assertCount(1, $this->records());
      $this->assertNotNull($response->json('errors.0.id'));
      $this->assertSame($response->json('errors.0.id'), $this->records()[0]['context']['error_id'] ?? null);
    }

    #[Test]
    public function an_id_written_by_the_developer_is_respected_in_the_response_and_in_the_log(): void {
      $this->enable();

      $response = $this->get('own-id');

      $this->assertSame('my-own-id', $response->json('errors.0.id'));
      $this->assertSame('my-own-id', $this->records()[0]['context']['error_id'] ?? null);
    }

    #[Test]
    public function with_several_errors_the_log_gets_the_id_of_the_first_one(): void {
      // Se escribe una sola línea por excepción: lleva el id del primer error del documento.
      // One line is written per exception: it carries the id of the first error of the document.
      $this->enable();

      $response = $this->get('mixed');

      $ids = array_column($response->json('errors'), 'id');
      $this->assertCount(2, $ids);
      $this->assertNotSame($ids[0], $ids[1]);
      $this->assertCount(1, $this->records());
      $this->assertSame($ids[0], $this->records()[0]['context']['error_id']);
    }

    // ---------------------------------------------------------------------------------------------
    // Errores del cliente: llevan id en la respuesta, pero no hay línea de log / Client errors: an id in the response, but no log line
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int}>
     */
    public static function clientErrors(): array {
      return [
        'json api exception' => ['missing', 404],
        'route that does not exist' => ['no-such-route', 404],
        'validation failed' => ['invalid', 422],
      ];
    }

    #[Test]
    #[DataProvider('clientErrors')]
    public function a_client_error_gets_an_id_in_the_response_and_leaves_nothing_in_the_log(string $uri, int $status): void {
      $this->enable();

      $response = $this->get($uri);

      $response->assertStatus($status);
      $this->assertMatchesRegularExpression(self::ULID, (string) $response->json('errors.0.id'));
      $this->assertCount(0, $this->records());
    }

    #[Test]
    public function each_validation_error_gets_its_own_id(): void {
      $this->enable();

      $ids = array_column($this->get('invalid')->json('errors'), 'id');

      $this->assertCount(2, $ids);
      $this->assertCount(2, array_unique($ids));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array {
      /** @var TestHandler $handler */
      $handler = Log::channel('spike')->getLogger()->getHandlers()[0];

      return array_map(fn($record) => ['message' => $record['message'], 'context' => (array) $record['context']], $handler->getRecords());
    }
  }
