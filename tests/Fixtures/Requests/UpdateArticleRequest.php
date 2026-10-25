<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Un `FormRequest` de una app que actualiza artículos: el recurso esperado es `articles` y el `id` esperado es el de la URL.
   * A `FormRequest` of an app that updates articles: the expected resource is `articles` and the expected `id` is the one in the URL.
   */
  class UpdateArticleRequest extends FormRequest {
    use InteractsWithJsonApi;

    public function authorize(): bool {
      return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
      return ['title' => ['sometimes', 'string', 'max:10']];
    }

    protected function jsonApiExpectedType(): ?string {
      return 'articles';
    }
  }
