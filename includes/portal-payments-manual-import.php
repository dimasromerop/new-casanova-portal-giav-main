<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_manual_payment_import_rows_table')) {
  function casanova_manual_payment_import_rows_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'casanova_manual_payment_import_rows';
  }
}

if (!function_exists('casanova_manual_payment_import_admin_url')) {
  function casanova_manual_payment_import_admin_url(array $args = []): string {
    $base = function_exists('casanova_portal_admin_url')
      ? casanova_portal_admin_url('links')
      : admin_url('admin.php?page=casanova-payments-links');

    return add_query_arg(array_merge(['section' => 'manual-import'], $args), $base);
  }
}

if (!function_exists('casanova_manual_payment_import_transient_key')) {
  function casanova_manual_payment_import_transient_key(string $token): string {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);
    return 'casanova_manual_payment_import_' . (int) get_current_user_id() . '_' . $token;
  }
}

if (!function_exists('casanova_manual_payment_import_create_table')) {
  function casanova_manual_payment_import_create_table(): bool {
    if (!function_exists('casanova_manual_payment_import_rows_table')) return false;

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    if (!function_exists('casanova_manual_payment_import_rows_create_sql')) {
      error_log('[CASANOVA][MANUAL_IMPORT][AUDIT] schema helper missing; cannot create table');
      return false;
    }

    $table = casanova_manual_payment_import_rows_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = casanova_manual_payment_import_rows_create_sql($table, $charset_collate);

    dbDelta($sql);

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
      $wpdb->query($sql);
      if (!empty($wpdb->last_error)) {
        error_log('[CASANOVA][MANUAL_IMPORT][AUDIT] create table failed: ' . $wpdb->last_error);
      }
      $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    return $exists === $table;
  }
}

if (!function_exists('casanova_manual_payment_import_ensure_table')) {
  function casanova_manual_payment_import_ensure_table(): bool {
    if (!function_exists('casanova_manual_payment_import_rows_table')) return false;

    global $wpdb;
    $table = casanova_manual_payment_import_rows_table();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists === $table) return true;

    return casanova_manual_payment_import_create_table();
  }
}

if (!function_exists('casanova_manual_payment_import_normalize_text')) {
  function casanova_manual_payment_import_normalize_text($value): string {
    $value = trim((string) $value);
    if (function_exists('remove_accents')) {
      $value = remove_accents($value);
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string) preg_replace('/\s+/', ' ', (string) $value));
  }
}

if (!function_exists('casanova_manual_payment_import_header_aliases')) {
  function casanova_manual_payment_import_header_aliases(): array {
    static $map = null;
    if ($map !== null) return $map;

    $aliases = [
      'expediente' => ['expediente', 'id expediente', 'idexpediente', 'codigo expediente', 'codigo giav', 'codigo'],
      'id_forma_pago' => ['id forma pago', 'idformapago', 'id forma de pago', 'forma pago id', 'giav forma pago', 'giav id forma pago'],
      'pagador' => ['pagador', 'pagoadr', 'payer', 'cliente', 'nombre pagador'],
      'dni' => ['dni', 'nif', 'nie', 'documento', 'dni nif', 'nif dni', 'passport', 'pasaporte'],
      'fecha' => ['fecha de pago', 'fecha pago', 'fecha cobro', 'fecha', 'payment date'],
      'forma_pago' => ['forma de pago', 'forma pago', 'metodo de pago', 'metodo pago', 'payment method'],
      'banco' => ['banco', 'bank', 'cuenta', 'entidad'],
      'importe' => ['importe', 'importe pago', 'importe cobro', 'pagado', 'amount'],
      'concepto' => ['concepto pago', 'concepto', 'descripcion', 'description'],
      'referencia' => ['referencia', 'referencia bancaria', 'justificante', 'operacion', 'numero operacion', 'documento pago'],
      'estado' => ['estado', 'status'],
      'total_reserva' => ['importe total reserva', 'total reserva', 'total'],
    ];

    $map = [];
    foreach ($aliases as $canonical => $items) {
      foreach ($items as $item) {
        $map[casanova_manual_payment_import_normalize_text($item)] = $canonical;
      }
    }

    return $map;
  }
}

if (!function_exists('casanova_manual_payment_import_canonical_header')) {
  function casanova_manual_payment_import_canonical_header($header): string {
    $key = casanova_manual_payment_import_normalize_text($header);
    $aliases = casanova_manual_payment_import_header_aliases();
    return $aliases[$key] ?? '';
  }
}

if (!function_exists('casanova_manual_payment_import_parse_amount')) {
  function casanova_manual_payment_import_parse_amount($value): float {
    if (is_int($value) || is_float($value)) {
      return round((float) $value, 2);
    }

    $s = trim((string) $value);
    if ($s === '') return 0.0;

    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === ',' || $s === '.') return 0.0;

    $last_comma = strrpos($s, ',');
    $last_dot = strrpos($s, '.');

    if ($last_comma !== false && $last_dot !== false) {
      if ($last_comma > $last_dot) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
      } else {
        $s = str_replace(',', '', $s);
      }
    } elseif ($last_comma !== false) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }

    return round((float) $s, 2);
  }
}

if (!function_exists('casanova_manual_payment_import_parse_date')) {
  function casanova_manual_payment_import_parse_date($value): string {
    if (is_int($value) || is_float($value)) {
      $serial = (float) $value;
    } else {
      $raw = trim((string) $value);
      if ($raw === '') return '';

      if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
        return $m[0];
      }

      if (preg_match('/^\d+(?:[.,]\d+)?$/', $raw)) {
        $serial = (float) str_replace(',', '.', $raw);
      } else {
        $formats = ['!d/m/Y', '!d-m-Y', '!Y/m/d', '!Y-m-d', '!d/m/y', '!d-m-y'];
        foreach ($formats as $format) {
          $dt = DateTimeImmutable::createFromFormat($format, $raw, wp_timezone());
          if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
          }
        }

        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
      }
    }

    if ($serial < 1000) return '';

    $unix = (int) round(($serial - 25569) * 86400);
    return gmdate('Y-m-d', $unix);
  }
}

