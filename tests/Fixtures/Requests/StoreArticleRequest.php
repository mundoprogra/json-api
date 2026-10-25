<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Requests;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Un `FormRequest` de una app que crea artículos: las reglas se escriben sobre los datos planos del documento (los atributos en snake_case y,
   * para las relaciones, el objeto de la relación: `author.data.id`) y el recurso esperado es `articles`.
   *
   * A `FormRequest` of an app that creates articles: the rules are written over the flat data of the document (attributes in snake_case and, for
   * relationships, the relationship object: `author.data.id`) and the expected resource is `articles`.
   */
  class StoreArticleRequest extends FormRequest {
    use InteractsWithJsonApi;

    public function authorize(): bool {
      return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
      return [
        'title' => ['required', 'string', 'max:10'],
        'created_at' => ['nullable', 'date'],
        'author.data.id' => ['nullable', 'integer'],
        'tags.data.*.id' => ['integer'],
      ];
    }

    protected function jsonApiExpectedType(): ?string {
      return 'articles';
    }
  }
