<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Pagination\LengthAwarePaginator;
  use MundoProgra\JsonApi\Query\Pagination;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Los links y el `meta` de paginación de JSON:API, armados por el paquete a partir de la página que trajo Eloquent y de la petición.
   * El paginador de Laravel usa `?page=2`; aquí se usa `page[number]` y `page[size]`. Los links conservan lo que mandó el cliente tal como lo
   * mandó (`sort`, `filter`, `include`, `fields` y lo ajeno), cambian solo el número de página y siempre llevan el tamaño efectivo. `prev` y
   * `next` son `null` en los extremos. La URL base sale de la petición, así que respeta los proxies de confianza.
   * The JSON:API pagination links and `meta`, built by the package from the page Eloquent fetched and the request. Laravel's paginator uses
   * `?page=2`; here `page[number]` and `page[size]` are used. The links keep what the client sent exactly as sent (`sort`, `filter`, `include`,
   * `fields` and anything else), change only the page number and always carry the effective size. `prev` and `next` are `null` at the ends.
   * The base URL comes from the request, so it respects trusted proxies.
   */
  class PaginationTest extends TestCase {
    use AssertsJsonApi;

    protected function tearDown(): void {
      Request::setTrustedProxies([], 0);

      parent::tearDown();
    }

    private function paginator(int $total, int $size, int $page): LengthAwarePaginator {
      return new LengthAwarePaginator([], $total, $size, $page);
    }

    private function pagination(int $total, int $size, int $page, string $uri = 'http://localhost/api/articles'): Pagination {
      return Pagination::of($this->paginator($total, $size, $page), Request::create($uri));
    }

    /**
     * Los parámetros de consulta de un link, ya decodificados.
     * The query parameters of a link, already decoded.
     *
     * @return array<string, mixed>
     */
    private function queryOf(?string $url): array {
      $this->assertNotNull($url);
      parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

      return $query;
    }

    // ---------------------------------------------------------------------------------------------
    // Los cuatro links / The four links
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_first_page_has_no_previous_link(): void {
      $links = $this->pagination(total: 50, size: 10, page: 1)->links();

      $this->assertSame('1', $this->queryOf($links['first'])['page']['number']);
      $this->assertSame('5', $this->queryOf($links['last'])['page']['number']);
      $this->assertNull($links['prev']);
      $this->assertSame('2', $this->queryOf($links['next'])['page']['number']);
    }

    #[Test]
    public function a_page_in_the_middle_has_both_neighbours(): void {
      $links = $this->pagination(total: 50, size: 10, page: 3)->links();

      $this->assertSame('2', $this->queryOf($links['prev'])['page']['number']);
      $this->assertSame('4', $this->queryOf($links['next'])['page']['number']);
    }

    #[Test]
    public function the_last_page_has_no_next_link(): void {
      $links = $this->pagination(total: 50, size: 10, page: 5)->links();

      $this->assertSame('4', $this->queryOf($links['prev'])['page']['number']);
      $this->assertNull($links['next']);
    }

    #[Test]
    public function with_no_results_there_is_a_single_page(): void {
      $pagination = $this->pagination(total: 0, size: 10, page: 1);
      $links = $pagination->links();

      $this->assertSame('1', $this->queryOf($links['first'])['page']['number']);
      $this->assertSame('1', $this->queryOf($links['last'])['page']['number']);
      $this->assertNull($links['prev']);
      $this->assertNull($links['next']);
      $this->assertSame(1, $pagination->meta()['page']['currentPage']);
      $this->assertSame(1, $pagination->meta()['page']['lastPage']);
    }

    #[Test]
    public function the_four_links_are_always_present_in_this_order(): void {
      $this->assertSame(['first', 'last', 'prev', 'next'], array_keys($this->pagination(total: 5, size: 10, page: 1)->links()));
    }

    #[Test]
    public function a_page_beyond_the_last_points_back_to_the_last_existing_page_and_has_no_next(): void {
      // 30 resultados en páginas de 10: la última es la 3. Pedir la 9 da una página vacía, y `prev` lleva a la 3, no a la 8.
      // 30 results in pages of 10: the last is the 3rd. Asking for the 9th gives an empty page, and `prev` leads to the 3rd, not the 8th.
      $pagination = $this->pagination(total: 30, size: 10, page: 9);
      $links = $pagination->links();

      $this->assertSame('1', $this->queryOf($links['first'])['page']['number']);
      $this->assertSame('3', $this->queryOf($links['last'])['page']['number']);
      $this->assertSame('3', $this->queryOf($links['prev'])['page']['number']);
      $this->assertNull($links['next']);
      $this->assertSame(9, $pagination->meta()['page']['currentPage']);
      $this->assertSame(3, $pagination->meta()['page']['lastPage']);
    }

    // ---------------------------------------------------------------------------------------------
    // Qué conservan los links / What the links keep
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_links_keep_what_the_client_sent_exactly_as_sent(): void {
      $uri = 'http://localhost/api/articles?sort=-createdAt&filter[status]=published&include=author,comments.user&fields[articles]=title,body&utm_source=mail&page[number]=2&page[size]=5';

      $query = $this->queryOf(Pagination::of($this->paginator(40, 5, 2), Request::create($uri))->links()['next']);

      $this->assertSame('-createdAt', $query['sort']);
      $this->assertSame(['status' => 'published'], $query['filter']);
      $this->assertSame('author,comments.user', $query['include']);
      $this->assertSame(['articles' => 'title,body'], $query['fields']);
      $this->assertSame('mail', $query['utm_source']);
      $this->assertSame(['number' => '3', 'size' => '5'], $query['page']);
    }

    #[Test]
    public function a_value_with_special_characters_survives_the_round_trip(): void {
      $uri = 'http://localhost/api/articles?filter[title]=a%20b%26c%3Dd';

      $query = $this->queryOf(Pagination::of($this->paginator(40, 10, 1), Request::create($uri))->links()['next']);

      $this->assertSame('a b&c=d', $query['filter']['title']);
    }

    #[Test]
    public function the_effective_size_is_always_there_even_if_the_client_did_not_ask_for_it(): void {
      $links = $this->pagination(total: 50, size: 15, page: 1)->links();

      $this->assertSame('15', $this->queryOf($links['next'])['page']['size']);
      $this->assertSame('15', $this->queryOf($links['first'])['page']['size']);
    }

    #[Test]
    public function the_size_in_the_links_is_the_one_that_was_applied_not_the_one_asked_for(): void {
      // El cliente pidió 500 y se recortó a 100: los links llevan 100, para que las demás páginas pidan lo mismo que se sirvió.
      // The client asked for 500 and it was trimmed to 100: the links carry 100, so the other pages ask for what was served.
      $query = $this->queryOf(Pagination::of($this->paginator(1000, 100, 1), Request::create('http://localhost/api/articles?page[size]=500'))->links()['next']);

      $this->assertSame('100', $query['page']['size']);
    }

    #[Test]
    public function any_other_page_key_is_kept(): void {
      $uri = 'http://localhost/api/articles?page[cursor]=abc&page[number]=1';

      $query = $this->queryOf(Pagination::of($this->paginator(40, 10, 1), Request::create($uri))->links()['next']);

      $this->assertSame('abc', $query['page']['cursor']);
      $this->assertSame('2', $query['page']['number']);
    }

    #[Test]
    public function the_path_is_the_one_of_the_request(): void {
      $links = $this->pagination(total: 50, size: 10, page: 1, uri: 'http://localhost/api/v1/users/7/articles')->links();

      $this->assertSame('http://localhost/api/v1/users/7/articles', explode('?', $links['next'])[0]);
    }

    #[Test]
    public function the_exact_text_of_a_link_has_the_brackets_encoded(): void {
      $links = $this->pagination(total: 50, size: 10, page: 1)->links();

      $this->assertSame('http://localhost/api/articles?page%5Bnumber%5D=2&page%5Bsize%5D=10', $links['next']);
    }

    // ---------------------------------------------------------------------------------------------
    // Proxies / Proxies
    // ---------------------------------------------------------------------------------------------

    private function proxiedRequest(): Request {
      return Request::create('http://internal:8000/api/articles', 'GET', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'api.example.com',
        'HTTP_X_FORWARDED_PORT' => '443',
      ]);
    }

    #[Test]
    public function behind_a_trusted_proxy_the_links_use_the_public_address(): void {
      Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

      $links = Pagination::of($this->paginator(50, 10, 1), $this->proxiedRequest())->links();

      $this->assertStringStartsWith('https://api.example.com/api/articles?', $links['next']);
      $this->assertStringStartsWith('https://api.example.com/api/articles?', $links['last']);
    }

    #[Test]
    public function without_a_trusted_proxy_the_forwarded_headers_are_ignored(): void {
      $links = Pagination::of($this->paginator(50, 10, 1), $this->proxiedRequest())->links();

      $this->assertStringStartsWith('http://internal:8000/api/articles?', $links['next']);
    }

    // ---------------------------------------------------------------------------------------------
    // meta.page
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function meta_page_has_the_current_page_the_size_the_last_page_and_the_total(): void {
      $this->assertSame(
        ['page' => ['currentPage' => 3, 'perPage' => 10, 'lastPage' => 5, 'total' => 47]],
        $this->pagination(total: 47, size: 10, page: 3)->meta(),
      );
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function styles(): array {
      return [
        'camel' => ['camel', ['currentPage', 'perPage', 'lastPage', 'total']],
        'kebab' => ['kebab', ['current-page', 'per-page', 'last-page', 'total']],
        'snake' => ['snake', ['current_page', 'per_page', 'last_page', 'total']],
      ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('styles')]
    public function the_meta_names_follow_the_member_case(string $style, array $expected): void {
      config()->set('json-api.member_case', $style);

      $this->assertSame($expected, array_keys($this->pagination(total: 47, size: 10, page: 3)->meta()['page']));
    }

    // ---------------------------------------------------------------------------------------------
    // El documento / The document
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_document_with_these_links_and_meta_is_valid_for_the_official_schema(): void {
      $pagination = $this->pagination(total: 47, size: 10, page: 3, uri: 'http://localhost/api/articles?sort=-title');

      $this->assertJsonApiDocument(['jsonapi' => ['version' => '1.1'], 'data' => [], 'links' => $pagination->links(), 'meta' => $pagination->meta()]);
    }

    #[Test]
    public function asking_twice_gives_the_same_answer(): void {
      $pagination = $this->pagination(total: 47, size: 10, page: 3);

      $this->assertSame($pagination->links(), $pagination->links());
      $this->assertSame($pagination->meta(), $pagination->meta());
    }
  }
