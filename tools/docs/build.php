<?php

  /**
   * Arma las páginas de docs/ a partir del contenido de tools/docs/content/<idioma>/<página>.html: les pone el encabezado, el menú, el índice de
   * la página, los enlaces «anterior / siguiente» y el pie. El contenido de cada tema se escribe a mano; esto solo evita repetir lo común en 26 archivos.
   * Una página sin contenido sale con un aviso de que se está escribiendo. Los estilos los compila Tailwind (bin/build-docs.sh).
   * Builds the docs/ pages from the content in tools/docs/content/<language>/<page>.html: it adds the header, the menu, the page index, the
   * «previous / next» links and the footer. The content of each topic is written by hand; this only avoids repeating what is common in 26 files.
   * A page without content comes out with a notice that it is being written. The styles are compiled by Tailwind (bin/build-docs.sh).
   *
   * Uso / Usage: php tools/docs/build.php
   */

  $root = dirname(__DIR__, 2);
  $docs = $root . '/docs';
  $repo = 'https://github.com/mundoprogra/json-api';
  $config = require __DIR__ . '/pages.php';

  $languages = ['es', 'en'];
  $order = [];
  foreach ($config['groups'] as $group) {
    array_push($order, ...$group['pages']);
  }

  function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  function write(string $path, string $content): void {
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
  }

  $logo = function (int $size) {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true" class="shrink-0">'
      . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#4338ca"/></linearGradient></defs>'
      . '<rect width="32" height="32" rx="8" fill="url(#g)"/>'
      . '<text x="16" y="21.5" text-anchor="middle" font-family="ui-monospace,SFMono-Regular,Menlo,monospace" font-weight="700" font-size="15" fill="#fff">{ }</text>'
      . '</svg>';
  };

  $menu = function (string $lang, string $current) use ($config) {
    $html = '';
    foreach ($config['groups'] as $group) {
      $html .= '<p class="mb-2 mt-6 px-2 text-xs font-semibold uppercase tracking-wider text-slate-500 first:mt-0 dark:text-slate-400">' . e($group['title'][$lang]) . '</p>';
      $html .= '<ul class="space-y-0.5">';
      foreach ($group['pages'] as $slug) {
        $active = $slug === $current;
        $classes = $active
          ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300'
          : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-100';
        $html .= '<li><a href="' . $slug . '.html" class="block rounded-md px-2 py-1.5 text-sm ' . $classes . '"' . ($active ? ' aria-current="page"' : '') . '>'
          . e($config['titles'][$slug][$lang]) . '</a></li>';
      }
      $html .= '</ul>';
    }

    return $html;
  };

  $groupOf = function (string $slug, string $lang) use ($config) {
    foreach ($config['groups'] as $group) {
      if (in_array($slug, $group['pages'], true)) {
        return $group['title'][$lang];
      }
    }

    return '';
  };

  foreach ($languages as $lang) {
    $ui = $config['ui'][$lang];
    $other = $lang === 'es' ? 'en' : 'es';

    foreach ($order as $position => $slug) {
      $title = $config['titles'][$slug][$lang];
      $file = __DIR__ . "/content/$lang/$slug.html";
      $content = is_file($file) ? (string) file_get_contents($file) : '<p>' . e($ui['writing']) . '</p>';

      // Las tablas van en un contenedor que se desplaza, para que no desborden en móvil. / Tables go in a scrolling container so they do not overflow on mobile.
      $content = str_replace(['<table', '</table>'], ['<div class="overflow-x-auto"><table', '</table></div>'], $content);

      // El índice de la página sale de los <h2 id> y <h3 id> del contenido. / The page index comes from the <h2 id> and <h3 id> of the content.
      preg_match_all('~<h([23]) id="([^"]+)"[^>]*>(.*?)</h\1>~s', $content, $headings, PREG_SET_ORDER);
      $toc = '';
      foreach ($headings as $heading) {
        $indent = $heading[1] === '3' ? 'pl-6' : 'pl-3';
        $toc .= '<li><a href="#' . $heading[2] . '" class="-ml-px block border-l border-transparent py-1 ' . $indent
          . ' text-slate-500 hover:border-slate-400 hover:text-slate-900 dark:text-slate-400 dark:hover:border-slate-500 dark:hover:text-slate-100">'
          . trim(strip_tags($heading[3])) . '</a></li>';
      }
      $tocBlock = $toc === '' ? '' : '<aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] overflow-y-auto py-10 pr-4 xl:block"><nav aria-label="' . e($ui['on_this_page']) . '">'
        . '<p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">' . e($ui['on_this_page']) . '</p>'
        . '<ul class="space-y-0.5 border-l border-slate-200 text-sm dark:border-slate-800">' . $toc . '</ul></nav></aside>';

      // «Anterior / siguiente» / «Previous / next»
      $card = function (?string $target, string $label, bool $right) use ($config, $lang) {
        if ($target === null) {
          return '<span></span>';
        }

        return '<a href="' . $target . '.html" class="group rounded-lg border border-slate-200 p-4 transition hover:border-indigo-400 dark:border-slate-800 dark:hover:border-indigo-500' . ($right ? ' text-right' : '') . '">'
          . '<span class="block text-xs text-slate-500 dark:text-slate-400">' . ($right ? e($label) . ' →' : '← ' . e($label)) . '</span>'
          . '<span class="mt-1 block font-semibold text-slate-900 group-hover:text-indigo-600 dark:text-slate-100 dark:group-hover:text-indigo-400">'
          . e($config['titles'][$target][$lang]) . '</span></a>';
      };
      $pager = '<nav class="mt-14 grid gap-4 border-t border-slate-200 pt-8 sm:grid-cols-2 dark:border-slate-800">'
        . $card($order[$position - 1] ?? null, $ui['previous'], false)
        . $card($order[$position + 1] ?? null, $ui['next'], true) . '</nav>';

      $menuHtml = $menu($lang, $slug);
      $mark = $logo(26);
      $groupName = e($groupOf($slug, $lang));
      $titleHtml = e($title);
      $f = $ui['footer'];

      $page = <<<HTML
<!doctype html>
<html lang="{$lang}" class="scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>{$titleHtml} · MundoProgra JSON:API</title>
  <meta name="description" content="{$ui['tagline']}">
  <link rel="stylesheet" href="../assets/style.css">
  <script defer src="../assets/docs.js"></script>
</head>
<body class="min-h-screen bg-white font-sans text-slate-700 antialiased dark:bg-slate-950 dark:text-slate-300">
  <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-white">{$ui['skip']}</a>
  <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85">
    <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4">
      <a href="index.html" class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">{$mark}<span>MundoProgra <span class="text-indigo-600 dark:text-indigo-400">JSON:API</span></span></a>
      <nav class="ml-auto flex items-center gap-2 text-sm">
        <a href="{$repo}" class="hidden rounded-md px-2.5 py-1.5 text-slate-600 hover:bg-slate-100 sm:inline-flex dark:text-slate-300 dark:hover:bg-slate-800">GitHub</a>
        <a href="../{$other}/{$slug}.html" hreflang="{$other}" lang="{$other}" title="{$ui['other_label']}" class="rounded-full border border-slate-300 px-3 py-1 font-medium text-slate-700 hover:border-indigo-400 hover:text-indigo-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-indigo-500 dark:hover:text-indigo-300">{$ui['other_name']}</a>
      </nav>
    </div>
  </header>
  <details class="border-b border-slate-200 bg-slate-50 px-4 lg:hidden dark:border-slate-800 dark:bg-slate-900/60">
    <summary class="cursor-pointer select-none py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{$ui['menu']}</summary>
    <nav class="pb-4" aria-label="{$ui['nav']}">{$menuHtml}</nav>
  </details>
  <div class="mx-auto grid max-w-7xl lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_13rem]">
    <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] overflow-y-auto border-r border-slate-200 p-5 lg:block dark:border-slate-800">
      <nav aria-label="{$ui['nav']}">{$menuHtml}</nav>
    </aside>
    <main id="content" class="min-w-0 px-4 py-10 sm:px-8 lg:px-12">
      <div class="mx-auto max-w-3xl">
        <p class="mb-2 text-sm font-medium text-indigo-600 dark:text-indigo-400">{$groupName}</p>
        <h1 id="top" class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">{$titleHtml}</h1>
        <article class="prose prose-slate mt-8 max-w-none dark:prose-invert prose-headings:scroll-mt-20 prose-headings:font-semibold prose-a:font-medium prose-a:text-indigo-600 prose-a:no-underline hover:prose-a:underline dark:prose-a:text-indigo-400 prose-code:rounded-md prose-code:bg-slate-100 prose-code:px-1.5 prose-code:py-0.5 prose-code:text-[0.875em] prose-code:font-medium prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-slate-800 prose-pre:rounded-xl prose-pre:bg-slate-900 prose-pre:text-slate-100 prose-pre:ring-1 prose-pre:ring-slate-800 prose-th:text-slate-900 dark:prose-th:text-white">
{$content}
        </article>
        {$pager}
      </div>
    </main>
    {$tocBlock}
  </div>
  <footer class="border-t border-slate-200 py-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
    <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}">{$f['code']}</a> ·
    <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}/blob/master/CHANGELOG.md">{$f['changelog']}</a> ·
    <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}/security/policy">{$f['security']}</a> ·
    <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}/blob/master/LICENSE.md">{$f['license']}</a>
  </footer>
