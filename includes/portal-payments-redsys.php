<?php
if (!defined('ABSPATH')) exit;

/**
 * Configuración Redsys.
 *
 * El plugin mantiene un TPV principal y un TPV dedicado a AMEX.
 * Por ahora el flujo usa siempre el TPV principal, pero dejamos la base
 * preparada para seleccionar uno u otro sin volver a tocar la integración.
 */
if (!function_exists('casanova_redsys_known_tpvs')) {
  function casanova_redsys_known_tpvs(): array {
    return [
      'default' => [
        'label' => 'TPV principal',
        'merchant_code' => '358384055',
        'terminal' => '001',
        'currency' => '978',
        'secret_key' => 'sq7HjrUOBfKmC576ILgskD5srU870gJ7',
        'sandbox' => 1,
        'endpoint_override' => '',
        'sig_version' => 'HMAC_SHA256_V1',
      ],
      'amex' => [
        'label' => 'TPV AMEX',
        'merchant_code' => '',
        'terminal' => '001',
        'currency' => '978',
        'secret_key' => '',
        'sandbox' => 1,
        'endpoint_override' => '',
        'sig_version' => 'HMAC_SHA256_V1',
      ],
    ];
  }
}

if (!function_exists('casanova_redsys_endpoint_for_env')) {
  function casanova_redsys_endpoint_for_env(bool $sandbox): string {
    return $sandbox
      ? 'https://sis-t.redsys.es:25443/sis/realizarPago'
      : 'https://sis.redsys.es/sis/realizarPago';
  }
}

if (!function_exists('casanova_redsys_normalize_tpv_settings')) {
  function casanova_redsys_normalize_tpv_settings(array $settings, array $defaults = []): array {
    $raw = array_replace($defaults, $settings);

    $label = trim((string)($raw['label'] ?? ''));
    if ($label === '') {
      $label = trim((string)($defaults['label'] ?? ''));
    }

    $merchant_code = preg_replace('/\D+/', '', (string)($raw['merchant_code'] ?? ''));

    $terminal = preg_replace('/\D+/', '', (string)($raw['terminal'] ?? ''));
    if ($terminal === '') {
      $terminal = preg_replace('/\D+/', '', (string)($defaults['terminal'] ?? ''));
    }
    if ($terminal !== '') {
      $terminal = str_pad(substr($terminal, -3), 3, '0', STR_PAD_LEFT);
    }

    $currency = preg_replace('/\D+/', '', (string)($raw['currency'] ?? ''));
    if ($currency === '') {
      $currency = preg_replace('/\D+/', '', (string)($defaults['currency'] ?? '978'));
    }
    if ($currency === '') {
      $currency = '978';
    }
    $currency = substr($currency, 0, 3);

    $secret_key = trim(str_replace(["\r", "\n", "\t"], '', (string)($raw['secret_key'] ?? '')));
    $secret_key = substr($secret_key, 0, 255);

    $sandbox = !empty($raw['sandbox']) ? 1 : 0;

    $endpoint_override = trim((string)($raw['endpoint_override'] ?? ''));
    $endpoint_override = $endpoint_override !== '' ? esc_url_raw($endpoint_override) : '';

    $sig_version = strtoupper(trim((string)($raw['sig_version'] ?? '')));
    $sig_version = preg_replace('/[^A-Z0-9_]/', '', $sig_version);
    if ($sig_version === '') {
      $sig_version = strtoupper(trim((string)($defaults['sig_version'] ?? 'HMAC_SHA256_V1')));
      $sig_version = preg_replace('/[^A-Z0-9_]/', '', $sig_version);
    }
    if ($sig_version === '') {
      $sig_version = 'HMAC_SHA256_V1';
    }

    $resolved_endpoint = $endpoint_override !== ''
      ? $endpoint_override
      : casanova_redsys_endpoint_for_env((bool)$sandbox);

    return [
      'label' => $label,
      'merchant_code' => $merchant_code,
      'terminal' => $terminal,
      'currency' => $currency,
      'secret_key' => $secret_key,
      'sandbox' => $sandbox,
      'endpoint_override' => $endpoint_override,
      'endpoint' => $resolved_endpoint,
      'resolved_endpoint' => $resolved_endpoint,
      'sig_version' => $sig_version,
    ];
  }
}

