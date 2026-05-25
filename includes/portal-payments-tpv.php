<?php
if (!defined('ABSPATH')) exit;

/**
 * Hooks: Redsys puede llamar SIN login
 */
add_action('admin_post_nopriv_casanova_tpv_notify', 'casanova_handle_tpv_notify');
add_action('admin_post_casanova_tpv_notify', 'casanova_handle_tpv_notify');

add_action('admin_post_nopriv_casanova_tpv_return', 'casanova_handle_tpv_return');
add_action('admin_post_casanova_tpv_return', 'casanova_handle_tpv_return');

add_action('init', function () {
  if (!empty($_REQUEST['casanova_tpv_notify'])) {
    casanova_handle_tpv_notify();
    exit;
  }

  if (!empty($_REQUEST['casanova_tpv_return'])) {
    casanova_handle_tpv_return();
    exit;
  }
}, 0);


/**
 * ==========================
 * Helpers Redsys (solo si NO existen)
 * ==========================
 */

if (!function_exists('casanova_redsys_decode_params')) {
  function casanova_redsys_decode_params(string $b64): array {
    // Redsys suele enviar MerchantParameters en Base64, pero a veces llega URL-safe (-_) o sin padding.
    // Para decodificar, normalizamos a Base64 estándar con padding. Para firmar, SIEMPRE se usa el string original.
    $b64 = trim($b64);
    $b64 = str_replace(' ', '+', $b64);
    $b64 = strtr($b64, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);

    $json = base64_decode($b64, true);
    if ($json === false) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
  }
}

if (!function_exists('casanova_redsys_normalize_sig')) {
  function casanova_redsys_normalize_sig(string $sig): string {
    // Redsys puede enviar la firma en Base64 URL-safe (-_). Normalizamos a Base64 estándar (+/)
    // y corregimos espacios por '+' (por si el transporte los convierte).
    $sig = trim($sig);
    $sig = str_replace(' ', '+', $sig);
    $sig = strtr($sig, '-_', '+/');
    $pad = strlen($sig) % 4;
    if ($pad) $sig .= str_repeat('=', 4 - $pad);
    return $sig;
  }
}

if (!function_exists('casanova_redsys_get_secret')) {
  function casanova_redsys_get_secret(string $tpv_key = '', array $context = []): string {
    if (function_exists('casanova_redsys_config')) {
      $resolved_tpv_key = $tpv_key !== ''
        ? $tpv_key
        : (function_exists('casanova_redsys_select_tpv_key') ? casanova_redsys_select_tpv_key($context) : 'default');
      $cfg = casanova_redsys_config($resolved_tpv_key);
      if (!empty($cfg['secret_key'])) return (string)$cfg['secret_key'];
    }
    return '';
  }
}

if (!function_exists('casanova_redsys_verify')) {
  function casanova_redsys_verify(string $mpB64, array $params, string $sigProvided, array $context = []): bool {
    if (!function_exists('casanova_redsys_signature')) return false;

    // IMPORTANTE: La firma se deriva usando el Ds_Order que viene DENTRO de MerchantParameters.
    // No usar el order guardado en DB para verificar, porque Redsys puede variar el formato (rellenos/ceros, etc.).
    $decoded = casanova_redsys_decode_params($mpB64);
    $order = (string)($decoded['Ds_Order'] ?? $decoded['DS_ORDER'] ?? $params['Ds_Order'] ?? $params['DS_ORDER'] ?? '');
    $order = trim($order);
    if ($order === '') return false;

    $context['params'] = $params;
    $secret = casanova_redsys_get_secret('', $context);
    if ($secret === '') return false;

    // Comparamos en Base64 estándar y sin padding (Redsys a veces lo omite).
    $got = rtrim(casanova_redsys_normalize_sig($sigProvided), '=');

    $candidates = [];
    $raw = trim($mpB64);
    if ($raw !== '') {
      $candidates[] = $raw;
    }

    $plus_fixed = str_replace(' ', '+', $raw);
    if ($plus_fixed !== '' && !in_array($plus_fixed, $candidates, true)) {
      $candidates[] = $plus_fixed;
    }

    $standard_b64 = strtr($plus_fixed, '-_', '+/');
    $pad = strlen($standard_b64) % 4;
    if ($pad) $standard_b64 .= str_repeat('=', 4 - $pad);
    if ($standard_b64 !== '' && !in_array($standard_b64, $candidates, true)) {
      $candidates[] = $standard_b64;
    }

    foreach ($candidates as $candidate) {
      $expected = casanova_redsys_signature($candidate, $order, $secret);
      if ($expected === '') continue;
      $exp = rtrim(casanova_redsys_normalize_sig($expected), '=');
      if (hash_equals($exp, $got)) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('casanova_payload_merge')) {
  function casanova_payload_merge($old, array $add): array {
    $oldArr = is_array($old) ? $old : (json_decode((string)$old, true) ?: []);
    if (!is_array($oldArr)) $oldArr = [];
    return array_replace_recursive($oldArr, $add);
  }
}

if (!function_exists('casanova_redsys_param')) {
  function casanova_redsys_param(array $params, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
      if (array_key_exists($key, $params)) {
        return trim((string)$params[$key]);
      }
    }
    return $default;
  }
}

if (!function_exists('casanova_redsys_intent_from_response_params')) {
  function casanova_redsys_intent_from_response_params(array $params) {
    $token = casanova_redsys_param($params, ['Ds_MerchantData', 'DS_MERCHANTDATA', 'Ds_Merchant_Data', 'DS_MERCHANT_DATA']);
    if ($token !== '' && function_exists('casanova_payment_intent_get_by_token')) {
      $intent = casanova_payment_intent_get_by_token($token);
      if ($intent) {
        return $intent;
      }
    }

    $order = casanova_redsys_param($params, ['Ds_Order', 'DS_ORDER', 'Ds_Merchant_Order', 'DS_MERCHANT_ORDER']);
    if ($order !== '' && function_exists('casanova_payment_intent_get_by_order')) {
      return casanova_payment_intent_get_by_order($order);
    }

    return null;
  }
}

if (!function_exists('casanova_redsys_request_snapshot')) {
  function casanova_redsys_request_snapshot(array $extra = []): array {
    $raw = '';
    try {
      $raw = (string)file_get_contents('php://input');
    } catch (Throwable $e) {
      $raw = '';
    }

    return array_merge([
      'time' => function_exists('current_time') ? current_time('mysql') : gmdate('c'),
      'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
      'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
      'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
      'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
      'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')),
      'content_length' => (string)($_SERVER['CONTENT_LENGTH'] ?? ''),
      'get_keys' => array_keys($_GET),
      'post_keys' => array_keys($_POST),
      'request_keys' => array_keys($_REQUEST),
      'raw_len' => strlen($raw),
      'raw_sha1' => $raw !== '' ? sha1($raw) : '',
    ], $extra);
  }
}

