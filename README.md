# MundoProgra JSON:API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![Tests](https://img.shields.io/github/actions/workflow/status/mundoprogra/json-api/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mundoprogra/json-api/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mundoprogra/json-api.svg?style=flat-square)](https://packagist.org/packages/mundoprogra/json-api)
[![License](https://img.shields.io/packagist/l/mundoprogra/json-api.svg?style=flat-square)](LICENSE.md)

**Español** · [English](#english)

---

## Español

Paquete para Laravel que convierte una API en un servicio [JSON:API 1.1](https://jsonapi.org/format/) de punta a punta: acepta y valida las peticiones en el estándar y responde en el estándar, tanto en éxito como en error.

> **Estado: en desarrollo (aún sin versión publicada).** Ya funcionan los errores en JSON:API, el alcance por prefijo o global, la negociación de contenido, la validación de los parámetros de consulta y los recursos con relaciones, `links` y `meta`; la paginación está en curso. La API pública se documenta aquí por completo al publicarse la versión `0.1.0`; mientras tanto, el detalle de cada cambio está en el [CHANGELOG](CHANGELOG.md).

### Qué busca resolver

- Aceptar y validar peticiones JSON:API (cabeceras, documento, parámetros de consulta y relaciones).
- Responder en JSON:API, tanto las respuestas exitosas como las de error.
- Configuración base al instalar, con valores sobrescribibles por petición (por ejemplo, el tamaño de página).
- Modo "API pura" (todas las respuestas en JSON:API) o limitado a un prefijo de rutas.

### Requisitos

- PHP 8.1 o superior
- Laravel 10, 11, 12 o 13

### Instalación

El paquete estará disponible en Packagist a partir de la versión `0.1.0`:

```bash
composer require mundoprogra/json-api
```

Publicar el archivo de configuración:

```bash
php artisan vendor:publish --tag="json-api-config"
```

### Desarrollo y pruebas

No necesitas PHP instalado: todo se ejecuta en Docker. Cada servicio de `docker-compose.yml` corresponde a una versión de Laravel:

```bash
docker compose run --rm l13                              # Laravel 13 (uso diario)
docker compose run --rm l10                              # Laravel 10 (PHP 8.1, la versión mínima)
docker compose run --rm -e ARGS="--filter=Provider" l13  # un subconjunto de pruebas
docker compose run --rm -e STABILITY=prefer-lowest l12   # dependencias en su versión mínima
```

Servicios disponibles: `l10`, `l11`, `l12` y `l13`. Ejecútalos **uno por uno**, no en paralelo: comparten la carpeta del paquete.

Calidad de código:

```bash
docker compose run --rm l13 vendor/bin/pint --test
docker compose run --rm l13 vendor/bin/phpstan analyse --no-progress
```

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

> **Status: under development (no release published yet).** JSON:API errors, the prefix or global scope, content negotiation, query parameter validation and resources with relationships, `links` and `meta` already work; pagination is in progress. The public API is documented here in full when version `0.1.0` is released; in the meantime, the detail of each change is in the [CHANGELOG](CHANGELOG.md).

### What it aims to solve

- Accept and validate JSON:API requests (headers, document, query parameters and relationships).
- Respond in JSON:API, for both successful and error responses.
- Sensible defaults chosen at install time, overridable per request (for example, the page size).
- "Pure API" mode (every response in JSON:API) or limited to a route prefix.

### Requirements

- PHP 8.1 or higher
- Laravel 10, 11, 12 or 13

### Installation

The package will be available on Packagist starting with version `0.1.0`:

```bash
composer require mundoprogra/json-api
```

Publish the config file:

```bash
php artisan vendor:publish --tag="json-api-config"
```

### Development and testing

You don't need PHP installed: everything runs in Docker. Each service in `docker-compose.yml` maps to a Laravel version:

```bash
docker compose run --rm l13                              # Laravel 13 (daily use)
docker compose run --rm l10                              # Laravel 10 (PHP 8.1, the minimum version)
docker compose run --rm -e ARGS="--filter=Provider" l13  # a subset of tests
docker compose run --rm -e STABILITY=prefer-lowest l12   # dependencies at their lowest versions
```

Available services: `l10`, `l11`, `l12` and `l13`. Run them **one at a time**, not in parallel: they share the package folder.

Code quality:

```bash
docker compose run --rm l13 vendor/bin/pint --test
docker compose run --rm l13 vendor/bin/phpstan analyse --no-progress
```

### Changelog

See the [CHANGELOG](CHANGELOG.md) for recent changes.

### Security

To report a vulnerability, please review the [security policy](../../security/policy).

### Credits

- [Alvaro Romero](https://github.com/mundoprogra)
- [All Contributors](../../contributors)

### License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.
