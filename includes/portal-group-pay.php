<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_group_pay_register_rewrite')) {
  function casanova_group_pay_register_rewrite(): void {
    add_rewrite_rule('^pay/group/([^/]+)/?$', 'index.php?casanova_group_pay_token=$matches[1]', 'top');
  }
}

add_action('init', function () {
  if (function_exists('casanova_group_pay_register_rewrite')) {
    casanova_group_pay_register_rewrite();
  }
});

add_filter('query_vars', function (array $vars): array {
  $vars[] = 'casanova_group_pay_token';
  return $vars;
});

add_action('template_redirect', function () {
  $token = (string) get_query_var('casanova_group_pay_token');
  if ($token === '') {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '') {
      $path = wp_parse_url($uri, PHP_URL_PATH);
      if (is_string($path) && $path !== '') {
        if (preg_match('#/pay/group/([^/]+)/?#', $path, $m)) {
          $token = (string)($m[1] ?? '');
        }
      }
    }
  }
  if ($token === '') return;
  casanova_handle_group_pay_request($token);
  exit;
});

function casanova_group_pay_link_metadata($row): array {
  if (function_exists('casanova_payment_links_read_metadata')) {
    return casanova_payment_links_read_metadata($row);
  }

  $raw = is_object($row) ? (string)($row->metadata ?? '') : '';
  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function casanova_group_pay_token_metadata($group): array {
  $raw = is_object($group) ? (string)($group->metadata ?? '') : '';
  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function casanova_group_pay_concept_slug(string $label, int $index): string {
  $id = function_exists('sanitize_title') ? sanitize_title($label) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
  $id = trim((string)$id, '-');
  return $id !== '' ? $id : 'concepto-' . max(1, $index);
}

function casanova_group_pay_token_concepts($group, float $fallback_unit_total): array {
  $meta = casanova_group_pay_token_metadata($group);
  $raw_concepts = is_array($meta['concepts'] ?? null) ? $meta['concepts'] : [];
  $concepts = [];
  $seen = [];
  $idx = 1;

  foreach ($raw_concepts as $raw) {
    if (!is_array($raw)) continue;
    $amount = round(max(0.0, (float)($raw['unit_total'] ?? 0)), 2);
    if ($amount <= 0.0) continue;

    $label = trim((string)($raw['label'] ?? ''));
    if ($label === '') $label = sprintf(__('Opción %d', 'casanova-portal'), $idx);

    $base_id = trim((string)($raw['id'] ?? ''));
    if ($base_id === '') $base_id = casanova_group_pay_concept_slug($label, $idx);
    $id = $base_id;
    $suffix = 2;
    while (isset($seen[$id])) {
      $id = $base_id . '-' . $suffix;
      $suffix++;
    }
    $seen[$id] = true;

    $concepts[] = [
      'id' => $id,
      'label' => $label,
      'unit_total' => $amount,
    ];
    $idx++;
  }

  if (empty($concepts) && $fallback_unit_total > 0.0) {
    $concepts[] = [
      'id' => 'default',
      'label' => __('Precio por persona', 'casanova-portal'),
      'unit_total' => round($fallback_unit_total, 2),
    ];
  }

  return $concepts;
}

function casanova_group_pay_concept_by_id(array $concepts, string $raw_id): array {
  $raw_id = trim(sanitize_key($raw_id));
  foreach ($concepts as $concept) {
    if (!is_array($concept)) continue;
    if ((string)($concept['id'] ?? '') === $raw_id) return $concept;
  }

  return is_array($concepts[0] ?? null) ? $concepts[0] : [];
}

function casanova_group_pay_concept_deposit(float $unit_total, int $idExpediente): float {
  if ($unit_total <= 0.0 || !function_exists('casanova_payments_calc_deposit_amount')) return 0.0;
  return round(max(0.0, (float)casanova_payments_calc_deposit_amount($unit_total, $idExpediente)), 2);
}

function casanova_group_pay_link_matches_concept(array $meta, string $concept_id): bool {
  $concept_id = trim($concept_id);
  if ($concept_id === '') return true;

  $meta_concept_id = trim((string)($meta['concept_id'] ?? ''));
  if ($meta_concept_id !== '') return $meta_concept_id === $concept_id;

  return $concept_id === 'default';
}

/**
 * Lineas de conceptos de un pago de grupo (un pago puede mezclar varios conceptos).
 * Devuelve [] para enlaces antiguos de concepto unico.
 */
function casanova_group_pay_meta_concept_lines(array $meta): array {
  $raw = is_array($meta['concept_lines'] ?? null) ? $meta['concept_lines'] : [];
  $lines = [];

  foreach ($raw as $line) {
    if (!is_array($line)) continue;
    $units = (int)($line['units'] ?? 0);
    if ($units <= 0) continue;

    $lines[] = [
      'id' => trim((string)($line['id'] ?? '')),
      'label' => trim((string)($line['label'] ?? '')),
      'unit_total' => round((float)($line['unit_total'] ?? 0), 2),
      'unit_deposit' => round((float)($line['unit_deposit'] ?? 0), 2),
      'unit_rest' => round((float)($line['unit_rest'] ?? 0), 2),
      'units' => $units,
    ];
  }

  return $lines;
}

/**
 * Personas de un pago que corresponden a un concepto concreto.
 * Con $concept_id vacio devuelve el total del pago.
 */
function casanova_group_pay_link_units_for_concept(array $meta, string $concept_id): int {
  $concept_id = trim($concept_id);
  $lines = casanova_group_pay_meta_concept_lines($meta);

  if (!empty($lines)) {
    $units = 0;
    foreach ($lines as $line) {
      if ($concept_id !== '' && $line['id'] !== $concept_id) continue;
      $units += (int)$line['units'];
    }
    return $units;
  }

  if (!casanova_group_pay_link_matches_concept($meta, $concept_id)) return 0;
  return max(0, (int)($meta['units'] ?? 0));
}

/**
 * Deposito unitario que se cobro en ese enlace para un concepto concreto.
 */
function casanova_group_pay_link_unit_deposit_for_concept($row, array $meta, string $concept_id): float {
  $concept_id = trim($concept_id);
  $lines = casanova_group_pay_meta_concept_lines($meta);

  if (!empty($lines)) {
    $amount = 0.0;
    $units = 0;
    foreach ($lines as $line) {
      if ($concept_id !== '' && $line['id'] !== $concept_id) continue;
      $amount += round((float)$line['unit_deposit'] * (int)$line['units'], 2);
      $units += (int)$line['units'];
    }
    if ($units > 0 && $amount > 0.0) return round($amount / (float)$units, 2);
  }

  $unit_deposit = round((float)($meta['unit_deposit'] ?? 0), 2);
  if ($unit_deposit > 0.0) return $unit_deposit;

  $total_units = max(0, (int)($meta['units'] ?? 0));
  if ($total_units > 0) {
    return round(((float)($row->amount_authorized ?? 0)) / (float)$total_units, 2);
  }

  return 0.0;
}

/**
 * Texto corto del desglose de conceptos: "Jugador x1, No jugador x1".
 */
function casanova_group_pay_lines_label(array $lines): string {
  $parts = [];
  foreach ($lines as $line) {
    $units = (int)($line['units'] ?? 0);
    if ($units <= 0) continue;
    $label = trim((string)($line['label'] ?? ''));
    if ($label === '') $label = trim((string)($line['id'] ?? ''));
    if ($label === '') continue;
    $parts[] = $label . ' x' . $units;
  }
  return implode(', ', $parts);
}

/**
 * Desglose de conceptos de un pago para las notas de GIAV.
 */
function casanova_group_pay_concepts_note(array $meta): string {
  $lines = casanova_group_pay_meta_concept_lines($meta);
  if (!empty($lines)) return casanova_group_pay_lines_label($lines);

  return trim((string)($meta['concept_label'] ?? ''));
}

/**
 * Cantidades por concepto recibidas del formulario publico: [concept_id => personas].
 * Mantiene compatibilidad con el formato antiguo (un concepto + numero de personas).
 */
function casanova_group_pay_quantities_from_request(array $concepts, string $qty_field, array $request, string $legacy_concept_field = '', string $legacy_units_field = ''): array {
  $out = [];
  $qty_raw = isset($request[$qty_field]) && is_array($request[$qty_field]) ? $request[$qty_field] : [];

  if (!empty($qty_raw)) {
    foreach ($concepts as $concept) {
      $cid = trim((string)($concept['id'] ?? ''));
      if ($cid === '') continue;
      $qty = isset($qty_raw[$cid]) ? (int)$qty_raw[$cid] : 0;
      if ($qty > 0) $out[$cid] = $qty;
    }
    return $out;
  }

  if ($legacy_concept_field === '') return $out;

  $concept = casanova_group_pay_concept_by_id($concepts, (string)($request[$legacy_concept_field] ?? ''));
  $cid = trim((string)($concept['id'] ?? ''));
  if ($cid === '') return $out;

  $units = ($legacy_units_field !== '' && isset($request[$legacy_units_field])) ? (int)$request[$legacy_units_field] : 1;
  if ($units < 1) $units = 1;
  $out[$cid] = $units;

  return $out;
}

function casanova_group_pay_token_configured_units($group): int {
  $meta = casanova_group_pay_token_metadata($group);
  foreach (['group_units', 'group_pax', 'max_units'] as $key) {
    $units = (int)($meta[$key] ?? 0);
    if ($units > 0) return $units;
  }

  return 0;
}

function casanova_group_pay_token_units_limit($group, int $fallback_num_pax): int {
  $configured = casanova_group_pay_token_configured_units($group);
  if ($configured > 0) return $configured;

  $fallback_num_pax = (int)$fallback_num_pax;
  return $fallback_num_pax > 0 ? $fallback_num_pax : 1;
}

function casanova_group_pay_link_matches_token(array $meta, int $group_id, int $idReservaPQ, $row = null, bool $allow_related = false): bool {
  $meta_group_id = (int)($meta['group_token_id'] ?? 0);
  if ($meta_group_id > 0 && $meta_group_id === $group_id) {
    return true;
  }
  if ($meta_group_id > 0 && !$allow_related) {
    return false;
  }

  $row_reserva_pq = is_object($row) ? (int)($row->id_reserva_pq ?? 0) : 0;
  $meta_reserva_pq = (int)($meta['id_reserva_pq'] ?? 0);

  if ($allow_related) {
    if ($idReservaPQ > 0) {
      return $row_reserva_pq === $idReservaPQ || $meta_reserva_pq === $idReservaPQ;
    }

    return true;
  }

  if ($meta_group_id > 0) {
    return $meta_group_id === $group_id;
  }

  if ($idReservaPQ > 0 && $meta_reserva_pq > 0) {
    return $meta_reserva_pq === $idReservaPQ;
  }

  return $group_id <= 0;
}

function casanova_group_pay_link_is_confirmed($row): bool {
  if (!$row || !is_object($row)) return false;

  $status = strtolower(trim((string)($row->status ?? '')));
  if ($status === 'paid') return true;
  if ($status !== 'active') return false;

  $has_payment_marker = (int)($row->giav_payment_id ?? 0) > 0 || trim((string)($row->paid_at ?? '')) !== '';
  if ($has_payment_marker) {
    if (function_exists('casanova_payment_link_update')) {
      casanova_payment_link_update((int)$row->id, ['status' => 'paid']);
    }
    return true;
  }

  if (!function_exists('casanova_payment_links_confirmed_intent_for_link')) return false;
  $intent = casanova_payment_links_confirmed_intent_for_link($row);
  if (!$intent) return false;

  if (function_exists('casanova_payment_links_mark_paid_from_confirmed_intent')) {
    casanova_payment_links_mark_paid_from_confirmed_intent($row, $intent, (string)($row->billing_dni ?? ''));
  }

  return true;
}

function casanova_group_pay_base_units_status(int $idExpediente, int $group_id, int $idReservaPQ, bool $allow_related = false): array {
  global $wpdb;

  $out = [
    'base_units' => 0,
    'deposit_units' => 0,
    'full_units' => 0,
    'rest_units' => 0,
  ];

  if ($idExpediente <= 0 || !function_exists('casanova_payment_links_table')) return $out;

  $table = casanova_payment_links_table();
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM {$table} WHERE id_expediente=%d AND scope='group_base' AND status IN ('paid','active') ORDER BY id ASC",
      $idExpediente
    )
  );
  if (empty($rows)) return $out;

  foreach ($rows as $row) {
    $meta = casanova_group_pay_link_metadata($row);
    if (!casanova_group_pay_link_matches_token($meta, $group_id, $idReservaPQ, $row, $allow_related)) {
      continue;
    }
    if (!casanova_group_pay_link_is_confirmed($row)) {
      continue;
    }

    $units = (int)($meta['units'] ?? 0);
    if ($units <= 0) continue;

    $mode = strtolower(trim((string)($meta['mode'] ?? '')));
    if ($mode === 'rest') {
      $out['rest_units'] += $units;
      continue;
    }

    $out['base_units'] += $units;
    if (function_exists('casanova_payment_links_is_effective_deposit') && casanova_payment_links_is_effective_deposit($row, $meta)) {
      $out['deposit_units'] += $units;
    } else {
      $out['full_units'] += $units;
    }
  }

  return $out;
}

