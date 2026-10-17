<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Tests\Fixtures\Models\User;
  use MundoProgra\JsonApi\Tests\Fixtures\Resources\UserResource;
  use MundoProgra\JsonApi\Tests\Support\AssertsJsonApi;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  /**
   * Criterio de aceptación de la Fase 6: el `UserResource` de `example-app` se puede reproducir con el paquete. El recurso de prueba tiene
   * los mismos atributos y el mismo link `self` (con el nombre de ruta `api.v1.users.show`); el objeto de recurso sale idéntico y la única
   * diferencia con la respuesta de hoy es el miembro `jsonapi`, que ya estaba documentado. No se toca la app: la adopción real es de la Fase 13.
   * Phase 6 acceptance criterion: `example-app`' `UserResource` can be reproduced with the package. The test resource has the same
   * attributes and the same `self` link (with the route name `api.v1.users.show`); the resource object comes out identical and the only
   * difference from today's response is the `jsonapi` member, which was already documented. The app is not touched: the real adoption is Phase 13.
   */
  class UserResourceEquivalenceTest extends TestCase {
    use AssertsJsonApi;

    private const UUID = '7b0f3f0e-6c1b-4a58-9d63-0c2a0d5f0a11';

    protected function setUp(): void {
      parent::setUp();

      // Como en la app: el prefijo de los nombres de ruta es `api.v1.` y el recurso solo dice `users.show`.
      // As in the app: the route name prefix is `api.v1.` and the resource only says `users.show`.
      config()->set('json-api.links.route_name_prefix', 'api.v1.');
    }

    protected function defineRoutes($router): void {
      $router->get('api/v1/users/{user}', fn() => UserResource::make($this->user()))->name('api.v1.users.show');
    }

    private function user(): User {
      return (new User())->forceFill([
        'uuid' => self::UUID,
        'username' => 'ada',
        'email' => 'ada@example.com',
        'phone' => null,
        'password' => 'secret',
      ]);
    }

    /**
     * Lo que produce hoy `App\Http\Resources\UserResource::toArray()` en `example-app`.
     * What `App\Http\Resources\UserResource::toArray()` in `example-app` produces today.
     *
     * @return array<string, mixed>
     */
    private function whatTheAppProducesToday(User $user): array {
      return [
        'type' => 'users',
        'id' => (string) $user->getRouteKey(),
        'attributes' => [
          'username' => $user->username,
          'email' => $user->email,
          'phone' => $user->phone,
        ],
        'links' => [
          'self' => route('api.v1.users.show', $user),
        ],
      ];
    }

    #[Test]
    public function the_resource_object_is_identical_to_the_one_the_app_produces_today(): void {
      $user = $this->user();

      $this->assertSame(
        $this->whatTheAppProducesToday($user),
        UserResource::make($user)->resolve(Request::create('/api/v1/users/' . self::UUID)),
      );
    }

    #[Test]
    public function the_id_is_the_uuid_as_text_and_the_type_is_the_table(): void {
      $data = UserResource::make($this->user())->resolve(Request::create('/'));

      $this->assertSame(self::UUID, $data['id']);
      $this->assertSame('users', $data['type']);
    }

    #[Test]
    public function the_null_phone_is_kept_and_what_is_not_declared_is_never_shown(): void {
      $attributes = UserResource::make($this->user())->resolve(Request::create('/'))['attributes'];

      $this->assertArrayHasKey('phone', $attributes);
      $this->assertNull($attributes['phone']);
      $this->assertSame(['username', 'email', 'phone'], array_keys($attributes));
    }

    #[Test]
    public function the_only_difference_in_the_response_is_the_jsonapi_member(): void {
      $user = $this->user();

      $response = $this->get('api/v1/users/' . self::UUID, ['Accept' => 'application/vnd.api+json']);

      $response->assertOk();
      $this->assertSame($this->whatTheAppProducesToday($user), $response->json('data'));
      $this->assertSame(['jsonapi', 'data'], array_keys((array) $response->json()));
      $this->assertSame(['version' => '1.1'], $response->json('jsonapi'));
      $this->assertJsonApiDocument((string) $response->getContent());
    }

    #[Test]
    public function turning_the_jsonapi_member_off_leaves_exactly_what_the_app_returns_today(): void {
      config()->set('json-api.jsonapi_object', false);

      $response = $this->get('api/v1/users/' . self::UUID, ['Accept' => 'application/vnd.api+json']);

      $this->assertSame(['data' => $this->whatTheAppProducesToday($this->user())], $response->json());
    }
  }