if (!function_exists('casanova_manual_payment_import_xlsx_col_index')) {
  function casanova_manual_payment_import_xlsx_col_index(string $cell_ref): int {
    if (!preg_match('/^([A-Z]+)/i', $cell_ref, $m)) return 0;
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
      $n = ($n * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
  }
}

if (!function_exists('casanova_manual_payment_import_zip_open')) {
  function casanova_manual_payment_import_zip_open(string $path) {
    if (class_exists('ZipArchive')) {
      $zip = new ZipArchive();
      if ($zip->open($path) !== true) {
        return new WP_Error('xlsx_open', 'No se pudo abrir el archivo XLSX.');
      }
      return [
        'type' => 'ziparchive',
        'zip' => $zip,
      ];
    }

    if (!class_exists('PclZip') && defined('ABSPATH')) {
      $pclzip_path = trailingslashit(ABSPATH) . 'wp-admin/includes/class-pclzip.php';
      if (file_exists($pclzip_path)) {
        require_once $pclzip_path;
      }
    }

    if (class_exists('PclZip')) {
      $zip = new PclZip($path);
      $list = $zip->listContent();
      if (!is_array($list)) {
        return new WP_Error('xlsx_open', 'No se pudo abrir el archivo XLSX.');
      }
      $names = [];
      foreach ($list as $item) {
        if (!empty($item['filename'])) {
          $names[(string) $item['filename']] = true;
        }
      }
      return [
        'type' => 'pclzip',
        'zip' => $zip,
        'names' => $names,
      ];
    }

    return new WP_Error('zip_missing', 'El servidor no tiene soporte ZIP para leer XLSX.');
  }
}

if (!function_exists('casanova_manual_payment_import_zip_get')) {
  function casanova_manual_payment_import_zip_get(array $archive, string $name): string {
    if (($archive['type'] ?? '') === 'ziparchive' && isset($archive['zip']) && $archive['zip'] instanceof ZipArchive) {
      $content = $archive['zip']->getFromName($name);
      return is_string($content) ? $content : '';
    }

    if (($archive['type'] ?? '') === 'pclzip' && isset($archive['zip']) && $archive['zip'] instanceof PclZip) {
      $items = $archive['zip']->extract(PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING);
      if (is_array($items) && isset($items[0]['content']) && is_string($items[0]['content'])) {
        return $items[0]['content'];
      }
    }

    return '';
  }
}

if (!function_exists('casanova_manual_payment_import_zip_has')) {
  function casanova_manual_payment_import_zip_has(array $archive, string $name): bool {
    if (($archive['type'] ?? '') === 'ziparchive' && isset($archive['zip']) && $archive['zip'] instanceof ZipArchive) {
      return $archive['zip']->locateName($name) !== false;
    }

    if (($archive['type'] ?? '') === 'pclzip') {
      $names = is_array($archive['names'] ?? null) ? $archive['names'] : [];
      return isset($names[$name]);
    }

    return false;
  }
}

if (!function_exists('casanova_manual_payment_import_zip_close')) {
  function casanova_manual_payment_import_zip_close(array $archive): void {
    if (($archive['type'] ?? '') === 'ziparchive' && isset($archive['zip']) && $archive['zip'] instanceof ZipArchive) {
      $archive['zip']->close();
    }
  }
}

if (!function_exists('casanova_manual_payment_import_first_sheet_path')) {
  function casanova_manual_payment_import_first_sheet_path(array $archive): string {
    $workbook_xml = casanova_manual_payment_import_zip_get($archive, 'xl/workbook.xml');
    $rels_xml = casanova_manual_payment_import_zip_get($archive, 'xl/_rels/workbook.xml.rels');

    if ($workbook_xml && $rels_xml) {
      $workbook = simplexml_load_string($workbook_xml, 'SimpleXMLElement', LIBXML_NONET);
      $rels = simplexml_load_string($rels_xml, 'SimpleXMLElement', LIBXML_NONET);
      if ($workbook && $rels && isset($workbook->sheets->sheet[0])) {
        $sheet = $workbook->sheets->sheet[0];
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($attrs['id'] ?? '');
        if ($rid !== '') {
          foreach ($rels->Relationship as $rel) {
            $a = $rel->attributes();
            if ((string) ($a['Id'] ?? '') !== $rid) continue;
            $target = (string) ($a['Target'] ?? '');
            if ($target !== '') {
              $target = ltrim($target, '/');
              if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
              }
              if (casanova_manual_payment_import_zip_has($archive, $target)) {
                return $target;
              }
            }
          }
        }
      }
    }

    return 'xl/worksheets/sheet1.xml';
  }
}

if (!function_exists('casanova_manual_payment_import_shared_strings')) {
  function casanova_manual_payment_import_shared_strings(array $archive): array {
    $xml = casanova_manual_payment_import_zip_get($archive, 'xl/sharedStrings.xml');
    if (!$xml) return [];

    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$sx) return [];

    $strings = [];
    foreach ($sx->si as $si) {
      if (isset($si->t)) {
        $strings[] = (string) $si->t;
        continue;
      }

      $text = '';
      if (isset($si->r)) {
        foreach ($si->r as $r) {
          $text .= (string) ($r->t ?? '');
        }
      }
      $strings[] = $text;
    }

    return $strings;
  }
}

if (!function_exists('casanova_manual_payment_import_xlsx_cell_value')) {
  function casanova_manual_payment_import_xlsx_cell_value(SimpleXMLElement $cell, array $shared_strings): string {
    $attrs = $cell->attributes();
    $type = (string) ($attrs['t'] ?? '');

    if ($type === 's') {
      $idx = (int) ($cell->v ?? -1);
      return isset($shared_strings[$idx]) ? (string) $shared_strings[$idx] : '';
    }

    if ($type === 'inlineStr') {
      if (isset($cell->is->t)) return (string) $cell->is->t;
      $text = '';
      if (isset($cell->is->r)) {
        foreach ($cell->is->r as $r) {
          $text .= (string) ($r->t ?? '');
        }
      }
      return $text;
    }

    if (isset($cell->v)) {
      return (string) $cell->v;
    }

    return '';
  }
}

