<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Un `FormRequest` que apaga la comprobación del `id` contra la URL.
   * A `FormRequest` that turns off the check of the `id` against the URL.
   */
  class NoIdCheckRequest extends FormRequest {
    use InteractsWithJsonApi;

    public function authorize(): bool {
      return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
      return [];
    }

    protected function jsonApiExpectedId(): ?string {
      return null;
    }
  }
