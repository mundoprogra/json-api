# MundoProgra JSON:API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![Tests](https://img.shields.io/github/actions/workflow/status/mundoprogra/json-api/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mundoprogra/json-api/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![License](https://img.shields.io/packagist/l/mundoprogra/json-api.svg?style=flat-square)](LICENSE.md)

**Español** · [English](#english)

---

## Español

Paquete para Laravel que convierte una API en un servicio [JSON:API 1.1](https://jsonapi.org/format/) de punta a punta: acepta y valida las peticiones en el estándar y responde en el estándar, tanto en éxito como en error.

> **Estado: versión `0.1.0` en preparación (aún sin publicar).** La API descrita aquí está completa y cubierta por pruebas en Laravel 10 a 13; falta probarla en una aplicación real antes de publicarla. El detalle de cada cambio está en el [CHANGELOG](CHANGELOG.md).

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

Con eso, `GET /api/articles?filter[status]=published&sort=-createdAt&include=author&fields[articles]=title,author&page[size]=5` ya funciona de punta a punta: filtra, ordena, pagina, carga el autor sin consultas de más y devuelve el documento con `data`, `included`, `links` y `meta`. Cada filtro, orden o `include` que no esté en tu lista responde `400` con el parámetro que falla.

### Recursos, colecciones y relaciones

| Quieres | Escribes |
|---|---|
| Un recurso | `ArticleResource::make($article)` |
| Una colección | `ArticleResource::collection($articles)` (un arreglo, una colección o un paginador de longitud conocida) |
| Una colección paginada | `ArticleResource::collection(Article::query()->jsonApiPaginate())` |
| `links` o `meta` propios | `jsonApiLinks($request)` y `jsonApiMeta($request)` en el recurso, o `->additional([...])` |
| Una relación a uno o a muchos | `Relationship::one($modelo, Clase::class)` / `Relationship::many($modelos, Clase::class)` |
| Una relación sin cargarla | `Relationship::unloaded()->links(...$this->relationshipLinks($request, 'author'))` |
| El recurso relacionado vacío | `AuthorResource::make(null)`, que responde `data: null` |

- Una colección vacía es `data: []`. La paginación suma `links` (`first`, `last`, `prev`, `next`) y `meta.page` (`currentPage`, `perPage`, `lastPage`, `total`), que conservan `sort`, `filter`, `include` y `fields` tal como los mandó el cliente.
- Un paginador simple o de cursor no se acepta (no sabe cuántas páginas hay) y lanza un error claro.
- `relationshipLinks($request, 'author')` arma `{self}/relationships/author` y `{self}/author` a partir del `links.self` del propio recurso. Es opcional.
- **`included`**: sale solo lo que el cliente pidió con `include`, de relaciones **ya cargadas** y que el recurso muestra. Cada recurso aparece una sola vez (por `type` e `id`) y nunca repite a los de `data`. Lo que un `fields[tipo]` oculta tampoco se incluye, así el documento cumple el *full linkage* de la spec. Para cargar las relaciones sin N+1, usa `jsonApiInclude([...])`.
- **Sparse fieldsets**: `fields[articles]=title` recorta los atributos y las relaciones de ese tipo; `type`, `id`, `links` y `meta` se quedan.

### Endpoints de relación

Una `Relationship` devuelta desde un controlador es la respuesta de un endpoint de relación (`GET /articles/1/relationships/author`): `{jsonapi, links, data, meta}` con solo el linkage (`type` e `id`).

```php
public function author(string $article) {
  $model = Article::query()->with('author')->findOrFail($article);

  return Relationship::one($model->author, AuthorResource::class)->links(
    self: route('articles.relationships.author', [$model]),
    related: route('articles.author', [$model]),
  );
}
```

Para **escribir** una relación, lee el linkage con `RelationshipDocument` (el paquete ya validó su forma). Lo que depende de tu modelo lo revisas tú, con estos códigos:

```php
use MundoProgra\JsonApi\Exceptions\JsonApiException;
use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;

public function updateAuthor(Request $request, string $article) {
  $document = RelationshipDocument::of($request);        // data() es null, un identificador o una lista; isList() dice cuál

  if ($document->isList()) {                             // una lista donde va un identificador
    throw JsonApiException::make(status: 422, detail: 'A to-one relationship takes one identifier or null.', pointer: '/data');
  }

  $identifier = $document->data();                       // ['type' => 'authors', 'id' => '3'] o null

  if ($identifier !== null && $identifier['type'] !== 'authors') {
    throw JsonApiException::make(status: 409, pointer: '/data/type');          // el tipo no es el de la relación
  }

  $author = $identifier === null ? null : Author::find($identifier['id'])
    ?? throw JsonApiException::make(status: 404, pointer: '/data');            // el recurso no existe

  // ... asociar, guardar y responder con la relación resultante (`200`)
}
```

`PATCH` reemplaza, `POST` agrega y `DELETE` quita en una relación a muchos. Revisa todo **antes** de escribir, para que un error no deje cambios a medias. El ejemplo completo está en [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi).

### Parámetros de consulta

| Macro del constructor de Eloquent | Qué hace |
|---|---|
| `jsonApiPaginate(?int $perPage = null, ?int $maxPerPage = null)` | Trae la página que pidió el cliente (`page[number]`, `page[size]`) y devuelve el paginador de Laravel. Una página fuera de rango es una lista vacía. |
| `jsonApiSort(array $allowed, ?string $default = null)` | Aplica `sort` (`-` para descendente) con una lista blanca. Agrega la clave primaria para que las páginas sean estables. Acepta `['author' => 'author_id']` para nombrar de otra forma una columna. |
| `jsonApiFilter(exact: [], partial: [], scopes: [])` | `exact`: igualdad (con comas, un `IN`). `partial`: `LIKE %valor%` con `%`, `_` escapados. `scopes`: llama a un scope del modelo. Un nombre fuera de las listas es `400`. |
| `jsonApiInclude(array $allowed)` | Carga por anticipado las relaciones de `include` (`['author', 'comments.author']`; pedir una ruta permite sus prefijos). Una ruta fuera de la lista es `400`. |

Las listas se escriben en **snake_case** (las columnas y relaciones de tu modelo); el cliente las manda en el estilo configurado.

**Tamaño de página**: sale del cliente (`page[size]`), si no del argumento `jsonApiPaginate(perPage: 30)`, si no de las opciones del middleware (`->middleware('json-api.options:per_page=25,max_per_page=50')`) y, si no, de la configuración; siempre acotado por el máximo.

`$request->jsonApiQuery()` entrega la consulta ya analizada (`includes()`, `fields()`, `sort()`, `filter()`, `page()`).

### Leer el documento de entrada

El paquete valida el cuerpo de todo `POST` y `PATCH` JSON antes de que llegue a tu controlador, y de un endpoint de relación también el `DELETE`. Para leerlo, la petición tiene estos métodos (los nombres de atributos y relaciones van en snake_case):

| Método | Devuelve |
|---|---|
| `$request->isJsonApi()` | Si el paquete trata la petición como de JSON:API |
| `$request->jsonApiType()` / `jsonApiId()` | El `type` y el `id` del documento (`id` es `null` si no vino) |
| `$request->jsonApiAttributes()` | Los atributos, con los nombres en snake_case |
| `$request->hasJsonApiRelationship('author')` | Si el cliente mandó esa relación (aunque sea `data: null`) |
| `$request->jsonApiRelationshipId('author')` | El `id` de una relación a uno, o `null` |
| `$request->jsonApiRelationshipIds('tags')` | Los `id` de una relación a muchos, en orden |

Sin el nombre, `jsonApiRelationshipId()` y `jsonApiRelationshipIds()` leen el linkage del cuerpo de un endpoint de relación.

**`FormRequest`**: agrega el trait `InteractsWithJsonApi` y escribe las reglas sobre los datos planos del documento (los atributos en snake_case y, para cada relación, su objeto). `validated()` sirve para llenar un modelo y cada error sale con su `source.pointer`:

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
      'tags.data.*.type' => ['in:labels'],                // /data/relationships/tags/data/0/type
    ];
  }

  // El `type` que acepta este endpoint: otro es un 409. Y en PATCH/PUT, el `id` debe ser el de la URL
  // (jsonApiExpectedId(), por defecto el último parámetro de la ruta).
  protected function jsonApiExpectedType(): ?string {
    return 'articles';
  }
}
```

### Errores

Todo error sale como `{"errors": [...]}` con `status`, `title`, `detail`, `code` y `source` (`pointer`, `parameter` o `header`), en el idioma de la app (español e inglés incluidos). El paquete se engancha al manejo de excepciones de Laravel y sale igual en las versiones 10 a 13; si la app registra su propio `renderable`, el de la app gana. Para lanzar uno a mano:

```php
throw JsonApiException::make(status: 404, pointer: '/data/relationships/author/data');
```

El título, el detalle y el código se completan solos a partir del estado. Un error `5xx` oculta sus detalles internos salvo que `errors.expose_details` (o `app.debug`) lo permita.

| Código base | Estado | Cuándo |
|---|---|---|
| `request.not_acceptable` | 406 | El `Accept` no permite JSON:API (`source.header`) |
| `request.unsupported_media_type` | 415 | El `Content-Type` no es JSON:API (`source.header`) |
| `request.bad_query` | 400 | Un parámetro de consulta mal formado o no permitido (`source.parameter`) |
| `request.bad_document` | 400 | El cuerpo no es JSON o no tiene la forma de un documento (`source.pointer`) |
| `request.client_id_not_allowed` | 403 | Un `POST` con `id` del cliente y `allow_client_ids` apagado |
| `auth.unauthenticated` | 401 | Sin autenticar; nunca redirige al login |
| `auth.forbidden` | 403 | Sin permiso |
| `resource.not_found` | 404 | El recurso no existe (no revela el nombre del modelo) |
| `route.not_found` | 404 | La ruta no existe |
| `request.method_not_allowed` | 405 | El método no está en la ruta; lleva `Allow` |
| `resource.conflict` | 409 | El `type` o el `id` del documento no son los del endpoint |
| `validation.<regla>` | 422 | Una regla de validación falló (`validation.required`, `validation.max`…) |
| `request.too_many_requests` | 429 | Límite de peticiones; lleva `Retry-After` |
| `server.error` / `server.unavailable` | 500 / 503 | Error inesperado o servicio no disponible |

Los códigos llevan el prefijo `mundoprogra.json-api.` (por ejemplo `mundoprogra.json-api.validation.required`). Se cambian con `errors.code_prefix`, se reemplazan uno a uno con `errors.codes` o se apagan con `errors.include_code`.

#### El orden de las comprobaciones

Cuando una petición falla por más de un motivo, sale solo el primero, en este orden:

1. `Content-Type` (`415`) y después `Accept` (`406`).
2. La sintaxis de la consulta (`400`).
3. El documento: JSON roto o mal formado (`400`) o un `id` del cliente (`403`).
4. La ruta: método no permitido (`405`) o ruta que no existe (`404`).
5. La autenticación (`401`).
6. El `FormRequest`: `type` o `id` distintos (`409`) y después las reglas (`422`).
7. Tu controlador: la lista blanca de la consulta (`400`) y el recurso que no existe (`404`).

Los pasos 1 a 3 corren antes que `auth`: sin sesión, un JSON roto es un `400` y no un `401`.

### Cómo se activa

Dentro del alcance (`mode`, `path_prefix` y `except`) el paquete agrega solo cuatro middleware a la pila global: `json-api.apply` (marca la petición y arregla el `Accept` para que `auth` responda `401` en lugar de buscar la ruta `login`), `json-api.negotiate`, `json-api.query` y `json-api.document`. Fuera de ese alcance no toca nada.

Para una ruta suelta fuera del alcance: `->middleware('json-api')` aplica los cuatro. También existen por separado y `json-api.options` (por ejemplo `json-api.options:per_page=25,max_per_page=50`). `json-api.document:relationship` y `:resource` fuerzan el tipo de documento sin mirar la URL.

### Configuración

Todo se puede definir en el `.env`. Con `php artisan vendor:publish --tag="json-api-config"` obtienes el archivo `config/json-api.php`.

| Opción | `.env` | Por defecto | Qué hace |
|---|---|---|---|
| `mode` | `JSON_API_MODE` | `prefix` | `prefix`: solo las rutas con `path_prefix`. `global`: toda la app |
| `path_prefix` | `JSON_API_PATH_PREFIX` | `api` | Prefijo(s) de ruta (un texto o un arreglo) |
| `except` | — | `['up']` | Rutas que el paquete nunca toca (admite comodines) |
| `strict_headers` | `JSON_API_STRICT_HEADERS` | `true` | Exige las cabeceras JSON:API; `false` también acepta `application/json` |
| `member_case` | `JSON_API_MEMBER_CASE` | `camel` | Estilo de los nombres: `camel`, `kebab` o `snake` |
| `jsonapi_object` | `JSON_API_JSONAPI_OBJECT` | `true` | Incluye `{"jsonapi": {"version": "1.1"}}` |
| `allow_client_ids` | `JSON_API_ALLOW_CLIENT_IDS` | `false` | Acepta el `id` que manda el cliente al crear |
| `allow_put` | `JSON_API_ALLOW_PUT` | `false` | También valida `PUT` como JSON:API |
| `reject_unknown_query_params` | `JSON_API_REJECT_UNKNOWN_QUERY_PARAMS` | `false` | Un parámetro desconocido de solo `a-z` responde `400` |
| `pagination.size` | `JSON_API_PAGE_SIZE` | `15` | Tamaño de página por defecto |
| `pagination.max_size` | `JSON_API_PAGE_MAX_SIZE` | `100` | Tope del `page[size]` del cliente |
| `pagination.on_exceed` | `JSON_API_PAGE_ON_EXCEED` | `clamp` | Pasado el tope: `clamp` lo recorta, `error` responde `400` |
| `query.max_include_depth` | `JSON_API_MAX_INCLUDE_DEPTH` | `3` | Profundidad máxima de `include` |
| `links.route_name_prefix` | `JSON_API_ROUTE_NAME_PREFIX` | vacío | Prefijo para resolver el nombre de las rutas de los `links` |
| `errors.expose_details` | `JSON_API_EXPOSE_DETAILS` | `app.debug` | Muestra el detalle real en los `500` |
| `errors.include_code` | `JSON_API_INCLUDE_CODE` | `true` | Envía `code` en cada error |
| `errors.code_prefix` | `JSON_API_CODE_PREFIX` | `mundoprogra.json-api` | Prefijo de los códigos |
| `errors.codes` | — | `[]` | Reemplaza códigos por los tuyos |
| `errors.include_id` | `JSON_API_INCLUDE_ID` | `false` | Agrega un `id` único a cada error y lo escribe en el log |

La configuración se valida al arrancar: un valor inválido detiene la aplicación con un mensaje que lista todos los problemas a la vez.

### Estilo de los nombres

`member_case` decide cómo se escriben los nombres de varias palabras en el documento: `createdAt` (`camel`), `created-at` (`kebab`) o `created_at` (`snake`). Se aplica a los atributos, las relaciones, `sort`, `fields` y el `meta` de paginación, en la entrada y en la salida. En tu código siempre escribes snake_case; el paquete convierte. Los tipos, los ids, los links y los punteros de los errores no cambian con el estilo.

### Límites conocidos

- El paquete no registra rutas ni controladores; los endpoints (y su autorización) son tuyos.
- Se negocia JSON:API 1.1, pero las extensiones y los perfiles no se implementan: cualquier `ext` se rechaza con `415` y los perfiles se ignoran. Tampoco hay `lid`.
- `PUT` no se valida salvo con `allow_put`; la spec solo define `PATCH` para actualizar.
- Un `include` sobre una relación que no se cargó se ignora en silencio: la carga anticipada es de `jsonApiInclude()`.
- Un paginador que implemente el contrato de Laravel pero no sea la clase `LengthAwarePaginator` de Illuminate no recibe `links` ni `meta`.
- Las reglas de cardinalidad (un identificador o una lista) y de tipo de un endpoint de relación las escribe la app, como en el ejemplo de arriba.
- El schema oficial de JSON:API que se usa en las pruebas es el de la versión 1.0.

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

Matriz completa de versiones (cada variante de PHP de cada Laravel, con dependencias estables y mínimas; tarda unos 30 minutos):

```bash
bin/matrix.sh                 # todas
bin/matrix.sh l12-php8.4      # solo esa variante
```

La API de referencia que usan las pruebas de contrato (artículos, recursos relacionados y endpoints de relación, escritos como los escribiría una app) está en [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi); sus pruebas validan cada respuesta contra el schema oficial de JSON:API.

### Changelog

Consulta el [CHANGELOG](CHANGELOG.md) para ver los cambios recientes.

### Seguridad

Para reportar una vulnerabilidad, revisa la [política de seguridad](../../security/policy).

### Créditos

- [Alvaro Romero](https://github.com/mundoprogra)
- [Todos los colaboradores](../../contributors)

### Licencia

Licencia MIT. Consulta el archivo [LICENSE](LICENSE.md) para más información.

---

## English

A Laravel package that turns an API into an end-to-end [JSON:API 1.1](https://jsonapi.org/format/) service: it accepts and validates requests in the standard and responds in the standard, for both success and error responses.

> **Status: version `0.1.0` in preparation (not published yet).** The API described here is complete and covered by tests on Laravel 10 to 13; it still has to be tried in a real application before release. The detail of each change is in the [CHANGELOG](CHANGELOG.md).

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
  // The short name of the route that shows an article: its `links.self` comes from there.
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

  // Only loaded relationships come out: the package never fires a query to complete a document.
  protected function jsonApiRelationships(Request $request): array {
    return [
      'author' => $this->whenLoaded('author', fn() => Relationship::one($this->author, AuthorResource::class)),
      'tags' => $this->whenLoaded('tags', fn() => Relationship::many($this->tags, TagResource::class)),
    ];
  }
}
```

