<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Pagos
 * POST /wp-json/casanova/v1/payments/intent
 */
class Casanova_Payments_Controller {

  private static array $ALLOWED_TYPES = ['deposit', 'balance'];
  private static array $ALLOWED_METHODS = ['card', 'bank_transfer'];

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/payments/intent', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'expediente_id' => [
          'type' => 'integer',
          'required' => true,
        ],
        'type' => [
          'type' => 'string',
          'required' => true,
          'validate_callback' => function ($value) {
            return in_array(strtolower((string)$value), self::$ALLOWED_TYPES, true);
          },
        ],
        'mock' => [
          'type' => 'integer',
          'required' => false,
        ],
        'method' => [
          'type' => 'string',
          'required' => false,
          'description' => 'card|bank_transfer',
          'validate_callback' => function ($value) {
            $v = strtolower(trim((string)$value));
            return $v === '' || in_array($v, self::$ALLOWED_METHODS, true);
          },
        ],
      ],
    ]);
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    $mock = (int) $request->get_param('mock') === 1 && current_user_can('manage_options');
    if ($mock) {
      $mock_url = esc_url_raw(add_query_arg(['payment' => 'intent-mock'], home_url('/portal')));
      return rest_ensure_response([
        'ok' => true,
        'redirect_url' => $mock_url,
      ]);
    }

    $expediente_id = (int) $request->get_param('expediente_id');
    if ($expediente_id <= 0) {
      return self::error_response(
        esc_html__('Expediente inválido.', 'casanova-portal'),
        'invalid_expediente',
        400
      );
    }

    $type = strtolower(trim((string) $request->get_param('type')));
    if (!in_array($type, self::$ALLOWED_TYPES, true)) {
      return self::error_response(
        esc_html__('Tipo de pago inválido.', 'casanova-portal'),
        'invalid_type',
        400
      );
    }

    $method = strtolower(trim((string)$request->get_param('method')));
    if ($method === '') $method = 'card';
    if (!in_array($method, self::$ALLOWED_METHODS, true)) {
      return self::error_response(
        esc_html__('Método de pago inválido.', 'casanova-portal'),
        'invalid_method',
        400
      );
    }

    $user_id = get_current_user_id();
    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if ($idCliente <= 0) {
      return self::error_response(
        esc_html__('Tu cuenta no está vinculada a un cliente.', 'casanova-portal'),
        'no_client',
        403
      );
    }

    $context = Casanova_Payments_Service::describe_for_user($user_id, $idCliente, $expediente_id);
    if (is_wp_error($context)) {
      $status = $context->get_error_code() === 'permissions' ? 403 : 400;
      return new WP_REST_Response([
        'ok' => false,
        'code' => $context->get_error_code() ?: 'payments_error',
        'message' => $context->get_error_message(),
      ], $status);
    }

    $actions = is_array($context['actions'] ?? null) ? $context['actions'] : [];
    $action = $actions[$type] ?? ['allowed' => false];
    if (empty($action['allowed'])) {
      $code = $type === 'deposit' ? 'deposit_not_allowed' : 'balance_not_allowed';
      $message = $type === 'deposit'
        ? esc_html__('El depósito no está disponible para este expediente.', 'casanova-portal')
        : esc_html__('No hay importe pendiente para pagar.', 'casanova-portal');
      return self::error_response($message, $code, 403);
    }

    // 1) Tarjeta (Redsys): mantenemos lo existente.
    if ($method === 'card') {
      $pay_url = $context['pay_url'] ?? '';
      if (!$pay_url) {
        return self::error_response(
          esc_html__('No se pudo generar la URL de pago.', 'casanova-portal'),
          'no_redirect',
          500
        );
      }

      $mode = $type === 'deposit' ? 'deposit' : 'full';
      $redirect_url = esc_url_raw(add_query_arg(['mode' => $mode], $pay_url));

      return rest_ensure_response([
        'ok' => true,
        'redirect_url' => $redirect_url,
      ]);
    }

    // 2) Transferencia (Inespay): iniciamos orden y devolvemos su portal URL.
    if (!class_exists('Casanova_Inespay_Service')) {
      return self::error_response(
        esc_html__('Inespay no está disponible en el servidor.', 'casanova-portal'),
        'inespay_missing',
        500
      );
    }
    if (!function_exists('casanova_payment_intent_create')) {
      return self::error_response(
        esc_html__('No se pudo inicializar el pago (intents).', 'casanova-portal'),
        'intent_missing',
        500
      );
    }

    $amount = (float)($action['amount'] ?? 0);
    if ($amount <= 0) {
      return self::error_response(
        esc_html__('Importe inválido.', 'casanova-portal'),
        'invalid_amount',
        400
      );
    }

    $mode = $type === 'deposit' ? 'deposit' : 'full';
    $reference = 'CAS-' . (int)$expediente_id . '-' . substr(casanova_payments_new_token(), 0, 10);
    $payer_name = 'Portal';
    $u = get_user_by('id', $user_id);
    if ($u && !empty($u->display_name)) $payer_name = (string)$u->display_name;

    $portal_base = function_exists('casanova_portal_base_url') ? (string)casanova_portal_base_url() : home_url('/portal-app/');
    $success_link = add_query_arg([
      'expediente' => (int)$expediente_id,
      'pay_status' => 'checking',
      'payment' => 'success',
      'method' => 'bank_transfer',
    ], $portal_base);
    $abort_link = add_query_arg([
      'expediente' => (int)$expediente_id,
      'pay_status' => 'ko',
      'payment' => 'failed',
      'method' => 'bank_transfer',
    ], $portal_base);

    $notif_url = home_url('/wp-json/casanova/v1/inespay/notify');

    $intent = casanova_payment_intent_create([
      'user_id' => $user_id,
      'id_cliente' => $idCliente,
      'id_expediente' => $expediente_id,
      'amount' => $amount,
      'currency' => 'EUR',
      'status' => 'created',
      'provider' => 'inespay',
      'method' => 'bank_transfer',
      'provider_reference' => $reference,
      'payload' => [
        'mode' => $mode,
        'method' => 'bank_transfer',
        'created_from' => 'portal',
      ],
    ]);
    if (is_wp_error($intent)) {
      error_log('[Casanova Payments] intent_create_failed: ' . $intent->get_error_message());
      return self::error_response(
        esc_html__('No se pudo crear el intento de pago.', 'casanova-portal'),
        'intent_create_failed',
        500
      );
    }

    $req = [
      'amount' => (int)round($amount * 100),
      'description' => ($mode === 'deposit' ? 'Depósito' : 'Pago') . ' Casanova Golf (' . (int)$expediente_id . ')',
            'subject' => ($mode === 'deposit' ? 'Depósito' : 'Pago') . ' Casanova Golf (' . (int)$expediente_id . ')',
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
        'expediente_id' => (int)$expediente_id,
        'idCliente' => (int)$idCliente,
        'payer' => $payer_name,
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
      error_log('[CASANOVA][INESPAY] init failed: ' . $res->get_error_message() . ' ' . wp_json_encode($res->get_error_data()));
      return self::error_response(
        esc_html__('No se pudo iniciar el pago por transferencia.', 'casanova-portal'),
        'inespay_init_failed',
        502
      );
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
      return self::error_response(
        esc_html__('Inespay no devolvió un enlace de pago.', 'casanova-portal'),
        'inespay_missing_link',
        502
      );
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

    return rest_ensure_response([
      'ok' => true,
      'redirect_url' => esc_url_raw($redirect_url),
      'method' => 'bank_transfer',
      'intent_id' => (int)$intent->id,
    ]);
  }

  private static function error_response(string $message, string $code, int $status): WP_REST_Response {
    return new WP_REST_Response([
      'ok' => false,
      'code' => $code,
      'message' => $message,
    ], $status);
  }

}