<?php

  // Textos de los errores (inglés). La estructura sigue el código base: `resource.not_found` -> ['resource']['not_found'].
  // Error texts (English). The structure follows the base code: `resource.not_found` -> ['resource']['not_found'].
  //
  // Cada código tiene `title` (resumen corto) y `detail` (explicación). Los marcadores como :method se reemplazan al usarlos.
  // Every code has `title` (short summary) and `detail` (explanation). Placeholders such as :method are replaced when used.
  return [
    'request' => [
      'bad_request' => [
        'title' => 'Bad request',
        'detail' => 'The request could not be understood or is malformed.',
      ],
      'method_not_allowed' => [
        'title' => 'Method not allowed',
        'detail' => 'The :method method is not supported for this route. Supported methods: :allowed.',
      ],
      'not_acceptable' => [
        'title' => 'Not acceptable',
        'detail' => 'The server cannot produce a response in the format requested in the Accept header.',
        'reasons' => [
          'malformed' => 'The Accept header could not be read.',
          'not_json_api' => 'The Accept header does not allow the JSON:API media type (application/vnd.api+json).',
          'unsupported_parameter' => 'Every JSON:API media type in the Accept header has media type parameters other than ext or profile, which are not supported.',
          'unsupported_extension' => 'Every JSON:API media type in the Accept header requires an extension, and this server supports none.',
          'refused' => 'The Accept header refuses the JSON:API media type.',
        ],
      ],
      'payload_too_large' => [
        'title' => 'Payload too large',
        'detail' => 'The request body exceeds the size this server accepts.',
      ],
      'unsupported_media_type' => [
        'title' => 'Unsupported media type',
        'detail' => 'The Content-Type of the request is not supported. Use application/vnd.api+json.',
        'reasons' => [
          'missing' => 'The request has a body but no Content-Type header. Use application/vnd.api+json.',
          'malformed' => 'The Content-Type header could not be read.',
          'not_json_api' => 'The Content-Type must be application/vnd.api+json.',
          'unsupported_parameter' => 'The Content-Type has media type parameters other than ext or profile, which are not supported.',
          'unsupported_extension' => 'The Content-Type requires an extension, and this server supports none.',
        ],
      ],
      'token_mismatch' => [
        'title' => 'Page expired',
        'detail' => 'The security token of the request is missing or has expired.',
      ],
      'too_many_requests' => [
        'title' => 'Too many requests',
        'detail' => 'Too many requests. Please try again later.',
      ],
    ],
    'auth' => [
      'unauthenticated' => [
        'title' => 'Unauthenticated',
        'detail' => 'Authentication is required to access this resource.',
      ],
      'forbidden' => [
        'title' => 'Forbidden',
        'detail' => 'You do not have permission to perform this action.',
      ],
      'invalid_signature' => [
        'title' => 'Invalid signature',
        'detail' => 'The signature of this link is invalid or has expired.',
      ],
    ],
    'resource' => [
      'not_found' => [
        'title' => 'Resource not found',
        'detail' => 'The requested resource could not be found.',
      ],
      'conflict' => [
        'title' => 'Conflict',
        'detail' => 'The request conflicts with the current state of the resource.',
      ],
    ],
    'route' => [
      'not_found' => [
        'title' => 'Route not found',
        'detail' => 'The requested route does not exist.',
      ],
    ],
    'validation' => [
      'failed' => [
        'title' => 'Validation failed',
        'detail' => 'The given data was invalid.',
      ],
    ],
    'server' => [
      'error' => [
        'title' => 'Server error',
        'detail' => 'An unexpected error occurred. Please try again later.',
      ],
      'unavailable' => [
        'title' => 'Service unavailable',
        'detail' => 'The service is temporarily unavailable. Please try again later.',
      ],
    ],
    // Texto para cualquier estado HTTP que no tenga uno propio.
    // Text for any HTTP status that has none of its own.
    'http' => [
      'generic' => [
        'title' => 'Request failed',
        'detail' => 'The request could not be completed.',
      ],
    ],
  ];
