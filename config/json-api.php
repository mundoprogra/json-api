<?php

  // Convierten el texto del .env a booleano o entero. Si el valor no se puede interpretar (por ejemplo "abc"), se deja tal cual
  // para que la validación del arranque lo rechace y lo muestre en el mensaje, en lugar de convertirlo en silencio.
  // They convert .env text to a boolean or an integer. If the value cannot be interpreted (for example "abc"), it is left as is
  // so the boot validation rejects it and shows it in the message, instead of silently converting it.
  $bool = static fn(mixed $value): mixed => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
  $int = static fn(mixed $value): mixed => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value;

  // Configuración de MundoProgra/JsonApi. Cada valor puede definirse en el .env con la variable indicada en `env:`.
  // MundoProgra/JsonApi configuration. Each value can be set in .env with the variable shown in `env:`.
  //
  // Los valores booleanos aceptan true/false, 1/0, yes/no y on/off. Las listas (path_prefix como arreglo, except) solo se definen aquí.
  // Boolean values accept true/false, 1/0, yes/no and on/off. Lists (path_prefix as an array, except) can only be set here.
  return [
    /*
     * Dónde se aplica el paquete (env: JSON_API_MODE).
     *   'prefix' -> solo las rutas que empiezan con `path_prefix`.
     *   'global' -> toda la aplicación responde en JSON:API (incluye 404 y 500).
     * Where the package is applied (env: JSON_API_MODE).
     *   'prefix' -> only routes starting with `path_prefix`.
     *   'global' -> the whole application responds in JSON:API (including 404 and 500).
     */
    'mode' => env('JSON_API_MODE', 'prefix'),

    // Prefijo(s) de ruta cuando `mode` es 'prefix'. Acepta un string o un arreglo de strings (env: JSON_API_PATH_PREFIX, solo string).
    // Route prefix(es) when `mode` is 'prefix'. Accepts a string or an array of strings (env: JSON_API_PATH_PREFIX, string only).
    'path_prefix' => env('JSON_API_PATH_PREFIX', 'api'),

    // Rutas que el paquete nunca toca (admite comodines).
    // Routes the package never touches (wildcards allowed).
    'except' => ['up'],

    // true: se exigen las cabeceras JSON:API (Accept / Content-Type). false: también se acepta application/json (env: JSON_API_STRICT_HEADERS).
    // true: JSON:API headers are required (Accept / Content-Type). false: application/json is also accepted (env: JSON_API_STRICT_HEADERS).
    'strict_headers' => $bool(env('JSON_API_STRICT_HEADERS', true)),

    // Estilo de los nombres de atributos y relaciones, en entrada y salida: 'camel', 'kebab' o 'snake' (env: JSON_API_MEMBER_CASE).
    // Naming style of attributes and relationships, for input and output: 'camel', 'kebab' or 'snake' (env: JSON_API_MEMBER_CASE).
    'member_case' => env('JSON_API_MEMBER_CASE', 'camel'),

    // Incluir el miembro {"jsonapi": {"version": "1.1"}} en las respuestas (env: JSON_API_JSONAPI_OBJECT).
    // Include the {"jsonapi": {"version": "1.1"}} member in responses (env: JSON_API_JSONAPI_OBJECT).
    'jsonapi_object' => $bool(env('JSON_API_JSONAPI_OBJECT', true)),

    // false: un POST con `data.id` responde 403. true: se acepta el id enviado por el cliente (env: JSON_API_ALLOW_CLIENT_IDS).
    // false: a POST with `data.id` responds 403. true: the client-sent id is accepted (env: JSON_API_ALLOW_CLIENT_IDS).
    'allow_client_ids' => $bool(env('JSON_API_ALLOW_CLIENT_IDS', false)),

    // true: también se valida como JSON:API el verbo PUT (la spec solo define PATCH para actualizar) (env: JSON_API_ALLOW_PUT).
    // true: the PUT verb is also validated as JSON:API (the spec only defines PATCH for updates) (env: JSON_API_ALLOW_PUT).
    'allow_put' => $bool(env('JSON_API_ALLOW_PUT', false)),

    // true: un parámetro de consulta desconocido responde 400. false: se ignora (env: JSON_API_REJECT_UNKNOWN_QUERY_PARAMS).
    // true: an unknown query parameter responds 400. false: it is ignored (env: JSON_API_REJECT_UNKNOWN_QUERY_PARAMS).
    'reject_unknown_query_params' => $bool(env('JSON_API_REJECT_UNKNOWN_QUERY_PARAMS', false)),

    'pagination' => [
      // Tamaño de página por defecto (env: JSON_API_PAGE_SIZE).
      // Default page size (env: JSON_API_PAGE_SIZE).
      'size' => $int(env('JSON_API_PAGE_SIZE', 15)),

      // Tope para el `page[size]` que envía el cliente (env: JSON_API_PAGE_MAX_SIZE).
      // Upper limit for the client-sent `page[size]` (env: JSON_API_PAGE_MAX_SIZE).
      'max_size' => $int(env('JSON_API_PAGE_MAX_SIZE', 100)),

      // Si el cliente pide más que `max_size`: 'clamp' lo recorta al máximo, 'error' responde 400 (env: JSON_API_PAGE_ON_EXCEED).
      // If the client asks for more than `max_size`: 'clamp' trims it to the maximum, 'error' responds 400 (env: JSON_API_PAGE_ON_EXCEED).
      'on_exceed' => env('JSON_API_PAGE_ON_EXCEED', 'clamp'),
    ],

    'query' => [
      // Profundidad máxima de `include` (por ejemplo, author.comments.user tiene profundidad 3) (env: JSON_API_MAX_INCLUDE_DEPTH).
      // Maximum `include` depth (for example, author.comments.user has depth 3) (env: JSON_API_MAX_INCLUDE_DEPTH).
      'max_include_depth' => $int(env('JSON_API_MAX_INCLUDE_DEPTH', 3)),
    ],

    'links' => [
      // Prefijo para resolver el nombre de las rutas al construir `links`. Si la ruta no existe, el link se omite (env: JSON_API_ROUTE_NAME_PREFIX).
      // Prefix used to resolve route names when building `links`. If the route does not exist, the link is omitted (env: JSON_API_ROUTE_NAME_PREFIX).
      'route_name_prefix' => env('JSON_API_ROUTE_NAME_PREFIX', ''),
    ],

    'errors' => [
      // Mostrar el detalle de las excepciones en los errores 500. null: se sigue el valor de `app.debug` (env: JSON_API_EXPOSE_DETAILS).
      // Show exception details in 500 errors. null: follow the value of `app.debug` (env: JSON_API_EXPOSE_DETAILS).
      'expose_details' => env('JSON_API_EXPOSE_DETAILS') === null ? null : $bool(env('JSON_API_EXPOSE_DETAILS')),

      // Enviar el miembro `code` en cada error. Apagado significa nunca: ningún error lleva `code` (env: JSON_API_INCLUDE_CODE).
      // Send the `code` member in every error. Off means never: no error carries a `code` (env: JSON_API_INCLUDE_CODE).
      'include_code' => $bool(env('JSON_API_INCLUDE_CODE', true)),

      // Prefijo de los códigos por defecto del paquete, por ejemplo mundoprogra.json-api.validation.required. Vacío: sin prefijo (env: JSON_API_CODE_PREFIX).
      // Prefix of the package default codes, for example mundoprogra.json-api.validation.required. Empty: no prefix (env: JSON_API_CODE_PREFIX).
      'code_prefix' => env('JSON_API_CODE_PREFIX', 'mundoprogra.json-api'),

      // Reemplaza cualquier código por defecto con el tuyo: la clave es el código base sin prefijo y el valor se usa tal cual, sin prefijo.
      // Replaces any default code with yours: the key is the base code without prefix and the value is used as is, without prefix.
      // Ejemplo / Example: ['validation.required' => 'ERR-1001', 'resource.not_found' => 'ERR-4040']
      'codes' => [],

      // Agregar a cada error un `id` único y escribirlo también en el log, para encontrar una ocurrencia concreta (env: JSON_API_INCLUDE_ID).
      // Add a unique `id` to every error and write it to the log as well, to find one specific occurrence (env: JSON_API_INCLUDE_ID).
      'include_id' => $bool(env('JSON_API_INCLUDE_ID', false)),
    ],
  ];