</body>
</html>

HTML;

      write("$docs/$lang/$slug.html", $page);
    }
  }

  // La portada: solo ofrece elegir el idioma. / The landing page: it only offers to choose the language.
  $big = $logo(64);
  $cards = '';
  foreach ($languages as $lang) {
    $ui = $config['ui'][$lang];
    $cards .= '<a href="' . $lang . '/index.html" hreflang="' . $lang . '" lang="' . $lang . '" class="group rounded-2xl border border-slate-200 bg-white p-6 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-500">'
      . '<span class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">' . strtoupper($lang) . '</span>'
      . '<span class="mt-1 block text-xl font-semibold text-slate-900 dark:text-white">' . e($ui['choose']) . '</span>'
      . '<span class="mt-1 block text-sm text-slate-500 group-hover:text-indigo-600 dark:text-slate-400 dark:group-hover:text-indigo-400">' . e($config['titles']['installation'][$lang]) . ' · '
      . e($config['titles']['getting-started'][$lang]) . ' · ' . e($config['titles']['errors'][$lang]) . ' →</span></a>';
  }
  $chips = '';
  foreach (['JSON:API 1.1', 'Laravel 10–13', 'PHP 8.1+', 'MIT'] as $chip) {
    $chips .= '<li class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">' . $chip . '</li>';
  }
  $taglineEs = e($config['ui']['es']['tagline']);
  $taglineEn = e($config['ui']['en']['tagline']);

  $landing = <<<HTML
