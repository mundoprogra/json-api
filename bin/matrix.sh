#!/bin/sh
# Matriz completa de versiones (Git Bash / Mac / Linux): corre las pruebas con cada variante de PHP de cada versión de Laravel, con las dependencias
# en su última versión (prefer-stable) y en su versión mínima (prefer-lowest). Es lo mismo que hace el CI de GitHub, pero con las imágenes de Docker.
# Tarda unos 30 minutos: córrelo antes de publicar una versión o cuando cambies algo que dependa de la versión de PHP. Los servicios se ejecutan
# uno por uno, porque comparten la carpeta del paquete.
#
# Full version matrix (Git Bash / Mac / Linux): runs the tests with every PHP variant of every Laravel version, with the dependencies at their latest
# version (prefer-stable) and at their minimum version (prefer-lowest). It is the same thing the GitHub CI does, but with the Docker images.
# It takes about 30 minutes: run it before releasing a version or when you change something that depends on the PHP version. The services run
# one at a time, because they share the package folder.
#
# Uso / Usage:
#   bin/matrix.sh                    -> todas las variantes / all the variants
#   bin/matrix.sh l10-php8.1         -> solo esas variantes / only those variants
#   bin/matrix.sh l12-php8.4 l13-php8.4
#
# Variantes / Variants: l10-php8.1 l10-php8.2 l10-php8.3 · l11-php8.2 l11-php8.3 l11-php8.4 l11-php8.5 · l12-php8.2 l12-php8.3 l12-php8.4 l12-php8.5 · l13-php8.3 l13-php8.4 l13-php8.5
#
# No hay imágenes 11-php8.5 ni 12-php8.5: las variantes con PHP 8.5 usan la imagen 13-php8.5 y el servicio de su Laravel fija la versión (Testbench).
# There are no 11-php8.5 or 12-php8.5 images: the PHP 8.5 variants use the 13-php8.5 image, and the service of their Laravel pins the version (Testbench).
#
# La salida completa de cada corrida queda en build/matrix/<variante>_<estabilidad>.txt, con las deprecaciones, avisos y notas que haya.
# The full output of each run is left in build/matrix/<variant>_<stability>.txt, with any deprecations, warnings and notices.
set -u

cd "$(dirname "$0")/.."

official="l10-php8.1 l10-php8.2 l10-php8.3 l11-php8.2 l11-php8.3 l11-php8.4 l11-php8.5 l12-php8.2 l12-php8.3 l12-php8.4 l12-php8.5 l13-php8.3 l13-php8.4 l13-php8.5"
variants="${*:-$official}"

mkdir -p build/matrix
rm -f build/matrix/*.txt

failed=""
deprecated=""

for variant in $variants; do
  service="${variant%%-*}"
  php="${variant#*-}"
  number="${service#l}"

  # El servicio de uso diario de Laravel 13 usa el vendor del host (lento): aquí se usa l13fast, con el vendor en un volumen de Docker.
  # The daily-use Laravel 13 service uses the host vendor (slow): here l13fast is used, with the vendor in a Docker volume.
  [ "$service" = "l13" ] && service="l13fast"

  for stability in prefer-stable prefer-lowest; do
    file="build/matrix/${variant}_${stability}.txt"
    lowest=""
    [ "$stability" = "prefer-lowest" ] && lowest="prefer-lowest"

    # Con PHP 8.5 solo existe la imagen 13-php8.5; el servicio de cada Laravel fija su versión con Testbench.
    # With PHP 8.5 only the 13-php8.5 image exists; the service of each Laravel pins its version with Testbench.
    tag="${number}-${php}"
    [ "$php" = "php8.5" ] && tag="13-php8.5"

    env "L${number}_TAG=${tag}" docker compose run --rm \
      -e STABILITY="$lowest" -e ARGS="--display-deprecations --display-warnings --display-notices" \
      "$service" > "$file" 2>&1

    summary=$(sed 's/\x1b\[[0-9;]*m//g' "$file" | tr -d '\000' | grep -aE '^(OK|Tests:)' | tr '\n' ' ')

    case "$summary" in
      OK*) status="OK" ;;
      *) status="FALLA"; failed="$failed ${variant}/${stability}" ;;
    esac

    if grep -aq 'Deprecations:\|triggered [0-9]* deprecation' "$file"; then
      deprecated="$deprecated ${variant}/${stability}"
    fi

    echo "$status  $variant  $stability  ${summary:-sin resultado de pruebas}"
  done
done

echo "---"
[ -n "$deprecated" ] && echo "Con deprecaciones / With deprecations:$deprecated"

if [ -n "$failed" ]; then
  echo "Fallaron / Failed:$failed"
  echo "FIN con fallos / END with failures"
  exit 1
fi

echo "Todo en verde / All green"
echo "FIN"
