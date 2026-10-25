<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Un `FormRequest` que no declara el tipo esperado: acepta cualquier `type`.
   * A `FormRequest` that does not declare the expected type: it accepts any `type`.
   */
  class LooseRequest extends FormRequest {
    use InteractsWithJsonApi;

    public function authorize(): bool {
      return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
      return ['title' => ['sometimes', 'string']];
    }
  }