The `type` of a resource is the model table and its `id` is the route key, always as text. Override `type()` and `id()` to change them.

**2. A controller** that applies the client query with whitelists:

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

With that, `GET /api/articles?filter[status]=published&sort=-createdAt&include=author&fields[articles]=title,author&page[size]=5` already works end to end: it filters, sorts, paginates, loads the author without extra queries and returns the document with `data`, `included`, `links` and `meta`. Every filter, sort or `include` that is not in your list answers `400` naming the failing parameter.

### Resources, collections and relationships

| You want | You write |
|---|---|
| A resource | `ArticleResource::make($article)` |
| A collection | `ArticleResource::collection($articles)` (an array, a collection or a length-aware paginator) |
| A paginated collection | `ArticleResource::collection(Article::query()->jsonApiPaginate())` |
| Your own `links` or `meta` | `jsonApiLinks($request)` and `jsonApiMeta($request)` in the resource, or `->additional([...])` |
| A to-one or to-many relationship | `Relationship::one($model, Class::class)` / `Relationship::many($models, Class::class)` |
| A relationship without loading it | `Relationship::unloaded()->links(...$this->relationshipLinks($request, 'author'))` |
| The empty related resource | `AuthorResource::make(null)`, which answers `data: null` |

- An empty collection is `data: []`. Pagination adds `links` (`first`, `last`, `prev`, `next`) and `meta.page` (`currentPage`, `perPage`, `lastPage`, `total`), which keep `sort`, `filter`, `include` and `fields` exactly as the client sent them.
- A simple or cursor paginator is not accepted (it does not know how many pages there are) and throws a clear error.
- `relationshipLinks($request, 'author')` builds `{self}/relationships/author` and `{self}/author` from the resource's own `links.self`. It is optional.
- **`included`**: only what the client asked for with `include` comes out, from relationships that are **already loaded** and that the resource shows. Each resource appears once (by `type` and `id`) and never repeats those in `data`. What a `fields[type]` hides is not included either, so the document keeps the spec's *full linkage*. To load relationships without N+1, use `jsonApiInclude([...])`.
- **Sparse fieldsets**: `fields[articles]=title` trims the attributes and relationships of that type; `type`, `id`, `links` and `meta` stay.