if (!function_exists('casanova_manual_payment_import_parse_xlsx')) {
  function casanova_manual_payment_import_parse_xlsx(string $path) {
    $archive = casanova_manual_payment_import_zip_open($path);
    if (is_wp_error($archive)) return $archive;

    $sheet_path = casanova_manual_payment_import_first_sheet_path($archive);
    $sheet_xml = casanova_manual_payment_import_zip_get($archive, $sheet_path);
    if (!$sheet_xml) {
      casanova_manual_payment_import_zip_close($archive);
      return new WP_Error('xlsx_sheet', 'No se encontro la primera hoja del XLSX.');
    }

    $shared_strings = casanova_manual_payment_import_shared_strings($archive);
    $sheet = simplexml_load_string($sheet_xml, 'SimpleXMLElement', LIBXML_NONET);
    casanova_manual_payment_import_zip_close($archive);

    if (!$sheet || !isset($sheet->sheetData->row)) {
      return new WP_Error('xlsx_parse', 'No se pudo leer el contenido del XLSX.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
      $cells = [];
      $max = 0;
      foreach ($row->c as $cell) {
        $attrs = $cell->attributes();
        $ref = (string) ($attrs['r'] ?? '');
        $idx = casanova_manual_payment_import_xlsx_col_index($ref);
        $cells[$idx] = casanova_manual_payment_import_xlsx_cell_value($cell, $shared_strings);
        if ($idx > $max) $max = $idx;
      }

      $out = [];
      for ($i = 0; $i <= $max; $i++) {
        $out[] = $cells[$i] ?? '';
      }
      $rows[] = $out;
    }

    return $rows;
  }
}

if (!function_exists('casanova_manual_payment_import_parse_csv')) {
  function casanova_manual_payment_import_parse_csv(string $path) {
    $contents = file_get_contents($path);
    if ($contents === false) {
      return new WP_Error('csv_open', 'No se pudo abrir el CSV.');
    }

    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
    $lines = preg_split('/\r\n|\n|\r/', (string) $contents);
    $first = '';
    foreach ($lines as $line) {
      if (trim((string) $line) !== '') {
        $first = (string) $line;
        break;
      }
    }

    $delimiter = ',';
    $best_count = 0;
    foreach ([',', ';', "\t"] as $candidate) {
      $count = count(str_getcsv($first, $candidate));
      if ($count > $best_count) {
        $best_count = $count;
        $delimiter = $candidate;
      }
    }

    $rows = [];
    foreach ($lines as $line) {
      if (trim((string) $line) === '') continue;
      $rows[] = str_getcsv((string) $line, $delimiter);
    }

    return $rows;
  }
}

if (!function_exists('casanova_manual_payment_import_parse_file')) {
  function casanova_manual_payment_import_parse_file(string $path, string $filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
      return casanova_manual_payment_import_parse_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
      return casanova_manual_payment_import_parse_csv($path);
    }
    return new WP_Error('unsupported_file', 'Formato no soportado. Usa .xlsx o .csv.');
  }
}

if (!function_exists('casanova_manual_payment_import_find_header')) {
  function casanova_manual_payment_import_find_header(array $rows) {
    foreach ($rows as $idx => $row) {
      $matches = 0;
      foreach ($row as $cell) {
        if (casanova_manual_payment_import_canonical_header($cell) !== '') {
          $matches++;
        }
      }
      if ($matches >= 2) {
        return (int) $idx;
      }
    }
    return new WP_Error('header_not_found', 'No se encontro una fila de cabeceras reconocible.');
  }
}

if (!function_exists('casanova_manual_payment_import_resolve_expediente')) {
  function casanova_manual_payment_import_resolve_expediente(string $ref) {
    static $cache = [];

    $ref = trim($ref);
    if ($ref === '') {
      return new WP_Error('missing_expediente', 'Falta expediente.');
    }

    if (isset($cache[$ref])) return $cache[$ref];

    if (function_exists('casanova_payment_links_resolve_expediente_reference')) {
      $resolved = casanova_payment_links_resolve_expediente_reference($ref);
      if (is_wp_error($resolved)) {
        $cache[$ref] = $resolved;
        return $resolved;
      }

      $exp = $resolved['expediente'] ?? null;
      $id_cliente = is_object($exp) ? (int) ($exp->IdCliente ?? 0) : 0;
      $cache[$ref] = [
        'id' => (int) ($resolved['id'] ?? 0),
        'codigo' => (string) ($resolved['codigo'] ?? ''),
        'titulo' => (string) ($resolved['titulo'] ?? ''),
        'id_cliente' => $id_cliente,
        'source' => (string) ($resolved['source'] ?? ''),
      ];
      return $cache[$ref];
    }

    if (preg_match('/^\d+$/', $ref) && function_exists('casanova_giav_expediente_get')) {
      $exp = casanova_giav_expediente_get((int) $ref);
      if (is_wp_error($exp)) {
        $cache[$ref] = $exp;
        return $exp;
      }
      if (is_object($exp)) {
        $cache[$ref] = [
          'id' => (int) ($exp->IdExpediente ?? $exp->Id ?? 0),
          'codigo' => (string) ($exp->Codigo ?? ''),
          'titulo' => (string) ($exp->Titulo ?? ''),
          'id_cliente' => (int) ($exp->IdCliente ?? 0),
          'source' => 'id',
        ];
        return $cache[$ref];
      }
    }

    $cache[$ref] = new WP_Error('expediente_not_found', 'No se encontro el expediente.');
    return $cache[$ref];
  }
}

if (!function_exists('casanova_manual_payment_import_row_hash')) {
  function casanova_manual_payment_import_row_hash(array $row): string {
    $parts = [
      'v1',
      (string) ($row['id_expediente'] ?? 0),
      (string) ($row['fecha'] ?? ''),
      number_format((float) ($row['importe'] ?? 0), 2, '.', ''),
      casanova_manual_payment_import_normalize_text($row['pagador'] ?? ''),
      strtoupper(preg_replace('/\s+/', '', (string) ($row['dni'] ?? ''))),
      casanova_manual_payment_import_normalize_text($row['concepto'] ?? ''),
      casanova_manual_payment_import_normalize_text($row['referencia'] ?? ''),
      (string) ($row['id_forma_pago'] ?? 0),
    ];
    return hash('sha256', implode('|', $parts));
  }
}

