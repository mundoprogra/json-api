#!/bin/sh
# Corre dentro del contenedor (ver docker-compose.yml).
# Instala las dependencias de la versión de Laravel elegida sin tocar composer.json y ejecuta PHPUnit.
#
# Variables:
#   TESTBENCH  Restricción de orchestra/testbench (ej. "8.*"); fija la versión de Laravel.
#   STABILITY  "prefer-lowest" para probar las versiones mínimas (opcional).
#   ARGS       Argumentos extra para PHPUnit (opcional).
set -eu

cd /package

# composer.lock es un archivo generado (ignorado por git); se borra para no mezclar versiones.
trap 'rm -f composer.lock' EXIT

composer update --no-interaction --no-progress ${STABILITY:+--$STABILITY} \
  --with "orchestra/testbench:${TESTBENCH}"

vendor/bin/phpunit ${ARGS:-}
