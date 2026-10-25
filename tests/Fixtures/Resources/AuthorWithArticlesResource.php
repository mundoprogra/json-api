<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;

  /**
   * Un autor cuyos artículos salen si están cargados: con ellos las relaciones forman un ciclo (artículo → autor → artículos), que el `included`
   * tiene que recorrer sin repetir ni dar vueltas.
   *
   * An author whose articles come out when loaded: with them the relationships form a cycle (article → author → articles), which `included` has
   * to walk without repeating or going around.
   */
  class AuthorWithArticlesResource extends AuthorResource {
    protected function jsonApiRelationships(Request $request): array {
      return [
        'articles' => $this->whenLoaded('articles', fn() => Relationship::many($this->articles, BlogArticleResource::class)),
      ];
    }
  }