if (!function_exists('casanova_manual_payment_import_existing_local_status')) {
  function casanova_manual_payment_import_existing_local_status(string $row_hash): string {
    if ($row_hash === '' || !function_exists('casanova_manual_payment_import_rows_table')) return '';
    if (!casanova_manual_payment_import_ensure_table()) return '';

    global $wpdb;
    $table = casanova_manual_payment_import_rows_table();
    $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE row_hash = %s LIMIT 1", $row_hash));
    return is_string($status) ? $status : '';
  }
}

if (!function_exists('casanova_manual_payment_import_find_giav_duplicate')) {
  function casanova_manual_payment_import_find_giav_duplicate(array $row): string {
    static $cache = [];

    $id_expediente = (int) ($row['id_expediente'] ?? 0);
    $fecha = (string) ($row['fecha'] ?? '');
    $importe = (float) ($row['importe'] ?? 0);
    if ($id_expediente <= 0 || $fecha === '' || $importe <= 0 || !function_exists('casanova_giav_cobros_por_expediente_all')) {
      return '';
    }

    if (!array_key_exists($id_expediente, $cache)) {
      if (function_exists('casanova_giav_portal_cobros_context')) {
        $context = casanova_giav_portal_cobros_context($id_expediente, (int) ($row['id_cliente'] ?? 0));
        $items = is_wp_error($context) ? [] : (array) ($context['items'] ?? []);
      } else {
        $items = casanova_giav_cobros_por_expediente_all($id_expediente, (int) ($row['id_cliente'] ?? 0));
        $items = is_wp_error($items) ? [] : (array) $items;
      }
      $cache[$id_expediente] = $items;
    }

    $payer = casanova_manual_payment_import_normalize_text($row['pagador'] ?? '');
    $concept = casanova_manual_payment_import_normalize_text($row['concepto'] ?? '');
    $reference = casanova_manual_payment_import_normalize_text($row['referencia'] ?? '');

    foreach ($cache[$id_expediente] as $c) {
      if (!is_object($c)) continue;
      $c_fecha = !empty($c->FechaCobro) ? substr((string) $c->FechaCobro, 0, 10) : '';
      $c_importe = abs((float) ($c->Importe ?? 0));
      if ($c_fecha !== $fecha || abs($c_importe - $importe) > 0.01) {
        continue;
      }

      $c_doc = casanova_manual_payment_import_normalize_text($c->Documento ?? '');
      $c_payer = casanova_manual_payment_import_normalize_text($c->Pagador ?? '');
      $c_concept = casanova_manual_payment_import_normalize_text($c->Concepto ?? '');
      $c_id = (int) ($c->Id ?? $c->IdCobro ?? 0);

      if ($reference !== '' && $c_doc !== '' && strpos($c_doc, $reference) !== false) {
        return 'Posible duplicado GIAV #' . ($c_id > 0 ? (string) $c_id : '?') . ' por referencia, fecha e importe.';
      }

      if ($payer !== '' && $c_payer !== '' && $payer === $c_payer) {
        if ($concept === '' || $c_concept === '' || $concept === $c_concept) {
          return 'Posible duplicado GIAV #' . ($c_id > 0 ? (string) $c_id : '?') . ' por pagador, fecha e importe.';
        }
      }
    }

    return '';
  }
}

