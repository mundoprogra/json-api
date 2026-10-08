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
