<?php

  namespace MundoProgra\JsonApi\Tests\Fixtures\Models;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  /**
   * Un comentario de prueba, con la tabla `comments`: pertenece a un artículo y lo escribió un autor.
   * A test comment, with the `comments` table: it belongs to an article and an author wrote it.
   */
  class Comment extends Model {
    protected $guarded = [];

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo {
      return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Author, $this>
     */
    public function author(): BelongsTo {
      return $this->belongsTo(Author::class);
    }
  }
