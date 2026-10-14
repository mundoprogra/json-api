<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Exceptions;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Throwable;

  /**
   * Un manejador de excepciones mínimo, que solo cumple el contrato y no tiene `renderable` (como uno hecho a mano por una app).
   * A minimal exception handler that only fulfils the contract and has no `renderable` (like one written by hand by an app).
   */
  class MinimalHandler implements ExceptionHandler {
    public function report(Throwable $e): void {
    }

    public function shouldReport(Throwable $e): bool {
      return false;
    }

    public function render($request, Throwable $e) {
      throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {
    }
  }
