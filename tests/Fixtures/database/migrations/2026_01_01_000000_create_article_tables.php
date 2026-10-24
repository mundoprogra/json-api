<?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  /**
   * Las tablas de prueba para las pruebas que sí necesitan una base de datos (SQLite en memoria): autores, artículos, etiquetas (con su tabla
   * pivote) y comentarios.
   * The test tables for the tests that do need a database (in-memory SQLite): authors, articles, tags (with their pivot table) and comments.
   */
  return new class extends Migration {
    public function up(): void {
      Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
      });

      Schema::create('articles', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id')->nullable();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->timestamps();
      });

      // Las etiquetas viven en `labels` (no en `tags`) y se identifican por su `slug`.
      // Tags live in `labels` (not `tags`) and are identified by their `slug`.
      Schema::create('labels', function (Blueprint $table) {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->timestamps();
      });

      Schema::create('article_tag', function (Blueprint $table) {
        $table->foreignId('article_id');
        $table->foreignId('tag_id');
      });

      Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('article_id');
        $table->foreignId('author_id')->nullable();
        $table->text('body');
        $table->timestamps();
      });
    }

    public function down(): void {
      Schema::dropIfExists('comments');
      Schema::dropIfExists('article_tag');
      Schema::dropIfExists('labels');
      Schema::dropIfExists('articles');
      Schema::dropIfExists('authors');
    }
  };