<!doctype html>
<html lang="es" class="scroll-smooth">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>MundoProgra JSON:API</title>
  <meta name="description" content="{$taglineEs} / {$taglineEn}">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-white font-sans text-slate-700 antialiased dark:from-slate-900 dark:via-slate-950 dark:to-slate-950 dark:text-slate-300">
  <main id="content" class="mx-auto flex min-h-screen max-w-3xl flex-col items-center justify-center px-6 py-16 text-center">
    {$big}
    <h1 id="top" class="mt-6 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">MundoProgra <span class="text-indigo-600 dark:text-indigo-400">JSON:API</span></h1>
    <p lang="es" class="mt-4 text-lg">{$taglineEs}</p>
    <p lang="en" class="mt-1 text-lg">{$taglineEn}</p>
    <ul class="mt-6 flex flex-wrap justify-center gap-2">{$chips}</ul>
    <div class="mt-10 grid w-full gap-4 sm:grid-cols-2">{$cards}</div>
    <p class="mt-10 text-sm text-slate-500 dark:text-slate-400">
      <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}">GitHub</a> ·
      <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="https://packagist.org/packages/mundoprogra/json-api">Packagist</a> ·
      <a class="hover:text-indigo-600 dark:hover:text-indigo-400" href="{$repo}/blob/master/CHANGELOG.md">Changelog</a>
    </p>
  </main>
</body>
</html>

HTML;

  write("$docs/index.html", $landing);
  write("$docs/.nojekyll", '');

  echo 'Páginas generadas / Pages generated: ' . (count($order) * count($languages) + 1) . "\n";
