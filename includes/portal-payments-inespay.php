<?php
if (!defined('ABSPATH')) exit;

/**
 * Inespay integration (Transferencia Online).
 *
 * - Init: se hace desde REST (Payments_Controller) para devolver un redirect_url.
 * - Callback: Inespay notifica solo pagos OK vía notifUrl (server-to-server).
 */

if (!function_exists('casanova_payments_try_giav_cobro_inespay')) {
  function casanova_payments_try_giav_cobro_inespay($intent, array $dataReturn): array {
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
      error_log('[CASANOVA][INESPAY][GIAV] cobro already inserted intent_id=' . (int)$intent->id);
      $result['giav_cobro'] = $payload_arr['giav_cobro'];
      $result['already'] = true;
      return $result;
    }

    $id_forma_pago = 0;
    if (defined('CASANOVA_GIAV_IDFORMAPAGO_INESPAY')) {
      $id_forma_pago = (int)CASANOVA_GIAV_IDFORMAPAGO_INESPAY;
    }
    if ($id_forma_pago <= 0) {
      $id_forma_pago = (int) get_option('casanova_giav_idformapago_inespay', 0);
    }

    if ($id_forma_pago <= 0) {
      error_log('[CASANOVA][INESPAY][GIAV] Missing idFormaPago (CASANOVA_GIAV_IDFORMAPAGO_INESPAY) intent_id=' . (int)$intent->id);
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => 'missing_idformapago_inespay',
      ];
      return $result;
    }

    $id_oficina = 0;
    if (defined('CASANOVA_GIAV_IDOFICINA')) {
      $id_oficina = (int)CASANOVA_GIAV_IDOFICINA;
    }
    $id_oficina = (int) apply_filters('casanova_giav_idoficina_for_cobro', $id_oficina, $intent, $dataReturn);

    $notes = [
      'source' => 'casanova-portal-giav',
      'provider' => 'inespay',
      'token' => (string)($intent->token ?? ''),
      'singlePayinId' => (string)($dataReturn['singlePayinId'] ?? ''),
      'codStatus' => (string)($dataReturn['codStatus'] ?? ''),
      'reference' => (string)($dataReturn['reference'] ?? ''),
      'debtorName' => $dataReturn['debtorName'] ?? null,
      'debtorAccount' => $dataReturn['debtorAccount'] ?? null,
      'creditorAccount' => $dataReturn['creditorAccount'] ?? null,
    ];

    $payload = [];
    $payload_raw = (string)($intent->payload ?? '');
    if ($payload_raw !== '') {
      $decoded = json_decode($payload_raw, true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $mode = strtolower(trim((string)($payload['mode'] ?? '')));
    $is_deposit = ($mode === 'deposit');

    $payer_name = 'Portal';
    if (!empty($intent->user_id) && function_exists('get_user_by')) {
      $u = get_user_by('id', (int)$intent->user_id);
      if ($u && !empty($u->display_name)) {
        $payer_name = (string)$u->display_name;
      }
    }

    $concepto = $is_deposit
      ? ('Depósito Inespay ' . (string)($dataReturn['reference'] ?? ''))
      : ('Pago Inespay ' . (string)($dataReturn['reference'] ?? ''));

    $giav_params = [
      'idFormaPago' => $id_forma_pago,
      'idOficina' => ($id_oficina > 0 ? (int)$id_oficina : null),
      'idExpediente' => (int)$intent->id_expediente,
      'idCliente' => (int)$intent->id_cliente,
      'idRelacionPasajeroReserva' => null,
      'idTipoOperacion' => 'Cobro',
      'importe' => (double)$intent->amount,
      'fechaCobro' => current_time('Y-m-d'),
      'concepto' => $concepto,
      'documento' => (string)($dataReturn['singlePayinId'] ?? ''),
      'pagador' => $payer_name,
      'notasInternas' => wp_json_encode($notes),
      'autocompensar' => true,
      'idEntityStage' => null,
    ];

    if ($id_oficina <= 0) {
      unset($giav_params['idOficina']);
    }

    if (!function_exists('casanova_giav_call')) {
      error_log('[CASANOVA][INESPAY][GIAV] casanova_giav_call missing');
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => 'casanova_giav_call_missing',
      ];
      return $result;
    }

    error_log('[CASANOVA][INESPAY][GIAV] Cobro_POST attempt intent_id=' . (int)$intent->id . ' exp=' . (int)$intent->id_expediente . ' cliente=' . (int)$intent->id_cliente . ' importe=' . (string)$intent->amount);
    $res = casanova_giav_call('Cobro_POST', $giav_params);

    if (is_wp_error($res)) {
      error_log('[CASANOVA][INESPAY][GIAV] Cobro_POST ERROR: ' . $res->get_error_message());
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => $res->get_error_message(),
      ];
      return $result;
    }

    $cobro_id = 0;
    if (is_object($res) && isset($res->Cobro_POSTResult)) {
      $cobro_id = (int)$res->Cobro_POSTResult;
    } elseif (is_numeric($res)) {
      $cobro_id = (int)$res;
    }

    if ($cobro_id > 0) {
      error_log('[CASANOVA][INESPAY][GIAV] Cobro_POST OK cobro_id=' . $cobro_id . ' intent_id=' . (int)$intent->id);
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'inserted_at' => current_time('mysql'),
        'ok' => true,
        'cobro_id' => $cobro_id,
      ];
      $result['inserted'] = true;
      $result['should_notify'] = true;
      return $result;
    }

    error_log('[CASANOVA][INESPAY][GIAV] Cobro_POST unexpected response intent_id=' . (int)$intent->id . ' res=' . print_r($res, true));
    $result['giav_cobro'] = [
      'attempted_at' => current_time('mysql'),
      'ok' => false,
      'error' => 'unexpected_response',
      'raw' => is_scalar($res) ? (string)$res : null,
    ];
    return $result;
  }
}

