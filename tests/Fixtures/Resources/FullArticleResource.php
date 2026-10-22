<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Resources;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Resources\Relationship;
  use MundoProgra\JsonApi\Resources\ResourceObject;

  /**
   * Un artículo con varios atributos (uno `null`, uno condicionado que no aplica), relaciones, `links.self` y `meta`: lo que hace falta para
   * probar que los sparse fieldsets dejan pasar solo lo pedido y no tocan `type`, `id`, `links` ni `meta`.
   * An article with several attributes (one `null`, one conditional that does not apply), relationships, `links.self` and `meta`: what is
   * needed to test that sparse fieldsets let through only what was asked for and do not touch `type`, `id`, `links` or `meta`.
   */
  class FullArticleResource extends ResourceObject {
    protected function jsonApiAttributes(Request $request): array {
      return [
        'title' => $this->title,
        'body' => $this->body,
        'published_at' => $this->published_at,
        'secret' => $this->when(false, 'never shown'),
      ];
    }

    protected function jsonApiRelationships(Request $request): array {
      return [
        'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
        'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
      ];
    }

    protected function jsonApiLinks(Request $request): array {
      return ['self' => 'https://example.com/articles/' . $this->id()];
    }

    protected function jsonApiMeta(Request $request): array {
      return ['revision' => 3];
    }
  }
