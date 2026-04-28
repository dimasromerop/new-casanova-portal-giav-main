<?php
if (!defined('ABSPATH')) exit;

function casanova_payment_link_new_token(): string {
  return bin2hex(random_bytes(24)); // 48 chars
}

function casanova_payment_link_create(array $data) {
  global $wpdb;
  $table = casanova_payment_links_table();
  $now = current_time('mysql');

  $row = [
    'token' => $data['token'] ?? casanova_payment_link_new_token(),
    'id_expediente' => (int)($data['id_expediente'] ?? 0),
    'id_reserva_pq' => !empty($data['id_reserva_pq']) ? (int)$data['id_reserva_pq'] : null,
    'scope' => (string)($data['scope'] ?? 'group_total'),
    'id_pasajero' => !empty($data['id_pasajero']) ? (int)$data['id_pasajero'] : null,
    'amount_authorized' => (string) number_format((float)($data['amount_authorized'] ?? 0), 2, '.', ''),
    'currency' => strtoupper((string)($data['currency'] ?? 'EUR')),
    'status' => (string)($data['status'] ?? 'active'),
    'expires_at' => !empty($data['expires_at']) ? (string)$data['expires_at'] : null,
    'created_by' => (string)($data['created_by'] ?? 'admin'),
    'paid_at' => !empty($data['paid_at']) ? (string)$data['paid_at'] : null,
    'giav_payment_id' => !empty($data['giav_payment_id']) ? (int)$data['giav_payment_id'] : null,
    'billing_dni' => !empty($data['billing_dni']) ? (string)$data['billing_dni'] : null,
    'metadata' => !empty($data['metadata']) ? (is_string($data['metadata']) ? $data['metadata'] : wp_json_encode($data['metadata'])) : null,
    'created_at' => $now,
    'updated_at' => $now,
  ];

  $ok = $wpdb->insert(
    $table,
    $row,
    [
      '%s', // token
      '%d', // id_expediente
      '%d', // id_reserva_pq
      '%s', // scope
      '%d', // id_pasajero
      '%s', // amount_authorized
      '%s', // currency
      '%s', // status
      '%s', // expires_at
      '%s', // created_by
      '%s', // paid_at
      '%d', // giav_payment_id
      '%s', // billing_dni
      '%s', // metadata
      '%s', // created_at
      '%s', // updated_at
    ]
  );

  if (!$ok) {
    return new WP_Error('payment_link_insert_failed', 'No se pudo crear el payment link: ' . $wpdb->last_error);
  }

  $row['id'] = (int)$wpdb->insert_id;
  return (object)$row;
}

function casanova_payment_link_get_by_token(string $token) {
  global $wpdb;
  $table = casanova_payment_links_table();
  $token = trim($token);
  if ($token === '') return null;

  return $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE token=%s LIMIT 1", $token)
  );
}

function casanova_payment_link_get(int $id) {
  global $wpdb;
  $table = casanova_payment_links_table();
  $id = (int)$id;
  if ($id <= 0) return null;

  return $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id)
  );
}

function casanova_payment_link_merge_metadata($old, array $add): string {
  $oldArr = [];
  if (is_array($old)) {
    $oldArr = $old;
  } elseif (is_string($old) && $old !== '') {
    $tmp = json_decode($old, true);
    if (is_array($tmp)) $oldArr = $tmp;
  }
  return wp_json_encode(array_replace_recursive($oldArr, $add));
}

function casanova_payment_link_update(int $id, array $fields): bool {
  global $wpdb;
  $table = casanova_payment_links_table();
  $id = (int)$id;
  if ($id <= 0) return false;

  $allowed = [
    'token','id_expediente','id_reserva_pq','scope','id_pasajero',
    'amount_authorized','currency','status','expires_at','created_by',
    'paid_at','giav_payment_id','billing_dni','metadata',
    'created_at','updated_at'
  ];

  $clean = [];
  foreach ($fields as $k => $v) {
    if (!in_array($k, $allowed, true)) continue;

    if ($k === 'metadata') {
      $clean[$k] = is_string($v) ? $v : wp_json_encode($v);
      continue;
    }
    if ($k === 'amount_authorized') {
      $clean[$k] = (string) number_format((float)$v, 2, '.', '');
      continue;
    }
    if (in_array($k, ['id_expediente','id_reserva_pq','id_pasajero','giav_payment_id'], true)) {
      $clean[$k] = (int)$v;
      continue;
    }

    $clean[$k] = $v;
  }

  $clean['updated_at'] = current_time('mysql');

  $formats = [];
  foreach ($clean as $k => $v) {
    if (in_array($k, ['id_expediente','id_reserva_pq','id_pasajero','giav_payment_id'], true)) { $formats[] = '%d'; continue; }
    if ($k === 'amount_authorized') { $formats[] = '%s'; continue; }
    $formats[] = '%s';
  }

  $ok = $wpdb->update($table, $clean, ['id' => $id], $formats, ['%d']);
  return $ok !== false;
}

function casanova_payment_link_mark_paid(int $id, int $giav_payment_id = 0, string $billing_dni = ''): bool {
  if ($id <= 0) return false;

  $fields = [
    'status' => 'paid',
    'paid_at' => current_time('mysql'),
  ];
  if ($giav_payment_id > 0) {
    $fields['giav_payment_id'] = $giav_payment_id;
  }
  if ($billing_dni !== '') {
    $fields['billing_dni'] = $billing_dni;
  }

  return casanova_payment_link_update($id, $fields);
}

function casanova_payment_link_intent_payload_array_local(object $intent): array {
  $raw = (string)($intent->payload ?? '');
  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function casanova_payment_link_estimated_remaining_from_intent(object $intent): float {
  $payload = casanova_payment_link_intent_payload_array_local($intent);
  $pending_at_create = round((float)($payload['pending_at_create'] ?? 0), 2);
  $amount = round((float)($intent->amount ?? 0), 2);
  if ($pending_at_create <= 0.01) {
    return 0.0;
  }

  return round(max(0.0, $pending_at_create - $amount), 2);
}

function casanova_payment_link_email_remaining_after_deposit(object $intent, int $idExpediente, int $idCliente = 0): float {
  $estimated = casanova_payment_link_estimated_remaining_from_intent($intent);
  $resolved = casanova_payment_link_resolve_pending_amount($idExpediente, $idCliente);

  if (is_wp_error($resolved)) {
    return $estimated;
  }

  $resolved = round((float)$resolved, 2);
  if ($estimated > 0.01) {
    if ($resolved - $estimated > 0.01) {
      error_log('[CASANOVA][PAYLINK] email remaining fallback to estimated exp=' . $idExpediente . ' intent_id=' . (int)($intent->id ?? 0) . ' resolved=' . $resolved . ' estimated=' . $estimated);
      return $estimated;
    }

    return round(min($resolved, $estimated), 2);
  }

  return $resolved;
}

function casanova_payment_link_resolve_pending_amount(int $idExpediente, int $idCliente = 0) {
  if ($idExpediente <= 0) {
    return new WP_Error('expediente', __('Expediente invalido.', 'casanova-portal'));
  }

  if (!function_exists('casanova_giav_expediente_get') || !function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
    return new WP_Error('giav_missing', __('No se pudo consultar GIAV para calcular el pendiente.', 'casanova-portal'));
  }

  if ($idCliente <= 0) {
    $exp = casanova_giav_expediente_get($idExpediente);
    if (is_wp_error($exp) || !is_object($exp)) {
      return new WP_Error('expediente_not_found', __('No se encontro el expediente en GIAV.', 'casanova-portal'));
    }
    $idCliente = (int)($exp->IdCliente ?? 0);
  }

  if ($idCliente <= 0) {
    return new WP_Error('cliente', __('No se pudo resolver el cliente del expediente.', 'casanova-portal'));
  }

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || !is_array($reservas) || empty($reservas)) {
    return new WP_Error('reservas', __('No se pudieron cargar las reservas del expediente.', 'casanova-portal'));
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc) || !is_array($calc)) {
    return new WP_Error('calc', __('No se pudo calcular el pendiente del expediente.', 'casanova-portal'));
  }

  return round((float)($calc['pendiente_real'] ?? 0), 2);
}

