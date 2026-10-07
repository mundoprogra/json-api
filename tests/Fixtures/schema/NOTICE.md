# JSON:API schema (test fixture)

`jsonapi-1.0.json` is a verbatim copy of the official JSON:API 1.0 response schema.

- Source: https://github.com/json-api/json-api/blob/52fc018adcc15358cb0f9d3de119f7e0fbe34ea6/_schemas/1.0/schema.json
- Upstream license: CC0 1.0 Universal (public domain dedication).
- Dialect: JSON Schema 2020-12.
- Scope: validates **responses** only. The same repository also publishes request schemas
  (`schema_create_resource.json`, `schema_update_resource.json`, `schema_update_relationship.json`),
  which are candidates for the request-validation phase.
- There is no official 1.1 schema; 1.1-only members are checked with additional assertions.

This folder is not shipped with the package (`tests/` is `export-ignore`d).
