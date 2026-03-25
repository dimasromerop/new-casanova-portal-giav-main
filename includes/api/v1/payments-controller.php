<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Pagos
 * POST /wp-json/casanova/v1/payments/intent
 */
class Casanova_Payments_Controller {

  private static array $ALLOWED_TYPES = ['deposit', 'balance'];
  private static array $ALLOWED_METHODS = ['card', 'bank_transfer', 'aplazame'];

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
          'description' => 'card|bank_transfer|aplazame',
          'validate_callback' => function ($value) {
            $v = strtolower(trim((string)$value));
            return $v === '' || in_array($v, self::$ALLOWED_METHODS, true);
          },
        ],
        'card_brand' => [
          'type' => 'string',
          'required' => false,
          'description' => 'amex|other',
          'validate_callback' => function ($value) {
            $v = strtolower(trim((string)$value));
            return $v === '' || $v === 'amex' || $v === 'other';
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
    $user_id_rl = get_current_user_id();
    if (function_exists('casanova_rate_limit') && !casanova_rate_limit('payments_intent_' . $user_id_rl, 10, 300)) {
      return new WP_REST_Response([
        'ok' => false,
        'code' => 'rate_limited',
        'message' => esc_html__('Demasiados intentos de pago. Espera unos minutos.', 'casanova-portal'),
      ], 429);
    }

    casanova_portal_log('payments.intent.enter', [
      'user_id' => get_current_user_id(),
      'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
      'expediente_id' => (int) $request->get_param('expediente_id'),
      'type' => (string) $request->get_param('type'),
      'method' => (string) $request->get_param('method'),
      'card_brand' => (string) $request->get_param('card_brand'),
    ]);
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

    $card_brand = 'other';
    if ($method === 'card') {
      if (function_exists('casanova_redsys_normalize_card_brand')) {
        $card_brand = casanova_redsys_normalize_card_brand($request->get_param('card_brand'));
      } else {
        $card_brand_raw = strtolower(trim((string)$request->get_param('card_brand')));
        $card_brand = ($card_brand_raw === 'amex' || $card_brand_raw === 'american_express') ? 'amex' : 'other';
      }
    }

    $user_id = function_exists('casanova_portal_get_effective_user_id')
      ? casanova_portal_get_effective_user_id()
      : get_current_user_id();
    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);
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

    $amount = (float)($action['amount'] ?? 0);
    if ($amount <= 0) {
      return self::error_response(
        esc_html__('Importe inválido.', 'casanova-portal'),
        'invalid_amount',
        400
      );
    }

    $mode = $type === 'deposit' ? 'deposit' : 'full';

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
      $redirect_url = esc_url_raw(add_query_arg([
        'autostart' => 1,
        'mode' => $mode,
        'card_brand' => $card_brand,
      ], $pay_url));

      return rest_ensure_response([
        'ok' => true,
        'redirect_url' => $redirect_url,
      ]);
    }

    // 2) Aplazame: creamos checkout server-side y el frontend abre el SDK.
    if ($method === 'aplazame') {
      if (!class_exists('Casanova_Aplazame_Service')) {
        return self::error_response(
          esc_html__('Aplazame no está disponible en el servidor.', 'casanova-portal'),
          'aplazame_missing',
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

      $public_cfg = Casanova_Aplazame_Service::public_checkout_config();
      if (is_wp_error($public_cfg)) {
        return self::error_response(
          esc_html__('Aplazame no está configurado todavía.', 'casanova-portal'),
          'aplazame_config_missing',
          500
        );
      }

      $aplazame_giav_method_id = defined('CASANOVA_GIAV_IDFORMAPAGO_APLAZAME')
        ? (int) CASANOVA_GIAV_IDFORMAPAGO_APLAZAME
        : (int) get_option('casanova_giav_idformapago_aplazame', 0);
      if ($aplazame_giav_method_id <= 0) {
        return self::error_response(
          esc_html__('Falta configurar la forma de pago de Aplazame en GIAV.', 'casanova-portal'),
          'aplazame_giav_missing',
          500
        );
      }

      $reference = 'APL-' . (int)$expediente_id . '-' . substr(casanova_payments_new_token(), 0, 12);
      $intent = casanova_payment_intent_create([
        'user_id' => $user_id,
        'id_cliente' => $idCliente,
        'id_expediente' => $expediente_id,
        'amount' => $amount,
        'currency' => 'EUR',
        'status' => 'created',
        'provider' => 'aplazame',
        'method' => 'aplazame',
        'provider_reference' => $reference,
        'payload' => [
          'mode' => $mode,
          'method' => 'aplazame',
          'created_from' => 'portal',
        ],
      ]);
      if (is_wp_error($intent)) {
        return self::error_response(
          esc_html__('No se pudo crear el intento de pago.', 'casanova-portal'),
          'intent_create_failed',
          500
        );
      }

      $merchant_urls = self::aplazame_merchant_urls($expediente_id, (int)$intent->id);
      $checkout_payload = Casanova_Aplazame_Service::build_checkout_payload(
        $user_id,
        $idCliente,
        $intent,
        is_array($context['reservas'] ?? null) ? $context['reservas'] : [],
        $merchant_urls
      );
      if (is_wp_error($checkout_payload)) {
        casanova_payment_intent_update((int)$intent->id, [
          'status' => 'failed',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'aplazame_init' => [
              'ok' => false,
              'error' => $checkout_payload->get_error_message(),
              'error_code' => $checkout_payload->get_error_code(),
              'time' => current_time('mysql'),
            ],
          ]),
        ]);

        $status = in_array($checkout_payload->get_error_code(), ['aplazame_missing_email', 'aplazame_missing_address'], true)
          ? 400
          : 500;

        return self::error_response(
          $checkout_payload->get_error_message(),
          'aplazame_payload_invalid',
          $status
        );
      }

      $checkout = Casanova_Aplazame_Service::create_checkout($checkout_payload);
      if (is_wp_error($checkout)) {
        casanova_payment_intent_update((int)$intent->id, [
          'status' => 'failed',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'aplazame_init' => [
              'ok' => false,
              'error' => $checkout->get_error_message(),
              'error_data' => $checkout->get_error_data(),
              'time' => current_time('mysql'),
            ],
          ]),
        ]);

        return self::error_response(
          $checkout->get_error_message() ?: esc_html__('No se pudo iniciar Aplazame.', 'casanova-portal'),
          'aplazame_init_failed',
          502
        );
      }

      casanova_payment_intent_update((int)$intent->id, [
        'provider_payment_id' => (string)($checkout['checkout_id'] ?? ''),
        'status' => 'initiated',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
          'aplazame_init' => [
            'ok' => true,
            'checkout_id' => (string)($checkout['checkout_id'] ?? ''),
            'location' => (string)($checkout['location'] ?? ''),
            'time' => current_time('mysql'),
          ],
        ]),
      ]);

      return rest_ensure_response([
        'ok' => true,
        'method' => 'aplazame',
        'flow' => 'aplazame',
        'checkout_id' => (string)($checkout['checkout_id'] ?? ''),
        'intent_id' => (int)$intent->id,
        'aplazame' => $public_cfg,
        'return_urls' => [
          'success' => $merchant_urls['success_url'],
          'pending' => $merchant_urls['pending_url'],
          'ko' => $merchant_urls['ko_url'],
          'error' => $merchant_urls['error_url'],
          'dismiss' => $merchant_urls['dismiss_url'],
        ],
      ]);
    }

    // 3) Transferencia (Inespay): iniciamos orden y devolvemos su portal URL.
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

    // IMPORTANT:
    // In production we MUST avoid returning directly to a protected portal URL because some setups
    // force a redirect to /login/?redirect_to=..., even if the user is already logged-in.
    // We instead return to a lightweight REST "bridge" that will then redirect to the SPA.
    $success_link = '';
    $abort_link = '';
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

    // Build return URLs AFTER intent exists.
    // IMPORTANT: Use a clean, non-REST return URL for third-party redirects.
    // Some production stacks cache or harden /wp-json/* in ways that can produce
    // rest_no_route intermittently. A plain WP route is more resilient.
    $success_link = add_query_arg([
      'status' => 'success',
      'expediente' => (int) $expediente_id,
      'intent_id' => (int) $intent->id,
    ], home_url('/inespay/return/'));

    $abort_link = add_query_arg([
      'status' => 'failed',
      'expediente' => (int) $expediente_id,
      'intent_id' => (int) $intent->id,
    ], home_url('/inespay/return/'));

    $payment_label = $type === 'deposit'
      ? __('Depósito', 'casanova-portal')
      : __('Pago', 'casanova-portal');

    $req = [
      'amount' => (int)round($amount * 100),
      'description' => $payment_label . ' Casanova Golf (' . (int)$expediente_id . ')',
      'subject' => $payment_label . ' Casanova Golf (' . (int)$expediente_id . ')',
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
      casanova_portal_log('payments.intent.inespay.wp_error', [
        'message' => $res->get_error_message(),
        'data' => $res->get_error_data(),
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
    casanova_portal_log('payments.intent.inespay.response', [
      'has_singlePayinId' => !empty($res['singlePayinId']),
      'redirect_url' => $redirect_url,
    ]);

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

  private static function aplazame_merchant_urls(int $expediente_id, int $intent_id): array {
    $portal_base = function_exists('casanova_portal_base_url')
      ? (string) casanova_portal_base_url()
      : home_url('/portal-app/');

    return [
      'portal_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id),
      'notification_url' => home_url('/wp-json/casanova/v1/aplazame/notify'),
      'success_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id, [
        'payment' => 'success',
        'method' => 'aplazame',
        'pay_status' => 'ok',
        'refresh' => '1',
      ]),
      'pending_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id, [
        'payment' => 'success',
        'method' => 'aplazame',
        'pay_status' => 'checking',
        'refresh' => '1',
      ]),
      'error_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id, [
        'payment' => 'failed',
        'method' => 'aplazame',
        'pay_status' => 'error',
      ]),
      'dismiss_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id),
      'ko_url' => self::portal_trip_url($portal_base, $expediente_id, $intent_id, [
        'payment' => 'failed',
        'method' => 'aplazame',
        'pay_status' => 'ko',
      ]),
    ];
  }

  private static function portal_trip_url(string $portal_base, int $expediente_id, int $intent_id, array $extra = []): string {
    $args = array_merge([
      'view' => 'trip',
      'tab' => 'payments',
      'expediente' => $expediente_id,
      'intent_id' => $intent_id,
    ], $extra);

    return esc_url_raw(add_query_arg($args, $portal_base));
  }

  private static function error_response(string $message, string $code, int $status): WP_REST_Response {
    return new WP_REST_Response([
      'ok' => false,
      'code' => $code,
      'message' => $message,
    ], $status);
  }

}
