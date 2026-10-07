<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  class ProviderTest extends TestCase {
    #[Test]
    public function boots_the_service_provider(): void {
      $this->assertNotNull($this->app->getProvider(JsonApiServiceProvider::class));
    }

    #[Test]
    public function merges_the_default_config(): void {
      $this->assertIsArray(config('json-api'));
    }
  }
