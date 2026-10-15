<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\Validator;
  use Illuminate\Validation\ValidationException;
  use MundoProgra\JsonApi\Tests\Fixtures\Rules\StrongPassword;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  class ValidationRenderingTest extends TestCase {
    use AssertsJsonApi;

    private const MESSAGES = [
      'required' => ':attribute is required',
      'email' => 'Not a valid email',
      'max' => 'Too long',
      'integer' => 'Must be a whole number',
    ];

    protected function setUp(): void {
      parent::setUp();

      $this->app->setLocale('en');
      config()->set('app.debug', false);

      // Estas pruebas ejercitan el renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');
    }

    protected function defineRoutes($router): void {
      // Reglas escritas con los nombres planos de los atributos, como hace una app que valida $request->input('data.attributes').
      // Rules written with the flat attribute names, like an app that validates $request->input('data.attributes').
      $router->post('attribute', fn(Request $request) => Validator::make(
        $request->input('data.attributes', []),
        ['title' => 'required', 'email' => 'email|max:5'],
        self::MESSAGES
      )->validate());

      $router->post('camel', fn(Request $request) => Validator::make(
        $request->input('data.attributes', []),
        ['first_name' => 'required'],
        self::MESSAGES
      )->validate());

      $router->post('password', fn(Request $request) => Validator::make(
        $request->input('data.attributes', []),
        ['password' => [new StrongPassword()]]
      )->validate());

      $router->post('relationship', fn(Request $request) => Validator::make(
        $request->input('data.relationships', []),
        ['author.data.id' => 'integer'],
        self::MESSAGES
      )->validate());

      // Reglas escritas con la ruta del documento.
      // Rules written with the document path.
      $router->post('document', fn(Request $request) => $request->validate(['data.attributes.title' => 'required'], self::MESSAGES));

      // Un dato que venía en la URL.
      // A value that came in the URL.
      $router->get('query', fn(Request $request) => $request->validate(['page.size' => 'integer|min:1'], self::MESSAGES));

      $router->post('with-messages', fn() => throw ValidationException::withMessages(['title' => 'The title is not allowed']));
      $router->post('with-status', fn() => throw ValidationException::withMessages(['title' => 'Bad'])->status(400));
    }

    #[Test]
    public function a_failed_rule_becomes_an_error_that_points_to_the_attribute(): void {
      $response = $this->postJson('attribute', ['data' => ['type' => 'articles', 'attributes' => ['email' => 'a@b.c']]]);

      $response->assertStatus(422);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame([
        'status' => '422',
        'code' => 'mundoprogra.json-api.validation.required',
        'title' => 'Validation failed',
        'detail' => 'title is required',
        'source' => ['pointer' => '/data/attributes/title'],
      ], $response->json('errors.0'));
      $this->assertCount(1, $response->json('errors'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function a_field_that_breaks_two_rules_gives_two_errors_in_order_each_with_its_own_code(): void {
      $response = $this->postJson('attribute', ['data' => ['attributes' => ['title' => 'x', 'email' => 'not-an-email']]]);

      $errors = $response->json('errors');

      $this->assertCount(2, $errors);
      $this->assertSame(
        ['mundoprogra.json-api.validation.email', 'mundoprogra.json-api.validation.max'],
        array_column($errors, 'code')
      );
      $this->assertSame(['Not a valid email', 'Too long'], array_column($errors, 'detail'));
      $this->assertSame(['/data/attributes/email', '/data/attributes/email'], array_column(array_column($errors, 'source'), 'pointer'));
    }

    #[Test]
    public function several_fields_give_one_error_per_field_and_rule(): void {
      $response = $this->postJson('attribute', ['data' => ['attributes' => ['email' => 'not-an-email']]]);

      $this->assertSame(
        ['/data/attributes/title', '/data/attributes/email', '/data/attributes/email'],
        array_column(array_column($response->json('errors'), 'source'), 'pointer')
      );
    }

    #[Test]
    public function the_status_of_every_error_is_422_and_so_is_the_response(): void {
      $response = $this->postJson('attribute', ['data' => ['attributes' => ['email' => 'not-an-email']]]);

      $response->assertStatus(422);
      $this->assertSame(['422', '422', '422'], array_column($response->json('errors'), 'status'));
    }

    #[Test]
    public function an_error_written_with_the_document_path_points_to_the_same_place(): void {
      $response = $this->postJson('document', ['data' => ['type' => 'articles', 'attributes' => []]]);

      $this->assertSame('/data/attributes/title', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    public function a_value_that_came_in_the_url_points_to_the_query_parameter(): void {
      $response = $this->getJson('query?page[size]=abc');

      $response->assertStatus(422);
      $this->assertSame(['parameter' => 'page[size]'], $response->json('errors.0.source'));
      $this->assertSame('mundoprogra.json-api.validation.integer', $response->json('errors.0.code'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function an_error_in_a_relationship_points_to_the_relationship(): void {
      $response = $this->postJson('relationship', ['data' => ['type' => 'articles', 'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => 'abc']]]]]);

      $this->assertSame('/data/relationships/author/data/id', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    public function the_pointer_uses_the_naming_style_of_the_client(): void {
      $this->assertSame(
        '/data/attributes/firstName',
        $this->postJson('camel', ['data' => ['attributes' => []]])->json('errors.0.source.pointer')
      );

      config()->set('json-api.member_case', 'kebab');
      $this->assertSame(
        '/data/attributes/first-name',
        $this->postJson('camel', ['data' => ['attributes' => []]])->json('errors.0.source.pointer')
      );
    }

    #[Test]
    public function an_exception_made_by_hand_has_no_rule_so_its_code_is_the_general_one(): void {
      $response = $this->postJson('with-messages');

      $response->assertStatus(422);
      $this->assertSame('mundoprogra.json-api.validation.failed', $response->json('errors.0.code'));
      $this->assertSame('The title is not allowed', $response->json('errors.0.detail'));
      $this->assertSame('/data/attributes/title', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    public function the_status_chosen_by_the_exception_is_respected(): void {
      $response = $this->postJson('with-status');

      $response->assertStatus(400);
      $this->assertSame('400', $response->json('errors.0.status'));
    }

    #[Test]
    public function a_custom_rule_gets_its_code_from_the_name_of_its_class(): void {
      $response = $this->postJson('password', ['data' => ['attributes' => ['password' => 'abc']]]);

      $this->assertSame('mundoprogra.json-api.validation.strong_password', $response->json('errors.0.code'));
      $this->assertSame('The password is too weak.', $response->json('errors.0.detail'));
      $this->assertSame('/data/attributes/password', $response->json('errors.0.source.pointer'));
    }

    #[Test]
    public function the_validation_messages_are_shown_even_when_error_details_are_hidden(): void {
      config()->set('json-api.errors.expose_details', false);

      $this->assertSame('title is required', $this->postJson('attribute', ['data' => ['attributes' => []]])->json('errors.0.detail'));
    }

    #[Test]
    public function the_code_settings_are_applied(): void {
      config()->set('json-api.errors.codes', ['validation.required' => 'ERR-1001']);
      $this->assertSame('ERR-1001', $this->postJson('attribute', ['data' => ['attributes' => []]])->json('errors.0.code'));

      config()->set('json-api.errors.include_code', false);
      $this->assertArrayNotHasKey('code', $this->postJson('attribute', ['data' => ['attributes' => []]])->json('errors.0'));
    }

    #[Test]
    public function the_jsonapi_member_follows_the_configuration(): void {
      $payload = ['data' => ['attributes' => []]];

      // Primero se comprueba que sí está, para que la prueba no pase por una respuesta que nunca lo tuvo.
      // First it is checked that it is there, so the test does not pass because of a response that never had it.
      $this->assertSame(['version' => '1.1'], $this->postJson('attribute', $payload)->json('jsonapi'));

      config()->set('json-api.jsonapi_object', false);
      $response = $this->postJson('attribute', $payload);

      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertArrayNotHasKey('jsonapi', $response->json());
    }
  }