### Relationship endpoints

A `Relationship` returned from a controller is the response of a relationship endpoint (`GET /articles/1/relationships/author`): `{jsonapi, links, data, meta}` with only the linkage (`type` and `id`).

```php
public function author(string $article) {
  $model = Article::query()->with('author')->findOrFail($article);

  return Relationship::one($model->author, AuthorResource::class)->links(
    self: route('articles.relationships.author', [$model]),
    related: route('articles.author', [$model]),
  );
}
```

To **write** a relationship, read the linkage with `RelationshipDocument` (the package already validated its shape). What depends on your model you check yourself, with these codes:

```php
use MundoProgra\JsonApi\Exceptions\JsonApiException;
use MundoProgra\JsonApi\Http\Requests\RelationshipDocument;

public function updateAuthor(Request $request, string $article) {
  $document = RelationshipDocument::of($request);        // data() is null, an identifier or a list; isList() says which

  if ($document->isList()) {                             // a list where an identifier goes
    throw JsonApiException::make(status: 422, detail: 'A to-one relationship takes one identifier or null.', pointer: '/data');
  }

  $identifier = $document->data();                       // ['type' => 'authors', 'id' => '3'] or null

  if ($identifier !== null && $identifier['type'] !== 'authors') {
    throw JsonApiException::make(status: 409, pointer: '/data/type');          // the type is not the relationship's
  }

  $author = $identifier === null ? null : Author::find($identifier['id'])
    ?? throw JsonApiException::make(status: 404, pointer: '/data');            // the resource does not exist

  // ... associate, save and answer with the resulting relationship (`200`)
}
```