function casanova_group_pay_rest_status(int $idExpediente, int $group_id, int $idReservaPQ, string $concept_id = '', bool $allow_related = false): array {
  global $wpdb;

  $out = [
    'deposit_units' => 0,
    'rest_units' => 0,
    'available_units' => 0,
    'deposit_amount_total' => 0.0,
    'unit_deposit' => 0.0,
  ];

  if ($idExpediente <= 0 || !function_exists('casanova_payment_links_table')) return $out;

  $table = casanova_payment_links_table();
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM {$table} WHERE id_expediente=%d AND scope='group_base' AND status IN ('paid','active') ORDER BY id ASC",
      $idExpediente
    )
  );
  if (empty($rows)) return $out;

  foreach ($rows as $row) {
    $meta = casanova_group_pay_link_metadata($row);
    if (!casanova_group_pay_link_matches_token($meta, $group_id, $idReservaPQ, $row, $allow_related)) {
      continue;
    }
    if (!casanova_group_pay_link_is_confirmed($row)) {
      continue;
    }

    // Un pago puede mezclar conceptos: contamos solo las personas de este concepto.
    $units = casanova_group_pay_link_units_for_concept($meta, $concept_id);
    if ($units <= 0) continue;

    $mode = strtolower(trim((string)($meta['mode'] ?? '')));
    if (function_exists('casanova_payment_links_is_effective_deposit') && casanova_payment_links_is_effective_deposit($row, $meta)) {
      $out['deposit_units'] += $units;
      $unit_deposit = casanova_group_pay_link_unit_deposit_for_concept($row, $meta, $concept_id);
      if ($unit_deposit > 0.0) {
        $out['deposit_amount_total'] += round($unit_deposit * (float)$units, 2);
      }
      continue;
    }

    if ($mode === 'rest') {
      $out['rest_units'] += $units;
    }
  }

  $out['available_units'] = max(0, (int)$out['deposit_units'] - (int)$out['rest_units']);
  if ((int)$out['deposit_units'] > 0 && (float)$out['deposit_amount_total'] > 0.0) {
    $out['unit_deposit'] = round((float)$out['deposit_amount_total'] / (float)$out['deposit_units'], 2);
  }
  return $out;
}

