<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Un `FormRequest` que dice cuál es el `id` esperado en lugar de leerlo de la URL.
   * A `FormRequest` that says which `id` is expected instead of reading it from the URL.
   */
  class FixedIdRequest extends FormRequest {
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
      return '99';
    }
  }