`PATCH` replaces, `POST` adds and `DELETE` removes on a to-many relationship. Check everything **before** writing, so an error leaves no half-done changes. The full example is in [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi).

### Query parameters

| Eloquent builder macro | What it does |
|---|---|
| `jsonApiPaginate(?int $perPage = null, ?int $maxPerPage = null)` | Fetches the page the client asked for (`page[number]`, `page[size]`) and returns Laravel's paginator. A page out of range is an empty list. |
| `jsonApiSort(array $allowed, ?string $default = null)` | Applies `sort` (`-` for descending) with a whitelist. It appends the primary key so pages are stable. It accepts `['author' => 'author_id']` to name a column differently. |
| `jsonApiFilter(exact: [], partial: [], scopes: [])` | `exact`: equality (with commas, an `IN`). `partial`: `LIKE %value%` with `%` and `_` escaped. `scopes`: calls a scope of the model. A name outside the lists is `400`. |
| `jsonApiInclude(array $allowed)` | Eager loads the relationships in `include` (`['author', 'comments.author']`; allowing a path allows its prefixes). A path outside the list is `400`. |

The lists are written in **snake_case** (the columns and relationships of your model); the client sends them in the configured style.

**Page size**: it comes from the client (`page[size]`), otherwise from the `jsonApiPaginate(perPage: 30)` argument, otherwise from the middleware options (`->middleware('json-api.options:per_page=25,max_per_page=50')`) and, otherwise, from the config; always capped by the maximum.

