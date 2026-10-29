<?php

  /**
   * Las páginas de la documentación, sus títulos y los textos de la interfaz, en español y en inglés.
   * The documentation pages, their titles and the interface texts, in Spanish and English.
   *
   * El orden de los grupos y de las páginas es el del menú y el de «anterior / siguiente».
   * The order of the groups and the pages is the one of the menu and of «previous / next».
   */
  return [
    'groups' => [
      ['title' => ['es' => 'Empezar', 'en' => 'Start'], 'pages' => ['index', 'installation', 'getting-started']],
      ['title' => ['es' => 'Guía', 'en' => 'Guide'], 'pages' => ['resources', 'relationship-endpoints', 'query-parameters', 'input-document', 'errors', 'activation']],
      ['title' => ['es' => 'Referencia', 'en' => 'Reference'], 'pages' => ['configuration', 'naming-style', 'known-limits']],
      ['title' => ['es' => 'Proyecto', 'en' => 'Project'], 'pages' => ['versioning']],
    ],

    'titles' => [
      'index' => ['es' => 'Inicio', 'en' => 'Home'],
      'installation' => ['es' => 'Instalación', 'en' => 'Installation'],
      'getting-started' => ['es' => 'Primeros pasos', 'en' => 'Getting started'],
      'resources' => ['es' => 'Recursos, colecciones y relaciones', 'en' => 'Resources, collections and relationships'],
      'relationship-endpoints' => ['es' => 'Endpoints de relación', 'en' => 'Relationship endpoints'],
      'query-parameters' => ['es' => 'Parámetros de consulta', 'en' => 'Query parameters'],
      'input-document' => ['es' => 'Leer el documento de entrada', 'en' => 'Reading the input document'],
      'errors' => ['es' => 'Errores', 'en' => 'Errors'],
      'activation' => ['es' => 'Cómo se activa', 'en' => 'How it switches on'],
      'configuration' => ['es' => 'Configuración', 'en' => 'Configuration'],
      'naming-style' => ['es' => 'Estilo de los nombres', 'en' => 'Naming style'],
      'known-limits' => ['es' => 'Límites conocidos', 'en' => 'Known limits'],
      'versioning' => ['es' => 'Versionado y soporte', 'en' => 'Versioning and support'],
    ],

    'ui' => [
      'es' => [
        'skip' => 'Saltar al contenido',
        'menu' => 'Menú',
        'nav' => 'Documentación',
        'on_this_page' => 'En esta página',
        'previous' => 'Anterior',
        'next' => 'Siguiente',
        'other_name' => 'English',
        'other_label' => 'Read this page in English',
        'tagline' => 'JSON:API 1.1 de punta a punta para Laravel 10 a 13.',
        'writing' => 'Esta página se está escribiendo.',
        'footer' => ['code' => 'Código e issues', 'changelog' => 'Changelog', 'security' => 'Seguridad', 'license' => 'Licencia MIT'],
        'choose' => 'Documentación en español',
      ],
      'en' => [
        'skip' => 'Skip to content',
        'menu' => 'Menu',
        'nav' => 'Documentation',
        'on_this_page' => 'On this page',
        'previous' => 'Previous',
        'next' => 'Next',
        'other_name' => 'Español',
        'other_label' => 'Leer esta página en español',
        'tagline' => 'End-to-end JSON:API 1.1 for Laravel 10 to 13.',
        'writing' => 'This page is being written.',
        'footer' => ['code' => 'Code and issues', 'changelog' => 'Changelog', 'security' => 'Security', 'license' => 'MIT license'],
        'choose' => 'Documentation in English',
      ],
    ],
  ];
