<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Las reglas para actualizar un artículo: todo es opcional (`sometimes`), porque un `PATCH` solo manda lo que cambia. El `type` esperado es
   * `articles` y el `id` esperado es el de la URL.
   *
   * The rules to update an article: everything is optional (`sometimes`), because a `PATCH` only sends what changes. The expected `type` is
   * `articles` and the expected `id` is the one in the URL.
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
      return [
        'title' => ['sometimes', 'string', 'max:50'],
        'status' => ['sometimes', 'string', 'in:draft,published'],
        'author.data.type' => ['in:authors'],
        'author.data.id' => ['integer'],
        'tags.data.*.type' => ['in:labels'],
        'tags.data.*.id' => ['string'],
      ];
    }

    protected function jsonApiExpectedType(): ?string {
      return 'articles';
    }
  }
