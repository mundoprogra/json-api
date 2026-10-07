# Changelog

All notable changes to `json-api` will be documented in this file.

## [Unreleased]

### Added

- Package skeleton for Laravel 10 to 13 (PHP 8.1+), with a plain service provider and a publishable config file (`json-api-config`).
- Docker test matrix (`docker-compose.yml`) with one service per Laravel version (`l10`, `l11`, `l12`, `l13`).
- PHPUnit suites (`Unit`, `Feature`, `Contract`) running on Orchestra Testbench.
- Contract testing helper (`assertJsonApiDocument()`) backed by the official JSON:API schema.
- Static analysis (PHPStan + Larastan) and code checks (Pint).
