<?php

  namespace MundoProgra\JsonApi\Tests\Support;

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
  }
