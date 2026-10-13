# Schema de JSON:API (fixture de pruebas) / JSON:API schema (test fixture)

## Español

`jsonapi-1.0.json` es una copia fiel del schema oficial de respuestas de JSON:API 1.0.

- Origen: https://github.com/json-api/json-api/blob/52fc018adcc15358cb0f9d3de119f7e0fbe34ea6/_schemas/1.0/schema.json
- Licencia original: CC0 1.0 Universal (dedicación al dominio público).
- Dialecto: JSON Schema 2020-12.
- Alcance: valida solo **respuestas**. El mismo repositorio publica además schemas de peticiones
  (`schema_create_resource.json`, `schema_update_resource.json`, `schema_update_relationship.json`),
  candidatos para la fase de validación de peticiones.
- No existe un schema oficial 1.1; los miembros exclusivos de 1.1 se comprueban con asserts adicionales.

Esta carpeta no se distribuye con el paquete (`tests/` está en `export-ignore`).

### Qué cubre el schema (fijado en `tests/Contract/SchemaCoverageTest.php`)

**Lo detecta bien:** documento vacío, `data` y `errors` juntos, miembros desconocidos, `included` sin `data`, recurso sin `type` o `id`,
`id` numérico, atributos llamados `id` o `type`, `attributes` que no es un objeto, relaciones vacías o sin `id` en el identificador,
`status` de error numérico, `pointer` que no es texto y enlaces con dirección **relativa** (exige URI absoluta).

**Acepta lo que el paquete producirá:** `jsonapi.version`, `source.pointer`, `source.parameter`, `source.header` (1.1), enlaces de documento, recurso,
relación y paginación (texto, `null` u objeto con `href`, `meta`, `rel`, `describedby`...), `included` y atributos con objetos anidados.
En 1.1 es válido un atributo llamado `relationships` o `links` (la 1.0 lo reservaba), y el schema lo acepta.

**Limitación conocida: rechaza miembros válidos en 1.1.** `lid` (en recursos e identificadores), `ext` y `profile` dentro de `jsonapi`,
`describedby` en `links`, `type` en los `links` de un error y los miembros de extensión (`atomic:results`, `ext:foo`).
Ninguno lo emite el paquete en la `0.1.0` (las operaciones atómicas quedan fuera). Si algún caso empieza a aceptarse, actualiza este archivo.

**Huecos: acepta lo que la spec no permite.** Cualquier valor de `jsonapi.version` (por ejemplo `9.9`), un atributo y una relación con el mismo nombre
(Fase 6) y un `included` con el mismo `type` e `id` repetido pero con contenido distinto (Fase 10): el schema solo compara objetos completos.
Se cubren con comprobaciones propias en las fases que generan esos documentos.

## English

`jsonapi-1.0.json` is a verbatim copy of the official JSON:API 1.0 response schema.

- Source: https://github.com/json-api/json-api/blob/52fc018adcc15358cb0f9d3de119f7e0fbe34ea6/_schemas/1.0/schema.json
- Upstream license: CC0 1.0 Universal (public domain dedication).
- Dialect: JSON Schema 2020-12.
- Scope: validates **responses** only. The same repository also publishes request schemas
  (`schema_create_resource.json`, `schema_update_resource.json`, `schema_update_relationship.json`),
  which are candidates for the request-validation phase.
- There is no official 1.1 schema; 1.1-only members are checked with additional assertions.

This folder is not shipped with the package (`tests/` is `export-ignore`d).

### What the schema covers (pinned in `tests/Contract/SchemaCoverageTest.php`)

**It catches well:** an empty document, `data` and `errors` together, unknown members, `included` without `data`, a resource without `type` or `id`,
a numeric `id`, attributes named `id` or `type`, `attributes` that is not an object, empty relationships or identifiers without `id`,
a numeric error `status`, a `pointer` that is not text, and **relative** links (it requires an absolute URI).

**It accepts what the package will produce:** `jsonapi.version`, `source.pointer`, `source.parameter`, `source.header` (1.1), document, resource,
relationship and pagination links (text, `null` or an object with `href`, `meta`, `rel`, `describedby`...), `included` and attributes with nested objects.
In 1.1 an attribute named `relationships` or `links` is valid (1.0 reserved them), and the schema accepts it.

**Known limitation: it rejects members that are valid in 1.1.** `lid` (in resources and identifiers), `ext` and `profile` inside `jsonapi`,
`describedby` in `links`, `type` in an error `links`, and extension members (`atomic:results`, `ext:foo`).
The package emits none of them in `0.1.0` (atomic operations are out of scope). If any case starts being accepted, update this file.

**Gaps: it accepts what the spec does not allow.** Any `jsonapi.version` value (for example `9.9`), an attribute and a relationship sharing a name
(Phase 6) and an `included` repeating the same `type` and `id` with different content (Phase 10): the schema only compares whole objects.
They are covered by own checks in the phases that generate those documents.
