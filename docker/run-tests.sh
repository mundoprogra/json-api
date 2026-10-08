#!/bin/sh
# Corre dentro del contenedor (ver docker-compose.yml).
# Instala las dependencias de la versión de Laravel elegida sin tocar composer.json y ejecuta PHPUnit.
# Runs inside the container (see docker-compose.yml).
# Installs the dependencies of the chosen Laravel version without touching composer.json and runs PHPUnit.
#
# Variables:
#   TESTBENCH  Restricción de orchestra/testbench (ej. "8.*"); fija la versión de Laravel.
#              Constraint for orchestra/testbench (e.g. "8.*"); it pins the Laravel version.
#   STABILITY  "prefer-lowest" para probar las versiones mínimas (opcional).
#              "prefer-lowest" to test the minimum versions (optional).
#   ARGS       Argumentos extra para PHPUnit (opcional).
#              Extra arguments for PHPUnit (optional).
set -eu

cd /package

# composer.lock es un archivo generado (ignorado por git); se borra para no mezclar versiones.
# composer.lock is a generated file (ignored by git); it is removed so versions do not get mixed.
trap 'rm -f composer.lock' EXIT

composer update --no-interaction --no-progress ${STABILITY:+--$STABILITY} \
  --with "orchestra/testbench:${TESTBENCH}"

vendor/bin/phpunit ${ARGS:-}
