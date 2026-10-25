<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Illuminate\Database\Eloquent\Collection as EloquentCollection;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Author;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Comment;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Tag;

  /**
   * Artículos de ejemplo con sus relaciones ya cargadas a mano (sin base de datos), para probar el `included`:
   *  - el artículo 10: autor Ada (1), etiquetas `php` y `laravel`, y dos comentarios (el 100 de Ada y el 101 de Bob);
   *  - el artículo 11: autor Bob (2), etiquetas `laravel` y `rails`, y un comentario (el 102 de Bob).
   * Hacen falta modelos nuevos en cada llamada: así un recurso no comparte instancias con otro.
   *
   * Sample articles with their relationships loaded by hand (no database), to test `included`:
   *  - article 10: author Ada (1), tags `php` and `laravel`, and two comments (100 by Ada and 101 by Bob);
   *  - article 11: author Bob (2), tags `laravel` and `rails`, and one comment (102 by Bob).
   * New models are needed on each call: that way a resource does not share instances with another.
   */
  trait BuildsBlogArticles {
    private function author(int $id, string $name): Author {
      return (new Author())->forceFill(['id' => $id, 'name' => $name, 'email' => strtolower($name) . '@example.com']);
    }

    private function comment(int $id, string $body, Author $author): Comment {
      return (new Comment())->forceFill(['id' => $id, 'body' => $body])->setRelation('author', $author);
    }

    private function tag(string $slug): Tag {
      return (new Tag())->forceFill(['slug' => $slug, 'name' => strtoupper($slug)]);
    }

    /**
     * Un artículo sin ninguna relación cargada.
     * An article with no relationship loaded.
     */
    private function bareArticle(int $id): Article {
      return (new Article())->forceFill(['id' => $id, 'title' => "Article $id"]);
    }

    /**
     * El artículo 10, con todo cargado.
     * Article 10, with everything loaded.
     */
    private function article(): Article {
      $first = $this->comment(100, 'First', $this->author(1, 'Ada'));

      return $this->bareArticle(10)->forceFill(['title' => 'Hello'])
        ->setRelation('author', $this->author(1, 'Ada'))
        ->setRelation('tags', new EloquentCollection([$this->tag('php'), $this->tag('laravel')]))
        ->setRelation('comments', new EloquentCollection([$first, $this->comment(101, 'Second', $this->author(2, 'Bob'))]))
        ->setRelation('featured_comment', $first);
    }

    /**
     * El artículo 11, con todo cargado.
     * Article 11, with everything loaded.
     */
    private function secondArticle(): Article {
      return $this->bareArticle(11)
        ->setRelation('author', $this->author(2, 'Bob'))
        ->setRelation('tags', new EloquentCollection([$this->tag('laravel'), $this->tag('rails')]))
        ->setRelation('comments', new EloquentCollection([$this->comment(102, 'Third', $this->author(2, 'Bob'))]));
    }

    /**
     * Un artículo cuyo autor tiene cargados sus artículos (incluido él mismo): un ciclo artículo → autor → artículos.
     * An article whose author has its articles loaded (itself included): a cycle article → author → articles.
     */
    private function articleInACycle(int $id = 10): Article {
      $author = $this->author(1, 'Ada')->setRelation('articles', new EloquentCollection([$this->bareArticle(10), $this->bareArticle(11), $this->bareArticle(12)]));

      return $this->bareArticle($id)->setRelation('author', $author);
    }
  }
