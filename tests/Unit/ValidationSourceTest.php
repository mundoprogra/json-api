<?php

  namespace MundoProgra\JsonApi\Tests\Unit;

  use Illuminate\Config\Repository;
  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Errors\ValidationSource;
  use MundoProgra\JsonApi\Support\Settings;
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;

  class ValidationSourceTest extends TestCase {
    /**
     * @return array<string, array{string, string, ?array<string, mixed>, string, string, array<string, string>}>
     */
    public static function cases(): array {
      // [método, URL, cuerpo, clave que entrega Laravel, estilo de nombres, ubicación esperada]
      // [method, URL, body, key given by Laravel, naming style, expected location]
      $article = fn(array $attributes = [], array $relationships = []) => ['data' => array_filter([
        'type' => 'articles',
        'attributes' => $attributes ?: null,
        'relationships' => $relationships ?: null,
      ])];

      return [
        // --- Regla 1: la clave ya es una ruta del documento / Rule 1: the key is already a document path
        'the whole document' => ['POST', '/articles', $article(['title' => 'x']), 'data', 'camel', ['pointer' => '/data']],
        'an attribute written as a document path' => ['POST', '/articles', $article(), 'data.attributes.email', 'camel', ['pointer' => '/data/attributes/email']],
        'a linkage written as a document path' => ['POST', '/articles', $article(), 'data.relationships.tags.data.0.id', 'camel', ['pointer' => '/data/relationships/tags/data/0/id']],
        'the type member' => ['POST', '/articles', $article(), 'data.type', 'camel', ['pointer' => '/data/type']],
        'document paths keep the client names' => ['POST', '/articles', $article(), 'data.attributes.firstName', 'camel', ['pointer' => '/data/attributes/firstName']],
        'a document path on a read request' => ['GET', '/articles', null, 'data.attributes.x', 'camel', ['pointer' => '/data/attributes/x']],

        // --- Regla 2: el dato venía en la URL / Rule 2: the value came in the URL
        'page size' => ['GET', '/articles?page[size]=abc', null, 'page.size', 'camel', ['parameter' => 'page[size]']],
        'page number' => ['GET', '/articles?page[number]=0', null, 'page.number', 'camel', ['parameter' => 'page[number]']],
        'sort' => ['GET', '/articles?sort=-title', null, 'sort', 'camel', ['parameter' => 'sort']],
        'a filter' => ['GET', '/articles?filter[status]=x', null, 'filter.status', 'camel', ['parameter' => 'filter[status]']],
        'a sparse fieldset' => ['GET', '/articles?fields[articles]=title', null, 'fields.articles', 'camel', ['parameter' => 'fields[articles]']],
        'include on a write request' => ['POST', '/articles?include=author', $article(['title' => 'x']), 'include', 'camel', ['parameter' => 'include']],
        'in the URL and in the document: the document wins' => ['POST', '/articles?sort=x', $article(['sort' => 'y']), 'sort', 'camel', ['pointer' => '/data/attributes/sort']],

        // --- Regla 3: una relación / Rule 3: a relationship
        'a relationship' => ['POST', '/articles', $article([], ['author' => ['data' => null]]), 'author', 'camel', ['pointer' => '/data/relationships/author']],
        'a relationship in the client style' => ['POST', '/articles', $article([], ['homeAddress' => ['data' => null]]), 'home_address', 'camel', ['pointer' => '/data/relationships/homeAddress']],
        'a relationship in kebab style' => ['POST', '/articles', $article([], ['home-address' => ['data' => null]]), 'home_address', 'kebab', ['pointer' => '/data/relationships/home-address']],
        'a nested part of a relationship' => ['POST', '/articles', $article([], ['tags' => ['data' => []]]), 'tags.0.id', 'camel', ['pointer' => '/data/relationships/tags/0/id']],

        // --- Regla 4: un atributo / Rule 4: an attribute
        'an attribute present in the body' => ['POST', '/articles', $article(['email' => 'x']), 'email', 'camel', ['pointer' => '/data/attributes/email']],
        'an attribute missing from the body' => ['POST', '/articles', $article(['title' => 'x']), 'email', 'camel', ['pointer' => '/data/attributes/email']],
        'a request with no body at all' => ['POST', '/articles', null, 'email', 'camel', ['pointer' => '/data/attributes/email']],
        'camel case' => ['POST', '/articles', $article(), 'first_name', 'camel', ['pointer' => '/data/attributes/firstName']],
        'kebab case' => ['POST', '/articles', $article(), 'first_name', 'kebab', ['pointer' => '/data/attributes/first-name']],
        'snake case' => ['POST', '/articles', $article(), 'first_name', 'snake', ['pointer' => '/data/attributes/first_name']],
        'only the first level is converted' => ['POST', '/articles', $article(), 'home_address.street_name', 'camel', ['pointer' => '/data/attributes/homeAddress/street_name']],
        'a position inside an attribute' => ['POST', '/articles', $article(), 'tags.0.name', 'camel', ['pointer' => '/data/attributes/tags/0/name']],
        'a patch is a write request' => ['PATCH', '/articles/1', $article(['email' => 'x']), 'email', 'camel', ['pointer' => '/data/attributes/email']],
        'a put is a write request' => ['PUT', '/articles/1', $article(['email' => 'x']), 'email', 'camel', ['pointer' => '/data/attributes/email']],
        'special characters are escaped' => ['POST', '/articles', $article(), 'a~b', 'snake', ['pointer' => '/data/attributes/a~0b']],

        // --- Regla 5: una lectura / Rule 5: a read request
        'a query parameter that is missing' => ['GET', '/articles', null, 'q', 'camel', ['parameter' => 'q']],
        'a nested query parameter that is missing' => ['GET', '/articles', null, 'page.size', 'camel', ['parameter' => 'page[size]']],
        'a head request is a read request' => ['HEAD', '/articles', null, 'q', 'camel', ['parameter' => 'q']],
        'a route parameter' => ['GET', '/articles/abc', null, 'article', 'camel', ['parameter' => 'article']],
      ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $expected
     */
    #[Test]
    #[DataProvider('cases')]
    public function tells_where_a_validation_error_points_to(string $method, string $uri, ?array $body, string $key, string $case, array $expected): void {
      $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/vnd.api+json'],
        $body === null ? null : json_encode($body)
      );

      $this->assertSame($expected, $this->source($case)->resolve($key, $request));
    }

    private function source(string $memberCase): ValidationSource {
      $config = require __DIR__ . '/../../config/json-api.php';
      Arr::set($config, 'member_case', $memberCase);

      return new ValidationSource(new Settings(new Repository(['json-api' => $config, 'app' => ['debug' => false]])));
    }
  }
