<?php
if (!defined('ABSPATH')) exit;

function casanova_fmt_date($date, string $format = 'd/m/Y'): string {
  if (empty($date)) return '';
  $ts = strtotime((string)$date);
  if (!$ts) return '';
  return date_i18n($format, $ts);
}

function casanova_fmt_date_range($from, $to): string {
  $a = casanova_fmt_date($from);
  $b = casanova_fmt_date($to);
  if ($a && $b) return $a . ' – ' . $b;
  return $a ?: $b;
}

function casanova_fmt_money($amount, string $currency = '€'): string {
  $n = is_numeric($amount) ? (float)$amount : 0.0;
  return number_format($n, 2, ',', '.') . ' ' . $currency;
}

function casanova_portal_asset_path(string $relative_path): string {
  return CASANOVA_GIAV_PLUGIN_PATH . ltrim($relative_path, '/\\');
}

function casanova_portal_asset_url(string $relative_path): string {
  return CASANOVA_GIAV_PLUGIN_URL . ltrim($relative_path, '/\\');
}

function casanova_portal_asset_version(string $relative_path): string {
  $path = casanova_portal_asset_path($relative_path);
  return file_exists($path) ? (string) filemtime($path) : casanova_portal_giav_current_version();
}

function casanova_portal_render_public_document_start(string $title, array $styles = ['assets/portal.css']): void {
  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

  echo '<!doctype html>';
  echo '<html lang="' . esc_attr(get_bloginfo('language')) . '">';
  echo '<head>';
  echo '<meta charset="' . esc_attr(get_bloginfo('charset')) . '" />';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
  echo '<title>' . esc_html($title) . '</title>';

  foreach ($styles as $style) {
    $href = add_query_arg(
      'ver',
      rawurlencode(casanova_portal_asset_version($style)),
      casanova_portal_asset_url($style)
    );
    echo '<link rel="stylesheet" href="' . esc_url($href) . '" />';
  }

  echo '</head>';
  echo '<body class="casanova-public-body">';
  echo '<main class="casanova-public-shell">';
}

function casanova_portal_render_public_document_end(): void {
  echo '</main>';
  echo '</body>';
  echo '</html>';
}

function casanova_portal_public_logo_html(): string {
  if (!defined('CASANOVA_AGENCY_LOGO_URL') || !CASANOVA_AGENCY_LOGO_URL) {
    return '';
  }

  return '<div class="casanova-public-page__logo"><img src="' . esc_url(CASANOVA_AGENCY_LOGO_URL) . '" alt="" /></div>';
}

function casanova_portal_action_button_html(?string $href, string $label, bool $disabled = false): string {
  $class = 'casanova-action-btn';
  if ($disabled) {
    return '<span class="' . esc_attr($class . ' casanova-action-btn--disabled') . '">' . esc_html($label) . '</span>';
  }

  return '<a class="' . esc_attr($class) . '" href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
}

function casanova_badge(string $text, string $type = 'neutral'): string {
  $map = [
    'ok' => 'casanova-badge--status-ok',
    'warn' => 'casanova-badge--status-warn',
    'bad' => 'casanova-badge--status-bad',
    'neutral' => 'casanova-badge--status-neutral',
  ];
  $variant = $map[$type] ?? $map['neutral'];

  return '<span class="' . esc_attr('casanova-badge ' . $variant) . '">' . esc_html($text) . '</span>';
}

function casanova_badge_from_mapped_estado(array $m): string {
  [$txt, $type] = casanova_reserva_estado_from_mapped($m);
  return casanova_badge($txt, $type);
}


function casanova_group_by(array $items, callable $keyFn): array {
  $out = [];
  foreach ($items as $it) {
    $k = (string) $keyFn($it);
    if ($k === '') $k = 'Otros';
    if (!isset($out[$k])) $out[$k] = [];
    $out[$k][] = $it;
  }
  return $out;
}

