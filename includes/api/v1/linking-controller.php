<?php

/**
 * REST endpoints for secure account linking (DNI -> GIAV) using Email OTP.
 *
 * Why: Bricks forms are great for layout, not for multi-step auth flows.
 */

if (!defined('ABSPATH')) exit;

class Casanova_Linking_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/linking/request', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'request_otp'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
      'args' => [
        'dni' => [
          'required' => true,
          'type'     => 'string',
        ],
      ],
    ]);

    register_rest_route('casanova/v1', '/linking/verify', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'verify_otp'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
      'args' => [
        'dni' => [
          'required' => true,
          'type'     => 'string',
        ],
        'otp' => [
          'required' => true,
          'type'     => 'string',
        ],
      ],
    ]);
  }

  private static function normalize_dni(string $dni): string {
    $dni = sanitize_text_field($dni);
    $dni = preg_replace('/\s+/', '', strtoupper(trim($dni)));
    return (string) $dni;
  }

  public static function request_otp(WP_REST_Request $req): WP_REST_Response {
    $user_id = get_current_user_id();
    $dni = self::normalize_dni((string) $req->get_param('dni'));

    if ($dni === '') {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'missing_dni',
        'message' => __('Introduce tu DNI.', 'casanova-portal'),
      ], 400);
    }

    update_user_meta($user_id, 'casanova_dni', $dni);

    // 1) Find customer by DNI
    if (!function_exists('casanova_giav_cliente_search_por_dni')) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'giav_unavailable',
        'message' => __('No podemos consultar el sistema en este momento. Inténtalo más tarde.', 'casanova-portal'),
      ], 500);
    }

    $resp = casanova_giav_cliente_search_por_dni($dni);
    if (is_wp_error($resp)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'giav_error',
        'message' => __('No hemos podido consultar el sistema. Inténtalo más tarde.', 'casanova-portal'),
      ], 500);
    }

    $idCliente = null;
    if (function_exists('casanova_giav_extraer_idcliente')) {
      $idCliente = casanova_giav_extraer_idcliente($resp);
    }
    if (!$idCliente) {
      // Keep message user-friendly, but not overly informative.
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'not_found',
        'message' => __('No encontramos ninguna reserva asociada a ese DNI. Si ya has viajado con nosotros, escríbenos y lo revisamos.', 'casanova-portal'),
      ], 404);
    }

    // 2) Send OTP via wp_mail() to GIAV email
    if (!function_exists('casanova_portal_send_linking_otp')) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'otp_unavailable',
        'message' => __('No se ha podido iniciar la verificación. Contacta con nosotros.', 'casanova-portal'),
      ], 500);
    }

    $sr = casanova_portal_send_linking_otp($user_id, $dni, (int) $idCliente);
    if (is_wp_error($sr)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => $sr->get_error_code(),
        'message' => $sr->get_error_message(),
      ], 400);
    }

    $base_url = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');

    return new WP_REST_Response([
      'ok'         => true,
      'status'     => 'otp_sent',
      'emailMasked'=> (string) ($sr['emailMasked'] ?? ''),
      'expiresIn'  => (int) ($sr['expiresIn'] ?? 600),
      'redirectTo' => $base_url,
    ], 200);
  }

  public static function verify_otp(WP_REST_Request $req): WP_REST_Response {
    $user_id = get_current_user_id();
    $dni = self::normalize_dni((string) $req->get_param('dni'));
    $otp = sanitize_text_field((string) $req->get_param('otp'));
    $otp = preg_replace('/\s+/', '', trim($otp));

    if ($dni === '' || $otp === '') {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'missing_fields',
        'message' => __('Introduce el código que te hemos enviado.', 'casanova-portal'),
      ], 400);
    }

    if (!function_exists('casanova_portal_verify_linking_otp')) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'otp_unavailable',
        'message' => __('No se ha podido validar el código. Contacta con nosotros.', 'casanova-portal'),
      ], 500);
    }

    $vr = casanova_portal_verify_linking_otp($user_id, $dni, $otp);
    if (is_wp_error($vr)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => $vr->get_error_code(),
        'message' => $vr->get_error_message(),
      ], 400);
    }

    $base_url = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');

    return new WP_REST_Response([
      'ok'         => true,
      'status'     => 'verified',
      'redirectTo' => $base_url,
      'giavCustomerId' => (int) ($vr['giavCustomerId'] ?? 0),
    ], 200);
  }
}
