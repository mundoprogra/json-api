<?php

  /**
   * Revisa que la documentación (docs/) siga al día con el paquete. No necesita Composer ni Laravel: solo PHP 8.1 o superior.
   * Checks that the documentation (docs/) is up to date with the package. It needs neither Composer nor Laravel: only PHP 8.1 or higher.
   *
   * Uso / Usage (desde la raíz del paquete / from the package root):
   *   php bin/check-docs.php
   *   docker compose run --rm l13fast php bin/check-docs.php
   *
   * Qué revisa / What it checks:
   *   1. estructura: las páginas, los estilos y `.nojekyll` existen / structure: the pages, the styles and `.nojekyll` exist
   *   2. paridad: `es/` y `en/` tienen las mismas páginas / parity: `es/` and `en/` have the same pages
   *   3. errores: cada código de `ErrorCodes` aparece en `errors.html` / errors: every `ErrorCodes` code appears in `errors.html`
   *   4. configuración: cada clave de `config/json-api.php` aparece en `configuration.html` dentro de `<code>`
   *      configuration: every key of `config/json-api.php` appears in `configuration.html` inside `<code>`
   *   5. enlaces: cada enlace relativo apunta a un archivo y a un ancla que existen / links: every relative link points to an existing file and anchor
   *   6. higiene: ningún término interno en la documentación / hygiene: no internal term in the documentation
   *
   * Termina con código 1 si algo falla / It exits with code 1 if anything fails.
   */

  $root = dirname(__DIR__);
  $docs = $root . '/docs';

  $languages = ['es', 'en'];

  // Las páginas de cada idioma, con el mismo nombre de archivo en los dos. / The pages of each language, with the same file name in both.
  $pages = [
    'index', 'installation', 'getting-started', 'resources', 'relationship-endpoints', 'query-parameters', 'input-document',
    'errors', 'activation', 'configuration', 'naming-style', 'known-limits', 'versioning',
  ];

  // Términos que no deben aparecer nunca en la documentación pública. / Terms that must never appear in the public documentation.
  $internalTerms = ['platform.users', 'e-sonrie', 'esonrie', 'sonrisas', 'PLAN.md', 'hotmail', 'roma_'];

  /** @var array<string, list<string>> $problems */
  $problems = [];
  $report = function (string $check, string $message) use (&$problems): void {
    $problems[$check][] = $message;
  };

  // 1. Estructura / Structure
  $required = ['index.html', 'assets/style.css', '.nojekyll'];
  foreach ($languages as $language) {
    foreach ($pages as $page) {
      $required[] = "$language/$page.html";
    }
  }
  foreach ($required as $file) {
    if (!is_file("$docs/$file")) {
      $report('estructura / structure', "falta docs/$file / missing docs/$file");
    }
  }

  // 2. Paridad entre idiomas / Parity between languages
  $listing = function (string $language) use ($docs): array {
    $files = glob("$docs/$language/*.html") ?: [];

    return array_map('basename', $files);
  };
  $spanish = $listing('es');
  $english = $listing('en');
  foreach (array_diff($spanish, $english) as $file) {
    $report('paridad / parity', "docs/es/$file no tiene su par en docs/en / docs/es/$file has no pair in docs/en");
  }
  foreach (array_diff($english, $spanish) as $file) {
    $report('paridad / parity', "docs/en/$file no tiene su par en docs/es / docs/en/$file has no pair in docs/es");
  }

  // 3. Códigos de error / Error codes
  $codesFile = file_get_contents($root . '/src/Errors/ErrorCodes.php') ?: '';
  preg_match_all("/public const [A-Z_]+ = '([a-z_]+\.[a-z_]+)';/", $codesFile, $matches);
  $codes = $matches[1];
  if ($codes === []) {
    $report('errores / errors', 'no se encontraron códigos en src/Errors/ErrorCodes.php / no codes found in src/Errors/ErrorCodes.php');
  }
  foreach ($languages as $language) {
    $page = (string) @file_get_contents("$docs/$language/errors.html");
    foreach ($codes as $code) {
      if (!str_contains($page, $code)) {
        $report('errores / errors', "docs/$language/errors.html no menciona $code / does not mention $code");
      }
    }
  }

  // 4. Claves de configuración / Configuration keys
  // El archivo usa env(); aquí se define una versión mínima que devuelve el valor por defecto. / The file uses env(); a minimal version that returns the default is defined here.
  if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
      return $default;
    }
  }
  $config = require $root . '/config/json-api.php';

  $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
    $keys = [];
    foreach ($values as $key => $value) {
      $path = $prefix . $key;
      if (is_array($value) && $value !== [] && !array_is_list($value)) {
        array_push($keys, ...$flatten($value, $path . '.'));
      } else {
        $keys[] = $path;
      }
    }

    return $keys;
  };
  $keys = $flatten($config);
  foreach ($languages as $language) {
    $page = (string) @file_get_contents("$docs/$language/configuration.html");
    foreach ($keys as $key) {
      if (!preg_match('~<code>\s*' . preg_quote($key, '~') . '\s*</code>~', $page)) {
        $report('configuración / configuration', "docs/$language/configuration.html no tiene <code>$key</code> / has no <code>$key</code>");
      }
    }
  }

  // 5. Enlaces relativos y anclas / Relative links and anchors
  $htmlFiles = [];
  if (is_dir($docs)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'html') {
        $htmlFiles[] = $file->getPathname();
      }
    }
  }
  sort($htmlFiles);

  $anchors = [];
  $anchorsOf = function (string $path) use (&$anchors): array {
    if (!isset($anchors[$path])) {
      $document = new DOMDocument();
      libxml_use_internal_errors(true);
      $document->loadHTML((string) file_get_contents($path));
      libxml_clear_errors();
      $found = [];
      foreach ((new DOMXPath($document))->query('//*[@id]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
          $found[$node->getAttribute('id')] = true;
        }
      }
      $anchors[$path] = $found;
    }

    return $anchors[$path];
  };

  foreach ($htmlFiles as $path) {
    $name = 'docs/' . ltrim(substr($path, strlen($docs)), '/');
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML((string) file_get_contents($path));
    libxml_clear_errors();

    $targets = [];
    foreach ((new DOMXPath($document))->query('//a[@href] | //link[@href] | //img[@src] | //script[@src]') ?: [] as $node) {
      if ($node instanceof DOMElement) {
        $targets[] = $node->hasAttribute('href') ? $node->getAttribute('href') : $node->getAttribute('src');
      }
    }

    foreach ($targets as $target) {
      // Externos (https:, mailto:, //host) no se revisan: no hay red. / External ones (https:, mailto:, //host) are not checked: there is no network.
      if ($target === '' || preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $target)) {
        continue;
      }

      [$location, $fragment] = array_pad(explode('#', $target, 2), 2, null);
      $location = (string) strtok($location, '?');

      if ($location === '') {
        $destination = $path;
      } else {
        $candidate = dirname($path) . '/' . $location;
        if (str_ends_with($candidate, '/')) {
          $candidate .= 'index.html';
        }
        $destination = realpath($candidate);
        if ($destination === false || !is_file($destination)) {
          $report('enlaces / links', "$name: $target no existe / does not exist");
          continue;
        }
      }

      if ($fragment !== null && $fragment !== '' && str_ends_with($destination, '.html') && !isset($anchorsOf($destination)[$fragment])) {
        $report('enlaces / links', "$name: la ancla #$fragment de $target no existe / the anchor #$fragment of $target does not exist");
      }
    }
  }

  // 6. Higiene / Hygiene
  if (is_dir($docs)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!($file instanceof SplFileInfo) || !$file->isFile() || !in_array($file->getExtension(), ['html', 'css', 'txt', 'md'], true)) {
        continue;
      }
      $content = (string) file_get_contents($file->getPathname());
      foreach ($internalTerms as $term) {
        if (stripos($content, $term) !== false) {
          $report('higiene / hygiene', 'docs/' . ltrim(substr($file->getPathname(), strlen($docs)), '/') . " contiene «$term» / contains «$term»");
        }
      }
    }
  }

  // Resultado / Result
  if ($problems === []) {
    echo 'Documentación al día / Documentation up to date: ' . count($htmlFiles) . ' páginas HTML / HTML pages, ' . count($codes) . ' códigos de error / error codes, ' . count($keys) . " claves de configuración / configuration keys.\n";
    exit(0);
  }

  $total = 0;
  foreach ($problems as $check => $messages) {
    echo "\n[$check] " . count($messages) . "\n";
    foreach ($messages as $message) {
      echo "  - $message\n";
      $total++;
    }
  }
  echo "\nFalló la revisión de la documentación / Documentation check failed: $total problema(s) / problem(s).\n";
  exit(1);
