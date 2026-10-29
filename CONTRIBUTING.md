# Contribuir / Contributing

[Español](#español) · [English](#english)

## Español

Por ahora el proyecto solo acepta **issues**. Los pull requests de terceros no se revisan (los de Dependabot sí se siguen abriendo). Cuando eso cambie, se avisará aquí.

### Cómo abrir un issue

- **Un bug:** usa la plantilla *Bug Report*. Indica la versión del paquete, la de PHP y la de Laravel, y los pasos para reproducirlo con la configuración que usaste.
- **Una idea o una pregunta:** usa la plantilla *Feature request or question*.
- **Una vulnerabilidad:** no uses issues; sigue la [política de seguridad](SECURITY.md).
- Antes de abrir uno, busca si ya existe y revisa el [README](README.md) y el [CHANGELOG](CHANGELOG.md).

### Correr las pruebas en local

Todo se ejecuta en Docker; no hace falta PHP en tu equipo. Desde la raíz del paquete:

```bash
docker compose run --rm l13                       # todas las pruebas con Laravel 13
docker compose run --rm l13fast vendor/bin/pint --test
docker compose run --rm l13fast vendor/bin/phpstan analyse --no-progress
bin/matrix.sh                                     # matriz completa de PHP y Laravel
```

El CI desactiva el bloqueo de avisos de seguridad de Composer para Laravel 10 y 11, porque esas versiones del framework ya no reciben soporte.

## English

For now the project only accepts **issues**. Third-party pull requests are not reviewed (Dependabot ones are still opened). When that changes, this page will say so.

### How to open an issue

- **A bug:** use the *Bug Report* template. Give the package version, the PHP and Laravel versions, and the steps to reproduce it with the configuration you used.
- **An idea or a question:** use the *Feature request or question* template.
- **A vulnerability:** do not use issues; follow the [security policy](SECURITY.md).
- Before opening one, check whether it already exists and read the [README](README.md) and the [CHANGELOG](CHANGELOG.md).

### Running the tests locally

Everything runs in Docker; you do not need PHP on your machine. From the package root:

```bash
docker compose run --rm l13                       # all the tests on Laravel 13
docker compose run --rm l13fast vendor/bin/pint --test
docker compose run --rm l13fast vendor/bin/phpstan analyse --no-progress
bin/matrix.sh                                     # full PHP and Laravel matrix
```

The CI turns off Composer's security-advisory block for Laravel 10 and 11, because those framework versions no longer receive support.