if (!function_exists('casanova_handle_inespay_notify')) {
  function casanova_handle_inespay_notify(WP_REST_Request $request): WP_REST_Response {
    $payload = $request->get_json_params();
    if (!is_array($payload)) {
      // Readme indica que puede venir urlencoded también.
      $payload = $request->get_params();
    }

    $dataReturnB64 = (string)($payload['dataReturn'] ?? '');
    $sig = (string)($payload['signatureDataReturn'] ?? '');

    error_log('[CASANOVA][INESPAY][NOTIFY] dataReturn_len=' . strlen($dataReturnB64) . ' sig_len=' . strlen($sig));

    if (!class_exists('Casanova_Inespay_Service')) {
      return new WP_REST_Response(['ok' => false, 'error' => 'inespay_service_missing'], 500);
    }

    $valid = Casanova_Inespay_Service::verify_signature($dataReturnB64, $sig);
    if (!$valid) {
      error_log('[CASANOVA][INESPAY][NOTIFY] invalid signature');
      return new WP_REST_Response(['ok' => false], 400);
    }

    $dataReturn = Casanova_Inespay_Service::decode_data_return($dataReturnB64);
    if (!$dataReturn) {
      error_log('[CASANOVA][INESPAY][NOTIFY] invalid dataReturn decode');
      return new WP_REST_Response(['ok' => false], 400);
    }

    $singlePayinId = (string)($dataReturn['singlePayinId'] ?? '');
    $reference = (string)($dataReturn['reference'] ?? '');

    // Solo debería llegar OK/SETTLED, pero por si acaso.
    $codStatus = strtoupper(trim((string)($dataReturn['codStatus'] ?? '')));
    if ($codStatus !== 'OK' && $codStatus !== 'SETTLED') {
      error_log('[CASANOVA][INESPAY][NOTIFY] unexpected codStatus=' . $codStatus);
      return new WP_REST_Response(['ok' => true], 200);
    }

    if (!function_exists('casanova_payment_intent_get_by_provider_payment_id') || !function_exists('casanova_payment_intent_get_by_provider_reference')) {
      return new WP_REST_Response(['ok' => false, 'error' => 'intent_helpers_missing'], 500);
    }

    $intent = null;
    if ($singlePayinId !== '') {
      $intent = casanova_payment_intent_get_by_provider_payment_id($singlePayinId);
    }
    if (!$intent && $reference !== '') {
      $intent = casanova_payment_intent_get_by_provider_reference($reference);
    }

    if (!$intent) {
      error_log('[CASANOVA][INESPAY][NOTIFY] intent not found singlePayinId=' . $singlePayinId . ' ref=' . $reference);
      return new WP_REST_Response(['ok' => true], 200);
    }

    // Merge payload
    $merge = [
      'inespay_callback' => [
        'dataReturn' => $dataReturn,
        'received_at' => current_time('mysql'),
      ],
    ];

    $giav_result = casanova_payments_try_giav_cobro_inespay($intent, $dataReturn);
    if (!empty($giav_result['giav_cobro'])) {
      $merge['giav_cobro'] = $giav_result['giav_cobro'];
    }

    // Guardamos provider fields si no estaban
    casanova_payment_intent_update((int)$intent->id, [
      'provider' => 'inespay',
      'method' => 'bank_transfer',
      'provider_payment_id' => $singlePayinId ?: (string)($intent->provider_payment_id ?? ''),
      'provider_reference' => $reference ?: (string)($intent->provider_reference ?? ''),
      'status' => 'returned_ok',
      'payload' => casanova_intent_payload_merge($intent->payload ?? null, $merge),
    ]);

    if (!empty($giav_result['should_notify'])) {
      do_action('casanova_payment_cobro_recorded', (int)$intent->id);
    }

    if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
      wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int)$intent->id]);
    }

    return new WP_REST_Response(['ok' => true], 200);
  }
}

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/inespay/notify', [
    'methods'  => 'POST',
    'callback' => 'casanova_handle_inespay_notify',
    'permission_callback' => '__return_true',
  ]);

  // Return bridge for customer redirects (success/abort).
  // We must not return directly to the portal page because some environments enforce
  // a /login/?redirect_to=... jump (even for already logged-in users), producing the
  // annoying "ya has iniciado sesión" screen.
  register_rest_route('casanova/v1', '/inespay/return', [
    'methods'  => 'GET',
    // Backward compatible REST bridge: redirect to the clean return URL.
    'callback' => function (WP_REST_Request $request) {
      $qs = [];
      foreach (['status', 'expediente', 'intent_id'] as $k) {
        if (null !== $request->get_param($k)) {
          $qs[$k] = $request->get_param($k);
        }
      }

      $clean = add_query_arg($qs, home_url('/inespay/return/'));
      $resp = new WP_REST_Response(null, 302);
      $resp->header('Location', $clean);
      return $resp;
    },
    'permission_callback' => '__return_true',
  ]);
});

