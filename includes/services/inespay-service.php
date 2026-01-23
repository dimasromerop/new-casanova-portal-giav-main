<?php
if (!defined('ABSPATH')) exit;

/**
 * Inespay (Transferencia Online) helper.
 *
 * Principio: el frontend nunca habla con Inespay. Solo el backend.
 */
class Casanova_Inespay_Service {

  public static function config() {
    $api_key   = defined('CASANOVA_INESPAY_API_KEY') ? (string)CASANOVA_INESPAY_API_KEY : '';
    $api_token = defined('CASANOVA_INESPAY_API_TOKEN') ? (string)CASANOVA_INESPAY_API_TOKEN : '';
    $base_url  = defined('CASANOVA_INESPAY_BASE_URL') ? rtrim((string)CASANOVA_INESPAY_BASE_URL, '/') : '';

    $api_key = trim($api_key);
    $api_token = trim($api_token);
    $base_url = trim($base_url);

    if ($api_key === '' || $api_token === '' || $base_url === '') {
      return new WP_Error(
        'inespay_config_missing',
        'Falta configuración Inespay: define CASANOVA_INESPAY_API_KEY, CASANOVA_INESPAY_API_TOKEN y CASANOVA_INESPAY_BASE_URL en wp-config.php'
      );
    }

    return [
      'api_key' => $api_key,
      'api_token' => $api_token,
      'base_url' => $base_url,
    ];
  }

  /**
   * POST JSON a Inespay (API-KEY / API-TOKEN en headers).
   */
  public static function post(string $path, array $body) {
    $cfg = self::config();
    if (is_wp_error($cfg)) return $cfg;

    $url = $cfg['base_url'] . '/' . ltrim($path, '/');

    $args = [
      'timeout' => 25,
      'headers' => [
        'Content-Type' => 'application/json',
        'API-KEY' => $cfg['api_key'],
        'API-TOKEN' => $cfg['api_token'],
      ],
      'body' => wp_json_encode($body),
    ];

    $res = wp_remote_post($url, $args);
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $raw  = (string) wp_remote_retrieve_body($res);

    $data = json_decode($raw, true);
    if (!is_array($data)) $data = null;

    if ($code < 200 || $code >= 300) {
      return new WP_Error('inespay_http_error', 'Inespay HTTP ' . $code, [
        'url' => $url,
        'code' => $code,
        'body' => $raw,
        'json' => $data,
      ]);
    }

    return is_array($data) ? $data : ['raw' => $raw];
  }

  /**
   * Inicializa un pago simple.
   *
   * Endpoint (según doc): /payins/single/init
   *
   * Importante:
   * - amount se envía multiplicado por 100 (entero)
   * - notifUrl debe ser servidor-servidor
   * - successLinkRedirect / abortLinkRedirect son redirecciones del cliente
   */
  public static function init_single_payment(array $params) {
    return self::post('/payins/single/init', $params);
  }

  /**
   * Best-effort: intenta localizar un enlace de redirección en la respuesta.
   * (La doc cambia nombres según versión y Readme no siempre lo expone.)
   */
  public static function extract_redirect_url(array $response): string {
    $candidates = [
      'paymentLink',
      'link',
      'url',
      'portalUrl',
      'redirectUrl',
      'successLinkRedirect',
      'flowUrl',
      'payUrl',
    ];
    foreach ($candidates as $k) {
      if (!empty($response[$k]) && is_string($response[$k]) && preg_match('~^https?://~i', $response[$k])) {
        return $response[$k];
      }
    }
    // fallback: busca cualquier string http(s) en primer nivel
    foreach ($response as $v) {
      if (is_string($v) && preg_match('~^https?://~i', $v)) return $v;
    }
    return '';
  }

  /**
   * Verifica signatureDataReturn según doc.
   *
   * - HmacSHA256 sobre dataReturn (Base64), usando API-KEY como clave.
   * - hash -> hex lowercase -> Base64.
   */
  public static function verify_signature(string $dataReturnB64, string $signatureDataReturn): bool {
    $cfg = self::config();
    if (is_wp_error($cfg)) return false;

    $dataReturnB64 = trim($dataReturnB64);
    $signatureDataReturn = trim($signatureDataReturn);
    if ($dataReturnB64 === '' || $signatureDataReturn === '') return false;

    $hex = hash_hmac('sha256', $dataReturnB64, $cfg['api_key']); // hex lowercase
    $expected = base64_encode(strtolower($hex));

    // Igual que Redsys: a veces vienen sin padding.
    $exp = rtrim($expected, '=');
    $got = rtrim($signatureDataReturn, '=');

    return hash_equals($exp, $got);
  }

  public static function decode_data_return(string $dataReturnB64): ?array {
    $dataReturnB64 = trim($dataReturnB64);
    if ($dataReturnB64 === '') return null;

    $json = base64_decode($dataReturnB64, true);
    if (!is_string($json) || $json === '') return null;

    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : null;
  }
}
