<?php

  // Textos de los errores (español). La estructura sigue el código base: `resource.not_found` -> ['resource']['not_found'].
  // Cada código tiene `title` (resumen corto) y `detail` (explicación). Los marcadores como :method se reemplazan al usarlos.
  //
  // Error texts (Spanish). The structure follows the base code: `resource.not_found` -> ['resource']['not_found'].
  // Every code has `title` (short summary) and `detail` (explanation). Placeholders such as :method are replaced when used.
  return [
    'request' => [
      'bad_request' => [
        'title' => 'Petición incorrecta',
        'detail' => 'La petición no se pudo entender o está mal formada.',
      ],
      'bad_query' => [
        'title' => 'Parámetro de consulta inválido',
        'detail' => 'Un parámetro de consulta de la petición no es válido.',
        'reasons' => [
          'malformed' => 'Este parámetro de consulta no tiene la forma que la especificación JSON:API define para él.',
          'invalid_name' => 'Este parámetro de consulta tiene un nombre que no es válido: los nombres solo pueden tener letras, dígitos, guiones y guiones bajos, y deben empezar y terminar con una letra o un dígito.',
          'empty_item' => 'Este parámetro de consulta tiene un elemento vacío en su lista separada por comas.',
          'invalid_page' => 'El número y el tamaño de página deben ser enteros positivos.',
          'page_size_too_large' => 'El tamaño de página no puede ser mayor que :max.',
          'include_too_deep' => 'Una ruta de relaciones en include está anidada más profundo que los :max niveles que permite este servidor.',
          'unknown_parameter' => 'Este servidor no soporta este parámetro de consulta.',
        ],
      ],
      'method_not_allowed' => [
        'title' => 'Método no permitido',
        'detail' => 'El método :method no está soportado en esta ruta. Métodos soportados: :allowed.',
      ],
      'not_acceptable' => [
        'title' => 'No aceptable',
        'detail' => 'El servidor no puede responder en el formato solicitado en la cabecera Accept.',
        'reasons' => [
          'malformed' => 'No se pudo leer la cabecera Accept.',
          'not_json_api' => 'La cabecera Accept no permite el tipo JSON:API (application/vnd.api+json).',
          'unsupported_parameter' => 'Todos los tipos JSON:API de la cabecera Accept traen parámetros distintos de ext o profile, que no están soportados.',
          'unsupported_extension' => 'Todos los tipos JSON:API de la cabecera Accept exigen una extensión, y este servidor no soporta ninguna.',
          'refused' => 'La cabecera Accept rechaza el tipo JSON:API.',
        ],
      ],
      'payload_too_large' => [
        'title' => 'Contenido demasiado grande',
        'detail' => 'El cuerpo de la petición supera el tamaño que acepta este servidor.',
      ],
      'unsupported_media_type' => [
        'title' => 'Tipo de contenido no soportado',
        'detail' => 'El Content-Type de la petición no está soportado. Usa application/vnd.api+json.',
        'reasons' => [
          'missing' => 'La petición trae cuerpo pero no la cabecera Content-Type. Usa application/vnd.api+json.',
          'malformed' => 'No se pudo leer la cabecera Content-Type.',
          'not_json_api' => 'El Content-Type debe ser application/vnd.api+json.',
          'unsupported_parameter' => 'El Content-Type trae parámetros distintos de ext o profile, que no están soportados.',
          'unsupported_extension' => 'El Content-Type exige una extensión, y este servidor no soporta ninguna.',
        ],
      ],
      'token_mismatch' => [
        'title' => 'Página expirada',
        'detail' => 'El token de seguridad de la petición falta o ya expiró.',
      ],
      'too_many_requests' => [
        'title' => 'Demasiadas peticiones',
        'detail' => 'Demasiadas peticiones. Inténtalo de nuevo más tarde.',
      ],
    ],
    'auth' => [
      'unauthenticated' => [
        'title' => 'No autenticado',
        'detail' => 'Se requiere autenticación para acceder a este recurso.',
      ],
      'forbidden' => [
        'title' => 'Prohibido',
        'detail' => 'No tienes permiso para realizar esta acción.',
      ],
      'invalid_signature' => [
        'title' => 'Firma inválida',
        'detail' => 'La firma de este enlace es inválida o ya expiró.',
      ],
    ],
    'resource' => [
      'not_found' => [
        'title' => 'Recurso no encontrado',
        'detail' => 'No se encontró el recurso solicitado.',
      ],
      'conflict' => [
        'title' => 'Conflicto',
        'detail' => 'La petición entra en conflicto con el estado actual del recurso.',
      ],
    ],
    'route' => [
      'not_found' => [
        'title' => 'Ruta no encontrada',
        'detail' => 'La ruta solicitada no existe.',
      ],
    ],
    'validation' => [
      'failed' => [
        'title' => 'Falló la validación',
        'detail' => 'Los datos enviados no son válidos.',
      ],
    ],
    'server' => [
      'error' => [
        'title' => 'Error del servidor',
        'detail' => 'Ocurrió un error inesperado. Inténtalo de nuevo más tarde.',
      ],
      'unavailable' => [
        'title' => 'Servicio no disponible',
        'detail' => 'El servicio no está disponible temporalmente. Inténtalo de nuevo más tarde.',
      ],
    ],
    // Texto para cualquier estado HTTP que no tenga uno propio.
    // Text for any HTTP status that has none of its own.
    'http' => [
      'generic' => [
        'title' => 'La petición falló',
        'detail' => 'La petición no se pudo completar.',
      ],
    ],
  ];