if (!function_exists('casanova_redsys_get_tpvs')) {
  function casanova_redsys_get_tpvs(): array {
    $defaults = casanova_redsys_known_tpvs();
    $saved = get_option('casanova_redsys_tpvs', []);
    if (!is_array($saved)) {
      $saved = [];
    }

    $tpvs = [];
    foreach ($defaults as $key => $default_cfg) {
      $saved_cfg = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
      $tpvs[$key] = casanova_redsys_normalize_tpv_settings($saved_cfg, $default_cfg);
    }

    return apply_filters('casanova_redsys_tpvs', $tpvs, $saved, $defaults);
  }
}

if (!function_exists('casanova_redsys_config')) {
  function casanova_redsys_config(string $tpv_key = 'default'): array {
    $tpvs = casanova_redsys_get_tpvs();
    if (!isset($tpvs[$tpv_key])) {
      $tpv_key = 'default';
    }

    $cfg = $tpvs[$tpv_key] ?? [];
    $cfg['tpv_key'] = $tpv_key;

    return apply_filters('casanova_redsys_config', $cfg, $tpv_key, $tpvs);
  }
}

if (!function_exists('casanova_redsys_is_config_complete')) {
  function casanova_redsys_is_config_complete(array $cfg): bool {
    return !empty($cfg['endpoint'])
      && !empty($cfg['merchant_code'])
      && !empty($cfg['terminal'])
      && !empty($cfg['currency'])
      && !empty($cfg['secret_key']);
  }
}

if (!function_exists('casanova_redsys_normalize_card_brand')) {
  function casanova_redsys_normalize_card_brand($value): string {
    $brand = strtolower(trim((string)$value));
    if ($brand === 'american_express') {
      $brand = 'amex';
    }
    return $brand === 'amex' ? 'amex' : 'other';
  }
}

