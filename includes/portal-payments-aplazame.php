<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_payments_try_giav_cobro_aplazame')) {
  function casanova_payments_try_giav_cobro_aplazame($intent, array $notification): array {
    $result = [
      'giav_cobro' => null,
      'already' => false,
      'inserted' => false,
      'should_notify' => false,
    ];

    if (!is_object($intent)) return $result;

    $payload_arr = is_array($intent->payload ?? null)
      ? (array) $intent->payload
      : (json_decode((string) ($intent->payload ?? ''), true) ?: []);
    if (!is_array($payload_arr)) $payload_arr = [];

    $already = isset($payload_arr['giav_cobro']) && is_array($payload_arr['giav_cobro'])
      && (!empty($payload_arr['giav_cobro']['cobro_id']) || !empty($payload_arr['giav_cobro']['inserted_at']));
    if ($already) {
      $result['giav_cobro'] = $payload_arr['giav_cobro'];
      $result['already'] = true;
      return $result;
    }

    $id_forma_pago = defined('CASANOVA_GIAV_IDFORMAPAGO_APLAZAME')
      ? (int) CASANOVA_GIAV_IDFORMAPAGO_APLAZAME
      : (int) get_option('casanova_giav_idformapago_aplazame', 0);

    if ($id_forma_pago <= 0) {
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => 'missing_idformapago_aplazame',
      ];
      return $result;
    }

    $id_oficina = defined('CASANOVA_GIAV_IDOFICINA') ? (int) CASANOVA_GIAV_IDOFICINA : 0;
    $id_oficina = (int) apply_filters('casanova_giav_idoficina_for_cobro', $id_oficina, $intent, $notification);

    $cliente = function_exists('casanova_giav_cliente_get_by_id')
      ? casanova_giav_cliente_get_by_id((int) ($intent->id_cliente ?? 0))
      : null;
    if (is_wp_error($cliente)) {
      $cliente = null;
    }

    $billing_name = trim((string) (($cliente->Nombre ?? '')));
    $billing_lastname = trim((string) (($cliente->Apellidos ?? '')));
    $billing_email = trim((string) (($cliente->Email ?? '')));
    $billing_dni = trim((string) (($cliente->Documento ?? ($cliente->PasaporteNumero ?? ''))));

    $payer_name = trim($billing_name . ' ' . $billing_lastname);
    if ($payer_name === '') {
      $payer_name = 'Portal';
    }

    $mode = strtolower(trim((string) ($payload_arr['mode'] ?? '')));
    $reference = (string) ($notification['mid'] ?? ($intent->provider_reference ?? ''));
    $provider_order_id = (string) ($notification['id'] ?? ($intent->provider_payment_id ?? ''));
    $concepto = $mode === 'deposit'
      ? ('Deposito Aplazame ' . $reference)
      : ('Pago Aplazame ' . $reference);

    $notas = [
      'source' => 'casanova-portal-giav',
      'provider' => 'aplazame',
      'aplazame_order_id' => $provider_order_id,
      'mid' => $reference,
      'status' => (string) ($notification['status'] ?? ''),
      'status_reason' => (string) ($notification['status_reason'] ?? ''),
      'token' => (string) ($intent->token ?? ''),
    ];

    if (function_exists('casanova_payments_record_cobro')) {
      return casanova_payments_record_cobro($intent, [
        'id_forma_pago' => $id_forma_pago,
        'id_oficina' => $id_oficina,
        'billing_dni' => $billing_dni,
        'billing_email' => $billing_email,
        'billing_name' => $billing_name,
        'billing_lastname' => $billing_lastname,
        'payment_link_id' => 0,
        'payment_link_scope' => '',
        'concepto' => $concepto,
        'documento' => $provider_order_id,
        'payer_name' => $payer_name,
        'notas_internas' => wp_json_encode($notas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ], 'APLAZAME');
    }

    return $result;
  }
}

