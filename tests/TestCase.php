<?php

  namespace MundoProgra\JsonApi\Tests;

  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use Orchestra\Testbench\TestCase as Orchestra;

  abstract class TestCase extends Orchestra {
    protected function getPackageProviders($app): array {
      return [
        JsonApiServiceProvider::class,
      ];
    }

    protected function defineEnvironment($app): void {
      $app['config']->set('database.default', 'testing');
    }
  }
