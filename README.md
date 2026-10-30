# MundoProgra JSON:API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![Tests](https://img.shields.io/github/actions/workflow/status/mundoprogra/json-api/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/mundoprogra/json-api/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![License](https://img.shields.io/packagist/l/mundoprogra/json-api.svg?style=flat-square)](LICENSE.md)

**Español** · [English](#english) · [Documentación / Documentation](https://mundoprogra.github.io/json-api/)

---

## Español

Paquete para Laravel que convierte una API en un servicio [JSON:API 1.1](https://jsonapi.org/format/) de punta a punta: acepta y valida las peticiones en el estándar y responde en el estándar, tanto en éxito como en error.

> **Estado: versión `0.1.0`, en validación.** La API descrita aquí está completa, cubierta por pruebas en Laravel 10 a 13 y probada en una aplicación real. En `0.x` la API puede cambiar entre versiones menores (con `^0.1` Composer solo instala `0.1.x`). El detalle de cada cambio está en el [CHANGELOG](CHANGELOG.md).

> **Documentación completa: <https://mundoprogra.github.io/json-api/es/>.** Este README es un resumen para empezar.

### Qué hace

- **Responde en JSON:API**: recursos, colecciones paginadas, relaciones, `included`, `links` y `meta`, y todos los errores (incluidos los de validación, `401`, `404`, `405` y `500`) como documentos de errores con `source.pointer` o `source.parameter`.
- **Valida lo que llega**: las cabeceras (`Accept` y `Content-Type`), los parámetros de consulta (`include`, `fields`, `sort`, `filter`, `page`) y el documento del cuerpo, con el puntero exacto del miembro que falla.
- **Aplica la consulta a Eloquent** con listas blancas que escribes tú: filtros, orden, paginación y carga anticipada de relaciones.
- **No registra rutas ni controladores**: tú decides las URL y la lógica; el paquete se ocupa del formato.
- **Se activa solo** al instalarlo, sin tocar `bootstrap/app.php`, `Kernel` ni `Handler`.

### Requisitos

- PHP 8.1 o superior
- Laravel 10, 11, 12 o 13

### Instalación

```bash
composer require mundoprogra/json-api
```

El paquete se descubre solo. Para cambiar la configuración o los textos de los errores:

```bash
php artisan vendor:publish --tag="json-api-config"
php artisan vendor:publish --tag="json-api-lang"
```

### Primeros pasos

Por defecto el paquete actúa sobre las rutas que empiezan con `api` (`mode = prefix`, `path_prefix = api`). Todo lo demás responde como siempre. Para que **toda** la aplicación responda en JSON:API, usa `JSON_API_MODE=global` en el `.env`.

**1. Un recurso** (qué se muestra de un modelo):

```php
use Illuminate\Http\Request;
use MundoProgra\JsonApi\Resources\Relationship;
use MundoProgra\JsonApi\Resources\ResourceObject;

class ArticleResource extends ResourceObject {
  // El nombre corto de la ruta que muestra un artículo: de ahí sale su `links.self`.
  protected function jsonApiRoute(): ?string {
    return 'articles.show';
  }

  // Los nombres se escriben en snake_case y salen en el estilo configurado (`createdAt` por defecto).
  protected function jsonApiAttributes(Request $request): array {
    return [
      'title' => $this->title,
      'status' => $this->status,
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }

  // Solo salen las relaciones que están cargadas: el paquete nunca dispara una consulta para completar un documento.
  protected function jsonApiRelationships(Request $request): array {
    return [
      'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
      'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
    ];
  }
}
```

El `type` de un recurso es la tabla del modelo y su `id` es la clave de ruta, siempre como texto. Se cambian sobrescribiendo `type()` e `id()`.

**2. Un controlador** que aplica la consulta del cliente con listas blancas:

```php
class ArticleController {
  public function index() {
    return ArticleResource::collection(
      Article::query()
        ->jsonApiFilter(exact: ['status', 'author' => 'author_id'], partial: ['title'], scopes: ['published'])
        ->jsonApiSort(['id', 'title', 'created_at'], default: '-id')
        ->jsonApiInclude(['author', 'tags', 'comments.author'])
        ->jsonApiPaginate()
    );
  }

  public function show(string $article) {
    return ArticleResource::make(
      Article::query()->jsonApiInclude(['author', 'tags'])->findOrFail($article)
    );
  }
}
```

**3. Las rutas** son las de siempre; el paquete no registra ninguna. Solo hace falta que exista la que nombraste en `jsonApiRoute()`, porque de ella sale el `links.self`:

```php
Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
```

Si tus rutas llevan un prefijo en el nombre (por ejemplo `v1.articles.show`), indícalo en `links.route_name_prefix`.

**4. Un `FormRequest`** para validar lo que se escribe. Las reglas van sobre los datos planos del documento y cada error sale con su `source.pointer`:

```php
use Illuminate\Foundation\Http\FormRequest;
use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

class StoreArticleRequest extends FormRequest {
  use InteractsWithJsonApi;

  public function authorize(): bool {
    return true;
  }

  public function rules(): array {
    return [
      'title' => ['required', 'string', 'max:50'],        // /data/attributes/title
      'status' => ['sometimes', 'in:draft,published'],    // /data/attributes/status
      'author.data.id' => ['integer'],                    // /data/relationships/author/data/id
    ];
  }

  // El `type` que acepta este endpoint: otro es un 409. Y en PATCH/PUT, el `id` debe ser el de la URL.
  protected function jsonApiExpectedType(): ?string {
    return 'articles';
  }
}
```

Solo lo que tiene una regla llega a `validated()`: un atributo del documento sin regla se ignora.

Con eso, `GET /api/articles?filter[status]=published&sort=-createdAt&include=author&fields[articles]=title,author&page[size]=5` ya funciona de punta a punta: filtra, ordena, pagina, carga el autor sin consultas de más y devuelve el documento con `data`, `included`, `links` y `meta`. Cada filtro, orden o `include` que no esté en tu lista responde `400` con el parámetro que falla.

### Parámetros de consulta

| Macro del constructor de Eloquent | Qué hace |
|---|---|
| `jsonApiPaginate(?int $perPage = null, ?int $maxPerPage = null)` | Trae la página que pidió el cliente (`page[number]`, `page[size]`). Una página fuera de rango es una lista vacía. |
| `jsonApiSort(array $allowed, ?string $default = null)` | Aplica `sort` (`-` para descendente) con una lista blanca y agrega la clave primaria para que las páginas sean estables. |
| `jsonApiFilter(exact: [], partial: [], scopes: [])` | `exact`: igualdad (con comas, un `IN`). `partial`: `LIKE %valor%`. `scopes`: llama a un scope del modelo. |
| `jsonApiInclude(array $allowed)` | Carga por anticipado las relaciones de `include` (`['author', 'comments.author']`). |

Las listas se escriben en **snake_case**; el cliente manda los nombres en el estilo configurado.

### Más documentación

La referencia completa está en el sitio. **Si no abre**, las mismas páginas están en la carpeta [`docs/`](docs/) de este repositorio (HTML) y su contenido fuente en [`tools/docs/content/`](tools/docs/content).

| Página | De qué trata |
|---|---|
| [Instalación](https://mundoprogra.github.io/json-api/es/installation.html) | Requisitos, instalación, y cómo comprobar que funciona |
| [Primeros pasos](https://mundoprogra.github.io/json-api/es/getting-started.html) | Un recurso, un controlador y sus rutas |
| [Recursos, colecciones y relaciones](https://mundoprogra.github.io/json-api/es/resources.html) | `included`, campos dispersos, paginación, `links` y `meta` |
| [Endpoints de relación](https://mundoprogra.github.io/json-api/es/relationship-endpoints.html) | Leer y escribir relaciones |
| [Parámetros de consulta](https://mundoprogra.github.io/json-api/es/query-parameters.html) | Las cuatro macros y el tamaño de página |
| [Leer el documento de entrada](https://mundoprogra.github.io/json-api/es/input-document.html) | Qué valida el paquete y cómo escribir las reglas |
| [Errores](https://mundoprogra.github.io/json-api/es/errors.html) | Los 19 códigos y el orden de las comprobaciones |
| [Cómo se activa](https://mundoprogra.github.io/json-api/es/activation.html) | Alcance (`mode`, `path_prefix`, `except`) y los cuatro middleware |
| [Configuración](https://mundoprogra.github.io/json-api/es/configuration.html) | Todas las claves y variables de `.env` |
| [Estilo de los nombres](https://mundoprogra.github.io/json-api/es/naming-style.html) | `camel`, `kebab` o `snake` |
| [Límites conocidos](https://mundoprogra.github.io/json-api/es/known-limits.html) | Lo que el paquete no cubre |
| [Versionado y soporte](https://mundoprogra.github.io/json-api/es/versioning.html) | SemVer y versiones de Laravel y PHP |

### Desarrollo y pruebas

No necesitas PHP instalado: todo se ejecuta en Docker. Cada servicio de `docker-compose.yml` corresponde a una versión de Laravel:

```bash
docker compose run --rm l13fast                              # Laravel 13: la verificación del día a día (rápido)
docker compose run --rm l10                                  # Laravel 10 (PHP 8.1, la versión mínima)
docker compose run --rm -e ARGS="--filter=Provider" l13fast  # un subconjunto de pruebas
docker compose run --rm -e STABILITY=prefer-lowest l12       # dependencias en su versión mínima
```

Servicios: `l10`, `l11`, `l12`, `l13` (con el `vendor` del equipo, para el IDE) y `l13fast` (el mismo, con `vendor` en un volumen de Docker: unas ocho veces más rápido en Windows). Ejecútalos **uno por uno**, no en paralelo: comparten la carpeta del paquete.

Calidad de código (PHPStan al nivel 8 y Pint):

```bash
docker compose run --rm l13fast vendor/bin/phpstan analyse --no-progress
docker compose run --rm l13fast vendor/bin/pint --test
```

Matriz completa de versiones (cada variante de PHP de cada Laravel, con dependencias estables y mínimas; tarda unos 45 minutos):

```bash
bin/matrix.sh                 # todas
bin/matrix.sh l12-php8.4      # solo esa variante
```

La API de referencia que usan las pruebas de contrato (artículos, recursos relacionados y endpoints de relación, escritos como los escribiría una app) está en [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi); sus pruebas validan cada respuesta contra el schema oficial de JSON:API.

### Changelog

Consulta el [CHANGELOG](CHANGELOG.md) para ver los cambios recientes.

### Seguridad

Para reportar una vulnerabilidad, sigue la [política de seguridad](SECURITY.md).

### Créditos

- [Alvaro Romero](https://github.com/mundoprogra)
- [Todos los colaboradores](https://github.com/mundoprogra/json-api/graphs/contributors)

### Licencia

Licencia MIT. Consulta el archivo [LICENSE](LICENSE.md) para más información.

---

## English

A Laravel package that turns an API into an end-to-end [JSON:API 1.1](https://jsonapi.org/format/) service: it accepts and validates requests in the standard and responds in the standard, for both success and error responses.

> **Status: version `0.1.0`, under validation.** The API described here is complete, covered by tests on Laravel 10 to 13 and tried in a real application. In `0.x` the API may change between minor versions (with `^0.1` Composer installs only `0.1.x`). The detail of each change is in the [CHANGELOG](CHANGELOG.md).

> **Full documentation: <https://mundoprogra.github.io/json-api/en/>.** This README is a summary to get you started.

### What it does

- **Responds in JSON:API**: resources, paginated collections, relationships, `included`, `links` and `meta`, and every error (validation, `401`, `404`, `405` and `500` included) as an error document with `source.pointer` or `source.parameter`.
- **Validates what arrives**: the headers (`Accept` and `Content-Type`), the query parameters (`include`, `fields`, `sort`, `filter`, `page`) and the body document, with the exact pointer of the failing member.
- **Applies the query to Eloquent** with whitelists you write: filters, sorting, pagination and eager loading of relationships.
- **Registers no routes or controllers**: you decide the URLs and the logic; the package takes care of the format.
- **Switches itself on** on install, without touching `bootstrap/app.php`, `Kernel` or `Handler`.

### Requirements

- PHP 8.1 or higher
- Laravel 10, 11, 12 or 13

### Installation

```bash
composer require mundoprogra/json-api
```

The package is auto-discovered. To change the configuration or the error texts:

```bash
php artisan vendor:publish --tag="json-api-config"
php artisan vendor:publish --tag="json-api-lang"
```

### Getting started

By default the package acts on routes starting with `api` (`mode = prefix`, `path_prefix = api`). Everything else answers as usual. To make the **whole** application respond in JSON:API, set `JSON_API_MODE=global` in `.env`.

**1. A resource** (what is shown of a model):

```php
use Illuminate\Http\Request;
use MundoProgra\JsonApi\Resources\Relationship;
use MundoProgra\JsonApi\Resources\ResourceObject;

class ArticleResource extends ResourceObject {
  // The short name of the route that shows an article: its `links.self` comes from it.
  protected function jsonApiRoute(): ?string {
    return 'articles.show';
  }

  // Names are written in snake_case and come out in the configured style (`createdAt` by default).
  protected function jsonApiAttributes(Request $request): array {
    return [
      'title' => $this->title,
      'status' => $this->status,
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }

  // Only the relationships that are loaded come out: the package never runs a query to complete a document.
  protected function jsonApiRelationships(Request $request): array {
    return [
      'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
      'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
    ];
  }
}
```

A resource's `type` is the model's table and its `id` is the route key, always as text. Override `type()` and `id()` to change them.

**2. A controller** that applies the client's query with whitelists:

```php
class ArticleController {
  public function index() {
    return ArticleResource::collection(
      Article::query()
        ->jsonApiFilter(exact: ['status', 'author' => 'author_id'], partial: ['title'], scopes: ['published'])
        ->jsonApiSort(['id', 'title', 'created_at'], default: '-id')
        ->jsonApiInclude(['author', 'tags', 'comments.author'])
        ->jsonApiPaginate()
    );
  }

  public function show(string $article) {
    return ArticleResource::make(
      Article::query()->jsonApiInclude(['author', 'tags'])->findOrFail($article)
    );
  }
}
```

**3. The routes** are the usual ones; the package registers none. The only requirement is that the one you named in `jsonApiRoute()` exists, because the `links.self` comes from it:

```php
Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
Route::get('articles/{article}', [ArticleController::class, 'show'])->name('articles.show');
```

If your routes carry a prefix in their name (for example `v1.articles.show`), set it in `links.route_name_prefix`.

**4. A `FormRequest`** to validate what is written. The rules go over the flat data of the document and each error comes out with its `source.pointer`:

```php
use Illuminate\Foundation\Http\FormRequest;
use MundoProgra\JsonApi\Http\Requests\InteractsWithJsonApi;

class StoreArticleRequest extends FormRequest {
  use InteractsWithJsonApi;

  public function authorize(): bool {
    return true;
  }

  public function rules(): array {
    return [
      'title' => ['required', 'string', 'max:50'],        // /data/attributes/title
      'status' => ['sometimes', 'in:draft,published'],    // /data/attributes/status
      'author.data.id' => ['integer'],                    // /data/relationships/author/data/id
    ];
  }

  // The `type` this endpoint accepts: any other is a 409. And on PATCH/PUT, the `id` must be the one in the URL.
  protected function jsonApiExpectedType(): ?string {
    return 'articles';
  }
}
```

Only what has a rule reaches `validated()`: an attribute of the document without a rule is ignored.

With that, `GET /api/articles?filter[status]=published&sort=-createdAt&include=author&fields[articles]=title,author&page[size]=5` already works end to end: it filters, sorts, paginates, loads the author without extra queries and returns the document with `data`, `included`, `links` and `meta`. Every filter, sort or `include` that is not in your list answers `400` naming the failing parameter.

### Query parameters

| Eloquent builder macro | What it does |
|---|---|
| `jsonApiPaginate(?int $perPage = null, ?int $maxPerPage = null)` | Fetches the page the client asked for (`page[number]`, `page[size]`). A page out of range is an empty list. |
| `jsonApiSort(array $allowed, ?string $default = null)` | Applies `sort` (`-` for descending) with a whitelist and adds the primary key so the pages are stable. |
| `jsonApiFilter(exact: [], partial: [], scopes: [])` | `exact`: equality (with commas, an `IN`). `partial`: `LIKE %value%`. `scopes`: calls a model scope. |
| `jsonApiInclude(array $allowed)` | Eager-loads the relationships of `include` (`['author', 'comments.author']`). |

The lists are written in **snake_case**; the client sends the names in the configured style.

### More documentation

The full reference is on the site. **If it does not open**, the same pages are in the [`docs/`](docs/) folder of this repository (HTML) and their source content in [`tools/docs/content/`](tools/docs/content).

| Page | What it covers |
|---|---|
| [Installation](https://mundoprogra.github.io/json-api/en/installation.html) | Requirements, installation, and how to check it works |
| [Getting started](https://mundoprogra.github.io/json-api/en/getting-started.html) | A resource, a controller and its routes |
| [Resources, collections and relationships](https://mundoprogra.github.io/json-api/en/resources.html) | `included`, sparse fieldsets, pagination, `links` and `meta` |
| [Relationship endpoints](https://mundoprogra.github.io/json-api/en/relationship-endpoints.html) | Reading and writing relationships |
| [Query parameters](https://mundoprogra.github.io/json-api/en/query-parameters.html) | The four macros and the page size |
| [Reading the input document](https://mundoprogra.github.io/json-api/en/input-document.html) | What the package validates and how to write the rules |
| [Errors](https://mundoprogra.github.io/json-api/en/errors.html) | The 19 codes and the order of the checks |
| [How it switches on](https://mundoprogra.github.io/json-api/en/activation.html) | Scope (`mode`, `path_prefix`, `except`) and the four middleware |
| [Configuration](https://mundoprogra.github.io/json-api/en/configuration.html) | Every key and `.env` variable |
| [Naming style](https://mundoprogra.github.io/json-api/en/naming-style.html) | `camel`, `kebab` or `snake` |
| [Known limits](https://mundoprogra.github.io/json-api/en/known-limits.html) | What the package does not cover |
| [Versioning and support](https://mundoprogra.github.io/json-api/en/versioning.html) | SemVer and Laravel and PHP versions |

### Development and testing

You don't need PHP installed: everything runs in Docker. Each service in `docker-compose.yml` maps to a Laravel version:

```bash
docker compose run --rm l13fast                              # Laravel 13: the day-to-day check (fast)
docker compose run --rm l10                                  # Laravel 10 (PHP 8.1, the minimum version)
docker compose run --rm -e ARGS="--filter=Provider" l13fast  # a subset of tests
docker compose run --rm -e STABILITY=prefer-lowest l12       # dependencies at their lowest versions
```

Services: `l10`, `l11`, `l12`, `l13` (with the host `vendor`, for the IDE) and `l13fast` (the same, with `vendor` in a Docker volume: about eight times faster on Windows). Run them **one at a time**, not in parallel: they share the package folder.

Code quality (PHPStan at level 8, and Pint):

```bash
docker compose run --rm l13fast vendor/bin/phpstan analyse --no-progress
docker compose run --rm l13fast vendor/bin/pint --test
```

Full version matrix (every PHP variant of every Laravel, with stable and lowest dependencies; it takes about 45 minutes):

```bash
bin/matrix.sh                 # all of them
bin/matrix.sh l12-php8.4      # only that variant
```

The reference API used by the contract tests (articles, related resources and relationship endpoints, written the way an app would write them) is in [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi); its tests validate every response against the official JSON:API schema.

### Changelog

See the [CHANGELOG](CHANGELOG.md) for recent changes.

### Security

To report a vulnerability, please follow the [security policy](SECURITY.md).

### Credits

- [Alvaro Romero](https://github.com/mundoprogra)
- [All Contributors](https://github.com/mundoprogra/json-api/graphs/contributors)

### License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