if (!function_exists('casanova_manual_payment_import_build_preview')) {
  function casanova_manual_payment_import_build_preview(array $raw_rows, array $context) {
    $header_idx = casanova_manual_payment_import_find_header($raw_rows);
    if (is_wp_error($header_idx)) return $header_idx;

    $headers = $raw_rows[$header_idx] ?? [];
    $columns = [];
    foreach ($headers as $idx => $header) {
      $canonical = casanova_manual_payment_import_canonical_header($header);
      if ($canonical !== '' && !isset($columns[$canonical])) {
        $columns[$canonical] = (int) $idx;
      }
    }

    if (!isset($columns['pagador'], $columns['fecha'], $columns['importe'])) {
      return new WP_Error('missing_required_columns', 'Faltan columnas minimas: Pagador, Fecha de pago e Importe.');
    }

    $default_expediente = trim((string) ($context['default_expediente'] ?? ''));
    $default_id_forma_pago = (int) ($context['id_forma_pago'] ?? 0);
    $default_id_oficina = (int) ($context['id_oficina'] ?? 0);
    $source_filename = (string) ($context['source_filename'] ?? '');
    $rows = [];
    $seen_hashes = [];
    $summary = [
      'total' => 0,
      'importable' => 0,
      'invalid' => 0,
      'duplicates' => 0,
      'not_received' => 0,
    ];

    for ($i = $header_idx + 1; $i < count($raw_rows); $i++) {
      $raw_row = $raw_rows[$i];
      $assoc = [];
      foreach ($columns as $key => $idx) {
        $assoc[$key] = isset($raw_row[$idx]) ? trim((string) $raw_row[$idx]) : '';
      }

      $joined = trim(implode('', array_map('trim', $assoc)));
      if ($joined === '') continue;

      $summary['total']++;

      $exp_ref = trim((string) ($assoc['expediente'] ?? ''));
      if ($exp_ref === '') $exp_ref = $default_expediente;

      $id_forma_pago = (int) casanova_manual_payment_import_parse_amount($assoc['id_forma_pago'] ?? 0);
      if ($id_forma_pago <= 0) $id_forma_pago = $default_id_forma_pago;

      $fecha = casanova_manual_payment_import_parse_date($assoc['fecha'] ?? '');
      $importe = casanova_manual_payment_import_parse_amount($assoc['importe'] ?? '');
      $concepto = trim((string) ($assoc['concepto'] ?? ''));
      $estado = trim((string) ($assoc['estado'] ?? ''));
      $not_received = (
        strpos(casanova_manual_payment_import_normalize_text($concepto), 'no recibido') !== false
        || strpos(casanova_manual_payment_import_normalize_text($estado), 'no recibido') !== false
      );

      $row = [
        'preview_id' => count($rows),
        'row_number' => $i + 1,
        'source_filename' => $source_filename,
        'expediente_ref' => $exp_ref,
        'id_expediente' => 0,
        'codigo_expediente' => '',
        'titulo_expediente' => '',
        'id_cliente' => 0,
        'id_forma_pago' => $id_forma_pago,
        'id_oficina' => $default_id_oficina,
        'pagador' => trim((string) ($assoc['pagador'] ?? '')),
        'dni' => strtoupper(preg_replace('/\s+/', '', (string) ($assoc['dni'] ?? ''))),
        'fecha' => $fecha,
        'forma_pago' => trim((string) ($assoc['forma_pago'] ?? '')),
        'banco' => trim((string) ($assoc['banco'] ?? '')),
        'importe' => $importe,
        'concepto' => $concepto,
        'referencia' => trim((string) ($assoc['referencia'] ?? '')),
        'estado' => $estado,
        'not_received' => $not_received,
        'importable' => true,
        'status' => 'ok',
        'message' => '',
        'row_hash' => '',
        'raw' => $assoc,
      ];

      $errors = [];
      if ($exp_ref === '') {
        $errors[] = 'Falta expediente.';
      } else {
        $resolved = casanova_manual_payment_import_resolve_expediente($exp_ref);
        if (is_wp_error($resolved)) {
          $errors[] = $resolved->get_error_message();
        } else {
          $row['id_expediente'] = (int) ($resolved['id'] ?? 0);
          $row['codigo_expediente'] = (string) ($resolved['codigo'] ?? '');
          $row['titulo_expediente'] = (string) ($resolved['titulo'] ?? '');
          $row['id_cliente'] = (int) ($resolved['id_cliente'] ?? 0);
          if ($row['id_cliente'] <= 0) {
            $errors[] = 'No se pudo resolver el titular del expediente.';
          }
        }
      }

      if ($row['id_forma_pago'] <= 0) $errors[] = 'Falta ID de forma de pago GIAV.';
      if ($row['fecha'] === '') $errors[] = 'Fecha invalida o vacia.';
      if ($row['importe'] <= 0) $errors[] = 'Importe invalido o vacio.';
      if ($row['not_received']) $errors[] = 'Fila marcada como NO recibido.';

      $row['row_hash'] = casanova_manual_payment_import_row_hash($row);

      if (isset($seen_hashes[$row['row_hash']])) {
        $errors[] = 'Duplicado dentro del archivo.';
      }
      $seen_hashes[$row['row_hash']] = true;

      if (empty($errors)) {
        $local_status = casanova_manual_payment_import_existing_local_status($row['row_hash']);
        if ($local_status === 'registered') {
          $errors[] = 'Ya fue importado anteriormente.';
        }
      }

      if (empty($errors)) {
        $giav_duplicate = casanova_manual_payment_import_find_giav_duplicate($row);
        if ($giav_duplicate !== '') {
          $errors[] = $giav_duplicate;
        }
      }

      if (!empty($errors)) {
        $row['importable'] = false;
        $row['message'] = implode(' ', $errors);
        $row['status'] = $row['not_received'] ? 'not_received' : 'invalid';
        if (strpos($row['message'], 'duplic') !== false || strpos($row['message'], 'importado') !== false) {
          $row['status'] = 'duplicate';
        }
      }

      if ($row['status'] === 'ok') $summary['importable']++;
      elseif ($row['status'] === 'duplicate') $summary['duplicates']++;
      elseif ($row['status'] === 'not_received') $summary['not_received']++;
      else $summary['invalid']++;

      $rows[] = $row;
    }

    if (empty($rows)) {
      return new WP_Error('no_rows', 'El archivo no contiene filas de pago.');
    }

    return [
      'created_at' => current_time('mysql'),
      'source_filename' => $source_filename,
      'default_expediente' => $default_expediente,
      'id_forma_pago' => $default_id_forma_pago,
      'id_oficina' => $default_id_oficina,
      'header_row' => $header_idx + 1,
      'columns' => $columns,
      'summary' => $summary,
      'rows' => $rows,
    ];
  }
}

if (!function_exists('casanova_manual_payment_import_save_log')) {
  function casanova_manual_payment_import_save_log(array $row, string $batch_token, string $status, int $giav_cobro_id = 0, string $error = ''): void {
    if (!function_exists('casanova_manual_payment_import_rows_table')) return;
    if (!casanova_manual_payment_import_ensure_table()) {
      error_log('[CASANOVA][MANUAL_IMPORT][AUDIT] audit table missing and could not be created');
      return;
    }

    global $wpdb;
    $table = casanova_manual_payment_import_rows_table();
    $row_hash = (string) ($row['row_hash'] ?? '');
    if ($row_hash === '') return;

    $data = [
      'batch_token' => substr($batch_token, 0, 64),
      'source_filename' => substr((string) ($row['source_filename'] ?? ''), 0, 255),
      'row_number' => (int) ($row['row_number'] ?? 0),
      'row_hash' => $row_hash,
      'id_expediente' => (int) ($row['id_expediente'] ?? 0),
      'id_cliente' => (int) ($row['id_cliente'] ?? 0),
      'id_forma_pago' => (int) ($row['id_forma_pago'] ?? 0),
      'payment_date' => ((string) ($row['fecha'] ?? '') !== '') ? (string) $row['fecha'] : null,
      'amount' => (float) ($row['importe'] ?? 0),
      'payer_name' => substr((string) ($row['pagador'] ?? ''), 0, 190),
      'payer_dni' => substr((string) ($row['dni'] ?? ''), 0, 32),
      'method_label' => substr((string) ($row['forma_pago'] ?? ''), 0, 96),
      'bank_label' => substr((string) ($row['banco'] ?? ''), 0, 96),
      'concept' => substr((string) ($row['concepto'] ?? ''), 0, 255),
      'reference' => substr((string) ($row['referencia'] ?? ''), 0, 190),
      'status' => substr($status, 0, 32),
      'giav_cobro_id' => $giav_cobro_id > 0 ? $giav_cobro_id : null,
      'error_message' => $error !== '' ? $error : null,
      'payload' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'created_by' => (int) get_current_user_id(),
      'updated_at' => current_time('mysql'),
    ];

    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE row_hash = %s LIMIT 1", $row_hash));
    if ($existing_id > 0) {
      unset($data['row_hash'], $data['created_by']);
      $ok = $wpdb->update($table, $data, ['id' => $existing_id]);
      if ($ok === false && !empty($wpdb->last_error)) {
        error_log('[CASANOVA][MANUAL_IMPORT][AUDIT] update failed: ' . $wpdb->last_error);
      }
      return;
    }

    $data['created_at'] = current_time('mysql');
    $ok = $wpdb->insert($table, $data);
    if ($ok === false && !empty($wpdb->last_error)) {
      error_log('[CASANOVA][MANUAL_IMPORT][AUDIT] insert failed: ' . $wpdb->last_error);
    }
  }
}

