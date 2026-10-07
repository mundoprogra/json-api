<?php

  namespace MundoProgra\JsonApi\Tests\Contract;

  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\ExpectationFailedException;
  use PHPUnit\Framework\TestCase;

  class ContractSmokeTest extends TestCase {
    use AssertsJsonApi;

    #[Test]
    public function validates_a_minimal_resource_document_against_the_official_schema(): void {
      $this->assertJsonApiDocument([
        'data' => ['type' => 'users', 'id' => '1', 'attributes' => ['name' => 'Ada']],
      ]);
    }

    #[Test]
    public function validates_a_minimal_error_document_against_the_official_schema(): void {
      $this->assertJsonApiDocument([
        'errors' => [['status' => '422', 'title' => 'Unprocessable Entity']],
      ]);
    }

    #[Test]
    public function rejects_a_document_with_both_data_and_errors(): void {
      $this->expectException(ExpectationFailedException::class);

      $this->assertJsonApiDocument([
        'data' => ['type' => 'users', 'id' => '1'],
        'errors' => [['status' => '500']],
      ]);
    }

    #[Test]
    public function rejects_a_resource_whose_id_is_not_a_string(): void {
      $this->expectException(ExpectationFailedException::class);

      $this->assertJsonApiDocument([
        'data' => ['type' => 'users', 'id' => 1],
      ]);
    }
  }
