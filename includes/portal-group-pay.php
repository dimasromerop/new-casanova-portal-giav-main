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

function casanova_group_pay_link_matches_token(array $meta, int $group_id, int $idReservaPQ): bool {
  $meta_group_id = (int)($meta['group_token_id'] ?? 0);
  if ($meta_group_id > 0) {
    return $meta_group_id === $group_id;
  }

  $meta_reserva_pq = (int)($meta['id_reserva_pq'] ?? 0);
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

  if (!function_exists('casanova_payment_links_confirmed_intent_for_link')) return false;
  $intent = casanova_payment_links_confirmed_intent_for_link($row);
  if (!$intent) return false;

  if (function_exists('casanova_payment_links_mark_paid_from_confirmed_intent')) {
    casanova_payment_links_mark_paid_from_confirmed_intent($row, $intent, (string)($row->billing_dni ?? ''));
  }

  return true;
}

function casanova_group_pay_rest_status(int $idExpediente, int $group_id, int $idReservaPQ): array {
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
    if (!casanova_group_pay_link_matches_token($meta, $group_id, $idReservaPQ)) {
      continue;
    }
    if (!casanova_group_pay_link_is_confirmed($row)) {
      continue;
    }

    $units = (int)($meta['units'] ?? 0);
    if ($units <= 0) continue;

    $mode = strtolower(trim((string)($meta['mode'] ?? '')));
    if (function_exists('casanova_payment_links_is_effective_deposit') && casanova_payment_links_is_effective_deposit($row, $meta)) {
      $out['deposit_units'] += $units;
      $unit_deposit = round((float)($meta['unit_deposit'] ?? 0), 2);
      if ($unit_deposit <= 0.0) {
        $unit_deposit = round(((float)($row->amount_authorized ?? 0)) / (float)$units, 2);
      }
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

  // Nuevo modelo: importe fijo por persona (unit_total), sin slots.
  $unit_total = (float)($group->unit_total ?? 0);
  if ($unit_total <= 0.0 && $baseTotal > 0 && $numPax > 0) {
    $unit_total = round(((float)$baseTotal) / ((float)$numPax), 2);
  }
  if ($unit_total <= 0.0) {
    casanova_render_payment_link_error(__('No se pudo determinar el importe por persona.', 'casanova-portal'));
    exit;
  }

  $deposit_allowed = function_exists('casanova_payments_is_deposit_allowed') ? casanova_payments_is_deposit_allowed($reservas) : false;
  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
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
    if ($nonce === '' || !wp_verify_nonce($nonce, 'casanova_group_pay_' . (int)$group->id)) {
      casanova_render_payment_link_error(__('Solicitud no valida.', 'casanova-portal'));
      exit;
    }

    $action = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : 'pay';
    if ($action === 'resend_magic') {
      $email_raw = isset($_POST['resend_email']) ? (string)$_POST['resend_email'] : '';
      $resend_email = trim(sanitize_email($email_raw));
      $dni_raw = isset($_POST['resend_dni']) ? (string)$_POST['resend_dni'] : '';
      $resend_dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));

      if ($resend_email === '' || !is_email($resend_email) || $resend_dni === '') {
        $flash_msg = __('Debes indicar email y DNI.', 'casanova-portal');
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
      $rest_status = casanova_group_pay_rest_status($idExpediente, (int)$group->id, $idReservaPQ);
      $rest_unit_deposit = round((float)($rest_status['unit_deposit'] ?? 0), 2);
      if ($rest_unit_deposit <= 0.0) {
        $rest_unit_deposit = $unit_deposit_configured;
      }
      $unit_rest = round(max(0.0, $unit_total - $rest_unit_deposit), 2);
      if ($unit_rest <= 0.01) {
        casanova_render_payment_link_error(__('No hay importe restante disponible para este viaje.', 'casanova-portal'));
        exit;
      }

      $available_units = min(10, max(0, (int)($rest_status['available_units'] ?? 0)));
      if ($available_units <= 0) {
        casanova_render_payment_link_error(__('No hay pagos restantes pendientes para este enlace de grupo.', 'casanova-portal'));
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
        casanova_render_payment_link_error(__('Debes indicar un email valido.', 'casanova-portal'));
        exit;
      }

      $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
      $dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
      if ($dni === '') {
        casanova_render_payment_link_error(__('Debes indicar el DNI/NIF.', 'casanova-portal'));
        exit;
      }

      $units = isset($_POST['rest_units']) ? (int)$_POST['rest_units'] : 1;
      if ($units < 1) $units = 1;
      if ($units > $available_units) $units = $available_units;

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

      $amount_to_pay = round($unit_rest * (float)$units, 2);
      if ($amount_to_pay <= 0.01) {
        casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
        exit;
      }

      if (!function_exists('casanova_payment_link_create')) {
        casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
        exit;
      }

      $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
      if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
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

      $expires_at = function_exists('casanova_payment_links_rest_expires_at')
        ? casanova_payment_links_rest_expires_at($idExpediente)
        : null;
      $total_due = round($unit_total * (float)$units, 2);
      $deposit_total = round($rest_unit_deposit * (float)$units, 2);

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
          'unit_total' => $unit_total,
          'unit_deposit' => $rest_unit_deposit,
          'unit_rest' => $unit_rest,
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
      casanova_render_payment_link_error(__('Debes indicar el DNI/NIF.', 'casanova-portal'));
      exit;
    }

    $units = isset($_POST['units']) ? (int)$_POST['units'] : 1;
    if ($units < 1) $units = 1;
    if ($units > 10) $units = 10;

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
    if ($units <= 0) {
      casanova_render_payment_link_error(__('Debes seleccionar al menos 1 persona.', 'casanova-portal'));
      exit;
    }

    $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
    if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
    if (!$deposit_allowed) $mode = 'full';

    $unit_deposit = ($mode === 'deposit') ? $unit_deposit_configured : 0.0;

    $total_due = round($unit_total * (float)$units, 2);
    $deposit_total = round($unit_deposit * (float)$units, 2);
    $deposit_effective = ($mode === 'deposit') && ($deposit_total + 0.01 < $total_due);
    if ($mode === 'deposit' && !$deposit_effective) $mode = 'full';

    $amount_to_pay = ($mode === 'deposit') ? $deposit_total : $total_due;
    if ($amount_to_pay <= 0.01) {
      casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
      exit;
    }

    if (!function_exists('casanova_payment_link_create')) {
      casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
      exit;
    }

    $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
    if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
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
        'unit_total' => $unit_total,
        'unit_deposit' => $unit_deposit,
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
  $rest_status = casanova_group_pay_rest_status($idExpediente, (int)$group->id, $idReservaPQ);
  $rest_unit_deposit = round((float)($rest_status['unit_deposit'] ?? 0), 2);
  if ($rest_unit_deposit <= 0.0) {
    $rest_unit_deposit = $unit_deposit_configured;
  }
  $unit_rest_preview = round(max(0.0, $unit_total - $rest_unit_deposit), 2);
  $rest_available_units = min(10, max(0, (int)($rest_status['available_units'] ?? 0)));
  $rest_stage_open = isset($_GET['stage']) && sanitize_key((string)$_GET['stage']) === 'rest';

  $default_amount = $deposit_allowed && $unit_deposit_preview > 0.009 && $unit_deposit_preview + 0.01 < $unit_total
    ? $unit_deposit_preview
    : $unit_total;
  $transfer_note = __('El pago por transferencia bancaria online PSD2 no tiene recargo y es completamente seguro. Serás redirigido a una página de pago donde podrás seleccionar tu banco y acceder a tu banca online para autorizar la transferencia. Una vez completado el pago, volverás automáticamente a nuestra página. Este método es compatible con la mayoría de bancos españoles y portugueses.', 'casanova-portal');
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

  $nonce = wp_create_nonce('casanova_group_pay_' . (int)$group->id);
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
  echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Importe por persona: %s EUR', 'casanova-portal'), number_format_i18n($unit_total, 2))) . '</div>';
  if ($deadline_txt !== '') {
    echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Fecha limite deposito: %s', 'casanova-portal'), $deadline_txt)) . '</div>';
  }
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

  echo '<details class="casanova-public-page__disclosure">';
  echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('¿Ya pagaste el depósito? Reenviar enlace para pagar el resto', 'casanova-portal') . '</summary>';
  echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Usa el mismo email y DNI/NIF con el que pagaste el depósito y te reenviamos el enlace del pago restante.', 'casanova-portal') . '</div>';
  echo '<form class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="resend_magic" />';
  echo '<div class="casanova-public-form__grid">';
  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>'
    . '<input class="casanova-public-field__control" type="email" name="resend_email" autocomplete="email" required value="" />'
    . '</label>';
  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('DNI / NIF', 'casanova-portal') . '</span>'
    . '<input class="casanova-public-field__control" type="text" name="resend_dni" autocomplete="tax-id" required value="" />'
    . '</label>';
  echo '</div>';
  echo '<div class="casanova-public-page__actions">';
  echo '<button class="casanova-public-button casanova-public-button--ghost" type="submit">' . esc_html__('Enviar enlace del resto', 'casanova-portal') . '</button>';
  echo '</div>';
  echo '</form>';
  echo '</details>';

  if ($unit_rest_preview > 0.01) {
    $rest_details_attr = $rest_stage_open ? ' open' : '';
    echo '<details id="casanova-group-rest" class="casanova-public-page__disclosure"' . $rest_details_attr . '>';
    echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('Pagar el resto individualmente', 'casanova-portal') . '</summary>';
    echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Si ya hay depositos pagados, cada persona puede pagar su parte restante desde aqui.', 'casanova-portal') . '</div>';

    if ($rest_available_units > 0) {
      echo '<div class="casanova-public-page__summary">';
      echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Importe restante por persona: %s EUR', 'casanova-portal'), number_format_i18n($unit_rest_preview, 2))) . '</div>';
      echo '<div class="casanova-public-page__summary-line">' . esc_html(sprintf(__('Personas con deposito pendiente de resto: %d', 'casanova-portal'), $rest_available_units)) . '</div>';
      echo '</div>';

      echo '<form id="casanova-group-rest-form" class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '">';
      echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
      echo '<input type="hidden" name="action" value="pay_rest" />';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombre', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_name" autocomplete="given-name" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Apellidos', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_lastname" autocomplete="family-name" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="email" name="billing_email" autocomplete="email" required value="" />';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('DNI / NIF (obligatorio)', 'casanova-portal') . '</span>';
      echo '<input class="casanova-public-field__control" type="text" name="billing_dni" autocomplete="tax-id" required value="" />';
      echo '</label>';

      echo '<div class="casanova-public-section-label">' . esc_html__('Metodo de pago', 'casanova-portal') . '</div>';
      if ($inespay_enabled) {
        echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="method" value="card" checked />' . esc_html__('Tarjeta', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '<label class="casanova-public-choice casanova-public-choice--compact">';
        echo '<input class="casanova-public-choice__control" type="radio" name="method" value="bank_transfer" />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
        echo '<span class="casanova-public-choice__hint">' . esc_html__('PSD2 sin recargo.', 'casanova-portal') . '</span>';
        echo '</label>';
        echo '</div>';
        echo '<div id="casanova-rest-method-note" class="casanova-public-page__method-note casanova-hidden">' . esc_html($transfer_note) . '</div>';
      } else {
        echo '<input type="hidden" name="method" value="card" />';
        echo '<div class="casanova-public-field__hint">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
      }

      echo '<div id="casanova-rest-card-brand-wrap">';
      echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
      echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
      echo '<label class="casanova-public-choice casanova-public-choice--compact">';
      echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" checked />' . esc_html__('Otra tarjeta', 'casanova-portal');
      echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
      echo '</label>';
      echo '<label class="casanova-public-choice casanova-public-choice--compact">';
      echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" />' . esc_html__('American Express (AMEX)', 'casanova-portal');
      echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opcion si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
      echo '</label>';
      echo '</div>';
      echo '</div>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Personas incluidas en este pago restante', 'casanova-portal') . '</span>';
      echo '<select class="casanova-public-field__control" name="rest_units" required>';
      for ($i = 1; $i <= $rest_available_units; $i++) {
        echo '<option value="' . esc_attr((string)$i) . '">' . esc_html((string)$i) . '</option>';
      }
      echo '</select>';
      echo '</label>';

      echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombres de viajeros (opcional)', 'casanova-portal') . '</span>';
      echo '<textarea class="casanova-public-field__control" name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2"></textarea>';
      echo '<span class="casanova-public-field__hint">' . esc_html__('Solo para referencia de la agencia. 1 nombre por linea.', 'casanova-portal') . '</span>';
      echo '</label>';

      echo '<div id="casanova-group-rest-summary" class="casanova-public-page__summary"></div>';
      echo '<div class="casanova-public-page__actions">';
      echo '<button id="casanova-group-rest-button" class="casanova-public-button" type="submit">'
        . esc_html(sprintf(__('Pagar resto %s %s', 'casanova-portal'), number_format_i18n($unit_rest_preview, 2), $euro_symbol))
        . '</button>';
      echo '</div>';
      echo '</form>';
    } else {
      echo '<div class="casanova-public-page__notice">' . esc_html__('Todavia no hay depositos registrados con pago restante pendiente para este enlace.', 'casanova-portal') . '</div>';
    }
    echo '</details>';
  }

  $wrap_main_payment_form = $rest_stage_open && $rest_available_units > 0;
  if ($wrap_main_payment_form) {
    echo '<details class="casanova-public-page__disclosure">';
    echo '<summary class="casanova-public-page__disclosure-summary">' . esc_html__('Necesito hacer otro pago: deposito o total', 'casanova-portal') . '</summary>';
    echo '<div class="casanova-public-page__disclosure-text">' . esc_html__('Usa esta opcion solo si todavia no has pagado el deposito o si quieres pagar el viaje completo.', 'casanova-portal') . '</div>';
  }

  echo '<form id="casanova-group-pay-form" class="casanova-public-form" method="post" action="' . esc_url($group_page_url) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="pay" />';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombre', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_name" autocomplete="given-name" required value="" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Apellidos', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_lastname" autocomplete="family-name" required value="" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="email" name="billing_email" autocomplete="email" required value="" />';
  echo '</label>';


  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('DNI / NIF (obligatorio)', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_dni" autocomplete="tax-id" required value="" />';
  echo '</label>';

  echo '<div class="casanova-public-section-label">' . esc_html__('Método de pago', 'casanova-portal') . '</div>';
  if ($inespay_enabled) {
    echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="card" checked />' . esc_html__('Tarjeta', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="bank_transfer" />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('PSD2 · Sin recargo.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
    echo '<div id="casanova-method-note" class="casanova-public-page__method-note casanova-hidden">' . esc_html($transfer_note) . '</div>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div class="casanova-public-field__hint">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  echo '<div id="casanova-card-brand-wrap">';
  echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
  echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
  echo '<label class="casanova-public-choice casanova-public-choice--compact">';
  echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" checked />' . esc_html__('Otra tarjeta', 'casanova-portal');
  echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
  echo '</label>';
  echo '<label class="casanova-public-choice casanova-public-choice--compact">';
  echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" />' . esc_html__('American Express (AMEX)', 'casanova-portal');
  echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opcion si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
  echo '</label>';
  echo '</div>';
  echo '<div class="casanova-public-field__hint">' . esc_html__('Elige con que tarjeta quieres realizar el pago.', 'casanova-portal') . '</div>';
  echo '</div>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Personas incluidas en este pago', 'casanova-portal') . '</span>';
  echo '<select class="casanova-public-field__control" name="units" required>';
  for ($i = 1; $i <= 10; $i++) {
    echo '<option value="' . esc_attr((string)$i) . '">' . esc_html((string)$i) . '</option>';
  }
  echo '</select>';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombres de viajeros (opcional)', 'casanova-portal') . '</span>';
  echo '<textarea class="casanova-public-field__control" name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2"></textarea>';
  echo '<span class="casanova-public-field__hint">' . esc_html__('Solo para referencia de la agencia. 1 nombre por línea.', 'casanova-portal') . '</span>';
  echo '</label>';

  if ($deposit_allowed) {
    echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="deposit" />' . esc_html__('Pagar deposito', 'casanova-portal');
    echo '</label>';
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="full" checked />' . esc_html__('Pagar total', 'casanova-portal');
    echo '</label>';
    echo '</div>';
  } else {
    echo '<input type="hidden" name="mode" value="full" />';
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="full" checked />' . esc_html__('Pagar total', 'casanova-portal');
    echo '</label>';
  }

  echo '<div id="casanova-group-summary" class="casanova-public-page__summary"></div>';
  echo '<div class="casanova-public-page__actions">';
  echo '<button id="casanova-group-pay-button" class="casanova-public-button" type="submit">'
    . esc_html(sprintf(__('Pagar %s %s', 'casanova-portal'), number_format_i18n($default_amount, 2), $euro_symbol))
    . '</button>';
  echo '</div>';

  echo '</form>';
  if ($wrap_main_payment_form) {
    echo '</details>';
  }
  echo '<p class="casanova-public-page__footer">' . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal') . '</p>';
  echo '<script>
    (function(){
      const unitTotal = ' . wp_json_encode(round($unit_total, 2)) . ';
      const unitDeposit = ' . wp_json_encode(round($unit_deposit_preview, 2)) . ';
      const unitRest = ' . wp_json_encode(round($unit_rest_preview, 2)) . ';
      const locale = ' . wp_json_encode($public_locale_tag) . ';
      const amountTemplate = ' . wp_json_encode($js_summary_amount_template) . ';
      const restAmountTemplate = ' . wp_json_encode($js_rest_amount_template) . ';
      const peopleLabel = ' . wp_json_encode($js_people_label) . ';
      const depositLabel = ' . wp_json_encode($js_deposit_label) . ';
      const totalLabel = ' . wp_json_encode($js_total_label) . ';
      const payLabel = ' . wp_json_encode($js_pay_label) . ';
      const payRestLabel = ' . wp_json_encode($js_pay_rest_label) . ';

      function fmt(n){
        if (!isFinite(n)) n = 0;
        try {
          return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
        } catch (e) {
          return String(Math.round(n * 100) / 100);
        }
      }

      function init(){
        const restForm = document.getElementById("casanova-group-rest-form");
        if (restForm) {
          const restUnitsSelect = restForm.elements["rest_units"];
          const restMethodInputs = restForm.querySelectorAll("input[name=method]");
          const restMethodNote = document.getElementById("casanova-rest-method-note");
          const restCardBrandWrap = document.getElementById("casanova-rest-card-brand-wrap");
          const restSummary = document.getElementById("casanova-group-rest-summary");
          const restBtn = document.getElementById("casanova-group-rest-button");

          function getRestUnits(){
            const raw = parseInt(restUnitsSelect && restUnitsSelect.value ? restUnitsSelect.value : "1", 10);
            if (!isFinite(raw) || raw < 1) return 1;
            return raw;
          }

          function getRestMethod(){
            let m = "card";
            Array.prototype.forEach.call(restMethodInputs, function(i){ if (i.checked) m = i.value; });
            return m;
          }

          function updateRest(){
            const units = getRestUnits();
            const method = getRestMethod();
            const amount = Math.round((Number(unitRest) * Number(units)) * 100) / 100;
            if (restMethodNote) restMethodNote.classList.toggle("casanova-hidden", method !== "bank_transfer");
            if (restCardBrandWrap) restCardBrandWrap.classList.toggle("casanova-hidden", method !== "card");
            if (restSummary) {
              const lines = [
                restAmountTemplate.replace("__AMOUNT__", fmt(unitRest)),
                peopleLabel + ": " + String(units),
                totalLabel + ": " + fmt(amount) + " EUR"
              ];
              restSummary.innerHTML = lines.map(function(line){
                return "<div class=\"casanova-public-page__summary-line\">" + line + "</div>";
              }).join("");
            }
            if (restBtn) restBtn.textContent = payRestLabel + " " + fmt(amount) + " \\u20AC";
          }

          restForm.addEventListener("change", updateRest);
          restForm.addEventListener("input", updateRest);
          updateRest();
        }

        const form = document.getElementById("casanova-group-pay-form");
        if (!form) return;
        const unitsSelect = form.elements["units"];
        const modeInputs = form.querySelectorAll("input[name=mode]");
        const methodInputs = form.querySelectorAll("input[name=method]");
        const methodNote = document.getElementById("casanova-method-note");
        const cardBrandWrap = document.getElementById("casanova-card-brand-wrap");
        const summary = document.getElementById("casanova-group-summary");
        const btn = document.getElementById("casanova-group-pay-button");

        function getUnits(){
          const raw = parseInt(unitsSelect && unitsSelect.value ? unitsSelect.value : "1", 10);
          if (!isFinite(raw) || raw < 1) return 1;
          if (raw > 10) return 10;
          return raw;
        }

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

        function update(){
          const units = getUnits();
          const mode = getMode();
          const method = getMethod();
          const total = Math.round((Number(unitTotal) * Number(units)) * 100) / 100;
          const dep = Math.round((Number(unitDeposit) * Number(units)) * 100) / 100;
          const isDeposit = (mode === "deposit" && dep > 0.009 && dep + 0.01 < total);
          const amount = isDeposit ? dep : total;
          if (methodNote) methodNote.classList.toggle("casanova-hidden", method !== "bank_transfer");
          if (cardBrandWrap) cardBrandWrap.classList.toggle("casanova-hidden", method !== "card");
          if (summary) {
            const lines = [
              amountTemplate.replace("__AMOUNT__", fmt(unitTotal)),
              peopleLabel + ": " + String(units)
            ];
            if (isDeposit) {
              lines.push(depositLabel + ": " + fmt(unitDeposit) + " EUR");
            }
            lines.push(totalLabel + ": " + fmt(amount) + " EUR");
            summary.innerHTML = lines.map(function(line){
              return "<div class=\"casanova-public-page__summary-line\">" + line + "</div>";
            }).join("");
          }
          if (btn) btn.textContent = payLabel + " " + fmt(amount) + " \\u20AC";
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