add_action('admin_post_casanova_manual_payment_import_preview', function (): void {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }
  check_admin_referer('casanova_manual_payment_import_preview');

  if (empty($_FILES['payments_file']['tmp_name']) || !is_uploaded_file($_FILES['payments_file']['tmp_name'])) {
    wp_safe_redirect(casanova_manual_payment_import_admin_url(['manual_import_error' => 'upload']));
    exit;
  }

  $filename = sanitize_file_name((string) ($_FILES['payments_file']['name'] ?? 'pagos.xlsx'));
  $raw_rows = casanova_manual_payment_import_parse_file((string) $_FILES['payments_file']['tmp_name'], $filename);
  if (is_wp_error($raw_rows)) {
    wp_safe_redirect(casanova_manual_payment_import_admin_url([
      'manual_import_error' => $raw_rows->get_error_code(),
    ]));
    exit;
  }

  $preview = casanova_manual_payment_import_build_preview((array) $raw_rows, [
    'source_filename' => $filename,
    'default_expediente' => sanitize_text_field((string) ($_POST['default_expediente'] ?? '')),
    'id_forma_pago' => absint($_POST['id_forma_pago'] ?? 0),
    'id_oficina' => absint($_POST['id_oficina'] ?? 0),
  ]);

  if (is_wp_error($preview)) {
    wp_safe_redirect(casanova_manual_payment_import_admin_url([
      'manual_import_error' => $preview->get_error_code(),
    ]));
    exit;
  }

  $token = wp_generate_password(24, false, false);
  set_transient(casanova_manual_payment_import_transient_key($token), $preview, 30 * MINUTE_IN_SECONDS);

  wp_safe_redirect(casanova_manual_payment_import_admin_url(['import_token' => $token]));
  exit;
});

add_action('admin_post_casanova_manual_payment_import_confirm', function (): void {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  $token = isset($_POST['import_token']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $_POST['import_token']) : '';
  if ($token === '') {
    wp_safe_redirect(casanova_manual_payment_import_admin_url(['manual_import_error' => 'expired']));
    exit;
  }

  check_admin_referer('casanova_manual_payment_import_confirm_' . $token);

  $preview = get_transient(casanova_manual_payment_import_transient_key($token));
  if (!is_array($preview)) {
    wp_safe_redirect(casanova_manual_payment_import_admin_url(['manual_import_error' => 'expired']));
    exit;
  }

  $selected = [];
  if (!empty($_POST['row_ids']) && is_array($_POST['row_ids'])) {
    foreach ($_POST['row_ids'] as $id) {
      $selected[(int) $id] = true;
    }
  }

  $registered = 0;
  $failed = 0;
  $skipped = 0;
  $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];

  foreach ($rows as $row) {
    $preview_id = (int) ($row['preview_id'] ?? -1);
    if (!isset($selected[$preview_id])) {
      continue;
    }

    if (empty($row['importable'])) {
      $skipped++;
      casanova_manual_payment_import_save_log($row, $token, 'skipped', 0, (string) ($row['message'] ?? 'Fila no importable.'));
      continue;
    }

    if (casanova_manual_payment_import_existing_local_status((string) ($row['row_hash'] ?? '')) === 'registered') {
      $skipped++;
      casanova_manual_payment_import_save_log($row, $token, 'skipped', 0, 'Ya fue importado anteriormente.');
      continue;
    }

    casanova_manual_payment_import_save_log($row, $token, 'processing', 0, '');

    if (!function_exists('casanova_payments_record_cobro')) {
      $failed++;
      casanova_manual_payment_import_save_log($row, $token, 'failed', 0, 'No esta disponible el helper Cobro_POST.');
      continue;
    }

    $documento = trim((string) ($row['referencia'] ?? ''));
    if ($documento === '') {
      $doc_parts = array_filter([
        'Importacion manual',
        (string) ($row['banco'] ?? ''),
        (string) ($row['fecha'] ?? ''),
      ]);
      $documento = implode(' - ', $doc_parts);
    }

    $notas = 'Importacion manual de cobro.';
    $notas .= ' Archivo: ' . (string) ($row['source_filename'] ?? '');
    $notas .= ' Fila: ' . (string) ($row['row_number'] ?? '');
    if (!empty($row['banco'])) $notas .= ' Banco: ' . (string) $row['banco'] . '.';
    if (!empty($row['forma_pago'])) $notas .= ' Forma: ' . (string) $row['forma_pago'] . '.';

    $intent = (object) [
      'id' => 0,
      'id_expediente' => (int) ($row['id_expediente'] ?? 0),
      'id_cliente' => (int) ($row['id_cliente'] ?? 0),
      'amount' => (float) ($row['importe'] ?? 0),
      'currency' => 'EUR',
      'payload' => null,
    ];

    $result = casanova_payments_record_cobro($intent, [
      'billing_dni' => (string) ($row['dni'] ?? ''),
      'id_forma_pago' => (int) ($row['id_forma_pago'] ?? 0),
      'id_oficina' => (int) ($row['id_oficina'] ?? 0),
      'concepto' => (string) ($row['concepto'] ?? ''),
      'documento' => $documento,
      'payer_name' => (string) (($row['pagador'] ?? '') ?: 'Importacion manual'),
      'fecha_cobro' => (string) ($row['fecha'] ?? ''),
      'notas_internas' => $notas,
      'create_payer_if_missing' => false,
    ], 'MANUAL_IMPORT');

    $giav_cobro = is_array($result['giav_cobro'] ?? null) ? $result['giav_cobro'] : [];
    $cobro_id = (int) ($giav_cobro['cobro_id'] ?? 0);
    if (!empty($result['inserted']) && $cobro_id > 0) {
      $registered++;
      casanova_manual_payment_import_save_log($row, $token, 'registered', $cobro_id, '');
      continue;
    }

    $failed++;
    $error = (string) ($giav_cobro['error'] ?? 'GIAV no confirmo el cobro.');
    casanova_manual_payment_import_save_log($row, $token, 'failed', 0, $error);
  }

  delete_transient(casanova_manual_payment_import_transient_key($token));
  if ($registered > 0 && function_exists('casanova_cache_buster_bump')) {
    casanova_cache_buster_bump();
  }

  wp_safe_redirect(casanova_manual_payment_import_admin_url([
    'manual_import_done' => '1',
    'registered' => $registered,
    'failed' => $failed,
    'skipped' => $skipped,
  ]));
  exit;
});