function casanova_payment_link_sync_after_cobro(object $intent, int $payment_link_id, string $payment_link_scope, int $giav_payment_id = 0, string $billing_dni = ''): bool {
  if ($payment_link_id <= 0) return false;

  if ($payment_link_scope !== 'individual_link') {
    $ok = casanova_payment_link_mark_paid($payment_link_id, $giav_payment_id, $billing_dni);
    if ($ok && in_array($payment_link_scope, ['slot_base', 'group_base'], true) && function_exists('casanova_payment_links_send_or_create_rest_magic')) {
      $link = function_exists('casanova_payment_link_get') ? casanova_payment_link_get($payment_link_id) : null;
      if ($link) {
        casanova_payment_links_send_or_create_rest_magic($link, $intent);
      }
    }
    return $ok;
  }

  $remaining = casanova_payment_link_resolve_pending_amount((int)($intent->id_expediente ?? 0), (int)($intent->id_cliente ?? 0));
  if (is_wp_error($remaining)) {
    $remaining = casanova_payment_link_estimated_remaining_from_intent($intent);
  } else {
    $remaining = round((float)$remaining, 2);
  }

  if ($remaining <= 0.01) {
    return casanova_payment_link_mark_paid($payment_link_id, $giav_payment_id, $billing_dni);
  }

  $fields = [
    'status' => 'active',
    'expires_at' => null,
  ];
  if ($giav_payment_id > 0) {
    $fields['giav_payment_id'] = $giav_payment_id;
  }
  if ($billing_dni !== '') {
    $fields['billing_dni'] = $billing_dni;
  }

  return casanova_payment_link_update($payment_link_id, $fields);
}

function casanova_payment_link_is_expired($link): bool {
  if (!$link || !is_object($link)) return true;
  if (empty($link->expires_at)) return false;
  $ts = strtotime((string)$link->expires_at);
  if (!$ts) return false;
  return $ts < time();
}

function casanova_payment_link_url(string $token): string {
  $token = trim($token);
  if ($token === '') return home_url('/');
  return home_url('/pay/' . rawurlencode($token) . '/');
}

// Rewrite for /pay/{token}
if (!function_exists('casanova_payment_links_register_rewrite')) {
  function casanova_payment_links_register_rewrite(): void {
    add_rewrite_rule('^pay/([^/]+)/?$', 'index.php?casanova_pay_token=$matches[1]', 'top');
  }
}

add_action('init', function () {
  if (function_exists('casanova_payment_links_register_rewrite')) {
    casanova_payment_links_register_rewrite();
  }
});

add_filter('query_vars', function (array $vars): array {
  $vars[] = 'casanova_pay_token';
  return $vars;
});

add_action('template_redirect', function () {
  $token = (string) get_query_var('casanova_pay_token');

  if ($token === '') {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && strpos($uri, '/pay/group/') !== false) {
      return; // let group-pay handler take this route
    }
    if (!empty($_GET['casanova_pay_token'])) {
      $token = (string) sanitize_text_field((string) $_GET['casanova_pay_token']);
    } else {
      if ($uri !== '') {
        $path = wp_parse_url($uri, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
          if (preg_match('#/pay/([^/]+)/?#', $path, $m)) {
            $token = (string)($m[1] ?? '');
          }
        }
      }
    }
  }

  if ($token === '') return;
  casanova_handle_payment_link_request($token);
  exit;
});