function casanova_reserva_actions_html($r, int $idExpediente, bool $expediente_pagado): string {
  $idReserva = (string)($r->Id ?? '');
  if ($idReserva === '') $idReserva = (string)($r->Codigo ?? '');
  if ($idReserva === '') return '';

  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  $view = add_query_arg(['expediente' => $idExpediente, 'reserva' => (int)$idReserva], $base);

  $voucher_preview_url = add_query_arg([
  'action' => 'casanova_voucher',
  'expediente' => $idExpediente,
  'reserva' => (int)$idReserva,
  '_wpnonce' => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . (int)$idReserva),
], admin_url('admin-post.php'));

  
  $voucher_pdf_url = add_query_arg([
  'action' => 'casanova_voucher_pdf',
  'expediente' => $idExpediente,
  'reserva' => (int)$idReserva,
  '_wpnonce' => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . (int)$idReserva),
], admin_url('admin-post.php'));


  $out = '<div class="casanova-inline-actions">';
  $out .= casanova_portal_action_button_html($view, esc_html__('Ver', 'casanova-portal'));

 if (!$expediente_pagado) {
  $out .= casanova_portal_action_button_html(null, esc_html__('Ver bono', 'casanova-portal'), true);
  $out .= casanova_portal_action_button_html(null, esc_html__('PDF', 'casanova-portal'), true);
} else {
  $out .= casanova_portal_action_button_html($voucher_preview_url, esc_html__('Ver bono', 'casanova-portal'));
  $out .= casanova_portal_action_button_html($voucher_pdf_url, esc_html__('PDF', 'casanova-portal'));
}

  $out .= '</div>';
  return $out;
}


function casanova_reserva_nombre_bono($r): string {
  if (!$r || !is_object($r)) return '';

  // 1) Campo directo
  $v = isset($r->ClienteBono) ? trim((string)$r->ClienteBono) : '';
  if ($v !== '') return $v;

  // 2) A veces va en Rooming (depende del tipo de reserva)
  $v = isset($r->Rooming) ? trim((string)$r->Rooming) : '';
  if ($v !== '') return $v;

  // 3) A veces está dentro de CustomDataValues (si GIAV lo usa)
  // Como no sabemos la estructura exacta, hacemos una búsqueda defensiva.
  if (isset($r->CustomDataValues) && $r->CustomDataValues) {
    $dump = print_r($r->CustomDataValues, true);

    // Si en ese dump aparece algo tipo "ClienteBono" o "Nombre", te lo extraemos luego fino.
    // Por ahora: si hay pista, al menos sabemos que está ahí.
    // (No devolvemos nada “a ciegas”).
  }

  return '';
}

function casanova_bono_servicio_btn($r, int $idExpediente, bool $expediente_pagado): string {
  $idReserva = (int)($r->Id ?? 0);
  if ($idReserva <= 0) return '';

  // Gate principal: pago a nivel expediente
  if (!$expediente_pagado) {
    return '<div class="casanova-inline-actions">'
      . casanova_portal_action_button_html(null, esc_html__('Ver bono', 'casanova-portal'), true)
      . casanova_portal_action_button_html(null, esc_html__('PDF', 'casanova-portal'), true)
      . '</div>';
  }

  // NO usamos Pendiente por servicio para bloquear, pero si quieres mantenerlo como "cinturón"
  // lo dejamos solo como deshabilitado (sin romper tu lógica anterior).
  $pend = (float)($r->Pendiente ?? 0);
  if ($pend > 0.01) {
    return '<div class="casanova-inline-actions">'
      . casanova_portal_action_button_html(null, esc_html__('Ver bono', 'casanova-portal'), true)
      . casanova_portal_action_button_html(null, esc_html__('PDF', 'casanova-portal'), true)
      . '</div>';
  }

  // Preview HTML
  $preview_url = function_exists('casanova_portal_voucher_url')
    ? casanova_portal_voucher_url($idExpediente, $idReserva, 'view')
    : add_query_arg([
      'action'    => 'casanova_voucher',
      'expediente'=> $idExpediente,
      'reserva'   => $idReserva,
      '_wpnonce'  => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva),
    ], admin_url('admin-post.php'));
  // PDF
  $pdf_url = function_exists('casanova_portal_voucher_url')
    ? casanova_portal_voucher_url($idExpediente, $idReserva, 'pdf')
    : add_query_arg([
      'action'    => 'casanova_voucher_pdf',
      'expediente'=> $idExpediente,
      'reserva'   => $idReserva,
      '_wpnonce'  => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva),
    ], admin_url('admin-post.php'));
  return '<div class="casanova-inline-actions">'
    . casanova_portal_action_button_html($preview_url, esc_html__('Ver bono', 'casanova-portal'))
    . casanova_portal_action_button_html($pdf_url, esc_html__('PDF', 'casanova-portal'))
    . '</div>';
}