if (!function_exists('casanova_manual_payment_import_error_message')) {
  function casanova_manual_payment_import_error_message(string $code): string {
    $map = [
      'upload' => 'No se pudo recibir el archivo.',
      'zip_missing' => 'El servidor no tiene ZipArchive; sube un CSV o activa la extension zip de PHP.',
      'xlsx_open' => 'No se pudo abrir el XLSX.',
      'xlsx_sheet' => 'No se encontro la hoja del XLSX.',
      'xlsx_parse' => 'No se pudo leer el XLSX.',
      'csv_open' => 'No se pudo abrir el CSV.',
      'unsupported_file' => 'Formato no soportado. Usa .xlsx o .csv.',
      'header_not_found' => 'No se encontro una fila de cabeceras reconocible.',
      'missing_required_columns' => 'Faltan columnas minimas: Pagador, Fecha de pago e Importe.',
      'no_rows' => 'El archivo no contiene filas de pago.',
      'expired' => 'La previsualizacion ha caducado. Vuelve a subir el archivo.',
    ];
    return $map[$code] ?? 'No se pudo preparar la importacion.';
  }
}

if (!function_exists('casanova_manual_payment_import_status_label')) {
  function casanova_manual_payment_import_status_label(string $status): string {
    return [
      'ok' => 'Lista',
      'invalid' => 'Revisar',
      'duplicate' => 'Duplicado',
      'not_received' => 'No recibido',
      'processing' => 'Procesando',
      'registered' => 'Registrado',
      'failed' => 'Error',
      'skipped' => 'Omitido',
    ][$status] ?? $status;
  }
}