if (!function_exists('casanova_handle_aplazame_notify')) {
  function casanova_handle_aplazame_notify(WP_REST_Request $request): WP_REST_Response {
    if (!class_exists('Casanova_Aplazame_Service')) {
      return new WP_REST_Response(['ok' => false, 'error' => 'aplazame_service_missing'], 500);
    }

    $authorization = (string) $request->get_header('authorization');
    if (!Casanova_Aplazame_Service::verify_notification_authorization($authorization)) {
      return new WP_REST_Response(['ok' => false], 403);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
      $body = (string) $request->get_body();
      $payload = json_decode($body, true);
    }
    if (!is_array($payload)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }

    $provider_order_id = trim((string) ($payload['id'] ?? ''));
    $provider_reference = trim((string) ($payload['mid'] ?? ''));
    if ($provider_reference === '') {
      return new WP_REST_Response(['ok' => false, 'error' => 'missing_mid'], 404);
    }

    if (!function_exists('casanova_payment_intent_get_by_provider_payment_id') || !function_exists('casanova_payment_intent_get_by_provider_reference')) {
      return new WP_REST_Response(['ok' => false, 'error' => 'intent_helpers_missing'], 500);
    }

    $intent = null;
    if ($provider_order_id !== '') {
      $intent = casanova_payment_intent_get_by_provider_payment_id($provider_order_id);
    }
    if (!$intent) {
      $intent = casanova_payment_intent_get_by_provider_reference($provider_reference);
    }
    if (!$intent) {
      return new WP_REST_Response(['ok' => false, 'error' => 'intent_not_found'], 404);
    }

    $status = strtolower(trim((string) ($payload['status'] ?? '')));
    $status_reason = strtolower(trim((string) ($payload['status_reason'] ?? '')));

    $merge = [
      'aplazame_callback' => [
        'payload' => $payload,
        'received_at' => current_time('mysql'),
      ],
    ];

    if ($status === 'pending' && $status_reason === 'confirmation_required') {
      $giav_result = casanova_payments_try_giav_cobro_aplazame($intent, $payload);
      if (!empty($giav_result['giav_cobro'])) {
        $merge['giav_cobro'] = $giav_result['giav_cobro'];
      }

      $confirmed = !empty($giav_result['inserted']) || !empty($giav_result['already']);
      casanova_payment_intent_update((int) $intent->id, [
        'provider' => 'aplazame',
        'method' => 'aplazame',
        'provider_payment_id' => $provider_order_id !== '' ? $provider_order_id : (string) ($intent->provider_payment_id ?? ''),
        'provider_reference' => $provider_reference,
        'status' => $confirmed ? 'pending_confirmation' : 'failed',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, array_merge($merge, [
          'aplazame_confirmation' => [
            'decision' => $confirmed ? 'ok' : 'ko',
            'time' => current_time('mysql'),
          ],
        ])),
      ]);

      if (!$confirmed) {
        return new WP_REST_Response([
          'status' => 'ko',
        ], 200);
      }

      if (!empty($giav_result['should_notify'])) {
        do_action('casanova_payment_cobro_recorded', (int) $intent->id);
      }

      if ((!empty($giav_result['inserted']) || !empty($giav_result['already'])) && !wp_next_scheduled('casanova_job_reconcile_payment', [(int) $intent->id])) {
        wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int) $intent->id]);
      }

      return new WP_REST_Response([
        'status' => 'ok',
        'order_id' => $provider_reference,
      ], 200);
    }

    if ($status === 'ok') {
      $giav_result = casanova_payments_try_giav_cobro_aplazame($intent, $payload);
      if (!empty($giav_result['giav_cobro'])) {
        $merge['giav_cobro'] = $giav_result['giav_cobro'];
      }

      $completed = !empty($giav_result['inserted']) || !empty($giav_result['already']);

      casanova_payment_intent_update((int) $intent->id, [
        'provider' => 'aplazame',
        'method' => 'aplazame',
        'provider_payment_id' => $provider_order_id !== '' ? $provider_order_id : (string) ($intent->provider_payment_id ?? ''),
        'provider_reference' => $provider_reference,
        'status' => $completed ? 'returned_ok' : 'failed',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, array_merge($merge, [
          'aplazame_completion' => [
            'decision' => $completed ? 'ok' : 'missing_giav_cobro',
            'time' => current_time('mysql'),
          ],
        ])),
      ]);

      if (!empty($giav_result['should_notify'])) {
        do_action('casanova_payment_cobro_recorded', (int) $intent->id);
      }

      if ((!empty($giav_result['inserted']) || !empty($giav_result['already'])) && !wp_next_scheduled('casanova_job_reconcile_payment', [(int) $intent->id])) {
        wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int) $intent->id]);
      }

      return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if ($status === 'ko') {
      casanova_payment_intent_update((int) $intent->id, [
        'provider' => 'aplazame',
        'method' => 'aplazame',
        'provider_payment_id' => $provider_order_id !== '' ? $provider_order_id : (string) ($intent->provider_payment_id ?? ''),
        'provider_reference' => $provider_reference,
        'status' => 'failed',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, $merge),
      ]);

      return new WP_REST_Response(['status' => 'ok'], 200);
    }

    casanova_payment_intent_update((int) $intent->id, [
      'provider' => 'aplazame',
      'method' => 'aplazame',
      'provider_payment_id' => $provider_order_id !== '' ? $provider_order_id : (string) ($intent->provider_payment_id ?? ''),
      'provider_reference' => $provider_reference,
      'status' => 'pending',
      'payload' => casanova_intent_payload_merge($intent->payload ?? null, $merge),
    ]);

    return new WP_REST_Response(['status' => 'ok'], 200);
  }
}

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/aplazame/notify', [
    'methods' => WP_REST_Server::CREATABLE,
    'callback' => 'casanova_handle_aplazame_notify',
    'permission_callback' => '__return_true',
  ]);
});
