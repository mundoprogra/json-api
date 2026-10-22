<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Query\QueryParameters;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  /**
   * `QueryParameters` interpreta la query string de una petición: `include`, `fields`, `sort` y `filter` (la paginación es del punto 7.2).
   * Es inmutable y no toca Eloquent. Los nombres de campo, de relación y de ordenamiento, y las claves del primer nivel de `filter`, pasan del
   * estilo de `member_case` a snake_case; el tipo de `fields[tipo]`, los valores de `filter` y lo anidado no se tocan. Solo lee la query string.
   * `QueryParameters` reads the query string of a request: `include`, `fields`, `sort` and `filter` (pagination is point 7.2).
   * It is immutable and does not touch Eloquent. Field, relationship and sort names, and the first-level keys of `filter`, go from the
   * `member_case` style to snake_case; the type in `fields[type]`, the `filter` values and anything nested are not touched. It only reads the query string.
   */
  class QueryParametersTest extends TestCase {
    private function query(string $queryString, string $memberCase = 'camel'): QueryParameters {
      return QueryParameters::from(Request::create('/api/articles?' . $queryString), $this->settings($memberCase));
    }

    private function settings(string $memberCase = 'camel'): Settings {
      $config = ['json-api' => require __DIR__ . '/../../config/json-api.php', 'app' => ['debug' => false]];
      Arr::set($config, 'json-api.member_case', $memberCase);

      return new Settings(new Repository($config));
    }

    // ---------------------------------------------------------------------------------------------
    // include
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function include_gives_the_relationship_paths_by_levels(): void {
      $this->assertSame([['author'], ['comments', 'user']], $this->query('include=author,comments.user')->includes());
    }

    #[Test]
    public function include_keeps_the_order_the_client_gave(): void {
      $this->assertSame([['tags'], ['author'], ['comments']], $this->query('include=tags,author,comments')->includes());
    }

    #[Test]
    public function a_path_can_have_several_levels(): void {
      $this->assertSame([['author', 'books', 'publisher']], $this->query('include=author.books.publisher')->includes());
    }

    /**
     * @return array<string, array{string, string, list<list<string>>}>
     */
    public static function includeStyles(): array {
      return [
        'camel' => ['camel', 'include=blogPosts.authorProfile', [['blog_posts', 'author_profile']]],
        'kebab' => ['kebab', 'include=blog-posts.author-profile', [['blog_posts', 'author_profile']]],
        'snake' => ['snake', 'include=blog_posts.author_profile', [['blog_posts', 'author_profile']]],
      ];
    }

    /**
     * @param  list<list<string>>  $expected
     */
    #[Test]
    #[DataProvider('includeStyles')]
    public function include_names_are_converted_to_snake_case(string $style, string $queryString, array $expected): void {
      $this->assertSame($expected, $this->query($queryString, $style)->includes());
    }

    #[Test]
    public function without_include_or_with_an_empty_one_there_is_nothing_to_include(): void {
      $this->assertSame([], $this->query('')->includes());
      $this->assertSame([], $this->query('include=')->includes());
    }

    // ---------------------------------------------------------------------------------------------
    // fields
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function fields_gives_the_requested_fields_by_type(): void {
      $this->assertSame(
        ['articles' => ['title', 'body'], 'people' => ['name']],
        $this->query('fields[articles]=title,body&fields[people]=name')->fields(),
      );
    }

    #[Test]
    public function the_field_names_are_converted_but_the_type_is_not(): void {
      $this->assertSame(
        ['blogPosts' => ['created_at', 'author_name']],
        $this->query('fields[blogPosts]=createdAt,authorName')->fields(),
      );
    }

    #[Test]
    public function an_empty_list_for_a_type_is_kept_because_it_asks_for_no_fields(): void {
      $this->assertSame(['articles' => []], $this->query('fields[articles]=')->fields());
    }

    #[Test]
    public function without_fields_there_is_no_restriction(): void {
      $this->assertSame([], $this->query('')->fields());
    }

    #[Test]
    public function fields_can_be_given_with_the_brackets_encoded(): void {
      $this->assertSame(['articles' => ['title']], $this->query('fields%5Barticles%5D=title')->fields());
    }

    // ---------------------------------------------------------------------------------------------
    // sort
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function sort_gives_each_field_with_its_direction(): void {
      $this->assertSame(
        [['field' => 'created_at', 'descending' => true], ['field' => 'title', 'descending' => false]],
        $this->query('sort=-createdAt,title')->sort(),
      );
    }

    #[Test]
    public function a_leading_minus_is_the_direction_not_part_of_the_name(): void {
      $this->assertSame([['field' => 'title', 'descending' => true]], $this->query('sort=-title')->sort());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sortStyles(): array {
      return [
        'camel' => ['camel', 'sort=-createdAt'],
        'kebab' => ['kebab', 'sort=-created-at'],
        'snake' => ['snake', 'sort=-created_at'],
      ];
    }

    #[Test]
    #[DataProvider('sortStyles')]
    public function sort_names_are_converted_to_snake_case(string $style, string $queryString): void {
      $this->assertSame([['field' => 'created_at', 'descending' => true]], $this->query($queryString, $style)->sort());
    }

    #[Test]
    public function without_sort_or_with_an_empty_one_there_is_no_ordering(): void {
      $this->assertSame([], $this->query('')->sort());
      $this->assertSame([], $this->query('sort=')->sort());
    }

    // ---------------------------------------------------------------------------------------------
    // filter
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function filter_gives_the_values_by_name(): void {
      $this->assertSame(['author' => '3', 'status' => 'published'], $this->query('filter[author]=3&filter[status]=published')->filter());
    }

    #[Test]
    public function the_first_level_keys_are_converted_and_the_values_are_not(): void {
      $this->assertSame(
        ['created_after' => '2020-01-01', 'author_name' => 'Ada Lovelace'],
        $this->query('filter[createdAfter]=2020-01-01&filter[authorName]=Ada%20Lovelace')->filter(),
      );
    }

    #[Test]
    public function a_value_with_commas_is_left_for_the_application_to_interpret(): void {
      $this->assertSame(['id' => '1,2,3'], $this->query('filter[id]=1,2,3')->filter());
    }

    #[Test]
    public function nested_filters_keep_their_inner_keys_as_they_are(): void {
      $this->assertSame(
        ['author' => ['firstName' => 'Ada', 'age' => ['gte' => '30']]],
        $this->query('filter[author][firstName]=Ada&filter[author][age][gte]=30')->filter(),
      );
    }

    #[Test]
    public function without_filter_there_are_no_conditions(): void {
      $this->assertSame([], $this->query('')->filter());
    }

    // ---------------------------------------------------------------------------------------------
    // page
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function page_gives_the_number_and_the_size_as_integers(): void {
      $query = $this->query('page[number]=2&page[size]=25');

      $this->assertSame(['number' => 2, 'size' => 25], $query->page());
      $this->assertSame(2, $query->pageNumber());
      $this->assertSame(25, $query->pageSize());
    }

    #[Test]
    public function only_the_page_keys_that_were_given_are_present(): void {
      $query = $this->query('page[size]=10');

      $this->assertSame(['size' => 10], $query->page());
      $this->assertNull($query->pageNumber());
      $this->assertSame(10, $query->pageSize());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function positiveIntegers(): array {
      return [
        'one' => ['1', 1],
        'a plain number' => ['25', 25],
        'with leading zeros' => ['007', 7],
        'a big one' => ['100000', 100000],
      ];
    }

    #[Test]
    #[DataProvider('positiveIntegers')]
    public function the_number_and_the_size_accept_any_positive_integer(string $text, int $expected): void {
      $query = $this->query("page[number]=$text&page[size]=$text");

      $this->assertSame($expected, $query->pageNumber());
      $this->assertSame($expected, $query->pageSize());
    }

    #[Test]
    public function the_keys_are_kept_in_the_order_the_client_gave(): void {
      $this->assertSame(['size' => 25, 'number' => 2], $this->query('page[size]=25&page[number]=2')->page());
    }

    #[Test]
    public function any_other_page_key_is_kept_as_the_client_wrote_it(): void {
      // La familia `page` es de estrategia libre: lo que no es número ni tamaño lo interpreta la aplicación, sin convertir ni los nombres ni los valores.
      // The `page` family has a free strategy: what is not a number or a size is for the application to interpret, converting neither names nor values.
      $query = $this->query('page[cursor]=abc&page[pageOffset]=5&page[number]=3&page[after][id]=9');

      $this->assertSame(['cursor' => 'abc', 'pageOffset' => '5', 'number' => 3, 'after' => ['id' => '9']], $query->page());
      $this->assertSame(3, $query->pageNumber());
    }

    #[Test]
    public function without_page_there_is_no_pagination_request(): void {
      $query = $this->query('');

      $this->assertSame([], $query->page());
      $this->assertNull($query->pageNumber());
      $this->assertNull($query->pageSize());
    }

    #[Test]
    public function page_does_not_depend_on_the_member_case(): void {
      foreach (['camel', 'kebab', 'snake'] as $style) {
        $this->assertSame(['number' => 2, 'size' => 5], $this->query('page[number]=2&page[size]=5', $style)->page(), $style);
      }
    }

    // ---------------------------------------------------------------------------------------------
    // Todo junto, y lo que no se lee / Everything together, and what is not read
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function every_parameter_can_come_together(): void {
      $query = $this->query('include=author&fields[articles]=title&sort=-createdAt&filter[status]=published&page[number]=2&page[size]=5');

      $this->assertSame(['number' => 2, 'size' => 5], $query->page());
      $this->assertSame([['author']], $query->includes());
      $this->assertSame(['articles' => ['title']], $query->fields());
      $this->assertSame([['field' => 'created_at', 'descending' => true]], $query->sort());
      $this->assertSame(['status' => 'published'], $query->filter());
    }

    #[Test]
    public function other_parameters_are_left_alone(): void {
      $query = $this->query('foo=1&utm_source=mail&include=author');

      $this->assertSame([['author']], $query->includes());
      $this->assertSame([], $query->fields());
      $this->assertSame([], $query->sort());
      $this->assertSame([], $query->filter());
    }

    #[Test]
    public function only_the_query_string_is_read_not_the_body(): void {
      // En un POST, `Request::create` pone estos datos en el cuerpo: no son parámetros de consulta.
      // In a POST, `Request::create` puts this data in the body: they are not query parameters.
      $request = Request::create('/api/articles', 'POST', ['include' => 'author', 'sort' => 'title']);

      $query = QueryParameters::from($request, $this->settings());

      $this->assertSame([], $query->includes());
      $this->assertSame([], $query->sort());
      $this->assertSame([], $query->page());
    }
  }