if (!function_exists('casanova_manual_payments_render_admin_page')) {
  function casanova_manual_payments_render_admin_page(): void {
    $error = isset($_GET['manual_import_error']) ? sanitize_key((string) $_GET['manual_import_error']) : '';
    $done = isset($_GET['manual_import_done']) && $_GET['manual_import_done'] === '1';
    $token = isset($_GET['import_token']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $_GET['import_token']) : '';

    echo '<div class="casanova-admin-stack--lg">';
    echo '<h2>Importar cobros manuales</h2>';
    echo '<p class="description">Sube un XLSX/CSV, revisa la previsualizacion y registra en GIAV solo las filas seleccionadas. El DNI es opcional: si no viene o no se resuelve, el cobro se imputa al titular del expediente.</p>';

    if ($error !== '') {
      echo '<div class="notice notice-error"><p>' . esc_html(casanova_manual_payment_import_error_message($error)) . '</p></div>';
    }

    if ($done) {
      $registered = absint($_GET['registered'] ?? 0);
      $failed = absint($_GET['failed'] ?? 0);
      $skipped = absint($_GET['skipped'] ?? 0);
      echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Importacion finalizada. Registrados: %d. Errores: %d. Omitidos: %d.', $registered, $failed, $skipped)) . '</p></div>';
    }

    echo '<section class="casanova-admin-card casanova-admin-card--wide">';
    echo '<h2>Subir archivo</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field('casanova_manual_payment_import_preview');
    echo '<input type="hidden" name="action" value="casanova_manual_payment_import_preview" />';
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="manual_import_expediente">Expediente por defecto</label></th>';
    echo '<td><input name="default_expediente" id="manual_import_expediente" type="text" inputmode="numeric" pattern="[0-9]*" class="regular-text" placeholder="ID interno o codigo visible" />';
    echo '<p class="description">Si el archivo incluye una columna Expediente, se usara por fila. Si no, se usara este valor.</p></td></tr>';

    echo '<tr><th scope="row"><label for="manual_import_id_forma_pago">ID forma de pago GIAV</label></th>';
    echo '<td><input name="id_forma_pago" id="manual_import_id_forma_pago" type="number" min="0" step="1" class="regular-text" />';
    echo '<p class="description">Opcional si el Excel incluye la columna <code>Id forma de pago</code>. Si una fila trae su propio ID, se usa el de la fila; si va vacia, se usa este valor por defecto.</p></td></tr>';

    echo '<tr><th scope="row"><label for="manual_import_id_oficina">ID oficina GIAV</label></th>';
    echo '<td><input name="id_oficina" id="manual_import_id_oficina" type="number" min="0" step="1" class="regular-text" />';
    echo '<p class="description">Opcional. Dejandolo vacio se usa el comportamiento actual de Cobro_POST.</p></td></tr>';

    echo '<tr><th scope="row"><label for="manual_import_file">Archivo</label></th>';
    echo '<td><input name="payments_file" id="manual_import_file" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />';
    echo '<p class="description">Columnas reconocidas: Expediente, Pagador, DNI/NIF, Fecha de pago, Forma de pago, Banco, Importe, Concepto, Referencia y Estado.</p></td></tr>';
    echo '</table>';
    submit_button('Previsualizar archivo', 'primary', 'submit', false);
    echo '</form>';
    echo '</section>';

    if ($token !== '') {
      $preview = get_transient(casanova_manual_payment_import_transient_key($token));
      if (!is_array($preview)) {
        echo '<div class="notice notice-warning"><p>La previsualizacion ha caducado. Vuelve a subir el archivo.</p></div>';
      } else {
        $summary = is_array($preview['summary'] ?? null) ? $preview['summary'] : [];
        $rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        $importable_count = 0;

        echo '<section class="casanova-admin-card casanova-admin-card--wide">';
        echo '<h2>Previsualizacion</h2>';
        echo '<p class="description">Archivo: <strong>' . esc_html((string) ($preview['source_filename'] ?? '')) . '</strong>. ';
        echo 'Filas: <strong>' . esc_html((string) ($summary['total'] ?? count($rows))) . '</strong>. ';
        echo 'Listas: <strong>' . esc_html((string) ($summary['importable'] ?? 0)) . '</strong>. ';
        echo 'Duplicadas: <strong>' . esc_html((string) ($summary['duplicates'] ?? 0)) . '</strong>. ';
        echo 'No recibidas: <strong>' . esc_html((string) ($summary['not_received'] ?? 0)) . '</strong>. ';
        echo 'Con errores: <strong>' . esc_html((string) ($summary['invalid'] ?? 0)) . '</strong>.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('casanova_manual_payment_import_confirm_' . $token);
        echo '<input type="hidden" name="action" value="casanova_manual_payment_import_confirm" />';
        echo '<input type="hidden" name="import_token" value="' . esc_attr($token) . '" />';

        echo '<table class="widefat striped casanova-admin-table--1200 casanova-manual-import-table">';
        echo '<thead><tr>';
        echo '<th class="casanova-admin-col--check"></th><th>Fila</th><th>Estado</th><th>Expediente</th><th>Fecha</th><th>Pagador</th><th>DNI</th><th>Importe</th><th>Concepto</th><th>Banco</th><th>Mensaje</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
          $importable = !empty($row['importable']);
          if ($importable) $importable_count++;
          $status = (string) ($row['status'] ?? '');
          $exp_label = trim((string) ($row['codigo_expediente'] ?? ''));
          if ($exp_label === '') $exp_label = (string) ($row['id_expediente'] ?? $row['expediente_ref'] ?? '');
          $amount_txt = number_format((float) ($row['importe'] ?? 0), 2, ',', '.') . ' EUR';

          echo '<tr class="casanova-manual-import-row casanova-manual-import-row--' . esc_attr($status) . '">';
          echo '<td>';
          if ($importable) {
            echo '<input type="checkbox" name="row_ids[]" value="' . esc_attr((string) ($row['preview_id'] ?? '')) . '" checked />';
          } else {
            echo '<input type="checkbox" disabled />';
          }
          echo '</td>';
          echo '<td>' . esc_html((string) ($row['row_number'] ?? '')) . '</td>';
          echo '<td><span class="casanova-admin-badge casanova-admin-badge--' . esc_attr($status) . '">' . esc_html(casanova_manual_payment_import_status_label($status)) . '</span></td>';
          echo '<td><code>' . esc_html($exp_label) . '</code></td>';
          echo '<td>' . esc_html((string) ($row['fecha'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['pagador'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['dni'] ?? '')) . '</td>';
          echo '<td>' . esc_html($amount_txt) . '</td>';
          echo '<td>' . esc_html((string) ($row['concepto'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['banco'] ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($row['message'] ?? '')) . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table>';

        if ($importable_count > 0) {
          echo '<p class="submit">';
          echo '<button type="submit" class="button button-primary">Registrar seleccionados en GIAV</button> ';
          echo '<a class="button button-secondary" href="' . esc_url(casanova_manual_payment_import_admin_url()) . '">Cancelar</a>';
          echo '</p>';
        } else {
          echo '<p><a class="button button-secondary" href="' . esc_url(casanova_manual_payment_import_admin_url()) . '">Volver a subir archivo</a></p>';
        }
        echo '</form>';
        echo '</section>';
      }
    }

    if (function_exists('casanova_manual_payment_import_rows_table') && casanova_manual_payment_import_ensure_table()) {
      global $wpdb;
      $table = casanova_manual_payment_import_rows_table();
      $history = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 25");
      echo '<section class="casanova-admin-card casanova-admin-card--wide">';
      echo '<h2>Ultimas importaciones</h2>';
      if (!empty($wpdb->last_error)) {
        echo '<div class="notice notice-error inline"><p>No se pudo consultar el historial local: ' . esc_html($wpdb->last_error) . '</p></div>';
      }
      if (!empty($history)) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Fecha importacion</th><th>Estado</th><th>Expediente</th><th>Fecha pago</th><th>Pagador</th><th>Importe</th><th>Cobro GIAV</th><th>Error</th></tr></thead><tbody>';
        foreach ($history as $item) {
          echo '<tr>';
          echo '<td>' . esc_html((string) ($item->created_at ?? '')) . '</td>';
          echo '<td>' . esc_html(casanova_manual_payment_import_status_label((string) ($item->status ?? ''))) . '</td>';
          echo '<td><code>' . esc_html((string) ($item->id_expediente ?? '')) . '</code></td>';
          echo '<td>' . esc_html((string) ($item->payment_date ?? '')) . '</td>';
          echo '<td>' . esc_html((string) ($item->payer_name ?? '')) . '</td>';
          echo '<td>' . esc_html(number_format((float) ($item->amount ?? 0), 2, ',', '.') . ' EUR') . '</td>';
          echo '<td>' . (!empty($item->giav_cobro_id) ? '<code>' . esc_html((string) $item->giav_cobro_id) . '</code>' : '') . '</td>';
          echo '<td>' . esc_html((string) ($item->error_message ?? '')) . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table>';
      } else {
        echo '<p class="description">Todavia no hay importaciones registradas.</p>';
      }
      echo '</section>';
    } else {
      echo '<section class="casanova-admin-card casanova-admin-card--wide">';
      echo '<h2>Ultimas importaciones</h2>';
      global $wpdb;
      $table_name = function_exists('casanova_manual_payment_import_rows_table') ? casanova_manual_payment_import_rows_table() : '';
      $detail = !empty($wpdb->last_error) ? ' Error MySQL: ' . $wpdb->last_error : '';
      $table_hint = $table_name !== '' ? ' Tabla esperada: ' . $table_name . '.' : '';
      echo '<div class="notice notice-error inline"><p>No se pudo preparar la tabla local de auditoria de importaciones.' . esc_html($table_hint . $detail) . '</p></div>';
      echo '</section>';
    }

    echo '</div>';
  }
}