if (!function_exists('casanova_redsys_card_brand_from_intent')) {
  function casanova_redsys_card_brand_from_intent($intent): string {
    if (!is_object($intent)) {
      return '';
    }

    $payload = [];
    $raw_payload = $intent->payload ?? null;
    if (is_array($raw_payload)) {
      $payload = $raw_payload;
    } elseif (is_string($raw_payload) && $raw_payload !== '') {
      $decoded = json_decode($raw_payload, true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    if (empty($payload)) {
      return '';
    }

    if (isset($payload['card_brand'])) {
      return casanova_redsys_normalize_card_brand($payload['card_brand']);
    }
    if (isset($payload['payment_card']['brand'])) {
      return casanova_redsys_normalize_card_brand($payload['payment_card']['brand']);
    }

    return '';
  }
}

if (!function_exists('casanova_redsys_tpv_key_from_intent')) {
  function casanova_redsys_tpv_key_from_intent($intent): string {
    if (!is_object($intent)) {
      return '';
    }

    $payload = [];
    $raw_payload = $intent->payload ?? null;
    if (is_array($raw_payload)) {
      $payload = $raw_payload;
    } elseif (is_string($raw_payload) && $raw_payload !== '') {
      $decoded = json_decode($raw_payload, true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $tpv_key = trim((string)($payload['redsys_tpv']['key'] ?? $payload['tpv_key'] ?? ''));
    return $tpv_key;
  }
}

if (!function_exists('casanova_redsys_match_tpv_key_from_params')) {
  function casanova_redsys_match_tpv_key_from_params(array $params): string {
    $merchant_code = trim((string)(
      $params['Ds_MerchantCode']
      ?? $params['DS_MERCHANTCODE']
      ?? $params['Ds_Merchant_MerchantCode']
      ?? $params['DS_MERCHANT_MERCHANTCODE']
      ?? ''
    ));
    $merchant_code = preg_replace('/\D+/', '', $merchant_code);

    $terminal = trim((string)(
      $params['Ds_Terminal']
      ?? $params['DS_TERMINAL']
      ?? $params['Ds_Merchant_Terminal']
      ?? $params['DS_MERCHANT_TERMINAL']
      ?? ''
    ));
    $terminal = preg_replace('/\D+/', '', $terminal);
    if ($terminal !== '') {
      $terminal = str_pad(substr($terminal, -3), 3, '0', STR_PAD_LEFT);
    }

    if ($merchant_code === '' && $terminal === '') {
      return '';
    }

    foreach (casanova_redsys_get_tpvs() as $tpv_key => $cfg) {
      $cfg_merchant = preg_replace('/\D+/', '', (string)($cfg['merchant_code'] ?? ''));
      $cfg_terminal = preg_replace('/\D+/', '', (string)($cfg['terminal'] ?? ''));
      if ($cfg_terminal !== '') {
        $cfg_terminal = str_pad(substr($cfg_terminal, -3), 3, '0', STR_PAD_LEFT);
      }

      if ($merchant_code !== '' && $cfg_merchant !== '' && $merchant_code !== $cfg_merchant) {
        continue;
      }
      if ($terminal !== '' && $cfg_terminal !== '' && $terminal !== $cfg_terminal) {
        continue;
      }
      if ($cfg_merchant !== '' || $cfg_terminal !== '') {
        return (string)$tpv_key;
      }
    }

    return '';
  }
}

if (!function_exists('casanova_redsys_select_tpv_key')) {
  function casanova_redsys_select_tpv_key(array $context = []): string {
    $tpvs = casanova_redsys_get_tpvs();
    $selected = 'default';
    $card_brand = '';

    if (!empty($context['tpv_key']) && is_string($context['tpv_key']) && isset($tpvs[$context['tpv_key']])) {
      $selected = $context['tpv_key'];
    }

    if (!empty($context['card_brand'])) {
      $card_brand = casanova_redsys_normalize_card_brand($context['card_brand']);
    } elseif (!empty($context['intent'])) {
      $card_brand = casanova_redsys_card_brand_from_intent($context['intent']);
    }

    if ($selected === 'default' && $card_brand === 'amex' && isset($tpvs['amex'])) {
      $selected = 'amex';
    }

    if ($selected === 'default' && !empty($context['intent'])) {
      $intent_key = casanova_redsys_tpv_key_from_intent($context['intent']);
      if ($intent_key !== '' && isset($tpvs[$intent_key])) {
        $selected = $intent_key;
      }
    }

    if ($selected === 'default' && !empty($context['params']) && is_array($context['params'])) {
      $params_key = casanova_redsys_match_tpv_key_from_params($context['params']);
      if ($params_key !== '' && isset($tpvs[$params_key])) {
        $selected = $params_key;
      }
    }

    $filtered = apply_filters('casanova_redsys_select_tpv_key', $selected, $context, $tpvs);
    if (!is_string($filtered) || !isset($tpvs[$filtered])) {
      return 'default';
    }

    return $filtered;
  }
}

if (!function_exists('casanova_redsys_get_secret')) {
  function casanova_redsys_get_secret(string $tpv_key = '', array $context = []): string {
    $resolved_tpv_key = $tpv_key !== '' ? $tpv_key : casanova_redsys_select_tpv_key($context);
    $cfg = casanova_redsys_config($resolved_tpv_key);
    if (!empty($cfg['secret_key'])) {
      return (string)$cfg['secret_key'];
    }
    return '';
  }
}

if (!function_exists('casanova_redsys_giav_method_id')) {
  function casanova_redsys_giav_method_id(string $tpv_key = '', array $context = []): int {
    $resolved_tpv_key = $tpv_key !== '' ? $tpv_key : casanova_redsys_select_tpv_key($context);
    $id_forma_pago = 0;

    if ($resolved_tpv_key === 'amex' && defined('CASANOVA_GIAV_IDFORMAPAGO_REDSYS_AMEX')) {
      $id_forma_pago = (int) CASANOVA_GIAV_IDFORMAPAGO_REDSYS_AMEX;
    }
    if ($resolved_tpv_key === 'amex' && $id_forma_pago <= 0) {
      $id_forma_pago = (int) get_option('casanova_giav_idformapago_redsys_amex', 0);
    }

    if ($id_forma_pago <= 0 && defined('CASANOVA_GIAV_IDFORMAPAGO_REDSYS')) {
      $id_forma_pago = (int) CASANOVA_GIAV_IDFORMAPAGO_REDSYS;
    }
    if ($id_forma_pago <= 0) {
      $id_forma_pago = (int) get_option('casanova_giav_idformapago_redsys', 1027);
    }

    $id_forma_pago = (int) apply_filters('casanova_redsys_giav_method_id', $id_forma_pago, $resolved_tpv_key, $context);
    return max(0, $id_forma_pago);
  }
}

if (!function_exists('casanova_redsys_build_tpv_payload')) {
  function casanova_redsys_build_tpv_payload(string $tpv_key): array {
    $cfg = casanova_redsys_config($tpv_key);
    return [
      'key' => $tpv_key,
      'label' => (string)($cfg['label'] ?? ''),
      'merchant_code' => (string)($cfg['merchant_code'] ?? ''),
      'terminal' => (string)($cfg['terminal'] ?? ''),
      'sandbox' => !empty($cfg['sandbox']),
      'endpoint' => (string)($cfg['endpoint'] ?? ''),
      'sig_version' => (string)($cfg['sig_version'] ?? ''),
      'giav_method_id' => function_exists('casanova_redsys_giav_method_id') ? casanova_redsys_giav_method_id($tpv_key) : 0,
    ];
  }
}

if (!function_exists('casanova_redsys_attach_intent_tpv')) {
  function casanova_redsys_attach_intent_tpv(int $intent_id, $old_payload, string $tpv_key): bool {
    if ($intent_id <= 0 || !function_exists('casanova_payment_intent_update')) {
      return false;
    }

    $payload = [];
    if (is_array($old_payload)) {
      $payload = $old_payload;
    } elseif (is_string($old_payload) && $old_payload !== '') {
      $decoded = json_decode($old_payload, true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $payload['redsys_tpv'] = casanova_redsys_build_tpv_payload($tpv_key);

    return casanova_payment_intent_update($intent_id, [
      'payload' => $payload,
    ]);
  }
}

if (!function_exists('casanova_redsys_prepare_redirect_data')) {
  function casanova_redsys_prepare_redirect_data($intent, array $context = []) {
    if (!is_object($intent) || empty($intent->id) || empty($intent->token) || empty($intent->order_redsys)) {
      return new WP_Error('redsys_invalid_intent', __('Intent de Redsys inválido.', 'casanova-portal'));
    }

    if (!function_exists('casanova_redsys_encode_params') || !function_exists('casanova_redsys_signature')) {
      return new WP_Error('redsys_helpers_missing', __('Redsys no está disponible.', 'casanova-portal'));
    }

    $context['intent'] = $intent;
    if (empty($context['card_brand'])) {
      $context['card_brand'] = casanova_redsys_card_brand_from_intent($intent);
    }
    $tpv_key = casanova_redsys_select_tpv_key($context);
    $cfg = casanova_redsys_config($tpv_key);
    if (!casanova_redsys_is_config_complete($cfg)) {
      return new WP_Error('redsys_config_incomplete', __('Config Redsys incompleta.', 'casanova-portal'));
    }

    $token = (string)$intent->token;
    $url_notify = function_exists('casanova_tpv_notify_url')
      ? casanova_tpv_notify_url()
      : home_url('/wp-json/casanova/v1/redsys/notify');
    $url_ok = function_exists('casanova_tpv_return_url')
      ? casanova_tpv_return_url(true, $token)
      : add_query_arg([
        'action' => 'casanova_tpv_return',
        'result' => 'ok',
        'token' => $token,
      ], admin_url('admin-post.php'));
    $url_ko = function_exists('casanova_tpv_return_url')
      ? casanova_tpv_return_url(false, $token)
      : add_query_arg([
        'action' => 'casanova_tpv_return',
        'result' => 'ko',
        'token' => $token,
      ], admin_url('admin-post.php'));

    $amount_cents = (string)((int) round(((float)$intent->amount) * 100));
    $merchant_params = [
      'DS_MERCHANT_AMOUNT' => $amount_cents,
      'DS_MERCHANT_ORDER' => (string)$intent->order_redsys,
      'DS_MERCHANT_MERCHANTCODE' => (string)$cfg['merchant_code'],
      'DS_MERCHANT_CURRENCY' => (string)$cfg['currency'],
      'DS_MERCHANT_TERMINAL' => (string)$cfg['terminal'],
      'DS_MERCHANT_TRANSACTIONTYPE' => '0',
      'DS_MERCHANT_MERCHANTURL' => $url_notify,
      'DS_MERCHANT_URLOK' => $url_ok,
      'DS_MERCHANT_URLKO' => $url_ko,
      'DS_MERCHANT_MERCHANTDATA' => $token,
    ];

    $mpB64 = casanova_redsys_encode_params($merchant_params);
    $signature = casanova_redsys_signature($mpB64, (string)$intent->order_redsys, (string)$cfg['secret_key']);
    if ($signature === '') {
      return new WP_Error('redsys_invalid_signature', __('Firma Redsys inválida.', 'casanova-portal'));
    }

    return [
      'tpv_key' => $tpv_key,
      'config' => $cfg,
      'endpoint' => (string)$cfg['endpoint'],
      'sig_version' => (string)$cfg['sig_version'],
      'merchant_params' => $merchant_params,
      'merchant_parameters' => $mpB64,
      'signature' => $signature,
      'amount_cents' => $amount_cents,
      'notify_url' => $url_notify,
      'url_ok' => $url_ok,
      'url_ko' => $url_ko,
    ];
  }
}


/**
 * Genera un order compatible con Redsys (4–12 chars, solo dígitos)
 * Formato: YYMMDD + intent_id padded a 6
 */
function casanova_redsys_order_from_intent_id(int $intent_id): string {
  $prefix = gmdate('ymd'); // YYMMDD
  $suffix = str_pad((string)$intent_id, 6, '0', STR_PAD_LEFT);
  return $prefix . $suffix; // 12 chars
}

if (!function_exists('casanova_tpv_notify_url')) {
  function casanova_tpv_notify_url(): string {
    $url = add_query_arg(['casanova_tpv_notify' => '1'], home_url('/'));
    return (string) apply_filters('casanova_tpv_notify_url', $url);
  }
}

if (!function_exists('casanova_tpv_return_url')) {
  function casanova_tpv_return_url(bool $ok, string $token = ''): string {
    $args = [
      'casanova_tpv_return' => '1',
      'result' => $ok ? 'ok' : 'ko',
    ];

    if ($token !== '') {
      $args['token'] = $token;
    }

    $url = add_query_arg($args, home_url('/'));
    return (string) apply_filters('casanova_tpv_return_url', $url, $ok, $token);
  }
}

function casanova_redsys_is_base64(string $s): bool {
  $d = base64_decode($s, true);
  return $d !== false && base64_encode($d) === preg_replace('/\s+/', '', $s);
}

function casanova_redsys_secret_key_raw(string $secret): string {
  // Redsys a veces te lo da ya en base64, a veces “parece base64”.
  // Si valida como base64, lo decodificamos. Si no, lo usamos tal cual.
  return casanova_redsys_is_base64($secret) ? base64_decode($secret, true) : $secret;
}

function casanova_redsys_encrypt_3des(string $order, string $key_raw): string {
  $iv = str_repeat("\0", 8);
  $order_padded = str_pad($order, 16, "\0"); // importante
  $cipher = openssl_encrypt(
    $order_padded,
    'DES-EDE3-CBC',
    $key_raw,
    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    $iv
  );
  return $cipher === false ? '' : $cipher;
}

function casanova_redsys_signature(string $merchantParamsB64, string $order, string $secret): string {
  $key_raw = casanova_redsys_secret_key_raw($secret);
  $key = casanova_redsys_encrypt_3des($order, $key_raw);
  if ($key === '') return '';
  $mac = hash_hmac('sha256', $merchantParamsB64, $key, true);
  return base64_encode($mac);
}

function casanova_redsys_encode_params(array $params): string {
  $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return base64_encode($json ?: '{}');
}

function casanova_redsys_decode_params(string $b64): array {
  $json = base64_decode($b64, true);
  if ($json === false) return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}
function casanova_redsys_verify_signature(string $merchantParamsB64, string $order, string $secret, string $sig_b64): bool {
  $expected = casanova_redsys_signature($merchantParamsB64, $order, $secret);
  return $expected !== '' && hash_equals($expected, $sig_b64);
}