`$request->jsonApiQuery()` gives the already parsed query (`includes()`, `fields()`, `sort()`, `filter()`, `page()`).

### Reading the input document

The package validates the body of every JSON `POST` and `PATCH` before it reaches your controller, and the `DELETE` of a relationship endpoint too. To read it, the request has these methods (attribute and relationship names are in snake_case):

| Method | Returns |
|---|---|
| `$request->isJsonApi()` | Whether the package treats the request as JSON:API |
| `$request->jsonApiType()` / `jsonApiId()` | The `type` and `id` of the document (`id` is `null` if absent) |
| `$request->jsonApiAttributes()` | The attributes, with names in snake_case |
| `$request->hasJsonApiRelationship('author')` | Whether the client sent that relationship (even as `data: null`) |
| `$request->jsonApiRelationshipId('author')` | The `id` of a to-one relationship, or `null` |
| `$request->jsonApiRelationshipIds('tags')` | The `id`s of a to-many relationship, in order |

Without the name, `jsonApiRelationshipId()` and `jsonApiRelationshipIds()` read the linkage of the body of a relationship endpoint.

**`FormRequest`**: add the `InteractsWithJsonApi` trait and write the rules over the flat data of the document (attributes in snake_case and, for each relationship, its object). `validated()` can fill a model and each error comes out with its `source.pointer`:

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
      'tags.data.*.type' => ['in:labels'],                // /data/relationships/tags/data/0/type
    ];
  }

  // The `type` this endpoint accepts: any other is a 409. And on PATCH/PUT, the `id` must be the one in the URL
  // (jsonApiExpectedId(), by default the last route parameter).
  protected function jsonApiExpectedType(): ?string {
    return 'articles';
  }
}
```

### Errors

Every error comes out as `{"errors": [...]}` with `status`, `title`, `detail`, `code` and `source` (`pointer`, `parameter` or `header`), in the app language (Spanish and English included). The package hooks into Laravel's exception handling and works the same on versions 10 to 13; if the app registers its own `renderable`, the app's wins. To throw one by hand:

```php
throw JsonApiException::make(status: 404, pointer: '/data/relationships/author/data');
```

The title, detail and code are filled in from the status. A `5xx` error hides its internals unless `errors.expose_details` (or `app.debug`) allows it.

| Base code | Status | When |
|---|---|---|
| `request.not_acceptable` | 406 | The `Accept` does not allow JSON:API (`source.header`) |
| `request.unsupported_media_type` | 415 | The `Content-Type` is not JSON:API (`source.header`) |
| `request.bad_query` | 400 | A malformed or not allowed query parameter (`source.parameter`) |
| `request.bad_document` | 400 | The body is not JSON or does not have the shape of a document (`source.pointer`) |
| `request.client_id_not_allowed` | 403 | A `POST` with a client `id` and `allow_client_ids` off |
| `auth.unauthenticated` | 401 | Not authenticated; it never redirects to login |
| `auth.forbidden` | 403 | No permission |
| `resource.not_found` | 404 | The resource does not exist (it does not reveal the model name) |
| `route.not_found` | 404 | The route does not exist |
| `request.method_not_allowed` | 405 | The method is not on the route; it carries `Allow` |
| `resource.conflict` | 409 | The `type` or `id` of the document are not those of the endpoint |
| `validation.<rule>` | 422 | A validation rule failed (`validation.required`, `validation.max`…) |
| `request.too_many_requests` | 429 | Rate limit; it carries `Retry-After` |
| `server.error` / `server.unavailable` | 500 / 503 | Unexpected error or service unavailable |

Codes carry the prefix `mundoprogra.json-api.` (for example `mundoprogra.json-api.validation.required`). Change it with `errors.code_prefix`, replace codes one by one with `errors.codes`, or turn them off with `errors.include_code`.

#### The order of the checks

When a request fails for more than one reason, only the first comes out, in this order:

1. `Content-Type` (`415`) and then `Accept` (`406`).
2. The syntax of the query (`400`).
3. The document: broken or malformed JSON (`400`) or a client `id` (`403`).
4. The route: method not allowed (`405`) or a route that does not exist (`404`).
5. Authentication (`401`).
6. The `FormRequest`: a different `type` or `id` (`409`) and then the rules (`422`).
7. Your controller: the query whitelist (`400`) and a resource that does not exist (`404`).

Steps 1 to 3 run before `auth`: without a session, broken JSON is a `400`, not a `401`.

### How it switches on

Inside the scope (`mode`, `path_prefix` and `except`) the package adds four middleware to the global stack on its own: `json-api.apply` (marks the request and fixes the `Accept` so `auth` answers `401` instead of looking up the `login` route), `json-api.negotiate`, `json-api.query` and `json-api.document`. Outside that scope it touches nothing.

For a single route outside the scope: `->middleware('json-api')` applies all four. They also exist separately, plus `json-api.options` (for example `json-api.options:per_page=25,max_per_page=50`). `json-api.document:relationship` and `:resource` force the kind of document without looking at the URL.

### Configuration

Everything can be set in `.env`. With `php artisan vendor:publish --tag="json-api-config"` you get the `config/json-api.php` file.

| Option | `.env` | Default | What it does |
|---|---|---|---|
| `mode` | `JSON_API_MODE` | `prefix` | `prefix`: only routes with `path_prefix`. `global`: the whole app |
| `path_prefix` | `JSON_API_PATH_PREFIX` | `api` | Route prefix(es) (a string or an array) |
| `except` | — | `['up']` | Routes the package never touches (wildcards allowed) |
| `strict_headers` | `JSON_API_STRICT_HEADERS` | `true` | Requires the JSON:API headers; `false` also accepts `application/json` |
| `member_case` | `JSON_API_MEMBER_CASE` | `camel` | Naming style: `camel`, `kebab` or `snake` |
| `jsonapi_object` | `JSON_API_JSONAPI_OBJECT` | `true` | Includes `{"jsonapi": {"version": "1.1"}}` |
| `allow_client_ids` | `JSON_API_ALLOW_CLIENT_IDS` | `false` | Accepts the `id` the client sends when creating |
| `allow_put` | `JSON_API_ALLOW_PUT` | `false` | Also validates `PUT` as JSON:API |
| `reject_unknown_query_params` | `JSON_API_REJECT_UNKNOWN_QUERY_PARAMS` | `false` | An unknown parameter made only of `a-z` answers `400` |
| `pagination.size` | `JSON_API_PAGE_SIZE` | `15` | Default page size |
| `pagination.max_size` | `JSON_API_PAGE_MAX_SIZE` | `100` | Upper limit for the client `page[size]` |
| `pagination.on_exceed` | `JSON_API_PAGE_ON_EXCEED` | `clamp` | Over the limit: `clamp` trims it, `error` answers `400` |
| `query.max_include_depth` | `JSON_API_MAX_INCLUDE_DEPTH` | `3` | Maximum `include` depth |
| `links.route_name_prefix` | `JSON_API_ROUTE_NAME_PREFIX` | empty | Prefix to resolve the route names of `links` |
| `errors.expose_details` | `JSON_API_EXPOSE_DETAILS` | `app.debug` | Shows the real detail in `500` errors |
| `errors.include_code` | `JSON_API_INCLUDE_CODE` | `true` | Sends `code` in every error |
| `errors.code_prefix` | `JSON_API_CODE_PREFIX` | `mundoprogra.json-api` | Prefix of the codes |
| `errors.codes` | — | `[]` | Replaces codes with your own |
| `errors.include_id` | `JSON_API_INCLUDE_ID` | `false` | Adds a unique `id` to each error and writes it to the log |

The configuration is validated on boot: an invalid value stops the application with a message that lists every problem at once.

### Naming style

`member_case` decides how multi-word names are written in the document: `createdAt` (`camel`), `created-at` (`kebab`) or `created_at` (`snake`). It applies to attributes, relationships, `sort`, `fields` and the pagination `meta`, on input and output. In your code you always write snake_case; the package converts. Types, ids, links and error pointers do not change with the style.

### Known limits

- The package registers no routes or controllers; the endpoints (and their authorization) are yours.
- JSON:API 1.1 is negotiated, but extensions and profiles are not implemented: any `ext` is rejected with `415` and profiles are ignored. There is no `lid` either.
- `PUT` is not validated unless `allow_put` is on; the spec only defines `PATCH` for updates.
- An `include` on a relationship that was not loaded is silently ignored: eager loading is `jsonApiInclude()`'s job.
- A paginator that implements Laravel's contract but is not Illuminate's `LengthAwarePaginator` class gets no `links` or `meta`.
- The cardinality rules (an identifier or a list) and type rules of a relationship endpoint are written by the app, as in the example above.
- The official JSON:API schema used in the tests is the one for version 1.0.

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

Full version matrix (every PHP variant of every Laravel, with stable and lowest dependencies; it takes about 30 minutes):

```bash
bin/matrix.sh                 # all of them
bin/matrix.sh l12-php8.4      # only that variant
```

The reference API used by the contract tests (articles, related resources and relationship endpoints, written the way an app would write them) is in [`tests/Fixtures/ReferenceApi`](tests/Fixtures/ReferenceApi); its tests validate every response against the official JSON:API schema.

### Changelog

See the [CHANGELOG](CHANGELOG.md) for recent changes.

### Security

To report a vulnerability, please review the [security policy](../../security/policy).

### Credits

- [Alvaro Romero](https://github.com/mundoprogra)
- [All Contributors](../../contributors)

### License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
