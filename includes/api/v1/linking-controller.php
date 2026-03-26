<?php

/**
 * REST endpoints for secure account linking (DNI or GIAV ID -> GIAV) using Email OTP.
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
        'identifier' => [
          'required' => false,
          'type'     => 'string',
        ],
        'identifierType' => [
          'required' => false,
          'type'     => 'string',
        ],
        'dni' => [
          'required' => false,
          'type'     => 'string',
        ],
        'giavId' => [
          'required' => false,
          'type'     => 'string',
        ],
        'giav_id' => [
          'required' => false,
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
        'identifier' => [
          'required' => false,
          'type'     => 'string',
        ],
        'identifierType' => [
          'required' => false,
          'type'     => 'string',
        ],
        'dni' => [
          'required' => false,
          'type'     => 'string',
        ],
        'giavId' => [
          'required' => false,
          'type'     => 'string',
        ],
        'giav_id' => [
          'required' => false,
          'type'     => 'string',
        ],
        'otp' => [
          'required' => true,
          'type'     => 'string',
        ],
      ],
    ]);
  }

  private static function normalize_identifier_type(string $identifier_type): string {
    if (function_exists('casanova_portal_linking_normalize_identifier_type')) {
      return casanova_portal_linking_normalize_identifier_type($identifier_type);
    }

    return 'dni';
  }

  private static function normalize_identifier(string $identifier, string $identifier_type): string {
    if (function_exists('casanova_portal_linking_normalize_identifier')) {
      return casanova_portal_linking_normalize_identifier($identifier, $identifier_type);
    }

    if ($identifier_type === 'giav_id') {
      return (string) preg_replace('/\D+/', '', sanitize_text_field($identifier));
    }

    $identifier = sanitize_text_field($identifier);
    return (string) preg_replace('/\s+/', '', strtoupper(trim($identifier)));
  }

  private static function extract_identifier(WP_REST_Request $req): array {
    $type = (string) $req->get_param('identifierType');
    if ($type === '') {
      $type = (string) $req->get_param('identifier_type');
    }
    if ($type === '' && ($req->get_param('giavId') !== null || $req->get_param('giav_id') !== null)) {
      $type = 'giav_id';
    }

    $type = self::normalize_identifier_type($type);

    $identifier = (string) $req->get_param('identifier');
    if ($identifier === '') {
      $identifier = (string) $req->get_param('identifier_value');
    }
    if ($identifier === '') {
      $identifier = $type === 'giav_id'
        ? (string) ($req->get_param('giavId') ?? $req->get_param('giav_id') ?? '')
        : (string) ($req->get_param('dni') ?? '');
    }

    return [
      'type'  => $type,
      'value' => self::normalize_identifier($identifier, $type),
    ];
  }

  private static function error_status(WP_Error $error): int {
    return match ($error->get_error_code()) {
      'missing_identifier', 'invalid_identifier', 'otp_missing_identifier', 'otp_missing_customer', 'otp_missing', 'otp_invalid', 'otp_not_found', 'otp_expired', 'otp_locked', 'otp_no_email' => 400,
      'otp_rate_limited' => 429,
      'not_found' => 404,
      'otp_send_failed' => 500,
      default => 500,
    };
  }

  public static function request_otp(WP_REST_Request $req): WP_REST_Response {
    $user_id = get_current_user_id();
    if (function_exists('casanova_rate_limit') && !casanova_rate_limit('linking_request_' . $user_id, 5, 900)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'rate_limited',
        'message' => __('Demasiados intentos. Espera unos minutos.', 'casanova-portal'),
      ], 429);
    }

    $lookup = self::extract_identifier($req);
    if ($lookup['value'] === '') {
      $message = function_exists('casanova_portal_linking_missing_identifier_message')
        ? casanova_portal_linking_missing_identifier_message($lookup['type'])
        : __('Introduce tu DNI.', 'casanova-portal');

      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'missing_identifier',
        'message' => $message,
      ], 400);
    }

    if ($lookup['type'] === 'dni') {
      update_user_meta($user_id, 'casanova_dni', $lookup['value']);
    }

    if (!function_exists('casanova_portal_linking_resolve_customer_id')) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'giav_unavailable',
        'message' => __('No podemos consultar el sistema en este momento. Inténtalo más tarde.', 'casanova-portal'),
      ], 500);
    }

    $giav_customer_id = casanova_portal_linking_resolve_customer_id($lookup['type'], $lookup['value']);
    if (is_wp_error($giav_customer_id)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => $giav_customer_id->get_error_code(),
        'message' => $giav_customer_id->get_error_message(),
      ], self::error_status($giav_customer_id));
    }

    if (!function_exists('casanova_portal_send_linking_otp')) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'otp_unavailable',
        'message' => __('No se ha podido iniciar la verificación. Contacta con nosotros.', 'casanova-portal'),
      ], 500);
    }

    $sr = casanova_portal_send_linking_otp($user_id, $lookup['value'], (int) $giav_customer_id, $lookup['type']);
    if (is_wp_error($sr)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => $sr->get_error_code(),
        'message' => $sr->get_error_message(),
      ], self::error_status($sr));
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
    if (function_exists('casanova_rate_limit') && !casanova_rate_limit('linking_verify_' . $user_id, 10, 900)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => 'rate_limited',
        'message' => __('Demasiados intentos. Espera unos minutos.', 'casanova-portal'),
      ], 429);
    }

    $lookup = self::extract_identifier($req);
    $otp = sanitize_text_field((string) $req->get_param('otp'));
    $otp = preg_replace('/\s+/', '', trim($otp));

    if ($otp === '') {
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

    $vr = casanova_portal_verify_linking_otp($user_id, $lookup['value'], $otp, $lookup['type']);
    if (is_wp_error($vr)) {
      return new WP_REST_Response([
        'ok'      => false,
        'code'    => $vr->get_error_code(),
        'message' => $vr->get_error_message(),
      ], self::error_status($vr));
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