function casanova_handle_payment_link_request(string $token): void {
  $token = sanitize_text_field($token);
  if (function_exists('casanova_portal_maybe_switch_public_locale')) {
    casanova_portal_maybe_switch_public_locale();
  }
  if ($token === '') {
    wp_die(esc_html__('Enlace de pago invalido.', 'casanova-portal'), 404);
  }

  $link = casanova_payment_link_get_by_token($token);
  if (!$link) {
    wp_die(esc_html__('Enlace de pago no encontrado.', 'casanova-portal'), 404);
  }

  $status = strtolower(trim((string)($link->status ?? '')));
  if ($status === 'paid') {
    casanova_render_payment_link_success($link);
    exit;
  }

  $payment_qs = isset($_GET['payment']) ? sanitize_key((string)$_GET['payment']) : '';
  if ($payment_qs === 'success') {
    casanova_render_payment_link_success($link);
    exit;
  }
  if ($payment_qs === 'failed') {
    casanova_render_payment_link_error(__('Pago no completado.', 'casanova-portal'));
    exit;
  }
  if ($status !== 'active') {
    casanova_render_payment_link_error(__('Enlace de pago no disponible.', 'casanova-portal'));
    exit;
  }

  if (casanova_payment_link_is_expired($link)) {
    casanova_payment_link_update((int)$link->id, ['status' => 'expired']);
    casanova_render_payment_link_error(__('Enlace de pago caducado.', 'casanova-portal'));
    exit;
  }

  $meta_prefill = [];
  $meta_raw = (string)($link->metadata ?? '');
  if ($meta_raw !== '') {
    $decoded = json_decode($meta_raw, true);
    if (is_array($decoded)) $meta_prefill = $decoded;
  }
  $prefill_name = '';
  $prefill_lastname = '';
  if (!empty($meta_prefill['billing_name'])) {
    $parts = explode(' ', trim((string)$meta_prefill['billing_name']));
    $prefill_name = (string) array_shift($parts);
    $prefill_lastname = trim(implode(' ', $parts));
  }
  if (!empty($meta_prefill['billing_lastname'])) {
    $prefill_lastname = (string) $meta_prefill['billing_lastname'];
  }
  $prefill_email = !empty($meta_prefill['billing_email']) ? (string)$meta_prefill['billing_email'] : '';
  $prefill_dni = !empty($meta_prefill['billing_dni']) ? (string)$meta_prefill['billing_dni'] : '';
  $prefill_mode = !empty($meta_prefill['mode']) ? strtolower((string)$meta_prefill['mode']) : '';
  $prefill_method = !empty($meta_prefill['preferred_method']) ? strtolower((string)$meta_prefill['preferred_method']) : '';
  if (function_exists('casanova_redsys_normalize_card_brand')) {
    $prefill_card_brand = casanova_redsys_normalize_card_brand($meta_prefill['preferred_card_brand'] ?? '');
  } else {
    $prefill_card_brand_raw = strtolower(trim((string)($meta_prefill['preferred_card_brand'] ?? '')));
    $prefill_card_brand = ($prefill_card_brand_raw === 'amex' || $prefill_card_brand_raw === 'american_express') ? 'amex' : 'other';
  }
  $auto_start = !empty($meta_prefill['auto_start']);
  $public_locale = function_exists('casanova_portal_get_public_requested_locale')
    ? casanova_portal_get_public_requested_locale()
    : '';

  $idExpediente = (int)($link->id_expediente ?? 0);
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

  if (!function_exists('casanova_giav_reservas_por_expediente')) {
    casanova_render_payment_link_error(__('Reservas no disponibles.', 'casanova-portal'));
    exit;
  }

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || empty($reservas)) {
    casanova_render_payment_link_error(__('No se pudieron cargar las reservas.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_calc_pago_expediente')) {
    casanova_render_payment_link_error(__('No se pudo calcular el pago.', 'casanova-portal'));
    exit;
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) {
    casanova_render_payment_link_error(__('No se pudo calcular el pago.', 'casanova-portal'));
    exit;
  }

  $pending = (float)($calc['pendiente_real'] ?? 0);
  $paid_now = (float)($calc['pagado'] ?? 0);

  if ($pending <= 0.01) {
    casanova_render_payment_link_error(__('No hay pagos pendientes.', 'casanova-portal'));
    exit;
  }

  $scope = strtolower(trim((string)($link->scope ?? 'group_total')));
  $authorized = (float)($link->amount_authorized ?? 0);
  if ($authorized <= 0 || $scope === 'group_total') {
    $authorized = $pending;
  }

  if ($authorized > $pending) {
    $authorized = $pending;
  }

  // Deposito para links individuales y depositos de grupo ya calculados.
  $deposit_allowed = false;
  $deposit_amount = 0.0;
  $deposit_base = in_array($scope, ['passenger_share', 'individual_link'], true) ? $authorized : $pending;
  if ($scope === 'group_base') {
    $group_total_due = (float)($meta_prefill['total_due'] ?? 0);
    $group_deposit_total = (float)($meta_prefill['deposit_total'] ?? 0);
    $group_units = (int)($meta_prefill['units'] ?? 0);
    $group_unit_total = (float)($meta_prefill['unit_total'] ?? 0);
    $group_unit_deposit = (float)($meta_prefill['unit_deposit'] ?? 0);
    if ($group_units > 0 && $group_unit_total > 0) {
      $group_total_due = round($group_unit_total * (float)$group_units, 2);
      $group_deposit_total = round($group_unit_deposit * (float)$group_units, 2);
    }
    if ($prefill_mode === 'deposit' && $group_deposit_total > 0.01 && $group_deposit_total + 0.01 < $group_total_due) {
      $deposit_allowed = true;
      $deposit_base = $group_total_due;
      $deposit_amount = round(min($group_deposit_total, $authorized, $pending), 2);
    }
  } elseif ($scope === 'passenger_share' && $paid_now <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
    $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
  } elseif ($scope === 'individual_link' && $paid_now <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
    $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
  }
  if ($deposit_allowed && $scope !== 'group_base' && function_exists('casanova_payments_calc_deposit_amount')) {
    $deposit_amount = casanova_payments_calc_deposit_amount($deposit_base, $idExpediente);
  }
  $deposit_effective = ($deposit_allowed && ($deposit_amount + 0.01 < $deposit_base));

  $min_amount = (float) apply_filters(
    'casanova_min_partial_payment_amount',
    function_exists('casanova_payments_get_deposit_min_amount') ? (float)casanova_payments_get_deposit_min_amount() : 50.00,
    $idExpediente,
    $idCliente
  );

  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
  }

  $autostart_mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
  if ($autostart_mode !== 'deposit' && $autostart_mode !== 'full') {
    $autostart_mode = '';
  }

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['autostart']) && $auto_start && $prefill_name !== '' && $prefill_lastname !== '' && $prefill_dni !== '') {
    $method = ($prefill_method === 'bank_transfer' && $inespay_enabled) ? 'bank_transfer' : 'card';
    $card_brand = $method === 'card' ? $prefill_card_brand : 'other';
    $mode_prefill = $autostart_mode !== '' ? $autostart_mode : $prefill_mode;
    $mode = ($mode_prefill === 'deposit' && $deposit_effective) ? 'deposit' : 'full';
    $nonce = wp_create_nonce('casanova_pay_link_' . (int)$link->id);
    $autostart_url = casanova_payment_link_url((string)$link->token);
    if (function_exists('casanova_portal_add_public_locale_arg')) {
      $autostart_url = casanova_portal_add_public_locale_arg($autostart_url, $public_locale);
    }

    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    echo '<form id="casanova-auto" method="post" action="' . esc_url($autostart_url) . '">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
    echo '<input type="hidden" name="billing_name" value="' . esc_attr($prefill_name) . '" />';
    echo '<input type="hidden" name="billing_lastname" value="' . esc_attr($prefill_lastname) . '" />';
    echo '<input type="hidden" name="billing_email" value="' . esc_attr($prefill_email) . '" />';
    echo '<input type="hidden" name="billing_dni" value="' . esc_attr($prefill_dni) . '" />';
    echo '<input type="hidden" name="mode" value="' . esc_attr($mode) . '" />';
    echo '<input type="hidden" name="method" value="' . esc_attr($method) . '" />';
    echo '<input type="hidden" name="card_brand" value="' . esc_attr($card_brand) . '" />';
    echo '</form>';
    echo '<script>document.getElementById("casanova-auto").submit();</script>';
    exit;
  }

  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if ($method === 'POST') {
    $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'casanova_pay_link_' . (int)$link->id)) {
      casanova_render_payment_link_error(__('Solicitud no valida.', 'casanova-portal'));
      exit;
    }

    $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
    $dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
    if ($dni === '') {
      casanova_render_payment_link_error(__('Debes indicar el DNI/NIF.', 'casanova-portal'));
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

    $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
    if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
    if ($paid_now > 0.01 || !$deposit_effective) {
      $mode = 'full';
    }

    $amount_to_pay = ($mode === 'deposit' && $deposit_effective) ? $deposit_amount : $authorized;
    $amount_to_pay = round((float)$amount_to_pay, 2);

    if ($amount_to_pay < 0.01) {
      casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
      exit;
    }

    if ($amount_to_pay - $pending > 0.01) {
      casanova_render_payment_link_error(__('Importe superior al pendiente.', 'casanova-portal'));
      exit;
    }

    $is_full = ($amount_to_pay + 0.01 >= $pending);
    if (!$is_full && $amount_to_pay < $min_amount) {
      casanova_render_payment_link_error(__('Importe inferior al minimo permitido.', 'casanova-portal'));
      exit;
    }

    if (!function_exists('casanova_payment_intent_create') || !function_exists('casanova_payment_intent_update')) {
      casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
      exit;
    }

    $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
    if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';

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

    if ($selected_method === 'bank_transfer' && !$inespay_enabled) {
      casanova_render_payment_link_error(__('Transferencia no disponible.', 'casanova-portal'));
      exit;
    }

    $link_meta_updates = [
      'billing_name' => $billing_name,
      'billing_lastname' => $billing_lastname,
      'billing_fullname' => $billing_fullname,
      'billing_dni' => $dni,
      'billing_email' => $billing_email,
      'mode' => $mode,
      'preferred_method' => $selected_method,
      'preferred_card_brand' => $selected_card_brand,
      'locale' => $public_locale,
    ];
    if ($scope === 'individual_link') {
      $link_meta_updates['auto_start'] = true;
    }

    casanova_payment_link_update((int)$link->id, [
      'billing_dni' => $dni,
      'metadata' => casanova_payment_link_merge_metadata($link->metadata ?? null, $link_meta_updates),
    ]);

    $payload_base = [
      'source' => 'payment_link',
      'requested_amount' => $amount_to_pay,
      'pending_at_create' => round($pending, 2),
      'mode' => $mode,
      'method' => $selected_method,
      'card_brand' => $selected_card_brand,
      'billing_dni' => $dni,
      'billing_name' => $billing_name,
      'billing_lastname' => $billing_lastname,
      'billing_fullname' => $billing_fullname,
      'billing_email' => $billing_email,
      'locale' => $public_locale,
      'payment_link' => [
        'id' => (int)$link->id,
        'token' => (string)$link->token,
        'scope' => (string)$link->scope,
        'authorized_amount' => (float)$authorized,
      ],
    ];

    if ($selected_method === 'bank_transfer') {
      $reference = 'CAS-' . (int)$idExpediente . '-' . substr(casanova_payments_new_token(), 0, 10);
      $payer_name = $billing_fullname !== '' ? $billing_fullname : 'Portal';

      $intent = casanova_payment_intent_create([
        'user_id' => 0,
        'id_cliente' => $idCliente,
        'id_expediente' => $idExpediente,
        'amount' => $amount_to_pay,
        'currency' => 'EUR',
        'status' => 'created',
        'provider' => 'inespay',
        'method' => 'bank_transfer',
        'provider_reference' => $reference,
        'payload' => $payload_base,
      ]);

    if (is_wp_error($intent)) {
      casanova_render_payment_link_error($intent->get_error_message());
      exit;
    }

    if (!is_object($intent) || empty($intent->id) || empty($intent->token)) {
      casanova_render_payment_link_error(__('Intent invalido.', 'casanova-portal'));
      exit;
    }

      if (!class_exists('Casanova_Inespay_Service')) {
        casanova_render_payment_link_error(__('Inespay no disponible.', 'casanova-portal'));
        exit;
      }

      $notif_url = home_url('/wp-json/casanova/v1/inespay/notify');
      $success_link = add_query_arg([
        'payment' => 'success',
        'intent_id' => (int)$intent->id,
      ], casanova_payment_link_url((string)$link->token));
      $abort_link = add_query_arg([
        'payment' => 'failed',
        'intent_id' => (int)$intent->id,
      ], casanova_payment_link_url((string)$link->token));
      if (function_exists('casanova_portal_add_public_locale_arg')) {
        $success_link = casanova_portal_add_public_locale_arg($success_link, $public_locale);
        $abort_link = casanova_portal_add_public_locale_arg($abort_link, $public_locale);
      }

      $payment_label = $mode === 'deposit'
        ? __('Depósito', 'casanova-portal')
        : __('Pago', 'casanova-portal');

      $req = [
        'amount' => (int)round($amount_to_pay * 100),
        'description' => $payment_label . ' Casanova Golf (' . (int)$idExpediente . ')',
        'subject' => $payment_label . ' Casanova Golf (' . (int)$idExpediente . ')',
        'reference' => $reference,
        'notifUrl' => $notif_url,
        'successLinkRedirect' => $success_link,
        'abortLinkRedirect' => $abort_link,
        'urlNotif' => $notif_url,
        'urlOk' => $success_link,
        'urlError' => $abort_link,
        'customData' => wp_json_encode([
          'token' => (string)$intent->token,
          'intent_id' => (int)$intent->id,
          'expediente_id' => (int)$idExpediente,
          'idCliente' => (int)$idCliente,
          'payer' => $payer_name,
          'billing_dni' => $dni,
          'billing_name' => $billing_fullname,
          'payment_link_token' => (string)$link->token,
        ]),
      ];

      $res = Casanova_Inespay_Service::init_single_payment($req);
      if (is_wp_error($res)) {
        casanova_payment_intent_update((int)$intent->id, [
          'status' => 'failed',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'inespay_init' => [
              'ok' => false,
              'error' => $res->get_error_message(),
              'error_data' => $res->get_error_data(),
              'time' => current_time('mysql'),
            ],
          ]),
        ]);
        casanova_render_payment_link_error(__('No se pudo iniciar el pago por transferencia.', 'casanova-portal'));
        exit;
      }

      $single_id = '';
      if (!empty($res['singlePayinId']) && is_string($res['singlePayinId'])) {
        $single_id = $res['singlePayinId'];
      }

      $redirect_url = Casanova_Inespay_Service::extract_redirect_url($res);
      if ($redirect_url === '') {
        casanova_payment_intent_update((int)$intent->id, [
          'status' => 'failed',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'inespay_init' => [
              'ok' => false,
              'error' => 'missing_redirect_url',
              'response' => $res,
              'time' => current_time('mysql'),
            ],
          ]),
        ]);
        casanova_render_payment_link_error(__('Inespay no devolvio un enlace de pago.', 'casanova-portal'));
        exit;
      }

      casanova_payment_intent_update((int)$intent->id, [
        'provider_payment_id' => $single_id ?: null,
        'status' => 'initiated',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
          'inespay_init' => [
            'ok' => true,
            'response' => $res,
            'time' => current_time('mysql'),
          ],
        ]),
      ]);

      wp_redirect($redirect_url);
      exit;
    }

    $intent = casanova_payment_intent_create([
      'user_id' => 0,
      'id_cliente' => $idCliente,
      'id_expediente' => $idExpediente,
      'amount' => $amount_to_pay,
      'currency' => 'EUR',
      'status' => 'created',
      'payload' => $payload_base,
    ]);

    if (is_wp_error($intent)) {
      casanova_render_payment_link_error($intent->get_error_message());
      exit;
    }

    if (!is_object($intent) || empty($intent->id) || empty($intent->token)) {
      casanova_render_payment_link_error(__('Intent invalido.', 'casanova-portal'));
      exit;
    }

    if (!function_exists('casanova_redsys_order_from_intent_id')) {
      casanova_render_payment_link_error(__('Redsys no disponible.', 'casanova-portal'));
      exit;
    }

    $order = casanova_redsys_order_from_intent_id((int)$intent->id);
    casanova_payment_intent_update((int)$intent->id, ['order_redsys' => $order]);
    $intent->order_redsys = $order;

    if (!function_exists('casanova_redsys_prepare_redirect_data')) {
      casanova_render_payment_link_error(__('Config Redsys no disponible.', 'casanova-portal'));
      exit;
    }

    $redsys_redirect = casanova_redsys_prepare_redirect_data($intent, [
      'source' => 'payment_link',
      'mode' => $mode,
      'card_brand' => $selected_card_brand,
      'id_expediente' => $idExpediente,
      'id_cliente' => $idCliente,
      'payment_link_id' => (int)($link->id ?? 0),
      'payment_link_token' => (string)($link->token ?? ''),
    ]);
    if (is_wp_error($redsys_redirect)) {
      casanova_render_payment_link_error($redsys_redirect->get_error_message());
      exit;
    }

    if (function_exists('casanova_redsys_attach_intent_tpv')) {
      casanova_redsys_attach_intent_tpv(
        (int)$intent->id,
        $intent->payload ?? null,
        (string)($redsys_redirect['tpv_key'] ?? 'default')
      );
    }

    casanova_payment_intent_update((int)$intent->id, ['status' => 'redirecting']);

    while (ob_get_level()) ob_end_clean();

    echo '<form id="redsys" action="' . esc_url((string)$redsys_redirect['endpoint']) . '" method="post">';
    echo '<input type="hidden" name="Ds_SignatureVersion" value="' . esc_attr((string)$redsys_redirect['sig_version']) . '">';
    echo '<input type="hidden" name="Ds_MerchantParameters" value="' . esc_attr((string)$redsys_redirect['merchant_parameters']) . '">';
    echo '<input type="hidden" name="Ds_Signature" value="' . esc_attr((string)$redsys_redirect['signature']) . '">';
    echo '</form>';
    echo '<script>document.getElementById("redsys").submit();</script>';
    exit;
  }

  $exp_label = '';
  $exp_codigo = '';
  if (function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, $idExpediente);
    $exp_label = trim((string)($meta['label'] ?? ''));
    $exp_codigo = trim((string)($meta['codigo'] ?? ''));
  }
  if ($exp_label === '') {
    $exp_label = sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente);
  }

  $deadline_txt = '';
  if (function_exists('casanova_payments_min_fecha_limite')) {
    $deadline = casanova_payments_min_fecha_limite($reservas);
    if ($deadline instanceof DateTimeInterface) {
      $deadline_txt = $deadline->format('d/m/Y');
    }
  }

  $nonce = wp_create_nonce('casanova_pay_link_' . (int)$link->id);
  $pref_mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
  if ($pref_mode !== 'deposit' && $pref_mode !== 'full') $pref_mode = '';
  $checked_deposit = ($deposit_effective && ($pref_mode === 'deposit' || $pref_mode === ''));
  $checked_full = !$checked_deposit;
  $transfer_note = __('El pago por transferencia bancaria online PSD2 no tiene recargo y es completamente seguro. Serás redirigido a una página de pago donde podrás seleccionar tu banco y acceder a tu banca online para autorizar la transferencia. Una vez completado el pago, volverás automáticamente a nuestra página. Este método es compatible con la mayoría de bancos españoles y portugueses.', 'casanova-portal');
  $payment_page_url = casanova_payment_link_url((string)$link->token);
  if (function_exists('casanova_portal_add_public_locale_arg')) {
    $payment_page_url = casanova_portal_add_public_locale_arg($payment_page_url, $public_locale);
  }
  $selector_html = function_exists('casanova_portal_public_language_selector_html')
    ? casanova_portal_public_language_selector_html($payment_page_url, $pref_mode !== '' ? ['mode' => $pref_mode] : [])
    : '';

  casanova_portal_render_public_document_start(__('Pago seguro', 'casanova-portal'));
  echo '<section class="casanova-public-page">';
  if ($selector_html !== '') {
    echo '<div class="casanova-public-page__toolbar">' . $selector_html . '</div>';
  }
  echo casanova_portal_public_logo_html();
  echo '<h2 class="casanova-public-page__title">' . esc_html__('Pago seguro', 'casanova-portal') . '</h2>';

  $codigo_html = '';
  if ($exp_codigo !== '') {
    $codigo_html = ' <span class="casanova-public-page__code">(' . esc_html($exp_codigo) . ')</span>';
  }
  echo '<p class="casanova-public-page__trip">' . wp_kses_post(
    sprintf(
      __('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'),
      esc_html($exp_label),
      $codigo_html
    )
  ) . '</p>';

  echo '<div class="casanova-public-page__summary">';

  $pendiente_html = '<strong>' . esc_html(number_format_i18n($pending, 2)) . ' EUR</strong>';
  echo '<div class="casanova-public-page__summary-line">' . wp_kses_post(
    sprintf(
      __('Pendiente total: %s', 'casanova-portal'),
      $pendiente_html
    )
  ) . '</div>';

  if ($deadline_txt !== '') {
    echo '<div class="casanova-public-page__summary-line">' . esc_html(
      sprintf(
        __('Fecha limite deposito: %s', 'casanova-portal'),
        $deadline_txt
      )
    ) . '</div>';
  }

  echo '</div>';

  echo '<form class="casanova-public-form" method="post" action="' . esc_url($payment_page_url) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Nombre', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_name" required value="' . esc_attr($prefill_name) . '" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Apellidos', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_lastname" required value="' . esc_attr($prefill_lastname) . '" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('Email (obligatorio)', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="email" name="billing_email" autocomplete="email" required value="' . esc_attr($prefill_email) . '" />';
  echo '</label>';

  echo '<label class="casanova-public-field"><span class="casanova-public-field__label">' . esc_html__('DNI / NIF (obligatorio)', 'casanova-portal') . '</span>';
  echo '<input class="casanova-public-field__control" type="text" name="billing_dni" required value="' . esc_attr($prefill_dni) . '" />';
  echo '</label>';

  echo '<div class="casanova-public-section-label">' . esc_html__('Metodo de pago', 'casanova-portal') . '</div>';
  if ($inespay_enabled) {
    $card_checked = ($prefill_method === 'bank_transfer') ? '' : 'checked';
    $bank_checked = ($prefill_method === 'bank_transfer') ? 'checked' : '';
    echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="card" ' . $card_checked . ' />' . esc_html__('Tarjeta', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label class="casanova-public-choice casanova-public-choice--compact">';
    echo '<input class="casanova-public-choice__control" type="radio" name="method" value="bank_transfer" ' . $bank_checked . ' />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
    echo '<span class="casanova-public-choice__hint">' . esc_html__('PSD2 · Sin recargo.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
    $note_class = 'casanova-public-page__method-note';
    if ($prefill_method !== 'bank_transfer') {
      $note_class .= ' casanova-hidden';
    }
    echo '<div id="casanova-method-note" class="' . esc_attr($note_class) . '">' . esc_html($transfer_note) . '</div>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div class="casanova-public-field__hint">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  $card_brand_wrap_class = ($inespay_enabled && $prefill_method === 'bank_transfer') ? 'casanova-hidden' : '';
  echo '<div id="casanova-card-brand-wrap" class="' . esc_attr($card_brand_wrap_class) . '">';
  echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
  echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
  echo '<label class="casanova-public-choice casanova-public-choice--compact">';
  echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" ' . ($prefill_card_brand === 'amex' ? '' : 'checked') . ' />' . esc_html__('Otra tarjeta', 'casanova-portal');
  echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
  echo '</label>';
  echo '<label class="casanova-public-choice casanova-public-choice--compact">';
  echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" ' . ($prefill_card_brand === 'amex' ? 'checked' : '') . ' />' . esc_html__('American Express (AMEX)', 'casanova-portal');
  echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opcion si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
  echo '</label>';
  echo '</div>';
  echo '<div class="casanova-public-field__hint">' . esc_html__('Usaremos el TPV correcto segun la tarjeta que vayas a usar.', 'casanova-portal') . '</div>';
  echo '</div>';

  if ($deposit_effective) {
    echo '<label class="casanova-public-choice">';
    echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="deposit" ' . ($checked_deposit ? 'checked' : '') . ' />';
    $deposit_amount_html = '<strong>' . esc_html(number_format_i18n($deposit_amount, 2)) . ' EUR</strong>';
    echo wp_kses_post(
      sprintf(
        __('Pagar deposito: %s', 'casanova-portal'),
        $deposit_amount_html
      )
    );
    echo '</label>';
  } else {
    echo '<input type="hidden" name="mode" value="full" />';
  }

  echo '<label class="casanova-public-choice">';
  echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="full" ' . ($checked_full ? 'checked' : '') . ' />';
  $amount_html = '<strong>' . esc_html(number_format_i18n($authorized, 2)) . ' EUR</strong>';
  echo wp_kses_post(
    sprintf(
      __('Pagar ahora: %s', 'casanova-portal'),
      $amount_html
    )
  );
  echo '</label>';

  echo '<div class="casanova-public-page__actions">';
  echo '<button class="casanova-public-button" type="submit">' . esc_html__('Continuar al pago', 'casanova-portal') . '</button>';
  echo '</div>';

  echo '</form>';
  if ($inespay_enabled) {
    echo '<script>
      (function(){
        const note = document.getElementById("casanova-method-note");
        const cardBrandWrap = document.getElementById("casanova-card-brand-wrap");
        if (!note) return;
        const inputs = document.querySelectorAll("input[name=method]");
        function update(){
          let method = "card";
          Array.prototype.forEach.call(inputs, function(i){ if (i.checked) method = i.value; });
          note.classList.toggle("casanova-hidden", method !== "bank_transfer");
          if (cardBrandWrap) {
            cardBrandWrap.classList.toggle("casanova-hidden", method !== "card");
          }
        }
        Array.prototype.forEach.call(inputs, function(i){ i.addEventListener("change", update); });
        update();
      })();
    </script>';
  }

  echo '<p class="casanova-public-page__footer">'
    . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal')
    . '</p>';

  echo '</section>';
  casanova_portal_render_public_document_end();
  exit;
}

function casanova_render_payment_link_success($link): void {
  $meta = [];
  $raw_meta = (string)($link->metadata ?? '');
  if ($raw_meta !== '') {
    $decoded = json_decode($raw_meta, true);
    if (is_array($decoded)) {
      $meta = $decoded;
    }
  }

  $billing_email = trim((string)($meta['billing_email'] ?? ''));
  $mode = strtolower(trim((string)($meta['mode'] ?? '')));
  $scope = strtolower(trim((string)($link->scope ?? '')));
  $id_expediente = (int)($link->id_expediente ?? 0);

  $is_group_payment = !empty($meta['group_token_id']);
  if (!$is_group_payment && $id_expediente > 0 && function_exists('casanova_giav_expediente_is_group_by_id')) {
    $is_group_payment = (bool) casanova_giav_expediente_is_group_by_id($id_expediente);
  }
  if (!$is_group_payment) {
    $is_group_payment = in_array($scope, ['group_base', 'slot_base', 'passenger_share'], true);
  }

  casanova_portal_render_public_document_start(__('Pago registrado correctamente', 'casanova-portal'));
  echo '<section class="casanova-public-page">';
  echo casanova_portal_public_logo_html();
  echo '<h2 class="casanova-public-page__title">' . esc_html__('Pago registrado correctamente', 'casanova-portal') . '</h2>';
  echo '<p class="casanova-public-page__intro">' . esc_html__('Gracias. Hemos recibido tu pago.', 'casanova-portal') . '</p>';

  if ($billing_email !== '' && is_email($billing_email)) {
    echo '<div class="casanova-public-page__notice">';
    echo esc_html(sprintf(__('Te hemos enviado la confirmacion a %s.', 'casanova-portal'), $billing_email));
    echo '</div>';
  }
  if ($mode === 'deposit') {
    echo '<div class="casanova-public-page__notice">';
    echo esc_html__('Si quedaba importe pendiente, recibiras ademas un enlace por email para completar el pago mas adelante.', 'casanova-portal');
    echo '</div>';
  }

  if (!$is_group_payment) {
    $link_account_url = function_exists('site_url') ? site_url('/login/') : home_url('/');
    echo '<div class="casanova-public-page__success-panel">';
    echo '<strong>' . esc_html__('Quieres acceder a tus documentos y facturas?', 'casanova-portal') . '</strong><br />';
    echo '<a class="casanova-public-link" href="' . esc_url($link_account_url) . '">'
      . esc_html__('Crear o vincular mi cuenta', 'casanova-portal')
      . '</a>';
    echo '</div>';
  }

  echo '</section>';
  casanova_portal_render_public_document_end();
}

function casanova_render_payment_link_error(string $message): void {
  casanova_portal_render_public_document_start(__('No se pudo completar el pago', 'casanova-portal'));
  echo '<section class="casanova-public-page casanova-public-page--error">';
  echo '<h2 class="casanova-public-page__title">' . esc_html__('No se pudo completar el pago', 'casanova-portal') . '</h2>';
  echo '<div class="casanova-public-page__notice casanova-public-page__notice--error">' . esc_html($message) . '</div>';
  echo '</section>';
  casanova_portal_render_public_document_end();
}


/**
 * Tras registrar un cobro de un depósito (scope slot_base|group_base, mode=deposit),
 * genera un magic link one-shot para pagar el resto y lo envía al email capturado.
 */
function casanova_maybe_send_magic_resto_link(int $intent_id): void {
  if ($intent_id <= 0) return;
  if (!function_exists('casanova_payment_intent_get')) return;

  $intent = casanova_payment_intent_get($intent_id);
  if (!$intent) return;

  $payload = [];
  $rawPayload = (string)($intent->payload ?? '');
  if ($rawPayload !== '') {
    $decoded = json_decode($rawPayload, true);
    if (is_array($decoded)) $payload = $decoded;
  }

  $plink = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
  $payment_link_id = (int)($plink['id'] ?? 0);
  $payment_link_scope = (string)($plink['scope'] ?? '');
  if ($payment_link_id <= 0 || !in_array($payment_link_scope, ['slot_base','group_base','individual_link'], true)) return;

  if (!function_exists('casanova_payment_link_get')) return;
  $link = casanova_payment_link_get($payment_link_id);
  if (!$link) return;

  if (function_exists('casanova_payment_links_send_or_create_rest_magic')) {
    casanova_payment_links_send_or_create_rest_magic($link, $intent);
    return;
  }

  $meta = [];
  $rawMeta = (string)($link->metadata ?? '');
  if ($rawMeta !== '') {
    $decoded = json_decode($rawMeta, true);
    if (is_array($decoded)) $meta = $decoded;
  }

  $mode = strtolower(trim((string)($meta['mode'] ?? '')));
  if ($mode !== 'deposit') return;

  if (!empty($meta['rest_magic_sent_at']) || !empty($meta['rest_magic_link_id'])) return;

  $email = trim((string)($meta['billing_email'] ?? ''));
  if ($email === '' || !is_email($email)) return;

  $remaining = 0.0;
  if ($payment_link_scope === 'individual_link') {
    $remaining = casanova_payment_link_email_remaining_after_deposit(
      $intent,
      (int)($link->id_expediente ?? 0),
      (int)($intent->id_cliente ?? 0)
    );
  } else {
    $total_due = (float)($meta['total_due'] ?? 0);
    $deposit_total = (float)($meta['deposit_total'] ?? 0);

    if ($payment_link_scope === 'group_base') {
      $units = (int)($meta['units'] ?? 0);
      $unit_total = (float)($meta['unit_total'] ?? 0);
      $unit_deposit = (float)($meta['unit_deposit'] ?? 0);
      if ($units > 0 && $unit_total > 0) {
        $total_due = round($unit_total * (float)$units, 2);
        $deposit_total = round($unit_deposit * (float)$units, 2);
      }
    }

    $remaining = round(max(0.0, $total_due - $deposit_total), 2);
  }

  if ($remaining <= 0.01) return;

  if (!function_exists('casanova_mail_send') || !function_exists('casanova_tpl_email_resto_pago_magic_link')) return;

    if ($payment_link_scope === 'individual_link') {
      $meta['rest_magic_link_id'] = (int)$link->id;
      $meta['rest_magic_token'] = (string)($link->token ?? '');
      $meta['rest_magic_sent_at'] = current_time('mysql');
      $meta['remaining'] = $remaining;
      $meta['auto_start'] = true;

    if (function_exists('casanova_payment_link_update')) {
      casanova_payment_link_update((int)$link->id, [
        'status' => 'active',
        'expires_at' => null,
        'metadata' => wp_json_encode($meta),
      ]);
    }

    $url_pago = add_query_arg([
      'autostart' => '1',
      'mode' => 'full',
    ], casanova_payment_link_url((string)$link->token));
    if (function_exists('casanova_portal_add_public_locale_arg')) {
      $url_pago = casanova_portal_add_public_locale_arg($url_pago, (string)($meta['locale'] ?? ''));
    }
  } else {
    $expires_at = function_exists('casanova_payment_links_rest_expires_at')
      ? casanova_payment_links_rest_expires_at((int)($link->id_expediente ?? 0))
      : date('Y-m-d H:i:s', time() + (14 * DAY_IN_SECONDS));

    $new = casanova_payment_link_create([
      'id_expediente' => (int)($link->id_expediente ?? 0),
      'id_reserva_pq' => !empty($link->id_reserva_pq) ? (int)$link->id_reserva_pq : null,
      'scope' => $payment_link_scope,
      'amount_authorized' => $remaining,
      'currency' => (string)($link->currency ?? 'EUR'),
      'status' => 'active',
      'expires_at' => $expires_at,
      'created_by' => 'magic_resto',
      'billing_dni' => (string)($meta['billing_dni'] ?? ($link->billing_dni ?? '')),
      'metadata' => array_merge($meta, [
        'mode' => 'rest',
        'parent_payment_link_id' => (int)$link->id,
        'deposit_paid' => $deposit_total,
        'remaining' => $remaining,
        'final_payment_deadline' => substr($expires_at, 0, 10),
        'auto_start' => true,
        'origin' => 'magic_resto',
      ]),
    ]);

    if (is_wp_error($new) || !$new) return;

    $meta['rest_magic_link_id'] = (int)$new->id;
    $meta['rest_magic_token'] = (string)($new->token ?? '');
    $meta['rest_magic_sent_at'] = current_time('mysql');
    if (function_exists('casanova_payment_link_update')) {
      casanova_payment_link_update((int)$link->id, [
        'metadata' => wp_json_encode($meta),
      ]);
    }

    $url_pago = casanova_payment_link_url((string)$new->token);
    if (function_exists('casanova_portal_add_public_locale_arg')) {
      $url_pago = casanova_portal_add_public_locale_arg($url_pago, (string)($meta['locale'] ?? ''));
    }
  }

  $codExp = '';
  $exp_id = (int)($link->id_expediente ?? 0);
  if ($exp_id > 0 && function_exists('casanova_giav_expediente_get')) {
    try {
      $exp = casanova_giav_expediente_get($exp_id);
      if (is_object($exp)) {
        $codExp = (string)($exp->CodigoExpediente ?? ($exp->Codigo ?? ($exp->Titulo ?? '')));
      }
    } catch (Throwable $e) {}
  }
  $tpl = casanova_tpl_email_resto_pago_magic_link([
    'to_email' => $email,
    'cliente_nombre' => (string)($meta['billing_name'] ?? ''),
    'idExpediente' => (int)($link->id_expediente ?? 0),
    'codigoExpediente' => $codExp,
    'importe' => number_format($remaining, 2, ',', '.') . ' €',
    'fecha_limite' => function_exists('casanova_payment_links_final_payment_deadline_label') ? casanova_payment_links_final_payment_deadline_label((int)($link->id_expediente ?? 0)) : '',
    'url_pago' => $url_pago,
  ]);

  if (!empty($tpl['to']) && !empty($tpl['subject']) && !empty($tpl['html'])) {
    casanova_mail_send($tpl['to'], (string)$tpl['subject'], (string)$tpl['html']);
  }
}

function casanova_payment_links_read_metadata($row): array {
  if ($row && is_object($row) && isset($row->_meta) && is_array($row->_meta)) {
    return $row->_meta;
  }

  $raw = '';
  if ($row && is_object($row)) {
    $raw = (string)($row->metadata ?? '');
  } elseif (is_array($row)) {
    $raw = (string)($row['metadata'] ?? '');
  }

  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function casanova_payment_links_deposit_amounts($link, array $meta, $intent = null): array {
  $scope = strtolower(trim((string)($link->scope ?? '')));

  if ($scope === 'individual_link') {
    if (is_object($intent)) {
      $remaining = casanova_payment_link_email_remaining_after_deposit(
        $intent,
        (int)($link->id_expediente ?? 0),
        (int)($intent->id_cliente ?? 0)
      );
    } else {
      $remaining = round((float)($meta['remaining'] ?? 0), 2);
    }

    $deposit_total = is_object($intent)
      ? round((float)($intent->amount ?? 0), 2)
      : round((float)($link->amount_authorized ?? 0), 2);

    return [
      'total_due' => round($deposit_total + max(0.0, $remaining), 2),
      'deposit_total' => $deposit_total,
      'remaining' => round(max(0.0, $remaining), 2),
    ];
  }

  $total_due = (float)($meta['total_due'] ?? 0);
  $deposit_total = (float)($meta['deposit_total'] ?? 0);

  if ($scope === 'group_base') {
    $units = (int)($meta['units'] ?? 0);
    $unit_total = (float)($meta['unit_total'] ?? 0);
    $unit_deposit = (float)($meta['unit_deposit'] ?? 0);
    if ($units > 0 && $unit_total > 0) {
      $total_due = round($unit_total * (float)$units, 2);
      $deposit_total = round($unit_deposit * (float)$units, 2);
    }
  }

  return [
    'total_due' => round($total_due, 2),
    'deposit_total' => round($deposit_total, 2),
    'remaining' => round(max(0.0, $total_due - $deposit_total), 2),
  ];
}

function casanova_payment_links_date_from_value($value): ?DateTimeImmutable {
  $raw = trim((string)($value ?? ''));
  if ($raw === '') return null;

  $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
  $date = substr($raw, 0, 10);
  $candidate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $raw;

  try {
    $dt = new DateTimeImmutable($candidate, $tz);
  } catch (Throwable $e) {
    $ts = strtotime($raw);
    if (!$ts) return null;
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
  }

  return ((int)$dt->format('Y') >= 2000) ? $dt : null;
}

function casanova_payment_links_trip_start_date(int $idExpediente): ?DateTimeImmutable {
  if ($idExpediente <= 0 || !function_exists('casanova_giav_expediente_get')) return null;

  try {
    $exp = casanova_giav_expediente_get($idExpediente);
  } catch (Throwable $e) {
    return null;
  }

  if (!is_object($exp) || is_wp_error($exp)) return null;

  foreach (['FechaInicio', 'FechaDesde', 'Desde', 'FechaSalida'] as $prop) {
    if (!empty($exp->{$prop})) {
      $dt = casanova_payment_links_date_from_value($exp->{$prop});
      if ($dt) return $dt;
    }
  }

  return null;
}

function casanova_payment_links_final_payment_deadline(int $idExpediente): ?DateTimeImmutable {
  $start = casanova_payment_links_trip_start_date($idExpediente);
  if (!$start) return null;

  return $start->modify('-5 days')->setTime(23, 59, 59);
}

function casanova_payment_links_final_payment_deadline_label(int $idExpediente): string {
  $deadline = casanova_payment_links_final_payment_deadline($idExpediente);
  return $deadline ? $deadline->format('d/m/Y') : '';
}

function casanova_payment_links_rest_expires_at(int $idExpediente): string {
  $deadline = casanova_payment_links_final_payment_deadline($idExpediente);
  if ($deadline) {
    return $deadline->format('Y-m-d H:i:s');
  }

  $day_seconds = defined('DAY_IN_SECONDS') ? (int)DAY_IN_SECONDS : 86400;
  return date('Y-m-d H:i:s', time() + (90 * $day_seconds));
}

function casanova_payment_links_is_effective_deposit($link, array $meta): bool {
  if (!$link || !is_object($link)) return false;

  $mode = strtolower(trim((string)($meta['mode'] ?? '')));
  if ($mode === 'deposit') return true;

  $scope = strtolower(trim((string)($link->scope ?? '')));
  if ($scope !== 'group_base') return false;

  $created_by = strtolower(trim((string)($link->created_by ?? '')));
  if ($created_by !== '' && $created_by !== 'group') return false;

  $amounts = casanova_payment_links_deposit_amounts($link, $meta, null);
  $deposit_total = round((float)($amounts['deposit_total'] ?? 0), 2);
  $remaining = round((float)($amounts['remaining'] ?? 0), 2);
  $authorized = round((float)($link->amount_authorized ?? 0), 2);

  return $deposit_total > 0.01 && $remaining > 0.01 && $authorized <= $deposit_total + 0.01;
}

function casanova_payment_links_intent_payload($intent): array {
  if (!$intent || !is_object($intent)) return [];

  $raw = (string)($intent->payload ?? '');
  if ($raw === '') return [];

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function casanova_payment_links_confirmed_intent_for_link($link) {
  global $wpdb;

  if (!$link || !is_object($link) || !function_exists('casanova_payments_table')) return null;

  $link_id = (int)($link->id ?? 0);
  $link_token = trim((string)($link->token ?? ''));
  $id_expediente = (int)($link->id_expediente ?? 0);
  if ($link_id <= 0 || $link_token === '' || $id_expediente <= 0) return null;

  $table = casanova_payments_table();
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM {$table} WHERE id_expediente=%d AND payload LIKE %s AND payload LIKE %s ORDER BY updated_at DESC, id DESC LIMIT 30",
      $id_expediente,
      '%"payment_link"%',
      '%' . $wpdb->esc_like($link_token) . '%'
    )
  );

  if (empty($rows)) return null;

  foreach ($rows as $intent) {
    $payload = casanova_payment_links_intent_payload($intent);
    $payment_link = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
    $payload_link_id = (int)($payment_link['id'] ?? 0);
    $payload_link_token = trim((string)($payment_link['token'] ?? ''));
    if ($payload_link_id !== $link_id && $payload_link_token !== $link_token) {
      continue;
    }

    $giav_cobro = is_array($payload['giav_cobro'] ?? null) ? $payload['giav_cobro'] : [];
    if (empty($giav_cobro['cobro_id']) && empty($giav_cobro['inserted_at'])) {
      continue;
    }

    $intent->_payload = $payload;
    return $intent;
  }

  return null;
}

function casanova_payment_links_mark_paid_from_confirmed_intent($link, $intent, string $dni = ''): void {
  if (!$link || !is_object($link) || !$intent || !is_object($intent) || !function_exists('casanova_payment_link_update')) return;

  $payload = isset($intent->_payload) && is_array($intent->_payload)
    ? $intent->_payload
    : casanova_payment_links_intent_payload($intent);
  $giav_cobro = is_array($payload['giav_cobro'] ?? null) ? $payload['giav_cobro'] : [];
  $cobro_id = (int)($giav_cobro['cobro_id'] ?? 0);
  $paid_at = trim((string)($giav_cobro['inserted_at'] ?? ($intent->updated_at ?? '')));
  if ($paid_at === '') {
    $paid_at = current_time('mysql');
  }

  $fields = [
    'status' => 'paid',
    'paid_at' => $paid_at,
  ];
  if ($cobro_id > 0) {
    $fields['giav_payment_id'] = $cobro_id;
  }
  if ($dni !== '') {
    $fields['billing_dni'] = $dni;
  }

  casanova_payment_link_update((int)$link->id, $fields);
  $link->status = 'paid';
  $link->paid_at = $paid_at;
  if ($cobro_id > 0) {
    $link->giav_payment_id = $cobro_id;
  }
  if ($dni !== '') {
    $link->billing_dni = $dni;
  }
}

function casanova_payment_links_rest_magic_url(string $token, array $meta, bool $reuse_current_link = false): string {
  $url = casanova_payment_link_url($token);
  if ($reuse_current_link) {
    $url = add_query_arg([
      'autostart' => '1',
      'mode' => 'full',
    ], $url);
  }
  if (function_exists('casanova_portal_add_public_locale_arg')) {
    $url = casanova_portal_add_public_locale_arg($url, (string)($meta['locale'] ?? ''));
  }
  return $url;
}

function casanova_payment_links_expediente_code(int $exp_id): string {
  if ($exp_id <= 0 || !function_exists('casanova_giav_expediente_get')) return '';

  try {
    $exp = casanova_giav_expediente_get($exp_id);
    if (is_object($exp)) {
      return (string)($exp->CodigoExpediente ?? ($exp->Codigo ?? ($exp->Titulo ?? '')));
    }
  } catch (Throwable $e) {}

  return '';
}

function casanova_payment_links_ensure_rest_magic_for_deposit($deposit_link, $intent = null) {
  if (!$deposit_link || !is_object($deposit_link)) return null;

  $scope = strtolower(trim((string)($deposit_link->scope ?? '')));
  if (!in_array($scope, ['slot_base', 'group_base', 'individual_link'], true)) return null;

  $meta = casanova_payment_links_read_metadata($deposit_link);
  $mode = strtolower(trim((string)($meta['mode'] ?? '')));
  if (!casanova_payment_links_is_effective_deposit($deposit_link, $meta)) return null;

  if ($mode !== 'deposit') {
    $meta['mode'] = 'deposit';
  }

  $email = trim((string)($meta['billing_email'] ?? ''));
  if ($email === '' || !is_email($email)) return null;

  $amounts = casanova_payment_links_deposit_amounts($deposit_link, $meta, $intent);
  $remaining = round((float)($amounts['remaining'] ?? 0), 2);
  $rest_token = trim((string)($meta['rest_magic_token'] ?? ''));

  if ($rest_token !== '') {
    $needs_update = ($mode !== 'deposit');
    $expires_at = casanova_payment_links_rest_expires_at((int)($deposit_link->id_expediente ?? 0));
    if ($remaining > 0.01 && (!isset($meta['remaining']) || abs((float)$meta['remaining'] - $remaining) > 0.009)) {
      $meta['remaining'] = $remaining;
      $needs_update = true;
    }
    if (!isset($meta['final_payment_deadline']) || (string)$meta['final_payment_deadline'] !== substr($expires_at, 0, 10)) {
      $meta['final_payment_deadline'] = substr($expires_at, 0, 10);
      $needs_update = true;
    }
    if ($needs_update && function_exists('casanova_payment_link_update')) {
      casanova_payment_link_update((int)$deposit_link->id, ['metadata' => wp_json_encode($meta)]);
    }
    if (function_exists('casanova_payment_link_update')) {
      $rest_link_id = (int)($meta['rest_magic_link_id'] ?? 0);
      if ($rest_link_id > 0) {
        casanova_payment_link_update($rest_link_id, ['expires_at' => $expires_at]);
      } elseif (function_exists('casanova_payment_link_get_by_token')) {
        $rest_link = casanova_payment_link_get_by_token($rest_token);
        if ($rest_link && !empty($rest_link->id)) {
          casanova_payment_link_update((int)$rest_link->id, ['expires_at' => $expires_at]);
        }
      }
    }

    return [
      'meta' => $meta,
      'email' => $email,
      'remaining' => $remaining,
      'expires_at' => $expires_at,
      'url_pago' => casanova_payment_links_rest_magic_url($rest_token, $meta, $scope === 'individual_link' && $rest_token === (string)($deposit_link->token ?? '')),
    ];
  }

  if ($remaining <= 0.01 || !function_exists('casanova_payment_link_update')) return null;

  if ($scope === 'individual_link') {
    $token = trim((string)($deposit_link->token ?? ''));
    if ($token === '') return null;

    $meta['rest_magic_link_id'] = (int)$deposit_link->id;
    $meta['rest_magic_token'] = $token;
    $meta['remaining'] = $remaining;
    $meta['auto_start'] = true;

    casanova_payment_link_update((int)$deposit_link->id, [
      'status' => 'active',
      'expires_at' => null,
      'metadata' => wp_json_encode($meta),
    ]);

    return [
      'meta' => $meta,
      'email' => $email,
      'remaining' => $remaining,
      'expires_at' => null,
      'url_pago' => casanova_payment_links_rest_magic_url($token, $meta, true),
    ];
  }

  if (!function_exists('casanova_payment_link_create')) return null;

  $expires_at = casanova_payment_links_rest_expires_at((int)($deposit_link->id_expediente ?? 0));

  $new = casanova_payment_link_create([
    'id_expediente' => (int)($deposit_link->id_expediente ?? 0),
    'id_reserva_pq' => !empty($deposit_link->id_reserva_pq) ? (int)$deposit_link->id_reserva_pq : null,
    'scope' => $scope,
    'amount_authorized' => $remaining,
    'currency' => (string)($deposit_link->currency ?? 'EUR'),
    'status' => 'active',
    'expires_at' => $expires_at,
    'created_by' => 'magic_resto',
    'billing_dni' => (string)($meta['billing_dni'] ?? ($deposit_link->billing_dni ?? '')),
    'metadata' => array_merge($meta, [
      'mode' => 'rest',
      'parent_payment_link_id' => (int)$deposit_link->id,
      'deposit_paid' => round((float)($amounts['deposit_total'] ?? 0), 2),
      'remaining' => $remaining,
      'final_payment_deadline' => substr($expires_at, 0, 10),
      'auto_start' => true,
      'origin' => 'magic_resto',
    ]),
  ]);

  if (is_wp_error($new) || !$new) return null;

  $meta['rest_magic_link_id'] = (int)$new->id;
  $meta['rest_magic_token'] = (string)($new->token ?? '');
  $meta['remaining'] = $remaining;
  $meta['final_payment_deadline'] = substr($expires_at, 0, 10);

  casanova_payment_link_update((int)$deposit_link->id, [
    'metadata' => wp_json_encode($meta),
  ]);

  return [
    'meta' => $meta,
    'email' => $email,
    'remaining' => $remaining,
    'expires_at' => $expires_at,
    'url_pago' => casanova_payment_links_rest_magic_url((string)($new->token ?? ''), $meta, false),
  ];
}

function casanova_payment_links_send_rest_magic_email($deposit_link, array $magic, string $email = '', bool $is_resend = false): bool {
  if (!$deposit_link || !is_object($deposit_link)) return false;
  if (!function_exists('casanova_mail_send') || !function_exists('casanova_tpl_email_resto_pago_magic_link')) return false;

  $meta = (isset($magic['meta']) && is_array($magic['meta']))
    ? $magic['meta']
    : casanova_payment_links_read_metadata($deposit_link);

  $to = trim($email);
  if ($to === '' || !is_email($to)) {
    $to = trim((string)($magic['email'] ?? ($meta['billing_email'] ?? '')));
  }
  if ($to === '' || !is_email($to)) return false;

  $url_pago = trim((string)($magic['url_pago'] ?? ''));
  if ($url_pago === '') return false;

  $exp_id = (int)($deposit_link->id_expediente ?? 0);
  $remaining_value = round((float)($magic['remaining'] ?? ($meta['remaining'] ?? 0)), 2);
  $remaining = $remaining_value > 0.01
    ? number_format($remaining_value, 2, ',', '.') . ' €'
    : '';

  $tpl = casanova_tpl_email_resto_pago_magic_link([
    'to_email' => $to,
    'cliente_nombre' => (string)($meta['billing_name'] ?? ($meta['billing_fullname'] ?? '')),
    'idExpediente' => $exp_id,
    'codigoExpediente' => casanova_payment_links_expediente_code($exp_id),
    'importe' => $remaining,
    'fecha_limite' => casanova_payment_links_final_payment_deadline_label($exp_id),
    'url_pago' => $url_pago,
  ]);

  if (empty($tpl['to']) || empty($tpl['subject']) || empty($tpl['html'])) return false;

  $ok = casanova_mail_send($tpl['to'], (string)$tpl['subject'], (string)$tpl['html']);
  if ($ok && function_exists('casanova_payment_link_update')) {
    $now = current_time('mysql');
    if (empty($meta['rest_magic_sent_at'])) {
      $meta['rest_magic_sent_at'] = $now;
    }
    if ($is_resend) {
      $meta['rest_magic_resent_at'] = $now;
    }
    casanova_payment_link_update((int)$deposit_link->id, ['metadata' => wp_json_encode($meta)]);
  }

  return (bool)$ok;
}

function casanova_payment_links_send_or_create_rest_magic($deposit_link, $intent = null, string $email = '', bool $force_send = false): bool {
  if (!$deposit_link || !is_object($deposit_link)) return false;

  $meta = casanova_payment_links_read_metadata($deposit_link);
  if (!$force_send && !empty($meta['rest_magic_sent_at'])) {
    return true;
  }

  $magic = casanova_payment_links_ensure_rest_magic_for_deposit($deposit_link, $intent);
  if (!$magic) return false;

  $magic_meta = (isset($magic['meta']) && is_array($magic['meta'])) ? $magic['meta'] : [];
  if (!$force_send && !empty($magic_meta['rest_magic_sent_at'])) {
    return true;
  }

  return casanova_payment_links_send_rest_magic_email($deposit_link, $magic, $email, $force_send);
}

add_action('casanova_payment_cobro_recorded', function ($intent_id) {
  casanova_maybe_send_magic_resto_link((int)$intent_id);
}, 20, 1);

/**
 * Busca el ultimo enlace pagado en modo deposito.
 * Seguridad: exige match por expediente + dni + email.
 */
function casanova_payment_links_find_deposit_with_rest(int $idExpediente, string $dni, string $email) {
  global $wpdb;
  if ($idExpediente <= 0 || $dni === '' || $email === '') return null;
  $table = casanova_payment_links_table();
  $needle_dni = strtoupper(preg_replace('/\s+/', '', $dni));

  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM {$table} WHERE status IN ('paid','active') AND id_expediente=%d AND scope IN ('slot_base','group_base','individual_link') ORDER BY paid_at DESC, id DESC LIMIT 100",
      $idExpediente
    )
  );
  if (empty($rows)) return null;

  foreach ($rows as $r) {
    $meta = casanova_payment_links_read_metadata($r);
    $row_dni = strtoupper(preg_replace('/\s+/', '', (string)($r->billing_dni ?? '')));
    $meta_dni = strtoupper(preg_replace('/\s+/', '', (string)($meta['billing_dni'] ?? '')));
    if ($row_dni !== $needle_dni && $meta_dni !== $needle_dni) {
      continue;
    }
    $m_email = trim((string)($meta['billing_email'] ?? ''));
    if ($m_email !== '' && strcasecmp($m_email, $email) === 0 && casanova_payment_links_is_effective_deposit($r, $meta)) {
      $status = strtolower(trim((string)($r->status ?? '')));
      if ($status !== 'paid') {
        $confirmed_intent = casanova_payment_links_confirmed_intent_for_link($r);
        if (!$confirmed_intent) {
          continue;
        }
        casanova_payment_links_mark_paid_from_confirmed_intent($r, $confirmed_intent, $needle_dni);
        $r->_confirmed_intent = $confirmed_intent;
      }
      $r->_meta = $meta;
      return $r;
    }
  }
  return null;
}

function casanova_payment_links_resend_rest_magic($deposit_row, string $email): bool {
  if (!$deposit_row || !is_object($deposit_row) || $email === '' || !is_email($email)) return false;
  if (!function_exists('casanova_payment_links_send_or_create_rest_magic')) return false;

  $intent = (isset($deposit_row->_confirmed_intent) && is_object($deposit_row->_confirmed_intent))
    ? $deposit_row->_confirmed_intent
    : null;

  return casanova_payment_links_send_or_create_rest_magic($deposit_row, $intent, $email, true);
}
