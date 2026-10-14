<?php

  namespace MundoProgra\JsonApi\Tests\Feature;

  use Illuminate\Support\Facades\File;
  use Illuminate\Support\ServiceProvider;
  use MundoProgra\JsonApi\Errors\ErrorCodes;
  use MundoProgra\JsonApi\Errors\ErrorMessages;
  use MundoProgra\JsonApi\JsonApiServiceProvider;
  use MundoProgra\JsonApi\Tests\TestCase;
  use PHPUnit\Framework\Attributes\Test;

  class TranslationPublishTest extends TestCase {
    /**
     * Carpeta temporal que hace de lang/ de la app, para no escribir dentro de vendor/.
     * Temporary folder that acts as the app lang/, so nothing is written inside vendor/.
     */
    private string $langPath;

    protected function defineEnvironment($app): void {
      parent::defineEnvironment($app);

      $this->langPath = sys_get_temp_dir() . '/json-api-lang-' . uniqid();
      mkdir($this->langPath);

      $app->useLangPath($this->langPath);
    }

    protected function tearDown(): void {
      File::deleteDirectory($this->langPath);

      parent::tearDown();
    }

    #[Test]
    public function registers_the_json_api_lang_tag_pointing_to_the_vendor_folder_of_the_app(): void {
      $paths = ServiceProvider::pathsToPublish(JsonApiServiceProvider::class, 'json-api-lang');

      $this->assertCount(1, $paths);
      $this->assertSame(realpath(__DIR__ . '/../../lang'), realpath((string) array_key_first($paths)));
      $this->assertSame($this->langPath . '/vendor/json-api', array_values($paths)[0]);
    }

    #[Test]
    public function the_artisan_command_copies_both_languages_into_the_app(): void {
      $this->artisan('vendor:publish', ['--tag' => 'json-api-lang'])->assertSuccessful();

      $this->assertFileExists($this->langPath . '/vendor/json-api/en/errors.php');
      $this->assertFileExists($this->langPath . '/vendor/json-api/es/errors.php');
    }

    #[Test]
    public function a_text_edited_in_the_app_overrides_the_package_text_and_keeps_the_rest(): void {
      // Laravel lee lang/vendor/json-api/<idioma>/errors.php por encima de los textos del paquete, y solo reemplaza lo que se escribió.
      // Laravel reads lang/vendor/json-api/<locale>/errors.php on top of the package texts, and only replaces what was written.
      mkdir($this->langPath . '/vendor/json-api/es', 0777, true);
      file_put_contents(
        $this->langPath . '/vendor/json-api/es/errors.php',
        "<?php\n\n  return ['resource' => ['not_found' => ['title' => 'No existe']]];\n"
      );

      $this->app->setLocale('es');
      $messages = $this->app->make(ErrorMessages::class);

      $this->assertSame('No existe', $messages->title(ErrorCodes::RESOURCE_NOT_FOUND));
      $this->assertSame('Prohibido', $messages->title(ErrorCodes::FORBIDDEN));
    }
  }