function casanova_pagar_expediente_btn(int $idExpediente, bool $expediente_pagado, float $total_pend): string {
  if ($expediente_pagado) {
    return '<span class="casanova-action-btn casanova-action-btn--disabled">' . esc_html__('Pagado', 'casanova-portal') . '</span>';
  }

  // Iniciar pago desde frontend (evita bloqueos a /wp-admin/ para no-admin)
  if (function_exists('casanova_portal_pay_expediente_url')) {
    $url = casanova_portal_pay_expediente_url($idExpediente);
  } else {
    $url = add_query_arg([
      'action' => 'casanova_pay_expediente',
      'expediente' => $idExpediente,
      '_wpnonce'   => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
    ], admin_url('admin-post.php'));
  }

  return casanova_portal_action_button_html($url, esc_html__('Pagar', 'casanova-portal'));
}

function casanova_pdf_logo_data_uri(): string {
  // Opción A: constante con URL del logo (recomendado que sea en uploads)
  $url = defined('CASANOVA_AGENCY_LOGO_URL') ? CASANOVA_AGENCY_LOGO_URL : '';
  if (!$url) return '';

  // Convertir URL a path local si es de tu dominio/uploads
  $uploads = wp_get_upload_dir();
  if (strpos($url, $uploads['baseurl']) === 0) {
    $path = $uploads['basedir'] . substr($url, strlen($uploads['baseurl']));
  } else {
    // Si no es local, Dompdf puede fallar. Mejor no insistir.
    return '';
  }

  if (!file_exists($path)) return '';

  $bin = file_get_contents($path);
  if ($bin === false) return '';

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $mime = $ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');

  return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function casanova_reserva_room_types_text($r): string {
  if (!is_object($r)) return '';

  $candidates = [];

  // 1) ¿Viene anidado como TiposDeHabitacion?
  $th = $r->TiposDeHabitacion ?? $r->tiposDeHabitacion ?? null;
  if (is_object($th)) {
    $candidates[] = $th;
  }

  // 2) ¿Viene en la raíz del objeto reserva?
  $candidates[] = $r;

  foreach ($candidates as $obj) {
    if (!is_object($obj)) continue;

    $parts = [];
    for ($i = 1; $i <= 4; $i++) {
      // puede ser uso1/num1 o Uso1/Num1
      $uso = $obj->{'uso'.$i} ?? $obj->{'Uso'.$i} ?? null;
      $num = $obj->{'num'.$i} ?? $obj->{'Num'.$i} ?? null;

      $uso = is_string($uso) ? trim($uso) : (is_object($uso) && isset($uso->value) ? trim((string)$uso->value) : trim((string)($uso ?? '')));
      $num = is_numeric($num) ? (int)$num : (int)($num ?? 0);

      if ($uso !== '' && $num > 0) {
        $parts[] = $num . ' ' . $uso;
      }
    }

    if (!empty($parts)) {
      return implode(', ', $parts);
    }
  }

  return '';
}
