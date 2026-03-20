<?php
declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$searchRoot = $pluginRoot;
$wpRoot = null;

for ($level = 0; $level < 8; $level++) {
  $candidate = $searchRoot . '/wp-includes/pomo/translations.php';
  if (file_exists($candidate)) {
    $wpRoot = $searchRoot;
    break;
  }
  $searchRoot = dirname($searchRoot);
}

if ($wpRoot === null) {
  throw new RuntimeException('Unable to locate WordPress POMO classes from plugin root.');
}

$pomoRoot = $wpRoot . '/wp-includes/pomo';

require_once $pomoRoot . '/translations.php';
require_once $pomoRoot . '/po.php';
require_once $pomoRoot . '/mo.php';

if (!function_exists('__')) {
  function __($text, $domain = null) {
    return $text;
  }
}

function sync_now_utc(): string {
  return gmdate('Y-m-d H:i+0000');
}

function sync_now_local(): string {
  return date('Y-m-d H:iO');
}

function sync_load_generated_literals(string $pluginRoot): array {
  $file = $pluginRoot . '/includes/generated/js-i18n-literals.php';
  if (!file_exists($file)) {
    return [];
  }

  $loaded = require $file;
  if (!is_array($loaded)) {
    throw new RuntimeException('Generated JS literals file did not return an array.');
  }

  $literals = array_map('strval', array_keys($loaded));
  $literals = array_values(array_filter($literals, static function (string $literal): bool {
    return trim($literal) !== '';
  }));

  $unique = [];
  foreach ($literals as $literal) {
    $unique[$literal] = true;
  }

  $sorted = array_keys($unique);
  natcasesort($sorted);
  return array_values($sorted);
}

function sync_import_po(string $file): PO {
  $po = new PO();
  if (!$po->import_from_file($file)) {
    throw new RuntimeException("Unable to import catalog: {$file}");
  }
  return $po;
}

function sync_apply_headers(PO $po, string $language): void {
  $headers = $po->headers;
  $headers['Project-Id-Version'] = 'casanova-portal';
  $headers['POT-Creation-Date'] = sync_now_utc();
  $headers['PO-Revision-Date'] = $language === '' ? sync_now_utc() : sync_now_local();
  $headers['Last-Translator'] = $headers['Last-Translator'] ?? '';
  $headers['Language-Team'] = $headers['Language-Team'] ?? '';
  $headers['Language'] = $language;
  $headers['MIME-Version'] = '1.0';
  $headers['Content-Type'] = 'text/plain; charset=UTF-8';
  $headers['Content-Transfer-Encoding'] = '8bit';
  $headers['X-Generator'] = 'casanova-portal catalog sync';
  $headers['X-Domain'] = 'casanova-portal';

  $po->headers = [];
  $po->set_headers($headers);
}

function sync_sort_entries(PO $po): void {
  uksort($po->entries, static function (string $left, string $right): int {
    return strnatcasecmp($left, $right);
  });
}

function sync_catalog(string $file, string $language, array $literals, callable $translateMissing): int {
  $po = sync_import_po($file);
  $known = [];

  foreach ($po->entries as $entry) {
    if ($entry instanceof Translation_Entry && $entry->singular !== '') {
      $known[$entry->singular] = true;
    }
  }

  $added = 0;
  foreach ($literals as $literal) {
    if (isset($known[$literal])) {
      continue;
    }

    $translation = $translateMissing($literal);
    $po->add_entry(new Translation_Entry([
      'singular' => $literal,
      'translations' => ($translation === null || $translation === '') ? [] : [$translation],
    ]));
    $known[$literal] = true;
    $added++;
  }

  sync_apply_headers($po, $language);
  sync_sort_entries($po);

  if (!$po->export_to_file($file)) {
    throw new RuntimeException("Unable to export catalog: {$file}");
  }

  return $added;
}

function sync_compile_mo(string $poFile, string $moFile): void {
  $po = sync_import_po($poFile);
  $mo = new MO();
  $mo->set_headers($po->headers);

  foreach ($po->entries as $entry) {
    if ($entry instanceof Translation_Entry) {
      $mo->add_entry($entry);
    }
  }

  if (!$mo->export_to_file($moFile)) {
    throw new RuntimeException("Unable to compile MO file: {$moFile}");
  }
}

function sync_log(string $message): void {
  fwrite(STDOUT, $message . PHP_EOL);
}

$compile = in_array('--compile', $argv, true);
$literals = sync_load_generated_literals($pluginRoot);

if (!$literals) {
  throw new RuntimeException('No generated JS literals found. Run the JS extractor first.');
}

$languagesDir = $pluginRoot . '/languages';

$potAdded = sync_catalog(
  $languagesDir . '/casanova-portal.pot',
  '',
  $literals,
  static fn(string $literal): string => ''
);

$esAdded = sync_catalog(
  $languagesDir . '/casanova-portal-es_ES.po',
  'es_ES',
  $literals,
  static fn(string $literal): string => $literal
);

$enAdded = sync_catalog(
  $languagesDir . '/casanova-portal-en_US.po',
  'en_US',
  $literals,
  static fn(string $literal): string => ''
);

sync_log("POT: added {$potAdded} entries");
sync_log("es_ES: added {$esAdded} entries");
sync_log("en_US: added {$enAdded} entries");

if ($compile) {
  sync_compile_mo(
    $languagesDir . '/casanova-portal-es_ES.po',
    $languagesDir . '/casanova-portal-es_ES.mo'
  );
  sync_compile_mo(
    $languagesDir . '/casanova-portal-en_US.po',
    $languagesDir . '/casanova-portal-en_US.mo'
  );
  sync_log('MO files compiled');
}
