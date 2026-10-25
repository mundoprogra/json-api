<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\ReferenceApi;

  use Illuminate\Foundation\Http\FormRequest;
  use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

  /**
   * Las reglas para crear un artículo, escritas sobre los datos planos del documento: los atributos con su nombre en snake_case y las relaciones con
   * su objeto (`author.data.id`, `tags.data.*.id`). El `type` esperado es `articles`.
   *
   * The rules to create an article, written over the flat data of the document: attributes by their snake_case name and relationships by their object
   * (`author.data.id`, `tags.data.*.id`). The expected `type` is `articles`.
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
        'title' => ['required', 'string', 'max:50'],
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
