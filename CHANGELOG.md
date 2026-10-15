# Changelog

All notable changes to `json-api` will be documented in this file.

## [Unreleased]

### Added

- Package skeleton for Laravel 10 to 13 (PHP 8.1+), with a plain service provider and a publishable config file (`json-api-config`).
- Docker test matrix (`docker-compose.yml`) with one service per Laravel version (`l10`, `l11`, `l12`, `l13`).
- PHPUnit suites (`Unit`, `Feature`, `Contract`) running on Orchestra Testbench.
- Contract testing helper (`assertJsonApiDocument()`) backed by the official JSON:API schema.
- Static analysis (PHPStan + Larastan) and code checks (Pint).
- Configuration file `config/json-api.php` with sensible defaults, publishable with `php artisan vendor:publish --tag=json-api-config`.
- Every option can be set from `.env` (`JSON_API_*` variables), with boolean and integer conversion.
- Recursive configuration merge: an app that overrides only part of a group (for example `pagination.size`) keeps the remaining defaults of that group.
- `Settings` class: typed, always up-to-date access to the configuration, shared as a singleton.
- Configuration validation at boot: invalid values stop the application with an `InvalidConfigurationException` that reports every problem at once.
- `Pointer`: builds and reads JSON Pointers (RFC 6901) for the `source.pointer` of errors, with correct escaping of `~` and `/`.
- `MemberName`: converts attribute and relationship names between the configured style (`camel`, `kebab` or `snake`) and `snake_case`, for names and for first-level array keys.
- `MediaType`: strict reader, comparer and writer of a single media type, with `ext` and `profile` lists (RFC 7230 tokens and quoted values).
- `ContentNegotiator`: JSON:API 1.1 content negotiation rules for the `Content-Type` (415) and `Accept` (406) headers, with a strict and a permissive mode; unsupported extensions are rejected and profiles are ignored.
- Contract tests that pin what the official JSON:API schema accepts and rejects, documented in `tests/Fixtures/schema/NOTICE.md`.
- `Error` and `ErrorDocument`: immutable JSON:API error objects (`status`, `title`, `detail`, `code`, `id`, `source.pointer|parameter|header`, `meta`) and the full error document, always sent as `application/vnd.api+json`. With several errors the HTTP status is the shared one, `400` if they are all 4xx but different, and `500` if any is 5xx.
- `JsonApiException`: throw JSON:API errors from the app code (one or several, with their own headers). Client errors (4xx) are not written to the log; server errors (5xx) are, like any other exception.
- Error codes: a stable catalog with the `mundoprogra.json-api` prefix (`errors.code_prefix`), replaceable one by one with `errors.codes`, and switched off entirely with `errors.include_code`. Validation errors get one code per failed rule.
- Error texts (`title` and `detail`) in English and Spanish, in the current Laravel locale, publishable with `php artisan vendor:publish --tag=json-api-lang`.
- `ExceptionRenderer`, hooked into Laravel's exception handling from the service provider (same behavior from Laravel 10 to 13, no change to `bootstrap/app.php`, `Kernel` or `Handler`): every exception is rendered as a JSON:API error document, never as HTML. Covers validation (`source.pointer` / `source.parameter` per field), authentication (`401`, never a redirect), authorization, missing routes and resources, `405` with `Allow`, `429` with `Retry-After`, `419`, invalid signed URLs, `503` and any `abort(<status>)`. Server errors hide their internals unless `errors.expose_details` is on. Any `renderable` registered by the app wins over the package.
- Exceptions that cannot be modified (for example from a third-party package) are converted with Laravel's own `map()`; the package renders the result.
- Optional error `id` (`errors.include_id`, off by default): a unique id per error, sent in the response and added to the log context as `error_id`, so a reported error can be found in the app logs. The package only generates and hands over the id; it never writes or stores logs.
- `RequestScope`: a single rule that decides whether the package acts on a request (route marked by hand, `except`, request already asking for the JSON:API media type, then `mode` and `path_prefix` by whole segments). Outside the scope the package yields to Laravel (HTML, Laravel's own JSON, usual 404s) and does not note the `error_id` in the log. A `JsonApiException` thrown by hand is always answered as JSON:API.
- `ApplyJsonApi` middleware, added at the start of the global stack when the HTTP kernel is created (no change to `Kernel` or `bootstrap/app.php`) and available as the `json-api` route alias: inside the scope the request asks for JSON:API, so `expectsJson()` is true and Laravel's `auth` middleware answers `401` instead of failing on `route('login')`. The original `Accept` header is kept on the request (`json-api.original_accept`). The alias marks a route by hand even outside the prefix or on an excepted path.
