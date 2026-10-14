<?php

  namespace MundoProgra\JsonApi\Errors;

  use Illuminate\Http\Request;
  use Illuminate\Support\Arr;
  use MundoProgra\JsonApi\Support\MemberName;
  use MundoProgra\JsonApi\Support\Pointer;
  use MundoProgra\JsonApi\Support\Settings;

  /**
   * Deduce a qué parte de la petición apunta un error de validación: al documento (`pointer`) o a la URL (`parameter`).
   * Laravel solo entrega el nombre del campo, sin decir de dónde vino el dato.
   * Works out which part of the request a validation error points to: the document (`pointer`) or the URL (`parameter`).
   * Laravel only gives the field name, without saying where the value came from.
   */
  final class ValidationSource {
    public function __construct(private readonly Settings $settings) {
    }

    /**
     * Reglas, en este orden y gana la primera que coincide:
     *   1. La clave es `data` o empieza con `data.`: es una ruta del documento.
     *   2. El primer segmento está en la URL y no dentro del documento: es un parámetro de la URL.
     *   3. Petición que escribe datos y el cuerpo trae esa relación: apunta a la relación.
     *   4. Petición que escribe datos: apunta al atributo.
     *   5. Cualquier otro caso (una lectura): es un parámetro de la URL.
     * Rules, in this order, and the first match wins:
     *   1. The key is `data` or starts with `data.`: it is a document path.
     *   2. The first segment is in the URL and not inside the document: it is a URL parameter.
     *   3. A request that writes data and the body carries that relationship: it points to the relationship.
     *   4. A request that writes data: it points to the attribute.
     *   5. Anything else (a read request): it is a URL parameter.
     *
     * @return array{pointer: string}|array{parameter: string}
     */
    public function resolve(string $key, Request $request): array {
      $segments = explode('.', $key);
      $first = $segments[0];

      if ($first === 'data') {
        return ['pointer' => (string) Pointer::from(...$segments)];
      }

      // Los nombres del documento van en el estilo del cliente; Laravel entrega los de la base de datos (snake_case).
      // Document names use the client style; Laravel gives the database ones (snake_case).
      $name = MemberName::for($this->settings->memberCase())->fromSnake($first);
      $document = $request->json()->all();
      $attributes = (array) Arr::get($document, 'data.attributes', []);
      $relationships = (array) Arr::get($document, 'data.relationships', []);
      $inDocument = array_key_exists($name, $attributes) || array_key_exists($name, $relationships);

      if ($request->query->has($first) && !$inDocument) {
        return ['parameter' => $this->bracketed($segments)];
      }

      if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $section = array_key_exists($name, $relationships) ? 'relationships' : 'attributes';

        // Solo el primer nivel es un nombre de miembro; lo de adentro son datos del cliente y no se convierte.
        // Only the first level is a member name; what is inside is client data and is not converted.
        return ['pointer' => (string) Pointer::from('data', $section, $name, ...array_slice($segments, 1))];
      }

      return ['parameter' => $this->bracketed($segments)];
    }

    /**
     * `page.size` -> `page[size]`.
     *
     * @param  list<string>  $segments
     */
    private function bracketed(array $segments): string {
      return $segments[0] . implode('', array_map(fn(string $segment) => "[$segment]", array_slice($segments, 1)));
    }
  }
