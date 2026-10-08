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
