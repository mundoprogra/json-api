<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Support\Facades\File;
  use Illuminate\Support\ServiceProvider;
  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Support\Settings;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  class ConfigPublishTest extends TestCase {
    /**
     * Carpeta temporal que hace de config/ de la app, para no escribir dentro de vendor/.
     * Temporary folder that acts as the app config/, so nothing is written inside vendor/.
     */
    private string $configPath;

    protected function defineEnvironment($app): void {
      parent::defineEnvironment($app);

      // Los providers se arrancan después de este método, así que el destino de publicación ya usa esta ruta.
      // Providers are booted after this method, so the publish destination already uses this path.
      $this->configPath = sys_get_temp_dir() . '/json-api-publish-' . uniqid();
      mkdir($this->configPath);

      $app->useConfigPath($this->configPath);
    }

    protected function tearDown(): void {
      File::deleteDirectory($this->configPath);

      parent::tearDown();
    }

    #[Test]
    public function registers_the_json_api_config_tag_pointing_to_the_app_config_folder(): void {
      $paths = ServiceProvider::pathsToPublish(JsonApiServiceProvider::class, 'json-api-config');

      $this->assertCount(1, $paths);
      $this->assertSame(realpath($this->packageConfigFile()), realpath((string) array_key_first($paths)));
      $this->assertSame($this->configPath . '/json-api.php', array_values($paths)[0]);
    }

    #[Test]
    public function the_artisan_command_copies_the_config_file_into_the_app(): void {
      $published = $this->configPath . '/json-api.php';

      $this->assertFileDoesNotExist($published);

      $this->artisan('vendor:publish', ['--tag' => 'json-api-config'])->assertSuccessful();

      $this->assertFileExists($published);
      $this->assertFileEquals($this->packageConfigFile(), $published);
    }

    #[Test]
    public function the_published_file_is_complete_and_works_as_the_app_config(): void {
      $this->artisan('vendor:publish', ['--tag' => 'json-api-config'])->assertSuccessful();

      // La app carga su copia publicada antes de que el paquete fusione sus valores por defecto.
      // The app loads its published copy before the package merges its defaults.
      config()->set('json-api', require $this->configPath . '/json-api.php');
      $this->app->register(JsonApiServiceProvider::class, true);

      $settings = $this->app->make(Settings::class);

      $this->assertSame('prefix', $settings->mode());
      $this->assertSame(15, $settings->paginationSize());
      $this->assertSame(['api'], $settings->pathPrefixes());
    }

    private function packageConfigFile(): string {
      return __DIR__ . '/../../config/json-api.php';
    }
  }