if (!function_exists('casanova_redsys_debug_log')) {
  function casanova_redsys_debug_log(string $event, array $extra = []): void {
    $payload = casanova_redsys_request_snapshot(array_merge(['event' => $event], $extra));
    if (function_exists('casanova_portal_log')) {
      casanova_portal_log('redsys_notify', $payload);
    }
    error_log('[CASANOVA][TPV][NOTIFY_DEBUG] ' . $event . ' ' . wp_json_encode($payload));
  }
}

if (!function_exists('casanova_redsys_hydrate_superglobals_from_rest_request')) {
  function casanova_redsys_hydrate_superglobals_from_rest_request($request): void {
    if (!is_object($request) || !method_exists($request, 'get_params')) {
      return;
    }

    $params = $request->get_params();
    if (!is_array($params) || empty($params)) {
      return;
    }

    foreach ($params as $key => $value) {
      if (!is_scalar($value)) continue;
      if (!isset($_REQUEST[$key])) $_REQUEST[$key] = $value;
      if (!isset($_POST[$key]) && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        $_POST[$key] = $value;
      }
    }
  }
}

if (!function_exists('casanova_tpv_redirect_url_for_intent')) {
  function casanova_tpv_redirect_url_for_intent($intent, bool $ok): string {
    $payload = [];
    $payload_raw = is_object($intent) ? (string)($intent->payload ?? '') : '';
    if ($payload_raw !== '') {
      $decoded = json_decode($payload_raw, true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $link = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
    $link_token = (string)($link['token'] ?? '');
    $public_locale = trim((string)($payload['locale'] ?? ''));

    if ($link_token !== '' && function_exists('casanova_payment_link_url')) {
      $url = casanova_payment_link_url($link_token);
      $url = add_query_arg([
        'pay_status' => $ok ? 'checking' : 'ko',
        'payment' => $ok ? 'success' : 'failed',
      ], $url);
      if (function_exists('casanova_portal_add_public_locale_arg')) {
        $url = casanova_portal_add_public_locale_arg($url, $public_locale);
      }
      return $url;
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');
    return add_query_arg([
      'expediente' => is_object($intent) ? (int)$intent->id_expediente : 0,
      'pay_status' => $ok ? 'checking' : 'ko',
      'payment' => $ok ? 'success' : 'failed',
    ], $base);
  }
}

if (!function_exists('casanova_payments_try_giav_cobro')) {
  function casanova_payments_try_giav_cobro($intent, array $params, int $ds_resp): array {
    $result = [
      'giav_cobro' => null,
      'already' => false,
      'inserted' => false,
      'should_notify' => false,
    ];

    if (!is_object($intent)) return $result;

    $payload_arr = is_array($intent->payload ?? null)
      ? (array)$intent->payload
      : (json_decode((string)($intent->payload ?? ''), true) ?: []);
    if (!is_array($payload_arr)) $payload_arr = [];

    $already = isset($payload_arr['giav_cobro']) && is_array($payload_arr['giav_cobro'])
      && (!empty($payload_arr['giav_cobro']['cobro_id']) || !empty($payload_arr['giav_cobro']['inserted_at']));

    if ($already) {
      error_log('[CASANOVA][TPV][GIAV] cobro already inserted intent_id=' . (int)$intent->id);
      $result['giav_cobro'] = $payload_arr['giav_cobro'];
      $result['already'] = true;
      return $result;
    }

    $id_forma_pago = function_exists('casanova_redsys_giav_method_id')
      ? casanova_redsys_giav_method_id('', ['intent' => $intent, 'params' => $params])
      : 0;
    if ($id_forma_pago <= 0) {
      if (defined('CASANOVA_GIAV_IDFORMAPAGO_REDSYS')) {
        $id_forma_pago = (int)CASANOVA_GIAV_IDFORMAPAGO_REDSYS;
      }
      if ($id_forma_pago <= 0) {
        $id_forma_pago = (int) get_option('casanova_giav_idformapago_redsys', 1027);
      }
    }

    // Oficina: algunos GIAV requieren idOficina para permitir Cobro_POST (si no, 'No se tiene acceso al registro').
    $id_oficina = 0;
    if (defined('CASANOVA_GIAV_IDOFICINA')) {
      $id_oficina = (int)CASANOVA_GIAV_IDOFICINA;
    }
    $id_oficina = (int) apply_filters('casanova_giav_idoficina_for_cobro', $id_oficina, $intent, $params);

    // Notas internas: guardamos huella Redsys sin liarla demasiado
    $notes = [
      'source' => 'casanova-portal-giav',
      'token' => (string)$intent->token,
      'order' => casanova_redsys_param($params, ['Ds_Order', 'DS_ORDER', 'Ds_Merchant_Order', 'DS_MERCHANT_ORDER']),
      'auth_code' => casanova_redsys_param($params, ['Ds_AuthorisationCode', 'DS_AUTHORISATIONCODE', 'Ds_AuthorizationCode', 'DS_AUTHORIZATIONCODE']),
      'merchant_identifier' => casanova_redsys_param($params, ['Ds_Merchant_Identifier', 'DS_MERCHANT_IDENTIFIER']),
      'card_country' => casanova_redsys_param($params, ['Ds_Card_Country', 'DS_CARD_COUNTRY']),
      'response' => (string)$ds_resp,
    ];

    // UX: concepto/pagador más humano.
    $payload = [];
    $payload_raw = (string)($intent->payload ?? '');
    if ($payload_raw !== '') {
      $decoded = json_decode($payload_raw, true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $mode = strtolower(trim((string)($payload['mode'] ?? '')));
    $is_deposit = ($mode === 'deposit');
    $payment_link = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
    $payment_link_id = (int)($payment_link['id'] ?? 0);
    $payment_link_token = (string)($payment_link['token'] ?? '');
    $payment_link_scope = (string)($payment_link['scope'] ?? '');
    $billing_dni = (string)($payload['billing_dni'] ?? ($payment_link['billing_dni'] ?? ''));
    $billing_name = trim((string)($payload['billing_name'] ?? ''));

    $notes['billing_dni'] = $billing_dni ?: null;
    $notes['billing_name'] = $billing_name ?: null;
    $notes['payment_link'] = [
      'id' => $payment_link_id ?: null,
      'token' => $payment_link_token ?: null,
      'scope' => $payment_link_scope ?: null,
    ];

    $payer_name = 'Portal';
    if (!empty($intent->user_id) && function_exists('get_user_by')) {
      $u = get_user_by('id', (int)$intent->user_id);
      if ($u && !empty($u->display_name)) {
        $payer_name = (string)$u->display_name;
      }
    } elseif ($billing_name !== '') {
      $payer_name = $billing_name;
    }

    $concepto = $is_deposit
      ? ('Depósito Redsys ' . (string)($intent->order_redsys ?? ''))
      : ('Pago Redsys ' . (string)($intent->order_redsys ?? ''));

    // Nota humana para GIAV (pagador + viajeros + nota interna).
    $plink_meta = [];
    if ($payment_link_id > 0 && function_exists('casanova_payment_link_get')) {
      $plink_row = casanova_payment_link_get($payment_link_id);
      if ($plink_row) {
        $rawm = (string)($plink_row->metadata ?? '');
        if ($rawm !== '') {
          $dec = json_decode($rawm, true);
          if (is_array($dec)) $plink_meta = $dec;
        }
      }
    }
    $note_email = (string)($plink_meta['billing_email'] ?? ($payload['billing_email'] ?? ''));
    $billing_email = trim($note_email);
    $billing_lastname = trim((string)($plink_meta['billing_lastname'] ?? ($payload['billing_lastname'] ?? '')));
    if ($billing_name === '' && !empty($plink_meta['billing_name'])) {
      $billing_name = trim((string)$plink_meta['billing_name']);
    }
    $billing_fullname = trim((string)($plink_meta['billing_fullname'] ?? ($payload['billing_fullname'] ?? '')));
    if ($billing_fullname === '') {
      $billing_fullname = trim($billing_name . ' ' . $billing_lastname);
    }
    if ($billing_fullname !== '') {
      $payer_name = $billing_fullname;
    }
    if ($billing_dni === '' && !empty($plink_meta['billing_dni'])) {
      $billing_dni = (string)$plink_meta['billing_dni'];
    }
    $note_others = $plink_meta['others_names'] ?? [];
    if (is_string($note_others) && $note_others !== '') {
      $note_others = preg_split('/\r\n|\r|\n/', $note_others);
    }
    if (!is_array($note_others)) $note_others = [];
    $note_others = array_values(array_filter(array_map('sanitize_text_field', $note_others), static function ($value) {
      return $value !== '';
    }));

    $mode = strtolower(trim((string)($plink_meta['mode'] ?? ($payload['mode'] ?? ''))));
    $mode_label = '';
    if ($mode === 'deposit') $mode_label = 'depósito';
    elseif ($mode === 'full') $mode_label = 'total';
    elseif ($mode === 'rest') $mode_label = 'resto';

    if ($payment_link_scope === 'group_base') {
      $units = (int)($plink_meta['units'] ?? 0);
      $parts = [
        'Pagador: ' . trim($payer_name . ' ' . $billing_dni) . ' (' . $note_email . ').',
      ];
      if ($units > 0) {
        $parts[] = 'Personas: ' . $units . '.';
      }
      if ($mode_label !== '') {
        $parts[] = 'Modalidad: ' . $mode_label . '.';
      }
      if (!empty($note_others)) {
        $parts[] = 'Referencia viajeros: ' . implode(', ', array_slice($note_others, 0, 20)) . '.';
      }
      $giav_human_note = implode(' ', $parts);
    } else {
      $note_covers_self = !empty($plink_meta['covers_self']);
      $note_covers_others = !empty($plink_meta['covers_others']);
      $note_internal = trim((string)($plink_meta['internal_note'] ?? ''));
      $others_txt = '';
      if ($note_covers_others && !empty($note_others)) {
        $others_txt = implode(', ', array_slice($note_others, 0, 20));
      }
      $giav_human_note = 'Pagador: ' . trim($payer_name . ' ' . $billing_dni) . ' (' . $note_email . '). '
        . 'Cubre: ' . ($note_covers_self ? 'sí' : 'no') . '. '
        . 'Otros: ' . ($others_txt !== '' ? $others_txt : '-') . '. '
        . 'Nota: ' . ($note_internal !== '' ? $note_internal : '-');
    }

    $notasInternas = $giav_human_note . ' | order=' . $notes['order'] . ' auth=' . $notes['auth_code'];

    error_log('[CASANOVA][TPV][GIAV] Cobro_POST params idFormaPago=' . (int)$id_forma_pago . ' idOficina=' . (int)$id_oficina);
    if (function_exists('casanova_payments_record_cobro')) {
      return casanova_payments_record_cobro($intent, [
        'id_forma_pago' => $id_forma_pago,
        'id_oficina' => $id_oficina,
        'billing_dni' => $billing_dni,
        'billing_email' => $billing_email,
        'billing_name' => $billing_name,
        'billing_lastname' => $billing_lastname,
        'billing_fullname' => $billing_fullname,
        'payment_link_id' => $payment_link_id,
        'payment_link_scope' => $payment_link_scope,
        'concepto' => $concepto,
        'documento' => $notes['auth_code'] !== '' ? $notes['auth_code'] : $notes['merchant_identifier'],
        'payer_name' => $payer_name,
        'notas_internas' => $notasInternas,
      ], 'TPV');
    }

    return $result;
  }
}

if (!function_exists('casanova_redsys_manual_recovery_admin_url')) {
  function casanova_redsys_manual_recovery_admin_url(array $args = []): string {
    $base = function_exists('casanova_portal_admin_url')
      ? casanova_portal_admin_url('payments')
      : admin_url('admin.php?page=casanova-payments');

    return add_query_arg(array_merge(['config_tab' => 'support'], $args), $base);
  }
}

add_action('admin_post_casanova_redsys_recover_authorized', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_redsys_recover_authorized');

  $intent_id = isset($_POST['intent_id']) ? absint($_POST['intent_id']) : 0;
  $auth_code = isset($_POST['auth_code']) ? sanitize_text_field((string)wp_unslash($_POST['auth_code'])) : '';
  $order_check = isset($_POST['order_redsys']) ? sanitize_text_field((string)wp_unslash($_POST['order_redsys'])) : '';
  $provider_ref = isset($_POST['provider_reference']) ? sanitize_text_field((string)wp_unslash($_POST['provider_reference'])) : '';

  $redirect_error = static function (string $code, int $intent_id = 0) {
    wp_safe_redirect(casanova_redsys_manual_recovery_admin_url([
      'redsys_recovery' => 'error',
      'redsys_recovery_error' => $code,
      'redsys_intent' => $intent_id ?: null,
    ]));
    exit;
  };

  if ($intent_id <= 0 || $auth_code === '') {
    $redirect_error('missing', $intent_id);
  }
  if (!function_exists('casanova_payment_intent_get') || !function_exists('casanova_payment_intent_update')) {
    $redirect_error('payments_missing', $intent_id);
  }

  $intent = casanova_payment_intent_get($intent_id);
  if (!$intent || !is_object($intent)) {
    $redirect_error('intent_not_found', $intent_id);
  }

  $provider = strtolower(trim((string)($intent->provider ?? '')));
  if ($provider !== '' && $provider !== 'redsys') {
    $redirect_error('provider', $intent_id);
  }

  $order = trim((string)($intent->order_redsys ?? ''));
  if ($order === '') {
    $redirect_error('order_missing', $intent_id);
  }
  if ($order_check !== '' && !hash_equals($order, $order_check)) {
    $redirect_error('order_mismatch', $intent_id);
  }

  $payload = [];
  $payload_raw = (string)($intent->payload ?? '');
  if ($payload_raw !== '') {
    $decoded = json_decode($payload_raw, true);
    if (is_array($decoded)) $payload = $decoded;
  }

  $existing_giav_cobro = is_array($payload['giav_cobro'] ?? null) ? $payload['giav_cobro'] : [];
  if (!empty($existing_giav_cobro['cobro_id']) || !empty($existing_giav_cobro['inserted_at'])) {
    wp_safe_redirect(casanova_redsys_manual_recovery_admin_url([
      'redsys_recovery' => 'already',
      'redsys_intent' => $intent_id,
    ]));
    exit;
  }

  $tpv = is_array($payload['redsys_tpv'] ?? null) ? $payload['redsys_tpv'] : [];
  $params = [
    'Ds_Order' => $order,
    'Ds_Response' => '0000',
    'Ds_AuthorisationCode' => $auth_code,
    'Ds_MerchantData' => (string)($intent->token ?? ''),
    'Ds_MerchantCode' => (string)($tpv['merchant_code'] ?? ''),
    'Ds_Terminal' => (string)($tpv['terminal'] ?? ''),
  ];
  if ($provider_ref !== '') {
    $params['Ds_Merchant_Identifier'] = $provider_ref;
  }

  $giav_result = function_exists('casanova_payments_try_giav_cobro')
    ? casanova_payments_try_giav_cobro($intent, $params, 0)
    : [];

  $manual_payload = [
    'redsys_manual_recovery' => [
      'authorized_in_redsys_panel' => true,
      'admin_user_id' => get_current_user_id(),
      'time' => current_time('mysql'),
      'order' => $order,
      'auth_code' => $auth_code,
      'provider_reference' => $provider_ref,
      'previous_status' => (string)($intent->status ?? ''),
    ],
  ];

  if (!empty($giav_result['giav_cobro'])) {
    $manual_payload['giav_cobro'] = $giav_result['giav_cobro'];
  }

  $inserted = !empty($giav_result['inserted']);
  $already = !empty($giav_result['already']);
  $new_status = ($inserted || $already) ? 'manual_authorized' : 'manual_failed';

  casanova_payment_intent_update($intent_id, [
    'status' => $new_status,
    'payload' => casanova_payload_merge($intent->payload ?? [], $manual_payload),
    'last_check_at' => current_time('mysql'),
  ]);

  if (!$inserted && !$already) {
    $redirect_error('giav_failed', $intent_id);
  }

  if (!empty($giav_result['should_notify'])) {
    do_action('casanova_payment_cobro_recorded', $intent_id);
  }
  if ($inserted && function_exists('casanova_invalidate_customer_cache')) {
    casanova_invalidate_customer_cache((int)$intent->user_id, (int)$intent->id_cliente, (int)$intent->id_expediente);
  }
  if (!wp_next_scheduled('casanova_job_reconcile_payment', [$intent_id])) {
    wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [$intent_id]);
  }

  wp_safe_redirect(casanova_redsys_manual_recovery_admin_url([
    'redsys_recovery' => 'ok',
    'redsys_intent' => $intent_id,
    'redsys_cobro' => (int)($giav_result['giav_cobro']['cobro_id'] ?? 0),
  ]));
  exit;
});

/**
 * ==========================
 * RETURN (cliente vuelve)
 * ==========================
 */
function casanova_handle_tpv_return(): void {

  error_log('[CASANOVA][TPV][RETURN] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));

  $mpB64 = isset($_REQUEST['Ds_MerchantParameters']) ? (string)wp_unslash($_REQUEST['Ds_MerchantParameters']) : '';
  $sig   = isset($_REQUEST['Ds_Signature']) ? (string)wp_unslash($_REQUEST['Ds_Signature']) : '';

  error_log('[CASANOVA][TPV][RETURN] mp_len=' . strlen($mpB64) . ' sig_len=' . strlen($sig));

  if ($mpB64 === '' || $sig === '') {
  // fallback: return por GET sin payload, usamos token en URL
  $token_qs = (string)($_REQUEST['token'] ?? '');
  $result_qs = strtolower(trim((string)($_REQUEST['result'] ?? '')));
  $return_ok = ($result_qs === 'ok');
  error_log('[CASANOVA][TPV][RETURN] missing params fallback token=' . $token_qs);

  if ($token_qs !== '' && function_exists('casanova_payment_intent_get_by_token')) {
    $intent = casanova_payment_intent_get_by_token($token_qs);
    if ($intent) {
      casanova_payment_intent_update((int)$intent->id, [
        'status' => $return_ok ? 'return_pending_notify' : 'returned_ko',
        'payload' => casanova_payload_merge($intent->payload ?? [], [
          'redsys_return' => [
            'valid_signature' => false,
            'error' => 'missing_params_return_get',
            'result' => $result_qs,
            'awaiting_notify' => $return_ok,
            'request_keys' => [
              'get' => array_keys($_GET),
              'post' => array_keys($_POST),
            ],
          ],
          'time' => current_time('mysql'),
        ]),
      ]);

      // Agenda reconciliación (una vez)
      // Sin MerchantParameters no podemos validar el pago desde el navegador.
      // El registro en GIAV queda a la espera del notify servidor-a-servidor.
      if ($return_ok) {
        if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
          $when = time() + 15;
          wp_schedule_single_event($when, 'casanova_job_reconcile_payment', [(int)$intent->id]);
          if (function_exists('casanova_log')) {
            casanova_log('tpv', 'reconcile scheduled', ['when' => $when, 'intent_id' => (int)$intent->id], 'info');
          } else {
            error_log('[CASANOVA][TPV] reconcile scheduled when=' . $when . ' intent_id=' . (int)$intent->id);
          }
        } else {
          $ts = wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id]);
          if (function_exists('casanova_log')) {
            casanova_log('tpv', 'reconcile already scheduled', ['at' => $ts, 'intent_id' => (int)$intent->id], 'debug');
          } else {
            error_log('[CASANOVA][TPV] reconcile already scheduled at=' . (string)$ts . ' intent_id=' . (int)$intent->id);
          }
        }
      }


      wp_safe_redirect(casanova_tpv_redirect_url_for_intent($intent, $return_ok));
      exit;
    }
  }

  // si no hay token o no existe intent, KO genérico
  wp_safe_redirect(add_query_arg(['pay_status' => 'ko', 'payment' => 'failed'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/'))));
  exit;
}


  $params = casanova_redsys_decode_params($mpB64);
  $token  = casanova_redsys_param($params, ['Ds_MerchantData', 'DS_MERCHANTDATA', 'Ds_Merchant_Data', 'DS_MERCHANT_DATA']);
  $order  = casanova_redsys_param($params, ['Ds_Order', 'DS_ORDER', 'Ds_Merchant_Order', 'DS_MERCHANT_ORDER']);

  error_log('[CASANOVA][TPV][RETURN] token=' . $token . ' order=' . $order);

  if (($token === '' && $order === '') || !function_exists('casanova_payment_intent_get_by_token')) {
    wp_safe_redirect(add_query_arg(['pay_status' => 'ko', 'payment' => 'failed'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/'))));
    exit;
  }

  $intent = casanova_redsys_intent_from_response_params($params);
  if (!$intent) {
    wp_safe_redirect(add_query_arg(['pay_status' => 'ko', 'payment' => 'failed'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/'))));
    exit;
  }

  $is_valid = casanova_redsys_verify($mpB64, $params, $sig, ['intent' => $intent]);
  // Fallback: si falla la firma pero tenemos intent y order guardado, probamos con ese order.
  // Esto cubre variaciones raras de Ds_Order y/o decodificación (manteniendo el token como ancla de seguridad).
  if (!$is_valid && !empty($intent->order_redsys) && function_exists('casanova_redsys_signature')) {
    $secret = casanova_redsys_get_secret('', ['intent' => $intent, 'params' => $params]);
    if ($secret !== '') {
      $expected2 = casanova_redsys_signature($mpB64, trim((string)$intent->order_redsys), $secret);
      if ($expected2 !== '') {
        $exp2 = rtrim(casanova_redsys_normalize_sig($expected2), '=');
        $got2 = rtrim(casanova_redsys_normalize_sig($sig), '=');
        $is_valid = hash_equals($exp2, $got2);
        if ($is_valid) {
          error_log('[CASANOVA][TPV][RETURN] signature fallback matched using intent->order_redsys');
        }
      }
    }
  }
  $ds_resp  = (int)casanova_redsys_param($params, ['Ds_Response', 'DS_RESPONSE'], '9999');
  $ok = $is_valid && $ds_resp >= 0 && $ds_resp <= 99;

  error_log('[CASANOVA][TPV][RETURN] intent=' . $intent->id . ' valid_sig=' . ($is_valid ? '1' : '0') . ' ds=' . $ds_resp);

  $giav_result = [];
  if ($ok) {
    $giav_result = casanova_payments_try_giav_cobro($intent, $params, $ds_resp);
  }

  $merge_payload = [
    'redsys_return' => [
      'valid_signature' => $is_valid,
      'ds_response' => $ds_resp,
      'params' => $params,
    ],
    'time' => current_time('mysql'),
  ];
  if (!empty($giav_result['giav_cobro'])) {
    $merge_payload['giav_cobro'] = $giav_result['giav_cobro'];
  }

  casanova_payment_intent_update((int)$intent->id, [
    'status' => $ok ? 'returned_ok' : 'returned_ko',
    'payload' => casanova_payload_merge($intent->payload ?? [], $merge_payload),
  ]);

  if (!empty($giav_result['should_notify'])) {
    do_action('casanova_payment_cobro_recorded', (int)$intent->id);
  }
  if (!empty($giav_result['inserted']) && function_exists('casanova_invalidate_customer_cache')) {
    casanova_invalidate_customer_cache((int)$intent->user_id, (int)$intent->id_cliente, (int)$intent->id_expediente);
  }

  if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
    wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int)$intent->id]);
  }

  wp_safe_redirect(casanova_tpv_redirect_url_for_intent($intent, $ok));
  exit;
}

/**
 * ==========================
 * NOTIFY (server to server)
 * ==========================
 */
function casanova_handle_tpv_notify(): void {

  error_log('[CASANOVA][TPV][NOTIFY] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
  casanova_redsys_debug_log('reached');

  $mpB64 = isset($_REQUEST['Ds_MerchantParameters']) ? (string)wp_unslash($_REQUEST['Ds_MerchantParameters']) : '';
  $sig   = isset($_REQUEST['Ds_Signature']) ? (string)wp_unslash($_REQUEST['Ds_Signature']) : '';
  $ver   = isset($_REQUEST['Ds_SignatureVersion']) ? (string)wp_unslash($_REQUEST['Ds_SignatureVersion']) : '';

  error_log('[CASANOVA][TPV][NOTIFY] mp_len=' . strlen($mpB64) . ' sig_len=' . strlen($sig) . ' ver=' . $ver);
  casanova_redsys_debug_log('params_seen', [
    'mp_len' => strlen($mpB64),
    'sig_len' => strlen($sig),
    'version' => $ver,
  ]);

  if ($mpB64 === '' || $sig === '') {
    casanova_redsys_debug_log('missing_params', [
      'mp_len' => strlen($mpB64),
      'sig_len' => strlen($sig),
      'version' => $ver,
    ]);
    status_header(400);
    echo 'Missing params';
    exit;
  }

  $params = casanova_redsys_decode_params($mpB64);
  $token  = casanova_redsys_param($params, ['Ds_MerchantData', 'DS_MERCHANTDATA', 'Ds_Merchant_Data', 'DS_MERCHANT_DATA']);
  $order  = casanova_redsys_param($params, ['Ds_Order', 'DS_ORDER', 'Ds_Merchant_Order', 'DS_MERCHANT_ORDER']);

  if (empty($params)) {
    error_log('[CASANOVA][TPV][NOTIFY] decoded params empty');
    casanova_redsys_debug_log('decoded_empty', [
      'mp_len' => strlen($mpB64),
      'sig_len' => strlen($sig),
    ]);
  }
  error_log('[CASANOVA][TPV][NOTIFY] token=' . $token . ' order=' . $order);
  casanova_redsys_debug_log('decoded', [
    'token_present' => $token !== '',
    'order' => $order,
    'merchant_code' => casanova_redsys_param($params, ['Ds_MerchantCode', 'DS_MERCHANTCODE', 'Ds_Merchant_MerchantCode', 'DS_MERCHANT_MERCHANTCODE']),
    'terminal' => casanova_redsys_param($params, ['Ds_Terminal', 'DS_TERMINAL', 'Ds_Merchant_Terminal', 'DS_MERCHANT_TERMINAL']),
    'ds_response' => casanova_redsys_param($params, ['Ds_Response', 'DS_RESPONSE']),
    'decoded_keys' => array_keys($params),
  ]);

  if (($token === '' && $order === '') || !function_exists('casanova_payment_intent_get_by_token') || !function_exists('casanova_payment_intent_update')) {
    casanova_redsys_debug_log('intent_lookup_unavailable', [
      'token_present' => $token !== '',
      'order' => $order,
    ]);
    status_header(500);
    echo 'Payments module missing';
    exit;
  }

  $intent = casanova_redsys_intent_from_response_params($params);
  if (!$intent) {
    casanova_redsys_debug_log('intent_not_found', [
      'token_present' => $token !== '',
      'order' => $order,
    ]);
    status_header(404);
    echo 'Intent not found';
    exit;
  }

  // Idempotencia básica
  $intent_payload = is_array($intent->payload ?? null)
    ? (array)$intent->payload
    : (json_decode((string)($intent->payload ?? ''), true) ?: []);
  if (!is_array($intent_payload)) $intent_payload = [];
  $has_giav_cobro = !empty($intent_payload['giav_cobro']['cobro_id']) || !empty($intent_payload['giav_cobro']['inserted_at']);

  $curr_status = (string)($intent->status ?? '');
  if ($curr_status === 'reconciled' || $has_giav_cobro) {
    status_header(200);
    echo 'OK';
    exit;
  }

  // Validación de firma usando el Ds_Order que viene dentro de MerchantParameters.
  $is_valid = casanova_redsys_verify($mpB64, $params, $sig, ['intent' => $intent]);
  // Fallback: si falla la firma pero tenemos el order guardado del intent, probamos también con ese order.
  if (!$is_valid && !empty($intent->order_redsys) && function_exists('casanova_redsys_signature')) {
    $secret = casanova_redsys_get_secret('', ['intent' => $intent, 'params' => $params]);
    if ($secret !== '') {
      $expected2 = casanova_redsys_signature($mpB64, trim((string)$intent->order_redsys), $secret);
      if ($expected2 !== '') {
        $exp2 = rtrim(casanova_redsys_normalize_sig($expected2), '=');
        $got2 = rtrim(casanova_redsys_normalize_sig($sig), '=');
        $is_valid = hash_equals($exp2, $got2);
        if ($is_valid) {
          error_log('[CASANOVA][TPV][NOTIFY] signature fallback matched using intent->order_redsys');
        }
      }
    }
  }

  $ds_resp = (int)casanova_redsys_param($params, ['Ds_Response', 'DS_RESPONSE'], '9999');
  $bank_ok = ($ds_resp >= 0 && $ds_resp <= 99);

  error_log('[CASANOVA][TPV][NOTIFY] intent=' . (int)$intent->id . ' status=' . $curr_status .
    ' valid_sig=' . ($is_valid ? '1' : '0') . ' ds=' . $ds_resp
  );
  casanova_redsys_debug_log('validated', [
    'intent_id' => (int)$intent->id,
    'status' => $curr_status,
    'valid_sig' => $is_valid,
    'ds_response' => $ds_resp,
    'bank_ok' => $bank_ok,
  ]);

  // Estado: separado para entender qué pasó
  $new_status = 'notified_bad_sig';
  if ($is_valid && $bank_ok) $new_status = 'notified_ok';
  elseif ($is_valid && !$bank_ok) $new_status = 'notified_ko';

  // ==============================================================
  // Puente Redsys -> GIAV: insertar Cobro_POST de forma idempotente
  // ==============================================================
  $extra_payload = [];
  $giav_result = [];
  if ($is_valid && $bank_ok) {
    $giav_result = casanova_payments_try_giav_cobro($intent, $params, $ds_resp);
    if (!empty($giav_result['giav_cobro'])) {
      $extra_payload['giav_cobro'] = $giav_result['giav_cobro'];
    }
  }

  $merge_payload = [
    'redsys_notify' => [
      'valid_signature' => $is_valid,
      'ds_response' => $ds_resp,
      'bank_ok' => $bank_ok,
      'params' => $params,
    ],
    'time' => current_time('mysql'),
  ];

  // Si intentamos (o fallamos) el insert de GIAV, lo registramos.
  // Ojo: solo marca inserted_at/cobro_id cuando realmente devuelve un id.
  // Si falla, NO marcamos y el siguiente NOTIFY podrá reintentar.
  if (isset($extra_payload['giav_cobro'])) {
    $merge_payload['giav_cobro'] = $extra_payload['giav_cobro'];
  }

  casanova_payment_intent_update((int)$intent->id, [
    'status' => $new_status,
    'payload' => casanova_payload_merge($intent->payload ?? [], $merge_payload),
    'last_check_at' => current_time('mysql'),
  ]);

  // Si se ha registrado cobro en GIAV, disparar email de confirmación aunque sea pago parcial.
  if (!empty($giav_result['should_notify'])) {
    do_action('casanova_payment_cobro_recorded', (int)$intent->id);
  }
  if (!empty($giav_result['inserted']) && function_exists('casanova_invalidate_customer_cache')) {
    casanova_invalidate_customer_cache((int)$intent->user_id, (int)$intent->id_cliente, (int)$intent->id_expediente);
  }

  // Solo agenda reconciliación si tiene sentido (firma válida + banco OK)
  if ($is_valid && $bank_ok) {
    if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
      $when = time() + 15;
      wp_schedule_single_event($when, 'casanova_job_reconcile_payment', [(int)$intent->id]);
      error_log('[CASANOVA][TPV] reconcile scheduled when=' . $when . ' intent_id=' . (int)$intent->id);
    } else {
      $ts = wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id]);
      error_log('[CASANOVA][TPV] reconcile already scheduled at=' . (string)$ts . ' intent_id=' . (int)$intent->id);
    }

    // DEBUG opcional: ejecutar reconciliación inline SOLO si lo pides por query (?recon_now=1)
    // (Esto evita dobles efectos en producción)
    $run_inline = !empty($_GET['recon_now']) && current_user_can('manage_options');
    if ($run_inline && function_exists('casanova_job_reconcile_payment')) {
      error_log('[CASANOVA][TPV] running reconcile inline intent_id=' . (int)$intent->id);
      casanova_job_reconcile_payment((int)$intent->id);
      error_log('[CASANOVA][TPV] reconcile inline finished intent_id=' . (int)$intent->id);
    }
  } else {
    error_log('[CASANOVA][TPV] reconcile NOT scheduled (valid_sig=' . ($is_valid ? '1' : '0') . ' bank_ok=' . ($bank_ok ? '1' : '0') . ') intent_id=' . (int)$intent->id);
  }

  status_header(200);
  echo 'OK';
  exit;
}

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/redsys/notify', [
    'methods'  => 'POST',
    'callback' => function ($request) {
      casanova_redsys_hydrate_superglobals_from_rest_request($request);
      casanova_redsys_debug_log('rest_callback', [
        'rest_param_keys' => is_object($request) && method_exists($request, 'get_params') ? array_keys((array)$request->get_params()) : [],
      ]);
      casanova_handle_tpv_notify();
      return new WP_REST_Response(['ok' => true], 200);
    },
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('casanova/v1', '/redsys/ping', [
    'methods'  => ['GET', 'POST'],
    'callback' => function ($request) {
      casanova_redsys_hydrate_superglobals_from_rest_request($request);
      casanova_redsys_debug_log('ping', [
        'rest_param_keys' => is_object($request) && method_exists($request, 'get_params') ? array_keys((array)$request->get_params()) : [],
      ]);

      return new WP_REST_Response([
        'ok' => true,
        'time' => current_time('mysql'),
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'notify_url' => function_exists('casanova_tpv_notify_url') ? casanova_tpv_notify_url() : rest_url('casanova/v1/redsys/notify'),
      ], 200);
    },
    'permission_callback' => '__return_true',
  ]);
});
