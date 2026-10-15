<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Contracts\Debug\ExceptionHandler;
  use Illuminate\Http\Request;
  use MundoProgra\JsonApi\Tests\Fixtures\Providers\AppRenderableAtBoot;
  use MundoProgra\JsonApi\Tests\Fixtures\Providers\AppRenderableAtRegister;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;
  use Throwable;

  /**
   * Si la app define su propia respuesta para una excepción, esa gana sobre la del paquete, sin importar dónde la registre.
   * If the app defines its own response for an exception, that one wins over the package one, no matter where it registers it.
   */
  class ExceptionRenderingOrderTest extends TestCase {
    protected function getPackageProviders($app): array {
      // El primero se registra antes que el provider del paquete y el último arranca después de él.
      // The first registers before the package provider and the last one boots after it.
      return [AppRenderableAtRegister::class, ...parent::getPackageProviders($app), AppRenderableAtBoot::class];
    }

    protected function setUp(): void {
      parent::setUp();

      // Estas pruebas ejercitan el orden del renderizador, no el alcance: en modo global el paquete aplica a todas las rutas de prueba.
      // These tests exercise the renderer order, not the scope: in global mode the package applies to every test route.
      config()->set('json-api.mode', 'global');
    }

    protected function defineRoutes($router): void {
      foreach ([402, 403, 409] as $status) {
        $router->get("abort$status", fn() => abort($status));
      }
    }

    #[Test]
    public function a_response_the_app_registered_before_the_package_wins(): void {
      $response = $this->get('abort402');

      $response->assertStatus(402);
      $this->assertSame(['app' => 'registered first'], $response->json());
    }

    #[Test]
    public function a_response_the_app_registered_in_a_provider_boot_wins_too(): void {
      $response = $this->get('abort409');

      $response->assertStatus(409);
      $this->assertSame(['app' => 'registered at boot'], $response->json());
    }

    #[Test]
    public function the_package_answers_what_the_app_does_not_handle(): void {
      // Las funciones de la app devuelven null para los demás errores: el paquete responde.
      // The app callbacks return null for the other errors: the package answers.
      $response = $this->get('abort403');

      $response->assertStatus(403);
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
      $this->assertSame('mundoprogra.json-api.auth.forbidden', $response->json('errors.0.code'));
    }

    #[Test]
    public function the_package_hooks_in_only_once(): void {
      // Una sola función de renderizado del paquete, aunque el manejador se resuelva varias veces.
      // A single package render callback, even if the handler is resolved several times.
      $this->app->make(ExceptionHandler::class);
      $this->app->make(ExceptionHandler::class);

      $handler = $this->app->make(ExceptionHandler::class);
      $callbacks = (fn() => $this->renderCallbacks)->call($handler);

      // Las de la app (2) más la del paquete (1).
      // The app ones (2) plus the package one (1).
      $this->assertCount(3, $callbacks);
    }

    #[Test]
    public function the_app_can_retouch_the_package_response_when_laravel_supports_it(): void {
      $handler = $this->app->make(ExceptionHandler::class);

      if (!method_exists($handler, 'respondUsing')) {
        $this->markTestSkipped('respondUsing() exists from Laravel 11.');
      }

      $handler->respondUsing(fn($response, Throwable $e, Request $request) => $response->header('X-Retouched-By-App', 'yes'));

      $response = $this->get('abort403');

      $this->assertSame('yes', $response->headers->get('X-Retouched-By-App'));
      $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }
  }
