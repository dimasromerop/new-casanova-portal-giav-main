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
    if (!empty($_GET['casanova_pay_token'])) {
      $token = (string) sanitize_text_field((string) $_GET['casanova_pay_token']);
    } else {
      $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
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

  // Deposito solo para links de passenger_share (pasajeros).
  $deposit_allowed = false;
  $deposit_amount = 0.0;
  $deposit_base = ($scope === 'passenger_share') ? $authorized : $pending;
  if ($scope === 'passenger_share' && $paid_now <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
    $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
  }
  if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
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

    casanova_payment_link_update((int)$link->id, ['billing_dni' => $dni]);

    if (!function_exists('casanova_payment_intent_create') || !function_exists('casanova_payment_intent_update')) {
      casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
      exit;
    }

    $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
    if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';

    if ($selected_method === 'bank_transfer' && !$inespay_enabled) {
      casanova_render_payment_link_error(__('Transferencia no disponible.', 'casanova-portal'));
      exit;
    }

    $payload_base = [
      'source' => 'payment_link',
      'requested_amount' => $amount_to_pay,
      'pending_at_create' => round($pending, 2),
      'mode' => $mode,
      'method' => $selected_method,
      'billing_dni' => $dni,
      'billing_name' => $billing_fullname,
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

      $req = [
        'amount' => (int)round($amount_to_pay * 100),
        'description' => ($mode === 'deposit' ? 'Deposito' : 'Pago') . ' Casanova Golf (' . (int)$idExpediente . ')',
        'subject' => ($mode === 'deposit' ? 'Deposito' : 'Pago') . ' Casanova Golf (' . (int)$idExpediente . ')',
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

    if (!function_exists('casanova_redsys_config')) {
      casanova_render_payment_link_error(__('Config Redsys no disponible.', 'casanova-portal'));
      exit;
    }

    $cfg = casanova_redsys_config();
    if (empty($cfg['endpoint']) || empty($cfg['merchant_code']) || empty($cfg['terminal']) || empty($cfg['currency']) || empty($cfg['secret_key'])) {
      casanova_render_payment_link_error(__('Config Redsys incompleta.', 'casanova-portal'));
      exit;
    }

    $url_notify = home_url('/wp-json/casanova/v1/redsys/notify');
    $intent_token = (string)$intent->token;

    $url_ok = add_query_arg([
      'action' => 'casanova_tpv_return',
      'result' => 'ok',
      'token'  => $intent_token,
    ], admin_url('admin-post.php'));

    $url_ko = add_query_arg([
      'action' => 'casanova_tpv_return',
      'result' => 'ko',
      'token'  => $intent_token,
    ], admin_url('admin-post.php'));

    $amount_cents = (string)((int) round(((float)$intent->amount) * 100));

    if (!function_exists('casanova_redsys_encode_params') || !function_exists('casanova_redsys_signature')) {
      casanova_render_payment_link_error(__('Redsys no disponible.', 'casanova-portal'));
      exit;
    }

    $merchantParams = [
      'DS_MERCHANT_AMOUNT' => $amount_cents,
      'DS_MERCHANT_ORDER' => (string)$intent->order_redsys,
      'DS_MERCHANT_MERCHANTCODE' => (string)$cfg['merchant_code'],
      'DS_MERCHANT_CURRENCY'     => (string)$cfg['currency'],
      'DS_MERCHANT_TERMINAL'     => (string)$cfg['terminal'],
      'DS_MERCHANT_TRANSACTIONTYPE' => '0',
      'DS_MERCHANT_MERCHANTURL' => $url_notify,
      'DS_MERCHANT_URLOK' => $url_ok,
      'DS_MERCHANT_URLKO' => $url_ko,
      'DS_MERCHANT_MERCHANTDATA' => (string)$intent->token,
    ];

    $mpB64 = casanova_redsys_encode_params($merchantParams);
    $signature = casanova_redsys_signature($mpB64, (string)$intent->order_redsys, (string)$cfg['secret_key']);

    if ($signature === '') {
      casanova_render_payment_link_error(__('Firma Redsys invalida.', 'casanova-portal'));
      exit;
    }

    casanova_payment_intent_update((int)$intent->id, ['status' => 'redirecting']);

    while (ob_get_level()) ob_end_clean();

    echo '<form id="redsys" action="' . esc_url($cfg['endpoint']) . '" method="post">';
    echo '<input type="hidden" name="Ds_SignatureVersion" value="HMAC_SHA256_V1">';
    echo '<input type="hidden" name="Ds_MerchantParameters" value="' . esc_attr($mpB64) . '">';
    echo '<input type="hidden" name="Ds_Signature" value="' . esc_attr($signature) . '">';
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

  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:720px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';

  if (defined('CASANOVA_AGENCY_LOGO_URL') && CASANOVA_AGENCY_LOGO_URL) {
    echo '<div style="margin:0 0 14px;"><img src="' . esc_url(CASANOVA_AGENCY_LOGO_URL) . '" alt="" style="max-height:44px;width:auto;"/></div>';
  }

  echo '<h2 style="margin:0 0 10px;">' . esc_html__('Pago seguro', 'casanova-portal') . '</h2>';

  $codigo_html = '';
  if ($exp_codigo !== '') {
    $codigo_html = ' <span style="color:#666;">(' . esc_html($exp_codigo) . ')</span>';
  }
  echo '<p style="margin:0 0 14px;">' . wp_kses_post(
    sprintf(
      __('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'),
      esc_html($exp_label),
      $codigo_html
    )
  ) . '</p>';

  echo '<div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';

  $pendiente_html = '<strong>' . esc_html(number_format($pending, 2, ',', '.')) . ' EUR</strong>';
  echo '<div>' . wp_kses_post(
    sprintf(
      __('Pendiente total: %s', 'casanova-portal'),
      $pendiente_html
    )
  ) . '</div>';

  $auth_html = '<strong>' . esc_html(number_format($authorized, 2, ',', '.')) . ' EUR</strong>';
  echo '<div style="margin-top:6px;">' . wp_kses_post(
    sprintf(
      __('Importe autorizado en este enlace: %s', 'casanova-portal'),
      $auth_html
    )
  ) . '</div>';

  if ($deadline_txt !== '') {
    echo '<div style="margin-top:6px;">' . esc_html(
      sprintf(
        __('Fecha limite: %s', 'casanova-portal'),
        $deadline_txt
      )
    ) . '</div>';
  }

  echo '</div>';

  echo '<form method="post" action="' . esc_url(casanova_payment_link_url((string)$link->token)) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Nombre', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_name" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Apellidos', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_lastname" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('DNI / NIF (obligatorio)', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_dni" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<div style="margin:14px 0 6px;font-weight:600;">' . esc_html__('Metodo de pago', 'casanova-portal') . '</div>';
  if ($inespay_enabled) {
    echo '<label style="display:block;margin:6px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="method" value="card" checked style="margin-right:8px;" />' . esc_html__('Tarjeta (Redsys)', 'casanova-portal');
    echo '</label>';
    echo '<label style="display:block;margin:6px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="method" value="bank_transfer" style="margin-right:8px;" />' . esc_html__('Transferencia bancaria (Inespay)', 'casanova-portal');
    echo '</label>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div style="margin:6px 0 12px;color:#666;">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  if ($deposit_effective) {
    echo '<label style="display:block;margin:12px 0 10px;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="mode" value="deposit" ' . ($checked_deposit ? 'checked' : '') . ' style="margin-right:8px;" />';
    $deposit_amount_html = '<strong>' . esc_html(number_format($deposit_amount, 2, ',', '.')) . ' EUR</strong>';
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

  echo '<label style="display:block;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
  echo '<input type="radio" name="mode" value="full" ' . ($checked_full ? 'checked' : '') . ' style="margin-right:8px;" />';
  $amount_html = '<strong>' . esc_html(number_format($authorized, 2, ',', '.')) . ' EUR</strong>';
  echo wp_kses_post(
    sprintf(
      __('Pagar ahora: %s', 'casanova-portal'),
      $amount_html
    )
  );
  echo '</label>';

  echo '<button type="submit" style="margin-top:10px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;">'
    . esc_html__('Continuar al pago', 'casanova-portal')
    . '</button>';

  echo '</form>';

  echo '<p style="margin:14px 0 0;font-size:12px;color:#777;">'
    . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal')
    . '</p>';

  echo '</div>';
  exit;
}

function casanova_render_payment_link_success($link): void {
  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:720px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';

  if (defined('CASANOVA_AGENCY_LOGO_URL') && CASANOVA_AGENCY_LOGO_URL) {
    echo '<div style="margin:0 0 14px;"><img src="' . esc_url(CASANOVA_AGENCY_LOGO_URL) . '" alt="" style="max-height:44px;width:auto;"/></div>';
  }

  echo '<h2 style="margin:0 0 10px;">' . esc_html__('Pago registrado correctamente', 'casanova-portal') . '</h2>';
  echo '<p style="margin:0 0 16px;">' . esc_html__('Gracias. Hemos recibido tu pago.', 'casanova-portal') . '</p>';

  $link_account_url = function_exists('site_url') ? site_url('/area-usuario/') : home_url('/');
  echo '<div style="padding:12px;border:1px solid #eee;border-radius:8px;background:#fafafa;">';
  echo '<strong>' . esc_html__('Quieres acceder a tus documentos y facturas?', 'casanova-portal') . '</strong><br />';
  echo '<a href="' . esc_url($link_account_url) . '" style="display:inline-block;margin-top:8px;color:#111;text-decoration:underline;">'
    . esc_html__('Crear o vincular mi cuenta', 'casanova-portal')
    . '</a>';
  echo '</div>';

  echo '</div>';
}

function casanova_render_payment_link_error(string $message): void {
  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:640px;margin:24px auto;padding:18px;border:1px solid #f0c2c2;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#fff7f7;">';
  echo '<h2 style="margin:0 0 10px;">' . esc_html__('No se pudo completar el pago', 'casanova-portal') . '</h2>';
  echo '<p style="margin:0;">' . esc_html($message) . '</p>';
  echo '</div>';
}
