<?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  /**
   * Las tablas de prueba para las pruebas que sí necesitan una base de datos (SQLite en memoria): autores y artículos.
   * The test tables for the tests that do need a database (in-memory SQLite): authors and articles.
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
    }

    public function down(): void {
      Schema::dropIfExists('articles');
      Schema::dropIfExists('authors');
    }
  };
