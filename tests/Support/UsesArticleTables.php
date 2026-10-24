<?php

  namespace MundoProgra\JsonApi\Tests\Support;

  use Illuminate\Support\Facades\DB;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\Article;

  /**
   * Crea las tablas de prueba de autores y artículos (migración en `tests/Fixtures/database/migrations`) y deja a mano una forma de llenarlas.
   * Creates the test tables for authors and articles (migration in `tests/Fixtures/database/migrations`) and gives a way to fill them.
   */
  trait UsesArticleTables {
    protected function defineDatabaseMigrations(): void {
      $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/database/migrations');
    }

    /**
     * Inserta `$count` artículos con id 1..$count; los de id par están publicados y los demás en borrador.
     * Inserts `$count` articles with id 1..$count; those with an even id are published and the rest are drafts.
     */
    protected function seedArticles(int $count = 40): void {
      $rows = [];

      for ($id = 1; $id <= $count; $id++) {
        $rows[] = ['id' => $id, 'title' => "Article $id", 'status' => $id % 2 === 0 ? 'published' : 'draft', 'created_at' => now(), 'updated_at' => now()];
      }

      Article::query()->insert($rows);
    }

    /**
     * Llena las relaciones de los primeros `$articles` artículos: 3 autores (el artículo `n` es del autor `((n - 1) % 3) + 1`), 5 etiquetas (dos
     * por artículo) y 2 comentarios por artículo, escritos por los autores 1 y 2. Los artículos deben existir (ver `seedArticles()`).
     * Fills the relations of the first `$articles` articles: 3 authors (article `n` belongs to author `((n - 1) % 3) + 1`), 5 tags (two per
     * article) and 2 comments per article, written by authors 1 and 2. The articles must exist (see `seedArticles()`).
     */
    protected function seedRelations(int $articles = 20): void {
      DB::table('authors')->insert(array_map(fn(int $id) => ['id' => $id, 'name' => "Author $id", 'created_at' => now(), 'updated_at' => now()], [1, 2, 3]));
      DB::table('labels')->insert(array_map(fn(int $id) => ['id' => $id, 'slug' => "tag-$id", 'name' => "Tag $id", 'created_at' => now(), 'updated_at' => now()], range(1, 5)));

      $pivot = [];
      $comments = [];

      for ($id = 1; $id <= $articles; $id++) {
        DB::table('articles')->where('id', $id)->update(['author_id' => (($id - 1) % 3) + 1]);

        $pivot[] = ['article_id' => $id, 'tag_id' => ($id % 5) + 1];
        $pivot[] = ['article_id' => $id, 'tag_id' => (($id + 1) % 5) + 1];

        foreach ([1, 2] as $author) {
          $comments[] = ['article_id' => $id, 'author_id' => $author, 'body' => "Comment of $author on $id", 'created_at' => now(), 'updated_at' => now()];
        }
      }

      DB::table('article_tag')->insert($pivot);
      DB::table('comments')->insert($comments);
    }
  }