function casanova_handle_group_pay_request(string $token): void {
  $token = sanitize_text_field($token);
  if (function_exists('casanova_pay_send_nocache_headers')) {
    casanova_pay_send_nocache_headers();
  }
  if (function_exists('casanova_portal_maybe_switch_public_locale')) {
    casanova_portal_maybe_switch_public_locale();
  }
  if ($token === '') {
    wp_die(esc_html__('Enlace de grupo invalido.', 'casanova-portal'), 404);
  }

  if (!function_exists('casanova_group_token_get')) {
    wp_die(esc_html__('Sistema de grupos no disponible.', 'casanova-portal'), 500);
  }

  $group = casanova_group_token_get($token);
  if (!$group) {
    wp_die(esc_html__('Enlace de grupo no encontrado.', 'casanova-portal'), 404);
  }

  $status = strtolower(trim((string)($group->status ?? '')));
  if ($status !== 'active') {
    casanova_render_payment_link_error(__('Enlace de grupo no disponible.', 'casanova-portal'));
    exit;
  }

  if (function_exists('casanova_group_token_is_expired') && casanova_group_token_is_expired($group)) {
    casanova_group_token_update((int)$group->id, ['status' => 'expired']);
    casanova_render_payment_link_error(__('Enlace de grupo caducado.', 'casanova-portal'));
    exit;
  }

  $idExpediente = (int)($group->id_expediente ?? 0);
  $idReservaPQ = (int)($group->id_reserva_pq ?? 0);
  if ($idExpediente <= 0) {
    casanova_render_payment_link_error(__('Expediente invalido.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_giav_expediente_get')) {
    casanova_render_payment_link_error(__('Sistema GIAV no disponible.', 'casanova-portal'));
    exit;
  }

  $exp = casanova_giav_expediente_get($idExpediente);
  if (is_wp_error($exp) || !is_object($exp)) {
    casanova_render_payment_link_error(__('No se pudo cargar el expediente.', 'casanova-portal'));
    exit;
  }

  $idCliente = (int)($exp->IdCliente ?? 0);
  if ($idCliente <= 0) {
    casanova_render_payment_link_error(__('Cliente no encontrado.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_group_context_from_reservas')) {
    casanova_render_payment_link_error(__('Sistema de grupos no disponible.', 'casanova-portal'));
    exit;
  }

  $ctx = casanova_group_context_from_reservas($idExpediente, $idCliente, $idReservaPQ ?: null);
  if (is_wp_error($ctx)) {
    casanova_render_payment_link_error($ctx->get_error_message());
    exit;
  }

  $reservas = $ctx['reservas'] ?? [];
  $calc = $ctx['calc'] ?? [];
  $numPax = (int)($ctx['num_pax'] ?? 0);
  $basePending = (float)($ctx['base_pending'] ?? 0);
  $baseTotal = (float)($ctx['base_total'] ?? 0);
  if ($baseTotal <= 0) {
    // Fallback: si no viene Venta desde GIAV, aproximamos como pendiente + ya pagado en slots.
    $slots_now = function_exists('casanova_group_slots_get') ? casanova_group_slots_get($idExpediente, $idReservaPQ ?: 0) : [];
    $paid_now = 0.0;
    if (!empty($slots_now)) {
      foreach ($slots_now as $s) { $paid_now += (float)($s->base_paid ?? 0); }
    }
    $baseTotal = max(0.0, $basePending + $paid_now);
  }
  $idReservaPQ = (int)($ctx['id_reserva_pq'] ?? $idReservaPQ);

  // Modelo de grupo: precio unico legacy o conceptos con precios distintos.
  $fallback_unit_total = (float)($group->unit_total ?? 0);
  if ($fallback_unit_total <= 0.0 && $baseTotal > 0 && $numPax > 0) {
    $fallback_unit_total = round(((float)$baseTotal) / ((float)$numPax), 2);
  }
  $group_concepts = casanova_group_pay_token_concepts($group, $fallback_unit_total);
  if (empty($group_concepts)) {
    casanova_render_payment_link_error(__('No se pudo determinar el importe por persona.', 'casanova-portal'));
    exit;
  }
  $default_concept = $group_concepts[0];
  $unit_total = round((float)($default_concept['unit_total'] ?? 0), 2);
  $has_group_concepts = count($group_concepts) > 1;
  $group_units_limit = casanova_group_pay_token_units_limit($group, $numPax);
  $configured_units = casanova_group_pay_token_configured_units($group);
  $allow_related_group_links = ($configured_units <= 0 || $numPax <= 0 || $group_units_limit >= $numPax);
  $base_units_status = casanova_group_pay_base_units_status($idExpediente, (int)$group->id, $idReservaPQ, $allow_related_group_links);
  $base_units_used = max(
    0,
    (int)($base_units_status['base_units'] ?? 0),
    (int)($base_units_status['rest_units'] ?? 0)
  );
  $main_available_units = max(0, $group_units_limit - $base_units_used);

  $deposit_allowed = function_exists('casanova_payments_is_deposit_allowed') ? casanova_payments_is_deposit_allowed($reservas) : false;
  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
  }
  $group_meta = casanova_group_pay_token_metadata($group);
  $stripe_only = !empty($group_meta['stripe_only']);
  $offer_usd_payment = !empty($group_meta['offer_usd_payment']) || $stripe_only;
  $disable_bank_transfer = !empty($group_meta['disable_bank_transfer']) || $offer_usd_payment;
  if ($disable_bank_transfer) {
    $inespay_enabled = false;
  }
  $stripe_available = function_exists('casanova_stripe_is_available') && casanova_stripe_is_available();
  $usd_payment_enabled = $offer_usd_payment && $stripe_available && function_exists('casanova_stripe_usd_quote');
  $usd_rate = 0.0;
  $usd_gross_up = 0.0;
  if ($usd_payment_enabled) {
    $usd_preview_quote = casanova_stripe_usd_quote(1.0);
    if (is_wp_error($usd_preview_quote) || !is_array($usd_preview_quote)) {
      $usd_payment_enabled = false;
    } else {
      $usd_rate = (float)($usd_preview_quote['eur_usd_rate'] ?? 0);
      $usd_gross_up = ((float)($usd_preview_quote['gross_up_percent'] ?? 0)) / 100.0;
      if ($usd_rate <= 0.0 || $usd_gross_up < 0.0 || $usd_gross_up >= 0.50) {
        $usd_payment_enabled = false;
        $usd_rate = 0.0;
        $usd_gross_up = 0.0;
      }
    }
  }
  $public_locale = function_exists('casanova_portal_get_public_requested_locale')
    ? casanova_portal_get_public_requested_locale()
    : '';
  $unit_deposit_configured = function_exists('casanova_payments_calc_deposit_amount')
    ? round(max(0.0, (float) casanova_payments_calc_deposit_amount($unit_total, $idExpediente)), 2)
    : 0.0;

  $flash_msg = '';
  $flash_type = 'info';

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
    $nonce_ok = function_exists('casanova_pay_csrf_verify')
      ? casanova_pay_csrf_verify('group_pay', (string)$group->token, $nonce, 'casanova_group_pay_' . (int)$group->id)
      : ($nonce !== '' && wp_verify_nonce($nonce, 'casanova_group_pay_' . (int)$group->id));
    if (!$nonce_ok) {
      $retry_url = function_exists('casanova_group_pay_url') ? casanova_group_pay_url((string)$group->token) : home_url('/');
      casanova_render_payment_link_error(__('Tu sesion de pago ha caducado o la pagina estaba desactualizada. Recarga la pagina e intentalo de nuevo.', 'casanova-portal'), $retry_url);
      exit;
    }

    $action = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : 'pay';
    if ($action === 'resend_magic') {
      $email_raw = isset($_POST['resend_email']) ? (string)$_POST['resend_email'] : '';
      $resend_email = trim(sanitize_email($email_raw));
      $dni_raw = isset($_POST['resend_dni']) ? (string)$_POST['resend_dni'] : '';
      $resend_dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));

      if ($resend_email === '' || !is_email($resend_email) || $resend_dni === '') {
        $flash_msg = __('Debes indicar email y documento de identidad o pasaporte.', 'casanova-portal');
        $flash_type = 'error';
      } elseif (!function_exists('casanova_payment_links_find_deposit_with_rest') || !function_exists('casanova_payment_links_resend_rest_magic')) {
        $flash_msg = __('Función no disponible. Contacta con la agencia.', 'casanova-portal');
        $flash_type = 'error';
      } else {
        $row = casanova_payment_links_find_deposit_with_rest($idExpediente, $resend_dni, $resend_email);
        if ($row) {
          $ok = casanova_payment_links_resend_rest_magic($row, $resend_email);
          if ($ok) {
            $flash_msg = __('Te hemos reenviado el enlace de pago final si existía un depósito registrado.', 'casanova-portal');
            $flash_type = 'success';
          } else {
            $flash_msg = __('No se pudo reenviar el enlace. Contacta con la agencia.', 'casanova-portal');
            $flash_type = 'error';
          }
        } else {
          $flash_msg = __('No encontrado. Contacta con la agencia.', 'casanova-portal');
          $flash_type = 'error';
        }
      }

      // Continuamos para renderizar la página con el mensaje.
    } elseif ($action === 'pay_rest') {
      // Cada persona puede pagar el resto de varios conceptos a la vez (jugador + no jugador).
      $rest_quantities = casanova_group_pay_quantities_from_request($group_concepts, 'rest_concept_qty', $_POST, 'rest_concept_id', 'rest_units');
      $rest_lines = [];
      $units = 0;
      $amount_to_pay = 0.0;
      $total_due = 0.0;
      $deposit_total = 0.0;

      foreach ($group_concepts as $concept) {
        $cid = trim((string)($concept['id'] ?? ''));
        $qty = (int)($rest_quantities[$cid] ?? 0);
        if ($cid === '' || $qty <= 0) continue;

        $line_unit_total = round((float)($concept['unit_total'] ?? 0), 2);
        if ($line_unit_total <= 0.0) continue;

        $line_status = casanova_group_pay_rest_status($idExpediente, (int)$group->id, $idReservaPQ, $cid, $allow_related_group_links);
        $line_available = max(0, (int)($line_status['available_units'] ?? 0));
        if ($line_available <= 0) continue;
        if ($qty > $line_available) {
          casanova_render_payment_link_error(sprintf(
            __('En "%1$s" solo quedan %2$d personas con depósito pendiente de resto.', 'casanova-portal'),
            (string)($concept['label'] ?? ''),
            $line_available
          ));
          exit;
        }

        $line_unit_deposit = round((float)($line_status['unit_deposit'] ?? 0), 2);
        if ($line_unit_deposit <= 0.0) {
          $line_unit_deposit = casanova_group_pay_concept_deposit($line_unit_total, $idExpediente);
        }
        $line_unit_rest = round(max(0.0, $line_unit_total - $line_unit_deposit), 2);
        if ($line_unit_rest <= 0.01) continue;

        $rest_lines[] = [
          'id' => $cid,
          'label' => trim((string)($concept['label'] ?? '')),
          'unit_total' => $line_unit_total,
          'unit_deposit' => $line_unit_deposit,
          'unit_rest' => $line_unit_rest,
          'units' => $qty,
        ];
        $units += $qty;
        $amount_to_pay += round($line_unit_rest * (float)$qty, 2);
        $total_due += round($line_unit_total * (float)$qty, 2);
        $deposit_total += round($line_unit_deposit * (float)$qty, 2);
      }

      if (empty($rest_lines) || $units <= 0) {
        casanova_render_payment_link_error(__('No hay pagos restantes pendientes para este enlace de grupo.', 'casanova-portal'));
        exit;
      }

      $amount_to_pay = round($amount_to_pay, 2);
      $total_due = round($total_due, 2);
      $deposit_total = round($deposit_total, 2);
      $rest_concept_id = (count($rest_lines) === 1) ? (string)$rest_lines[0]['id'] : 'mixto';
      $rest_concept_label = casanova_group_pay_lines_label($rest_lines);
      $rest_unit_total = round($total_due / (float)$units, 2);
      $rest_unit_deposit = round($deposit_total / (float)$units, 2);
      $unit_rest = round($amount_to_pay / (float)$units, 2);

      $name_raw = isset($_POST['billing_name']) ? (string)$_POST['billing_name'] : '';
      $lastname_raw = isset($_POST['billing_lastname']) ? (string)$_POST['billing_lastname'] : '';
      $billing_name = trim(sanitize_text_field($name_raw));
      $billing_lastname = trim(sanitize_text_field($lastname_raw));
      if ($billing_name === '' || $billing_lastname === '') {
        casanova_render_payment_link_error(__('Debes indicar Nombre y Apellidos.', 'casanova-portal'));
        exit;
      }
      $billing_fullname = trim($billing_name . ' ' . $billing_lastname);

      $email_raw = isset($_POST['billing_email']) ? (string)$_POST['billing_email'] : '';
      $billing_email = trim(sanitize_email($email_raw));
      if ($billing_email === '' || !is_email($billing_email)) {
        casanova_render_payment_link_error(__('Debes indicar un email valido.', 'casanova-portal'));
        exit;
      }

      $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
      $dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
      if ($dni === '') {
        casanova_render_payment_link_error(__('Debes indicar un documento de identidad o pasaporte.', 'casanova-portal'));
        exit;
      }

      $others_raw = isset($_POST['others_names']) ? (string)$_POST['others_names'] : '';
      $others_names = trim(sanitize_textarea_field($others_raw));
      $others_list = [];
      if ($others_names !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $others_names);
        foreach ($lines as $ln) {
          $ln = trim(sanitize_text_field($ln));
          if ($ln !== '') $others_list[] = $ln;
        }
      }

      if ($amount_to_pay <= 0.01) {
        casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
        exit;
      }

      if (!function_exists('casanova_payment_link_create')) {
        casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
        exit;
      }

      $selected_currency = isset($_POST['currency']) ? strtoupper(trim((string)$_POST['currency'])) : 'EUR';
      if ($selected_currency !== 'USD') {
        $selected_currency = 'EUR';
      }
      if ($selected_currency === 'USD' && !$usd_payment_enabled) {
        casanova_render_payment_link_error(__('Pago en USD no disponible.', 'casanova-portal'));
        exit;
      }

      $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
      if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
      if ($selected_currency === 'USD' || $disable_bank_transfer) {
        $selected_method = 'card';
      }
      if ($selected_method === 'bank_transfer' && !$inespay_enabled) $selected_method = 'card';
      if (function_exists('casanova_redsys_normalize_card_brand')) {
        $selected_card_brand = $selected_method === 'card'
          ? casanova_redsys_normalize_card_brand($_POST['card_brand'] ?? '')
          : 'other';
      } else {
        $selected_card_brand_raw = strtolower(trim((string)($_POST['card_brand'] ?? '')));
        $selected_card_brand = ($selected_method === 'card' && ($selected_card_brand_raw === 'amex' || $selected_card_brand_raw === 'american_express'))
          ? 'amex'
          : 'other';
      }
      if ($selected_currency === 'USD') {
        $selected_card_brand = 'other';
      }

      $expires_at = function_exists('casanova_payment_links_rest_expires_at')
        ? casanova_payment_links_rest_expires_at($idExpediente)
        : null;

      $link = casanova_payment_link_create([
        'id_expediente' => $idExpediente,
        'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
        'scope' => 'group_base',
        'amount_authorized' => $amount_to_pay,
        'currency' => 'EUR',
        'status' => 'active',
        'expires_at' => $expires_at,
        'created_by' => 'group_rest',
        'billing_dni' => $dni,
        'metadata' => [
          'mode' => 'rest',
          'units' => $units,
          'unit_total' => $rest_unit_total,
          'unit_deposit' => $rest_unit_deposit,
          'unit_rest' => $unit_rest,
          'concept_id' => $rest_concept_id,
          'concept_label' => $rest_concept_label,
          'concept_lines' => $rest_lines,
          'billing_name' => $billing_name,
          'billing_lastname' => $billing_lastname,
          'billing_fullname' => $billing_fullname,
          'billing_dni' => $dni,
          'billing_email' => $billing_email,
          'others_names' => $others_list,
          'group_token_id' => (int)$group->id,
          'id_reserva_pq' => $idReservaPQ,
          'preferred_method' => $selected_method,
          'preferred_card_brand' => $selected_card_brand,
          'preferred_currency' => $selected_currency,
          'offer_usd_payment' => $offer_usd_payment,
          'stripe_only' => $stripe_only,
          'disable_bank_transfer' => $disable_bank_transfer,
          'auto_start' => true,
          'total_due' => $total_due,
          'deposit_total' => $deposit_total,
          'remaining' => $amount_to_pay,
          'origin' => 'group_rest',
          'locale' => $public_locale,
        ],
      ]);

      if (is_wp_error($link)) {
        casanova_render_payment_link_error($link->get_error_message());
        exit;
      }

      $url = add_query_arg(['autostart' => '1', 'mode' => 'full'], casanova_payment_link_url((string)($link->token ?? '')));
      if (function_exists('casanova_portal_add_public_locale_arg')) {
        $url = casanova_portal_add_public_locale_arg($url, $public_locale);
      }
      wp_safe_redirect($url);
      exit;
    } else {
      if ($main_available_units <= 0) {
        casanova_render_payment_link_error(__('Ya no quedan personas pendientes para este enlace de grupo.', 'casanova-portal'));
        exit;
      }

    $name_raw = isset($_POST['billing_name']) ? (string)$_POST['billing_name'] : '';
    $lastname_raw = isset($_POST['billing_lastname']) ? (string)$_POST['billing_lastname'] : '';
    $billing_name = trim(sanitize_text_field($name_raw));
    $billing_lastname = trim(sanitize_text_field($lastname_raw));
    if ($billing_name === '' || $billing_lastname === '') {
      casanova_render_payment_link_error(__('Debes indicar Nombre y Apellidos.', 'casanova-portal'));
      exit;
    }
    $billing_fullname = trim($billing_name . ' ' . $billing_lastname);

    $email_raw = isset($_POST['billing_email']) ? (string)$_POST['billing_email'] : '';
    $billing_email = trim(sanitize_email($email_raw));
    if ($billing_email === '' || !is_email($billing_email)) {
      casanova_render_payment_link_error(__('Debes indicar un email válido.', 'casanova-portal'));
      exit;
    }


    $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
    $dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
    if ($dni === '') {
      casanova_render_payment_link_error(__('Debes indicar un documento de identidad o pasaporte.', 'casanova-portal'));
      exit;
    }

    $others_raw = isset($_POST['others_names']) ? (string)$_POST['others_names'] : '';
    $others_names = trim(sanitize_textarea_field($others_raw));
    $others_list = [];
    if ($others_names !== '') {
      $lines = preg_split('/\r\n|\r|\n/', $others_names);
      foreach ($lines as $ln) {
        $ln = trim(sanitize_text_field($ln));
        if ($ln !== '') $others_list[] = $ln;
      }
    }

    $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
    if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
    if (!$deposit_allowed) $mode = 'full';

    // Un mismo pago puede cubrir varios conceptos a la vez (jugador + no jugador).
    $pay_quantities = casanova_group_pay_quantities_from_request($group_concepts, 'concept_qty', $_POST, 'concept_id', 'units');
    $pay_lines = [];
    $units = 0;
    $total_due = 0.0;
    $deposit_total = 0.0;

    foreach ($group_concepts as $concept) {
      $cid = trim((string)($concept['id'] ?? ''));
      $qty = (int)($pay_quantities[$cid] ?? 0);
      if ($cid === '' || $qty <= 0) continue;

      $line_unit_total = round((float)($concept['unit_total'] ?? 0), 2);
      if ($line_unit_total <= 0.0) continue;

      $line_unit_deposit = ($mode === 'deposit') ? casanova_group_pay_concept_deposit($line_unit_total, $idExpediente) : 0.0;

      $pay_lines[] = [
        'id' => $cid,
        'label' => trim((string)($concept['label'] ?? '')),
        'unit_total' => $line_unit_total,
        'unit_deposit' => $line_unit_deposit,
        'units' => $qty,
      ];
      $units += $qty;
      $total_due += round($line_unit_total * (float)$qty, 2);
      $deposit_total += round($line_unit_deposit * (float)$qty, 2);
    }

    if (empty($pay_lines) || $units <= 0) {
      casanova_render_payment_link_error(__('Debes seleccionar al menos 1 persona.', 'casanova-portal'));
      exit;
    }
    if ($units > $main_available_units) {
      casanova_render_payment_link_error(sprintf(
        __('Este enlace solo admite %d personas más. Ajusta las cantidades e inténtalo de nuevo.', 'casanova-portal'),
        $main_available_units
      ));
      exit;
    }

    $total_due = round($total_due, 2);
    $deposit_total = round($deposit_total, 2);
    $deposit_effective = ($mode === 'deposit') && ($deposit_total > 0.01) && ($deposit_total + 0.01 < $total_due);
    if ($mode === 'deposit' && !$deposit_effective) {
      $mode = 'full';
      $deposit_total = 0.0;
      foreach (array_keys($pay_lines) as $idx) {
        $pay_lines[$idx]['unit_deposit'] = 0.0;
      }
    }

    $pay_concept_id = (count($pay_lines) === 1) ? (string)$pay_lines[0]['id'] : 'mixto';
    $pay_concept_label = casanova_group_pay_lines_label($pay_lines);
    $pay_unit_total = round($total_due / (float)$units, 2);
    $unit_deposit = round($deposit_total / (float)$units, 2);

    $amount_to_pay = ($mode === 'deposit') ? $deposit_total : $total_due;
    if ($amount_to_pay <= 0.01) {
      casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
      exit;
    }

    if (!function_exists('casanova_payment_link_create')) {
      casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
      exit;
    }

    $selected_currency = isset($_POST['currency']) ? strtoupper(trim((string)$_POST['currency'])) : 'EUR';
    if ($selected_currency !== 'USD') {
      $selected_currency = 'EUR';
    }
    if ($selected_currency === 'USD' && !$usd_payment_enabled) {
      casanova_render_payment_link_error(__('Pago en USD no disponible.', 'casanova-portal'));
      exit;
    }

    $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
    if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
    if ($selected_currency === 'USD' || $disable_bank_transfer) {
      $selected_method = 'card';
    }
    if ($selected_method === 'bank_transfer' && !$inespay_enabled) $selected_method = 'card';
    if (function_exists('casanova_redsys_normalize_card_brand')) {
      $selected_card_brand = $selected_method === 'card'
        ? casanova_redsys_normalize_card_brand($_POST['card_brand'] ?? '')
        : 'other';
    } else {
      $selected_card_brand_raw = strtolower(trim((string)($_POST['card_brand'] ?? '')));
      $selected_card_brand = ($selected_method === 'card' && ($selected_card_brand_raw === 'amex' || $selected_card_brand_raw === 'american_express'))
        ? 'amex'
        : 'other';
    }
    if ($selected_currency === 'USD') {
      $selected_card_brand = 'other';
    }

    $link = casanova_payment_link_create([
      'id_expediente' => $idExpediente,
      'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
      'scope' => 'group_base',
      'amount_authorized' => $amount_to_pay,
      'currency' => 'EUR',
      'status' => 'active',
      'created_by' => 'group',
      'billing_dni' => $dni,
      'metadata' => [
        'mode' => $mode,
        'units' => $units,
        'unit_total' => $pay_unit_total,
        'unit_deposit' => $unit_deposit,
        'concept_id' => $pay_concept_id,
        'concept_label' => $pay_concept_label,
        'concept_lines' => $pay_lines,
        'billing_name' => $billing_name,
        'billing_lastname' => $billing_lastname,
        'billing_fullname' => $billing_fullname,
        'billing_dni' => $dni,
        'billing_email' => $billing_email,
        'others_names' => $others_list,
        'group_token_id' => (int)$group->id,
        'id_reserva_pq' => $idReservaPQ,
        'preferred_method' => $selected_method,
        'preferred_card_brand' => $selected_card_brand,
        'preferred_currency' => $selected_currency,
        'offer_usd_payment' => $offer_usd_payment,
        'stripe_only' => $stripe_only,
        'disable_bank_transfer' => $disable_bank_transfer,
        'auto_start' => true,
        'total_due' => $total_due,
        'deposit_total' => ($mode === 'deposit') ? $deposit_total : 0,
        'locale' => $public_locale,
      ],
    ]);

    if (is_wp_error($link)) {
      casanova_render_payment_link_error($link->get_error_message());
      exit;
    }

    $url = add_query_arg(['autostart' => '1'], casanova_payment_link_url((string)($link->token ?? '')));
    if (function_exists('casanova_portal_add_public_locale_arg')) {
      $url = casanova_portal_add_public_locale_arg($url, $public_locale);
    }
    wp_safe_redirect($url);
    exit;
    }
  }

  $exp_label = '';
  $exp_codigo = '';
  if (function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, $idExpediente);
    $exp_label = trim((string)($meta['label'] ?? ''));
    $exp_codigo = trim((string)($meta['codigo'] ?? ''));
  }
  if ($exp_label === '') $exp_label = sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente);

  $deadline_txt = '';
  if (function_exists('casanova_payments_min_fecha_limite')) {
    $deadline = casanova_payments_min_fecha_limite($reservas);
    if ($deadline instanceof DateTimeInterface) {
      $deadline_txt = $deadline->format('d/m/Y');
    }
  }

  $unit_deposit_preview = $deposit_allowed ? $unit_deposit_configured : 0.0;
  $concept_public = [];
  $rest_available_units = 0;
  $unit_rest_preview = 0.0;
  foreach ($group_concepts as $concept) {
    $cid = (string)($concept['id'] ?? 'default');
    $c_label = (string)($concept['label'] ?? '');
    $c_unit_total = round((float)($concept['unit_total'] ?? 0), 2);
    if ($c_unit_total <= 0.0) continue;

    $c_deposit_configured = casanova_group_pay_concept_deposit($c_unit_total, $idExpediente);
    $c_status = casanova_group_pay_rest_status($idExpediente, (int)$group->id, $idReservaPQ, $cid, $allow_related_group_links);
    $c_rest_unit_deposit = round((float)($c_status['unit_deposit'] ?? 0), 2);
    if ($c_rest_unit_deposit <= 0.0) $c_rest_unit_deposit = $c_deposit_configured;
    $c_unit_rest = round(max(0.0, $c_unit_total - $c_rest_unit_deposit), 2);
    $c_available = max(0, (int)($c_status['available_units'] ?? 0));

    if ($c_available > 0 && $rest_available_units <= 0) {
      $unit_rest_preview = $c_unit_rest;
    }
    $rest_available_units += $c_available;

    $concept_public[] = [
      'id' => $cid,
      'label' => $c_label,
      'unit_total' => $c_unit_total,
      'unit_deposit' => $deposit_allowed ? $c_deposit_configured : 0.0,
      'configured_deposit' => $c_deposit_configured,
      'unit_rest' => $c_unit_rest,
      'rest_available_units' => $c_available,
    ];
  }
  if ($unit_rest_preview <= 0.0 && !empty($concept_public)) {
    $unit_rest_preview = round((float)($concept_public[0]['unit_rest'] ?? 0), 2);
  }
  $rest_stage_open = isset($_GET['stage']) && sanitize_key((string)$_GET['stage']) === 'rest';

  $default_amount = $deposit_allowed && $unit_deposit_preview > 0.009 && $unit_deposit_preview + 0.01 < $unit_total
    ? $unit_deposit_preview
    : $unit_total;
  $transfer_note = __('El pago por transferencia bancaria no tiene recargo y es totalmente seguro. Te llevaremos a una página donde podrás elegir tu banco y autorizar la transferencia desde tu banca online. Al terminar, volverás automáticamente aquí. Compatible con la mayoría de bancos españoles y portugueses.', 'casanova-portal');
  $group_page_url = function_exists('casanova_group_pay_url') ? casanova_group_pay_url((string)$group->token) : home_url('/');
  if (function_exists('casanova_portal_add_public_locale_arg')) {
    $group_page_url = casanova_portal_add_public_locale_arg($group_page_url, $public_locale);
  }
  $selector_html = function_exists('casanova_portal_public_language_selector_html')
    ? casanova_portal_public_language_selector_html($group_page_url)
    : '';
  $public_locale_tag = function_exists('casanova_portal_normalize_locale_tag')
    ? casanova_portal_normalize_locale_tag($public_locale)
    : str_replace('_', '-', $public_locale);
  if ($public_locale_tag === '') {
    $public_locale_tag = 'es-ES';
  }
  $js_summary_amount_template = sprintf(__('Importe por persona: %s EUR', 'casanova-portal'), '__AMOUNT__');
  $js_people_label = __('Personas incluidas en este pago', 'casanova-portal');
  $js_deposit_label = __('Depósito', 'casanova-portal');
  $js_total_label = __('Total', 'casanova-portal');
  $js_pay_label = __('Pagar', 'casanova-portal');
  $js_rest_amount_template = sprintf(__('Importe restante por persona: %s EUR', 'casanova-portal'), '__AMOUNT__');
  $js_pay_rest_label = __('Pagar resto', 'casanova-portal');
  $js_min_units_message = __('Selecciona al menos 1 persona.', 'casanova-portal');
  $js_max_units_template = sprintf(__('Este enlace solo admite %s personas más.', 'casanova-portal'), '__UNITS__');

  $nonce = function_exists('casanova_pay_csrf_token')
    ? casanova_pay_csrf_token('group_pay', (string)$group->token)
    : wp_create_nonce('casanova_group_pay_' . (int)$group->id);
  $euro_symbol = html_entity_decode('&euro;', ENT_QUOTES, 'UTF-8');

  casanova_portal_render_public_document_start(__('Pago del viaje', 'casanova-portal'));
  echo '<section class="casanova-public-page">';
  if ($selector_html !== '') {
    echo '<div class="casanova-public-page__toolbar">' . $selector_html . '</div>';
  }
  echo casanova_portal_public_logo_html();
  echo '<h2 class="casanova-public-page__title">' . esc_html__('Pago del viaje', 'casanova-portal') . '</h2>';
  echo '<p class="casanova-public-page__intro">' . esc_html__('Selecciona cuántas personas quedan incluidas en este pago y completa los datos del pagador.', 'casanova-portal') . '</p>';
  $codigo_html = $exp_codigo ? ' <span class="casanova-public-page__code">(' . esc_html($exp_codigo) . ')</span>' : '';
  echo '<p class="casanova-public-page__trip">' . wp_kses_post(
    sprintf(__('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'), esc_html($exp_label), $codigo_html)
  ) . '</p>';

  echo '<div class="casanova-public-page__summary">';
  if ($has_group_concepts) {
    $concept_lines = [];
    foreach ($concept_public as $concept) {
      $concept_lines[] = trim((string)($concept['label'] ?? '')) . ': ' . number_format_i18n((float)($concept['unit_total'] ?? 0), 2) . ' EUR';
    }
    echo '<div class="casanova-public-page__summary-line">' . esc_html__('Opciones de precio:', 'casanova-portal') . '</div>';
    foreach ($concept_lines as $line) {
      echo '<div class="casanova-public-page__summary-line">' . esc_html($line) . '</div>';
    }
  } else {
    echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Importe por persona: %s EUR', 'casanova-portal'), number_format_i18n($unit_total, 2))) . '</div>';
  }
  if ($deadline_txt !== '') {
    echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Fecha límite del depósito: %s', 'casanova-portal'), $deadline_txt)) . '</div>';
  }
  echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Personas pendientes de pago inicial: %d de %d', 'casanova-portal'), $main_available_units, $group_units_limit)) . '</div>';
  echo '</div>';

  if ($flash_msg !== '') {
    $notice_class = 'casanova-public-page__notice casanova-public-page__notice--info';
    if ($flash_type === 'success') {
      $notice_class = 'casanova-public-page__notice casanova-public-page__notice--success';
    } elseif ($flash_type === 'error') {
      $notice_class = 'casanova-public-page__notice casanova-public-page__notice--error';
    }
    echo '<div class="' . esc_attr($notice_class) . '">' . esc_html($flash_msg) . '</div>';
  }

  $show_resend_magic_fallback = $unit_rest_preview > 0.01 && $rest_available_units <= 0;
  if ($show_resend_magic_fallback) {
  echo '<details class="casanova-public-page__disclosure">';
  echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('Ya pagué el depósito y no veo el pago restante', 'casanova-portal') . '</summary>';
  echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Usa el mismo email y documento o pasaporte con el que pagaste el depósito. Si encontramos el depósito, te reenviaremos el enlace para continuar.', 'casanova-portal') . '</div>';
  echo '<form class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="resend_magic" />';
  echo '<div class="casanova-public-form__grid">';
  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>'
    . '<input class="casanova-public-field__control" type="email" name="resend_email" autocomplete="email" required value="" />'
    . '</label>';
  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Documento / pasaporte', 'casanova-portal') . '</span>'
    . '<input class="casanova-public-field__control" type="text" name="resend_dni" autocomplete="tax-id" required value="" />'
    . '</label>';
  echo '</div>';
  echo '<div class="casanova-public-page__actions">';
  echo '<button class="casanova-public-button casanova-public-button--ghost" type="submit">' . esc_html__('Reenviar enlace', 'casanova-portal') . '</button>';
  echo '</div>';
  echo '</form>';
  echo '</details>';
  }

  echo '<style>
    .casanova-group-wizard{border:1px solid #d9e5df;border-radius:18px;padding:18px;box-shadow:0 18px 45px rgba(15,23,42,.08)}
    .casanova-group-step[hidden]{display:none!important}
    .casanova-group-step-indicator{font-weight:600;margin:-2px 0 18px;color:#fff;background:linear-gradient(135deg,#0b3f35 0%,#13715f 100%);border-radius:14px;padding:14px 16px}
    .casanova-group-step-title{font-size:1.14rem;font-weight:600;margin:0 0 14px;color:#0f172a}
    .casanova-group-live-summary{margin:14px 0;padding:14px 16px;border:1px solid #cfe3d9;border-radius:14px;background:#f3faf6;color:#0f3d32;font-weight:700}
    .casanova-group-live-summary:empty{display:none}
    .casanova-group-step-actions{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:18px}
    .casanova-group-step-actions .casanova-public-button{width:auto}
    .casanova-group-step-actions--final{justify-content:space-between}
    @media (max-width:640px){.casanova-group-step-actions{flex-direction:column;align-items:stretch}.casanova-group-step-actions .casanova-public-button{width:100%}}
    .casanova-public-button--loading{pointer-events:none;opacity:.75}
  </style>';

  if ($unit_rest_preview > 0.01 && $rest_available_units > 0) {
    $rest_details_attr = $rest_stage_open ? ' open' : '';
    echo '<details id="casanova-group-rest" class="casanova-public-page__disclosure"' . $rest_details_attr . '>';
    echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('Quiero pagar el resto del viaje', 'casanova-portal') . '</summary>';
    echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Si ya hay depósitos pagados, cada persona puede pagar su parte restante desde aquí.', 'casanova-portal') . '</div>';

    if ($rest_available_units > 0) {
      $rest_options = array_values(array_filter($concept_public, function ($concept) {
        return (int)($concept['rest_available_units'] ?? 0) > 0;
      }));
      $rest_has_choices = count($rest_options) > 1;

      echo '<div class="casanova-public-page__summary">';
      if ($rest_has_choices) {
        echo '<div class="casanova-public-page__summary-line">' . esc_html__('Importe restante por persona:', 'casanova-portal') . '</div>';
        foreach ($rest_options as $concept) {
          echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(
            '%s: %s EUR (%d pendientes)',
            trim((string)($concept['label'] ?? '')),
            number_format_i18n((float)($concept['unit_rest'] ?? 0), 2),
            (int)($concept['rest_available_units'] ?? 0)
          )) . '</div>';
        }
      } else {
        echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Importe restante por persona: %s EUR', 'casanova-portal'), number_format_i18n($unit_rest_preview, 2))) . '</div>';
      }
      echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Personas con depósito pendiente de resto: %d', 'casanova-portal'), $rest_available_units)) . '</div>';
      echo '</div>';

      echo '<form id="casanova-group-rest-form" class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '" novalidate>';
      echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
      echo '<input type="hidden" name="action" value="pay_rest" />';
      echo '<div class="casanova-group-wizard">';
      echo '<div class="casanova-group-step-indicator" aria-live="polite"></div>';

      echo '<div class="casanova-group-step" data-step-title="' . esc_attr__('Opción y personas', 'casanova-portal') . '">';
      echo '<div class="casanova-group-step-title">' . esc_html__('Elige qué parte quieres pagar', 'casanova-portal') . '</div>';
      if ($rest_has_choices) {
        echo '<div class="casanova-public-section-label">' . esc_html__('Indica cuántas personas pagas de cada opción', 'casanova-portal') . '</div>';
      }
      $rest_first_option = true;
      foreach ($rest_options as $concept) {
        $cid = trim((string)($concept['id'] ?? ''));
        if ($cid === '') continue;
        $available = max(1, (int)($concept['rest_available_units'] ?? 0));
        $field_label = $rest_has_choices
          ? sprintf('%s - %s EUR (%d pendientes)', trim((string)($concept['label'] ?? '')), number_format_i18n((float)($concept['unit_total'] ?? 0), 2), $available)
          : __('Personas incluidas en este pago restante', 'casanova-portal');
        $min_qty = $rest_has_choices ? 0 : 1;
        $default_qty = $rest_first_option ? 1 : 0;

        echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html($field_label) . '</span>';
        echo '<select class="casanova-public-field__control" name="rest_concept_qty[' . esc_attr($cid) . ']" data-rest-concept-qty="' . esc_attr($cid) . '" required>';
        for ($i = $min_qty; $i <= $available; $i++) {
          echo '<option value="' . esc_attr((string)$i) . '"' . selected($i, $default_qty, false) . '>' . esc_html((string)$i) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        $rest_first_option = false;
      }
      echo '<div id="casanova-group-rest-option-summary" class="casanova-group-live-summary"></div>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombres de viajeros (opcional)', 'casanova-portal') . '</span>';
      echo '<textarea class="casanova-public-field__control" name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2"></textarea>';
      echo '<span class="casanova-public-field__hint">' . esc_html__('Solo para referencia de la agencia. 1 nombre por línea.', 'casanova-portal') . '</span>';
      echo '</label>';
      echo '<div class="casanova-group-step-actions">';
      echo '<span></span>';
      echo '<button class="casanova-public-button" type="button" data-wizard-next>' . esc_html__('Continuar', 'casanova-portal') . '</button>';
      echo '</div>';
      echo '</div>';

      echo '<div class="casanova-group-step" data-step-title="' . esc_attr__('Datos del pagador', 'casanova-portal') . '" hidden>';
      echo '<div class="casanova-group-step-title">' . esc_html__('Datos del pagador', 'casanova-portal') . '</div>';
      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombre', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_name" autocomplete="given-name" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Apellidos', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_lastname" autocomplete="family-name" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="email" name="billing_email" autocomplete="email" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Documento de identidad / pasaporte (obligatorio)', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_dni" autocomplete="tax-id" required value="" />';
      echo '<span class="casanova-public-field__hint">' . esc_html__('DNI/NIE, pasaporte o documento nacional.', 'casanova-portal') . '</span>';
      echo '</label>';
      echo '<div class="casanova-group-step-actions">';
      echo '<button class="casanova-public-button casanova-public-button--ghost" type="button" data-wizard-prev>' . esc_html__('Atrás', 'casanova-portal') . '</button>';
      echo '<button class="casanova-public-button" type="button" data-wizard-next>' . esc_html__('Continuar', 'casanova-portal') . '</button>';
      echo '</div>';
      echo '</div>';

      echo '<div class="casanova-group-step" data-step-title="' . esc_attr__('Método y confirmación', 'casanova-portal') . '" hidden>';
      echo '<div class="casanova-group-step-title">' . esc_html__('Método de pago y confirmación', 'casanova-portal') . '</div>';
      if ($usd_payment_enabled) {
        echo '<div class="casanova-public-section-label">' . esc_html__('Moneda de pago', 'casanova-portal') . '</div>';
        echo '<div class="casanova-public-form__grid casanova-public-choice-group" id="casanova-rest-currency-wrap">';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="currency" value="EUR" checked />EUR';
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago en euros con las opciones habituales.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="currency" value="USD" />USD';
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago con tarjeta.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '</div>';
      } else {
        echo '<input type="hidden" name="currency" value="EUR" />';
      }
      echo '<div class="casanova-public-section-label" id="casanova-rest-method-label">' . esc_html__('Método de pago', 'casanova-portal') . '</div>';
      if ($inespay_enabled) {
        echo '<div class="casanova-public-form__grid casanova-public-choice-group" id="casanova-rest-method-wrap">';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="method" value="card" checked />' . esc_html__('Tarjeta', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="method" value="bank_transfer" />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Sin recargo.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '</div>';
        echo '<div id="casanova-rest-method-note" class="casanova-public-page__method-note casanova-hidden">' . esc_html($transfer_note) . '</div>';
      } else {
        echo '<input type="hidden" name="method" value="card" />';
        echo '<div class="casanova-public-field__hint">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
      }

      if ($stripe_only) {
        echo '<input type="hidden" name="card_brand" value="other" />';
      } else {
        echo '<div id="casanova-rest-card-brand-wrap">';
        echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
        echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" checked />' . esc_html__('Otra tarjeta', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" />' . esc_html__('American Express (AMEX)', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opción si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '</div>';
        echo '</div>';
      }

      echo '<div id="casanova-group-rest-summary" class="casanova-public-page__summary"></div>';
      echo '<div class="casanova-group-step-actions casanova-group-step-actions--final">';
      echo '<button class="casanova-public-button casanova-public-button--ghost" type="button" data-wizard-prev>' . esc_html__('Atrás', 'casanova-portal') . '</button>';
      echo '<button id="casanova-group-rest-button" class="casanova-public-button" type="submit">'
        . esc_html(sprintf(__('Pagar resto %s %s', 'casanova-portal'), number_format_i18n($unit_rest_preview, 2), $euro_symbol))
        . '</button>';
      echo '</div>';
      echo '</div>';
      echo '</div>';
      echo '</form>';
    } else {
      echo '<div class="casanova-public-page__notice">' . esc_html__('Todavía no hay depósitos registrados con pago restante pendiente para este enlace.', 'casanova-portal') . '</div>';
    }
    echo '</details>';
  }

  $wrap_main_payment_form = $rest_stage_open && $rest_available_units > 0;
  if ($wrap_main_payment_form) {
    echo '<details class="casanova-public-page__disclosure">';
    echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('Necesito hacer otro pago: depósito o total', 'casanova-portal') . '</summary>';
    echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Usa esta opción solo si todavía no has pagado el depósito o si quieres pagar el viaje completo.', 'casanova-portal') . '</div>';
  }

  if ($main_available_units > 0) {
  $main_step_order = $deposit_allowed ? 'option,mode,payer,method' : 'option,payer,method';
  echo '<form id="casanova-group-pay-form" class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '" novalidate data-step-order="' . esc_attr($main_step_order) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="pay" />';
  if (!$deposit_allowed) {
    echo '<input type="hidden" name="mode" value="full" />';
  }

  echo '<div class="casanova-group-wizard">';
  echo '<div class="casanova-group-step-indicator" aria-live="polite"></div>';

  echo '<div class="casanova-group-step" data-step-key="payer" data-step-title="' . esc_attr__('Datos del pagador', 'casanova-portal') . '" hidden>';
  echo '<div class="casanova-group-step-title">' . esc_html__('Datos del pagador', 'casanova-portal') . '</div>';
  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombre', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_name" autocomplete="given-name" required value="" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Apellidos', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_lastname" autocomplete="family-name" required value="" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="email" name="billing_email" autocomplete="email" required value="" />';
  echo '</label>';


  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Documento de identidad / pasaporte (obligatorio)', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_dni" autocomplete="tax-id" required value="" />';
  echo '<span class="casanova-public-field__hint">' . esc_html__('DNI/NIE, pasaporte o documento nacional.', 'casanova-portal') . '</span>';
  echo '</label>';
  echo '<div class="casanova-group-step-actions">';
  echo '<button class="casanova-public-button casanova-public-button--ghost" type="button" data-wizard-prev>' . esc_html__('Atrás', 'casanova-portal') . '</button>';
  echo '<button class="casanova-public-button" type="button" data-wizard-next>' . esc_html__('Continuar', 'casanova-portal') . '</button>';
  echo '</div>';
  echo '</div>';

  echo '<div class="casanova-group-step" data-step-key="method" data-step-title="' . esc_attr__('Método y confirmación', 'casanova-portal') . '" hidden>';
  echo '<div class="casanova-group-step-title">' . esc_html__('Método de pago', 'casanova-portal') . '</div>';

  if ($usd_payment_enabled) {
    echo '<div class="casanova-public-section-label">' . esc_html__('Moneda de pago', 'casanova-portal') . '</div>';
    echo '<div class="casanova-public-form__grid casanova-public-choice-group" id="casanova-currency-wrap">';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="currency" value="EUR" checked />EUR';
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago en euros con las opciones habituales.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="currency" value="USD" />USD';
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago con tarjeta.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
  } else {
    echo '<input type="hidden" name="currency" value="EUR" />';
  }
  echo '<div class="casanova-public-section-label" id="casanova-method-label">' . esc_html__('Método de pago', 'casanova-portal') . '</div>';
  if ($inespay_enabled) {
    echo '<div class="casanova-public-form__grid casanova-public-choice-group" id="casanova-method-wrap">';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="card" checked />' . esc_html__('Tarjeta', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="bank_transfer" />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Sin recargo.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
    echo '<div id="casanova-method-note" class="casanova-public-page__method-note casanova-hidden">' . esc_html($transfer_note) . '</div>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div class="casanova-public-field__hint">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  if ($stripe_only) {
    echo '<input type="hidden" name="card_brand" value="other" />';
  } else {
    echo '<div id="casanova-card-brand-wrap">';
    echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
    echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" checked />' . esc_html__('Otra tarjeta', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" />' . esc_html__('American Express (AMEX)', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opción si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
    echo '<div class="casanova-public-field__hint">' . esc_html__('Elige con qué tarjeta quieres realizar el pago.', 'casanova-portal') . '</div>';
    echo '</div>';
  }

  echo '</div>';

  echo '<div class="casanova-group-step" data-step-key="option" data-step-title="' . esc_attr__('Opción y personas', 'casanova-portal') . '" hidden>';
  echo '<div class="casanova-group-step-title">' . esc_html__('Elige tu opción y personas incluidas', 'casanova-portal') . '</div>';

  $has_concept_choices = count($concept_public) > 1;
  if ($has_concept_choices) {
    echo '<div class="casanova-public-section-label">' . esc_html__('Indica cuántas personas pagas de cada opción', 'casanova-portal') . '</div>';
  }
  $first_option = true;
  foreach ($concept_public as $concept) {
    $cid = trim((string)($concept['id'] ?? ''));
    if ($cid === '') continue;
    $field_label = $has_concept_choices
      ? trim((string)($concept['label'] ?? '')) . ' - ' . number_format_i18n((float)($concept['unit_total'] ?? 0), 2) . ' EUR'
      : __('Personas incluidas en este pago', 'casanova-portal');
    $min_qty = $has_concept_choices ? 0 : 1;
    $default_qty = $first_option ? 1 : 0;

    echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html($field_label) . '</span>';
    echo '<select class="casanova-public-field__control" name="concept_qty[' . esc_attr($cid) . ']" data-concept-qty="' . esc_attr($cid) . '" required>';
    for ($i = $min_qty; $i <= $main_available_units; $i++) {
      echo '<option value="' . esc_attr((string)$i) . '"' . selected($i, $default_qty, false) . '>' . esc_html((string)$i) . '</option>';
    }
    echo '</select>';
    echo '</label>';
    $first_option = false;
  }
  if ($has_concept_choices) {
    echo '<div class="casanova-public-field__hint">' . esc_html__('Puedes combinar varias opciones en un mismo pago.', 'casanova-portal') . '</div>';
  }
  echo '<div id="casanova-group-option-summary" class="casanova-group-live-summary"></div>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombres de viajeros (opcional)', 'casanova-portal') . '</span>';
  echo '<textarea class="casanova-public-field__control" name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2"></textarea>';
  echo '<span class="casanova-public-field__hint">' . esc_html__('Solo para referencia de la agencia. 1 nombre por línea.', 'casanova-portal') . '</span>';
  echo '</label>';

  echo '<div class="casanova-group-step-actions">';
  echo '<span></span>';
  echo '<button class="casanova-public-button" type="button" data-wizard-next>' . esc_html__('Continuar', 'casanova-portal') . '</button>';
  echo '</div>';
  echo '</div>';

  if ($deposit_allowed) {
    echo '<div class="casanova-group-step" data-step-key="mode" data-step-title="' . esc_attr__('Tipo de pago', 'casanova-portal') . '">';
    echo '<div class="casanova-group-step-title">' . esc_html__('¿Qué quieres pagar?', 'casanova-portal') . '</div>';
    echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="deposit" />' . esc_html__('Pagar depósito', 'casanova-portal') . ' <strong class="casanova-group-mode-amount" data-mode="deposit"></strong>';
    echo '</label>';
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="full" checked />' . esc_html__('Pagar total', 'casanova-portal') . ' <strong class="casanova-group-mode-amount" data-mode="full"></strong>';
    echo '</label>';
    echo '</div>';
    echo '<div class="casanova-group-step-actions">';
    echo '<button class="casanova-public-button casanova-public-button--ghost" type="button" data-wizard-prev>' . esc_html__('Atrás', 'casanova-portal') . '</button>';
    echo '<button class="casanova-public-button" type="button" data-wizard-next>' . esc_html__('Continuar', 'casanova-portal') . '</button>';
    echo '</div>';
    echo '</div>';
  }

  echo '<div class="casanova-group-step" data-step-key="method" hidden>';
  echo '<div id="casanova-group-summary" class="casanova-public-page__summary"></div>';
  echo '<div class="casanova-group-step-actions casanova-group-step-actions--final">';
  echo '<button class="casanova-public-button casanova-public-button--ghost" type="button" data-wizard-prev>' . esc_html__('Atrás', 'casanova-portal') . '</button>';
  echo '<button id="casanova-group-pay-button" class="casanova-public-button" type="submit">'
    . esc_html(sprintf(__('Pagar %s %s', 'casanova-portal'), number_format_i18n($default_amount, 2), $euro_symbol))
    . '</button>';
  echo '</div>';
  echo '</div>';
  echo '</div>';

  echo '</form>';
  } else {
    echo '<div class="casanova-public-page__notice">' . esc_html__('Este enlace ya tiene cubiertas todas las personas del grupo.', 'casanova-portal') . '</div>';
  }
  if ($wrap_main_payment_form) {
    echo '</details>';
  }
  echo '<p class="casanova-public-page__footer">' . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal') . '</p>';
  echo '<script>
    (function(){
      const mainAvailableUnits = ' . wp_json_encode((int)$main_available_units) . ';
      const concepts = ' . wp_json_encode($concept_public) . ';
      const locale = ' . wp_json_encode($public_locale_tag) . ';
      const minUnitsMessage = ' . wp_json_encode($js_min_units_message) . ';
      const maxUnitsTemplate = ' . wp_json_encode($js_max_units_template) . ';
      const amountTemplate = ' . wp_json_encode($js_summary_amount_template) . ';
      const restAmountTemplate = ' . wp_json_encode($js_rest_amount_template) . ';
      const peopleLabel = ' . wp_json_encode($js_people_label) . ';
      const depositLabel = ' . wp_json_encode($js_deposit_label) . ';
      const totalLabel = ' . wp_json_encode($js_total_label) . ';
      const payLabel = ' . wp_json_encode($js_pay_label) . ';
      const payRestLabel = ' . wp_json_encode($js_pay_rest_label) . ';
      const processingLabel = ' . wp_json_encode(__('Procesando, redirigiendo al pago...', 'casanova-portal')) . ';
      function casanovaPaySubmitLoading(formEl, btnId){
        if (!formEl) return;
        formEl.addEventListener("submit", function(ev){
          if (ev.defaultPrevented) return;
          var b = document.getElementById(btnId);
          if (b){ b.textContent = processingLabel; b.classList.add("casanova-public-button--loading"); b.setAttribute("aria-busy", "true"); }
        });
      }
      const usdEnabled = ' . wp_json_encode((bool)$usd_payment_enabled) . ';
      const usdRate = ' . wp_json_encode((float)$usd_rate) . ';
      const usdGrossUp = ' . wp_json_encode((float)$usd_gross_up) . ';

      function fmt(n){
        if (!isFinite(n)) n = 0;
        try {
          return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
        } catch (e) {
          return String(Math.round(n * 100) / 100);
        }
      }

      function usdAmount(eurAmount){
        const eur = Number(eurAmount || 0);
        if (!usdEnabled || !isFinite(eur) || !isFinite(usdRate) || !isFinite(usdGrossUp) || usdRate <= 0 || usdGrossUp < 0 || usdGrossUp >= 1) return 0;
        return Math.ceil(((eur * usdRate) / (1 - usdGrossUp)) * 100) / 100;
      }

      function fmtUsd(n){
        if (!isFinite(n)) n = 0;
        try {
          return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(n) + " USD";
        } catch (e) {
          return "$" + (Math.round(n * 100) / 100).toFixed(2) + " USD";
        }
      }

      function displayAmount(eurAmount, currency){
        return currency === "USD" ? fmtUsd(usdAmount(eurAmount)) : fmt(eurAmount) + " EUR";
      }

      function escapeHtml(value){
        return String(value).replace(/[&<>"\']/g, function(ch){
          return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#039;" })[ch] || ch;
        });
      }

      const conceptById = {};
      Array.prototype.forEach.call(concepts || [], function(c){
        conceptById[String(c.id || "")] = c;
      });

      // Un pago puede mezclar conceptos: leemos la cantidad elegida en cada uno.
      function readCart(selects, attrName){
        const lines = [];
        let units = 0;
        selects.forEach(function(select){
          const qty = parseInt(select.value || "0", 10);
          if (!isFinite(qty) || qty <= 0) return;
          const concept = conceptById[String(select.getAttribute(attrName) || "")];
          if (!concept) return;
          lines.push({ concept: concept, units: qty });
          units += qty;
        });
        return { lines: lines, units: units };
      }

      function cartAmount(cart, field){
        let total = 0;
        cart.lines.forEach(function(line){
          total += Math.round(Number(line.concept[field] || 0) * line.units * 100) / 100;
        });
        return Math.round(total * 100) / 100;
      }

      function cartLineSummaries(cart, field, currency){
        return cart.lines.map(function(line){
          const amount = Math.round(Number(line.concept[field] || 0) * line.units * 100) / 100;
          const label = String(line.concept.label || "");
          const prefix = label !== "" ? (label + " x" + String(line.units)) : (peopleLabel + ": " + String(line.units));
          return prefix + " - " + displayAmount(amount, currency);
        });
      }

      function setCartValidity(selects, cart, maxUnits){
        if (!selects.length) return true;
        let message = "";
        if (cart.units < 1) {
          message = minUnitsMessage;
        } else if (maxUnits > 0 && cart.units > maxUnits) {
          message = maxUnitsTemplate.replace("__UNITS__", String(maxUnits));
        }
        selects[0].setCustomValidity(message);
        return message === "";
      }

      function initWizard(form){
        if (!form) return;
        const steps = Array.prototype.slice.call(form.querySelectorAll(".casanova-group-step"));
        if (!steps.length) return;
        const indicator = form.querySelector(".casanova-group-step-indicator");
        steps.forEach(function(step, index){
          if (!step.getAttribute("data-step-key")) step.setAttribute("data-step-key", "step-" + String(index));
        });

        const configuredOrder = (form.getAttribute("data-step-order") || "")
          .split(",")
          .map(function(key){ return key.trim(); })
          .filter(Boolean);
        const order = configuredOrder.length ? configuredOrder : steps.reduce(function(keys, step){
          const key = step.getAttribute("data-step-key");
          if (keys.indexOf(key) === -1) keys.push(key);
          return keys;
        }, []);
        const titles = {};
        steps.forEach(function(step){
          const key = step.getAttribute("data-step-key");
          if (!titles[key] && step.getAttribute("data-step-title")) titles[key] = step.getAttribute("data-step-title");
        });

        let current = 0;
        function activeSteps(key){
          return steps.filter(function(step){ return step.getAttribute("data-step-key") === key; });
        }
        function fieldsForKey(key){
          return activeSteps(key).reduce(function(fields, step){
            return fields.concat(Array.prototype.slice.call(step.querySelectorAll("input, select, textarea")));
          }, []);
        }
        function invalidFieldForKey(key){
          const fields = fieldsForKey(key);
          for (let i = 0; i < fields.length; i++) {
            const field = fields[i];
            if (field.type === "hidden" || field.disabled) continue;
            if (!field.checkValidity()) return field;
          }
          return null;
        }
        function show(index){
          current = Math.max(0, Math.min(index, order.length - 1));
          const key = order[current];
          steps.forEach(function(step){
            step.hidden = step.getAttribute("data-step-key") !== key;
          });
          if (indicator) {
            const title = titles[key] || "";
            indicator.textContent = "Paso " + String(current + 1) + " de " + String(order.length) + (title ? ": " + title : "");
          }
        }
        function validateCurrent(){
          const invalid = invalidFieldForKey(order[current]);
          if (!invalid) return true;
          invalid.reportValidity();
          return false;
        }

        form.addEventListener("click", function(event){
          const next = event.target.closest("[data-wizard-next]");
          const prev = event.target.closest("[data-wizard-prev]");
          if (next) {
            event.preventDefault();
            if (validateCurrent()) show(current + 1);
          }
          if (prev) {
            event.preventDefault();
            show(current - 1);
          }
        });
        form.addEventListener("submit", function(event){
          for (let i = 0; i < order.length; i++) {
            const invalid = invalidFieldForKey(order[i]);
            if (invalid) {
              event.preventDefault();
              show(i);
              window.setTimeout(function(){ invalid.reportValidity(); }, 0);
              return;
            }
          }
        });
        show(0);
      }

      function init(){
        const restForm = document.getElementById("casanova-group-rest-form");
        if (restForm) {
          const restQtySelects = Array.prototype.slice.call(restForm.querySelectorAll("[data-rest-concept-qty]"));
          const restMethodInputs = restForm.querySelectorAll("input[name=method]");
          const restCurrencyInputs = restForm.querySelectorAll("input[name=currency]");
          const restMethodWrap = document.getElementById("casanova-rest-method-wrap");
          const restMethodLabel = document.getElementById("casanova-rest-method-label");
          const restMethodNote = document.getElementById("casanova-rest-method-note");
          const restCardBrandWrap = document.getElementById("casanova-rest-card-brand-wrap");
          const restSummary = document.getElementById("casanova-group-rest-summary");
          const restOptionSummary = document.getElementById("casanova-group-rest-option-summary");
          const restBtn = document.getElementById("casanova-group-rest-button");

          function getRestMethod(){
            let m = "card";
            Array.prototype.forEach.call(restMethodInputs, function(i){ if (i.checked) m = i.value; });
            return m;
          }

          function getRestCurrency(){
            let c = "EUR";
            Array.prototype.forEach.call(restCurrencyInputs, function(i){ if (i.checked) c = i.value; });
            return c === "USD" ? "USD" : "EUR";
          }

          function updateRest(){
            const cart = readCart(restQtySelects, "data-rest-concept-qty");
            setCartValidity(restQtySelects, cart, 0);
            const units = cart.units;
            const currency = getRestCurrency();
            let method = getRestMethod();
            if (currency === "USD") {
              Array.prototype.forEach.call(restMethodInputs, function(i){ if (i.value === "card") i.checked = true; });
              method = "card";
            }
            const amount = cartAmount(cart, "unit_rest");
            if (restMethodNote) restMethodNote.classList.toggle("casanova-hidden", currency === "USD" || method !== "bank_transfer");
            if (restMethodWrap) restMethodWrap.classList.toggle("casanova-hidden", currency === "USD");
            if (restMethodLabel) restMethodLabel.classList.toggle("casanova-hidden", currency === "USD");
            if (restCardBrandWrap) restCardBrandWrap.classList.toggle("casanova-hidden", currency === "USD" || method !== "card");
            const singleRest = cart.lines.length === 1 ? cart.lines[0] : null;
            const lines = [
              singleRest ? restAmountTemplate.replace("__AMOUNT__", fmt(Number(singleRest.concept.unit_rest || 0))) : "",
              (singleRest && singleRest.concept.label) ? "Opción: " + singleRest.concept.label : ""
            ].concat(
              cart.lines.length > 1 ? cartLineSummaries(cart, "unit_rest", currency) : [],
              [
                peopleLabel + ": " + String(units),
                totalLabel + ": " + displayAmount(amount, currency)
              ]
            ).filter(Boolean);
            const renderedSummary = lines.map(function(line){
                return "<div class=\"casanova-public-page__summary-line\">" + escapeHtml(line) + "</div>";
              }).join("");
            if (restSummary) restSummary.innerHTML = renderedSummary;
            if (restOptionSummary) restOptionSummary.innerHTML = renderedSummary;
            if (restBtn) restBtn.textContent = payRestLabel + " " + displayAmount(amount, currency);
          }

          restForm.addEventListener("change", updateRest);
          restForm.addEventListener("input", updateRest);
          updateRest();
          initWizard(restForm);
          casanovaPaySubmitLoading(restForm, "casanova-group-rest-button");
        }

        const form = document.getElementById("casanova-group-pay-form");
        if (!form) return;
        initWizard(form);
        casanovaPaySubmitLoading(form, "casanova-group-pay-button");
        const qtySelects = Array.prototype.slice.call(form.querySelectorAll("[data-concept-qty]"));
        const modeInputs = form.querySelectorAll("input[name=mode]");
        const methodInputs = form.querySelectorAll("input[name=method]");
        const currencyInputs = form.querySelectorAll("input[name=currency]");
        const methodWrap = document.getElementById("casanova-method-wrap");
        const methodLabel = document.getElementById("casanova-method-label");
        const methodNote = document.getElementById("casanova-method-note");
        const cardBrandWrap = document.getElementById("casanova-card-brand-wrap");
        const summary = document.getElementById("casanova-group-summary");
        const optionSummary = document.getElementById("casanova-group-option-summary");
        const btn = document.getElementById("casanova-group-pay-button");
        const modeAmountDeposit = form.querySelector(".casanova-group-mode-amount[data-mode=deposit]");
        const modeAmountFull = form.querySelector(".casanova-group-mode-amount[data-mode=full]");

        function getMode(){
          let m = "full";
          Array.prototype.forEach.call(modeInputs, function(i){ if (i.checked) m = i.value; });
          return m;
        }

        function getMethod(){
          let m = "card";
          Array.prototype.forEach.call(methodInputs, function(i){ if (i.checked) m = i.value; });
          return m;
        }

        function getCurrency(){
          let c = "EUR";
          Array.prototype.forEach.call(currencyInputs, function(i){ if (i.checked) c = i.value; });
          return c === "USD" ? "USD" : "EUR";
        }

        function update(){
          const cart = readCart(qtySelects, "data-concept-qty");
          setCartValidity(qtySelects, cart, mainAvailableUnits);
          const units = cart.units;
          const mode = getMode();
          const currency = getCurrency();
          let method = getMethod();
          if (currency === "USD") {
            Array.prototype.forEach.call(methodInputs, function(i){ if (i.value === "card") i.checked = true; });
            method = "card";
          }
          const total = cartAmount(cart, "unit_total");
          const dep = cartAmount(cart, "unit_deposit");
          const isDeposit = (mode === "deposit" && dep > 0.009 && dep + 0.01 < total);
          const amount = isDeposit ? dep : total;
          if (methodNote) methodNote.classList.toggle("casanova-hidden", currency === "USD" || method !== "bank_transfer");
          if (methodWrap) methodWrap.classList.toggle("casanova-hidden", currency === "USD");
          if (methodLabel) methodLabel.classList.toggle("casanova-hidden", currency === "USD");
          if (cardBrandWrap) cardBrandWrap.classList.toggle("casanova-hidden", currency === "USD" || method !== "card");
          const single = cart.lines.length === 1 ? cart.lines[0] : null;
          const lines = [
            single ? amountTemplate.replace("__AMOUNT__", fmt(Number(single.concept.unit_total || 0))) : "",
            (single && single.concept.label) ? "Opción: " + single.concept.label : ""
          ].concat(
            cart.lines.length > 1 ? cartLineSummaries(cart, "unit_total", currency) : [],
            [peopleLabel + ": " + String(units)]
          ).filter(Boolean);
          if (isDeposit && single) {
            lines.push(depositLabel + ": " + displayAmount(Number(single.concept.unit_deposit || 0), currency));
          }
          lines.push(totalLabel + ": " + displayAmount(amount, currency));
          const renderedSummary = lines.map(function(line){
              return "<div class=\"casanova-public-page__summary-line\">" + escapeHtml(line) + "</div>";
            }).join("");
          if (summary) summary.innerHTML = renderedSummary;
          if (optionSummary) optionSummary.innerHTML = renderedSummary;
          if (btn) btn.textContent = payLabel + " " + displayAmount(amount, currency);
          if (modeAmountFull) modeAmountFull.textContent = displayAmount(total, currency);
          if (modeAmountDeposit) {
            modeAmountDeposit.textContent = (dep > 0.009 && dep + 0.01 < total) ? displayAmount(dep, currency) : "";
          }
        }

        form.addEventListener("change", update);
        form.addEventListener("input", update);
        update();
      }

      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
      } else {
        init();
      }
    })();
  </script>';

  echo '</section>';
  casanova_portal_render_public_document_end();
  exit;
}
