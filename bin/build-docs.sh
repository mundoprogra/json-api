#!/bin/sh
# Compila la documentación (Git Bash / Mac / Linux): arma las páginas de docs/ con PHP y compila los estilos con Tailwind, todo dentro de Docker.
# El resultado (docs/) se sube al repositorio; node_modules/ no. Córrelo cada vez que cambies tools/docs/ y antes de hacer commit.
# Builds the documentation (Git Bash / Mac / Linux): builds the docs/ pages with PHP and compiles the styles with Tailwind, all inside Docker.
# The result (docs/) is committed to the repository; node_modules/ is not. Run it every time you change tools/docs/ and before committing.
#
# Uso / Usage:
#   bin/build-docs.sh
#   docker compose run --rm l13fast php bin/check-docs.php      # revisar el resultado / check the result
set -eu

cd "$(dirname "$0")/.."

docker compose run --rm l13fast sh -c '
  php tools/docs/build.php &&
  npm ci --no-audit --no-fund --silent &&
  npx tailwindcss -i tools/docs/input.css -o docs/assets/style.css --minify
'