// Clean return handler (NO /wp-json). This avoids stacks that cache/harden REST.
add_action('template_redirect', function () {
  if (empty(get_query_var('casanova_inespay_return'))) return;

  $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'failed';
  $status = ($status === 'success') ? 'success' : 'failed';

  $expediente_id = isset($_GET['expediente']) ? absint($_GET['expediente']) : 0;
  $intent_id = isset($_GET['intent_id']) ? absint($_GET['intent_id']) : 0;

  $portal_base = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/portal-app/');

  $portal_url = add_query_arg([
    'view' => 'trip',
    'tab' => 'payments',
    'expediente' => $expediente_id,
    'pay_status' => ($status === 'success' ? 'checking' : 'ko'),
    'payment' => ($status === 'success' ? 'success' : 'failed'),
    'method' => 'bank_transfer',
    'intent_id' => $intent_id,
  ], $portal_base);

  // IMPORTANT: never bounce through wp-login.php here.
  // The portal itself enforces access (portal-access-gate.php). If we redirect to wp-login
  // from a third-party return (often a POST), some environments can temporarily evaluate
  // the session as not logged-in and show the annoying "ya has iniciado sesión" screen.
  // Always go straight back into the portal; if login is needed, the portal gate will handle it.
  wp_safe_redirect($portal_url);
  exit;
});
