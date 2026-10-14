<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Log\Logger;
  use Monolog\Handler\TestHandler;
  use Monolog\Logger as Monolog;
  use MundoProgra\JsonApi\Errors\Error;
  use MundoProgra\JsonApi\Exceptions\JsonApiException;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use Psr\Log\LoggerInterface;
  use RuntimeException;
  use Throwable;

  /**
   * El paquete nunca escribe logs: esto comprueba qué le dice la excepción al reportador de Laravel, que es quien escribe en el log de la app.
   * The package never writes logs: this checks what the exception tells Laravel's reporter, which is what writes to the app log.
   */
  class JsonApiExceptionReportingTest extends TestCase {
    private TestHandler $log;

    protected function setUp(): void {
      parent::setUp();

      // Un registro de prueba en lugar del log real de la app.
      // A test log instead of the real app log.
      $this->log = new TestHandler();
      $this->app->instance(LoggerInterface::class, new Logger(new Monolog('test', [$this->log])));
    }

    #[Test]
    public function control_laravel_does_log_an_ordinary_exception(): void {
      // Sin esta prueba, "no se escribió nada" podría significar simplemente que el registro de prueba no funciona.
      // Without this test, "nothing was written" could just mean the test log does not work.
      $this->report(new RuntimeException('something broke'));

      $this->assertCount(1, $this->log->getRecords());
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function clientErrors(): array {
      return [
        'not found' => [[404]],
        'forbidden' => [[403]],
        'validation failed' => [[422]],
        'several client errors' => [[403, 404]],
      ];
    }

    /**
     * @param  list<int>  $statuses
     */
    #[Test]
    #[DataProvider('clientErrors')]
    public function a_client_error_is_not_written_to_the_log(array $statuses): void {
      $this->report($this->exceptionWith($statuses));

      $this->assertCount(0, $this->log->getRecords());
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function serverErrors(): array {
      return [
        'internal error' => [[500]],
        'service unavailable' => [[503]],
        'a server error among client errors' => [[404, 500]],
      ];
    }

    /**
     * @param  list<int>  $statuses
     */
    #[Test]
    #[DataProvider('serverErrors')]
    public function a_server_error_is_written_to_the_log_like_any_other_exception(array $statuses): void {
      $exception = $this->exceptionWith($statuses);

      $this->report($exception);

      $this->assertCount(1, $this->log->getRecords());
      $this->assertStringContainsString($exception->getMessage(), (string) $this->log->getRecords()[0]['message']);
    }

    #[Test]
    public function the_exception_tells_laravel_directly_whether_it_was_handled(): void {
      // En Laravel, report() que devuelve algo distinto de false significa "ya lo manejé: no lo escribas en el log".
      // In Laravel, a report() that returns anything other than false means "I handled it: do not write it to the log".
      $this->assertTrue($this->exceptionWith([404])->report());
      $this->assertFalse($this->exceptionWith([500])->report());
    }

    /**
     * @param  list<int>  $statuses
     */
    private function exceptionWith(array $statuses): JsonApiException {
      return new JsonApiException(array_map(fn(int $status) => Error::make(status: $status, detail: "problem $status"), $statuses));
    }

    private function report(Throwable $exception): void {
      $this->app->make(ExceptionHandler::class)->report($exception);
    }
  }
