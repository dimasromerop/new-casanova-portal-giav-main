<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_stripe_config')) {
  function casanova_stripe_config(): array {
    $secret_key = defined('CASANOVA_STRIPE_SECRET_KEY')
      ? (string) CASANOVA_STRIPE_SECRET_KEY
      : (string) get_option('casanova_stripe_secret_key', '');
    $webhook_secret = defined('CASANOVA_STRIPE_WEBHOOK_SECRET')
      ? (string) CASANOVA_STRIPE_WEBHOOK_SECRET
      : (string) get_option('casanova_stripe_webhook_secret', '');
    $id_forma_pago = defined('CASANOVA_GIAV_IDFORMAPAGO_STRIPE')
      ? (int) CASANOVA_GIAV_IDFORMAPAGO_STRIPE
      : (int) get_option('casanova_giav_idformapago_stripe', 0);

    return [
      'secret_key' => trim($secret_key),
      'webhook_secret' => trim($webhook_secret),
      'giav_method_id' => max(0, $id_forma_pago),
      'fee_percent' => (float) get_option('casanova_stripe_fee_percent', 1.5),
      'fx_fee_percent' => (float) get_option('casanova_stripe_fx_fee_percent', 0.4),
      'margin_percent' => (float) get_option('casanova_stripe_margin_percent', 1.0),
      'fallback_rate' => (float) get_option('casanova_stripe_eur_usd_fallback_rate', 0),
    ];
  }
}

if (!function_exists('casanova_stripe_is_available')) {
  function casanova_stripe_is_available(): bool {
    $cfg = casanova_stripe_config();
    return $cfg['secret_key'] !== '' && (int) $cfg['giav_method_id'] > 0;
  }
}

if (!function_exists('casanova_stripe_giav_method_id')) {
  function casanova_stripe_giav_method_id(): int {
    $cfg = casanova_stripe_config();
    return (int) apply_filters('casanova_stripe_giav_method_id', (int) $cfg['giav_method_id'], $cfg);
  }
}

if (!function_exists('casanova_stripe_public_webhook_url')) {
  function casanova_stripe_public_webhook_url(): string {
    return home_url('/wp-json/casanova/v1/stripe/notify');
  }
}

if (!function_exists('casanova_stripe_sanitize_percent')) {
  function casanova_stripe_sanitize_percent($value): float {
    $value = (float) str_replace(',', '.', (string) $value);
    if ($value < 0) $value = 0;
    if ($value > 50) $value = 50;
    return round($value, 4);
  }
}

if (!function_exists('casanova_stripe_sanitize_rate')) {
  function casanova_stripe_sanitize_rate($value): float {
    $value = (float) str_replace(',', '.', (string) $value);
    if ($value < 0) $value = 0;
    if ($value > 5) $value = 5;
    return round($value, 6);
  }
}

if (!function_exists('casanova_stripe_fetch_ecb_eur_usd_rate')) {
  function casanova_stripe_fetch_ecb_eur_usd_rate() {
    $url = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    $res = wp_remote_get($url, [
      'timeout' => 10,
      'redirection' => 3,
      'headers' => [
        'Accept' => 'application/xml,text/xml,*/*',
      ],
    ]);

    if (is_wp_error($res)) {
      return $res;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code < 200 || $code >= 300 || $body === '') {
      return new WP_Error('stripe_fx_rate_http_error', 'No se pudo consultar EUR/USD.');
    }

    if (!preg_match('/currency=[\'"]USD[\'"]\s+rate=[\'"]([0-9.]+)[\'"]/i', $body, $m)
      && !preg_match('/rate=[\'"]([0-9.]+)[\'"]\s+currency=[\'"]USD[\'"]/i', $body, $m)) {
      return new WP_Error('stripe_fx_rate_missing_usd', 'La respuesta EUR/USD no contiene USD.');
    }

    $rate = (float) $m[1];
    if ($rate <= 0) {
      return new WP_Error('stripe_fx_rate_invalid', 'La cotizacion EUR/USD no es valida.');
    }

    return [
      'rate' => round($rate, 6),
      'source' => 'ecb',
      'fetched_at' => current_time('mysql'),
    ];
  }
}

if (!function_exists('casanova_stripe_eur_usd_rate')) {
  function casanova_stripe_eur_usd_rate(bool $force_refresh = false): array {
    $cache_key = 'casanova_stripe_eur_usd_rate';
    if (!$force_refresh) {
      $cached = get_transient($cache_key);
      if (is_array($cached) && (float) ($cached['rate'] ?? 0) > 0) {
        return $cached;
      }
    }

    $fresh = casanova_stripe_fetch_ecb_eur_usd_rate();
    if (!is_wp_error($fresh) && is_array($fresh) && (float) ($fresh['rate'] ?? 0) > 0) {
      set_transient($cache_key, $fresh, 6 * HOUR_IN_SECONDS);
      update_option('casanova_stripe_last_eur_usd_rate', (float) $fresh['rate'], false);
      update_option('casanova_stripe_last_eur_usd_rate_at', (string) $fresh['fetched_at'], false);
      return $fresh;
    }

    $last_rate = (float) get_option('casanova_stripe_last_eur_usd_rate', 0);
    if ($last_rate > 0) {
      return [
        'rate' => round($last_rate, 6),
        'source' => 'last_cached',
        'fetched_at' => (string) get_option('casanova_stripe_last_eur_usd_rate_at', ''),
      ];
    }

    $cfg = casanova_stripe_config();
    $fallback = (float) $cfg['fallback_rate'];
    if ($fallback > 0) {
      return [
        'rate' => round($fallback, 6),
        'source' => 'fallback',
        'fetched_at' => '',
      ];
    }

    return [
      'rate' => 0.0,
      'source' => 'unavailable',
      'fetched_at' => '',
      'error' => is_wp_error($fresh) ? $fresh->get_error_message() : 'No se pudo resolver EUR/USD.',
    ];
  }
}

if (!function_exists('casanova_stripe_usd_quote')) {
  function casanova_stripe_usd_quote(float $eur_amount) {
    $eur_amount = round(max(0.0, $eur_amount), 2);
    if ($eur_amount <= 0) {
      return new WP_Error('stripe_quote_invalid_amount', __('Importe invalido.', 'casanova-portal'));
    }

    $cfg = casanova_stripe_config();
    $rate_info = casanova_stripe_eur_usd_rate();
    $rate = (float) ($rate_info['rate'] ?? 0);
    if ($rate <= 0) {
      return new WP_Error('stripe_quote_rate_unavailable', __('No se pudo calcular la cotizacion EUR/USD.', 'casanova-portal'));
    }

    $stripe_fee = max(0.0, (float) $cfg['fee_percent']);
    $fx_fee = max(0.0, (float) $cfg['fx_fee_percent']);
    $margin = max(0.0, (float) $cfg['margin_percent']);
    $gross_up = ($stripe_fee + $fx_fee + $margin) / 100.0;
    if ($gross_up >= 0.50) {
      return new WP_Error('stripe_quote_invalid_fee', __('Configuracion de comisiones Stripe invalida.', 'casanova-portal'));
    }

    $usd_raw = ($eur_amount * $rate) / (1.0 - $gross_up);
    $usd_cents = (int) ceil($usd_raw * 100);
    $usd_amount = round($usd_cents / 100, 2);

    return [
      'eur_amount' => $eur_amount,
      'currency' => 'USD',
      'usd_amount' => $usd_amount,
      'usd_cents' => $usd_cents,
      'eur_usd_rate' => $rate,
      'rate_source' => (string) ($rate_info['source'] ?? ''),
      'rate_fetched_at' => (string) ($rate_info['fetched_at'] ?? ''),
      'stripe_fee_percent' => $stripe_fee,
      'fx_fee_percent' => $fx_fee,
      'margin_percent' => $margin,
      'gross_up_percent' => round($gross_up * 100, 4),
      'calculated_at' => current_time('mysql'),
    ];
  }
}

if (!function_exists('casanova_stripe_format_usd')) {
  function casanova_stripe_format_usd(float $amount): string {
    return '$' . number_format($amount, 2, '.', ',') . ' USD';
  }
}

if (!function_exists('casanova_stripe_flatten_params')) {
  function casanova_stripe_flatten_params(array $params, string $prefix = ''): array {
    $out = [];
    foreach ($params as $key => $value) {
      if ($value === null) continue;
      $full_key = $prefix === '' ? (string) $key : $prefix . '[' . (string) $key . ']';
      if (is_array($value)) {
        $out = array_merge($out, casanova_stripe_flatten_params($value, $full_key));
        continue;
      }
      if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
      }
      $out[$full_key] = (string) $value;
    }
    return $out;
  }
}

if (!function_exists('casanova_stripe_api_request')) {
  function casanova_stripe_api_request(string $method, string $path, array $params = []) {
    $cfg = casanova_stripe_config();
    if ($cfg['secret_key'] === '') {
      return new WP_Error('stripe_secret_missing', __('Stripe no esta configurado.', 'casanova-portal'));
    }

    $args = [
      'method' => strtoupper($method),
      'timeout' => 45,
      'headers' => [
        'Authorization' => 'Bearer ' . $cfg['secret_key'],
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
    ];

    if (!empty($params)) {
      $args['body'] = casanova_stripe_flatten_params($params);
    }

    $url = 'https://api.stripe.com/v1' . $path;
    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
      return $res;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if (!is_array($data)) {
      $data = [];
    }

    if ($code < 200 || $code >= 300) {
      $message = (string) ($data['error']['message'] ?? 'Stripe API error.');
      return new WP_Error('stripe_api_error', $message, [
        'status' => $code,
        'response' => $data,
      ]);
    }

    return $data;
  }
}

if (!function_exists('casanova_stripe_return_url')) {
  function casanova_stripe_return_url(bool $ok, $intent, string $session_id = ''): string {
    $args = [
      'casanova_stripe_return' => '1',
      'result' => $ok ? 'success' : 'failed',
      'intent_id' => is_object($intent) ? (int) $intent->id : 0,
      'token' => is_object($intent) ? (string) ($intent->token ?? '') : '',
    ];
    if ($ok) {
      $args['session_id'] = $session_id !== '' ? $session_id : '{CHECKOUT_SESSION_ID}';
    }

    $url = add_query_arg($args, home_url('/'));
    return str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $url);
  }
}

if (!function_exists('casanova_stripe_create_checkout_session')) {
  function casanova_stripe_create_checkout_session($intent, array $context) {
    if (!is_object($intent) || empty($intent->id) || empty($intent->token)) {
      return new WP_Error('stripe_invalid_intent', __('Intent invalido.', 'casanova-portal'));
    }

    $quote = is_array($context['quote'] ?? null) ? $context['quote'] : [];
    $usd_cents = (int) ($quote['usd_cents'] ?? 0);
    if ($usd_cents <= 0) {
      return new WP_Error('stripe_invalid_usd_amount', __('Importe USD invalido.', 'casanova-portal'));
    }

    $payment_link = is_array($context['payment_link'] ?? null) ? $context['payment_link'] : [];
    $link_token = trim((string) ($payment_link['token'] ?? ''));
    $payment_page_url = ($link_token !== '' && function_exists('casanova_payment_link_url'))
      ? casanova_payment_link_url($link_token)
      : home_url('/');

    $success_url = casanova_stripe_return_url(true, $intent);
    $cancel_url = add_query_arg([
      'payment' => 'failed',
      'intent_id' => (int) $intent->id,
    ], $payment_page_url);

    $locale = strtolower(substr((string) ($context['locale'] ?? ''), 0, 2));
    if (!in_array($locale, ['es', 'en', 'fr', 'de', 'it', 'pt'], true)) {
      $locale = 'auto';
    }

    $metadata = [
      'intent_id' => (string) (int) $intent->id,
      'intent_token' => (string) $intent->token,
      'id_expediente' => (string) (int) ($intent->id_expediente ?? 0),
      'payment_link_id' => (string) (int) ($payment_link['id'] ?? 0),
      'payment_link_token' => $link_token,
      'eur_amount' => number_format((float) ($quote['eur_amount'] ?? $intent->amount ?? 0), 2, '.', ''),
      'usd_amount' => number_format(((float) $usd_cents) / 100, 2, '.', ''),
      'eur_usd_rate' => (string) ($quote['eur_usd_rate'] ?? ''),
    ];

    $exp_id = (int) ($intent->id_expediente ?? 0);
    $mode = strtolower(trim((string) ($context['mode'] ?? '')));
    $label = $mode === 'deposit' ? 'Deposito' : 'Pago';
    $description = $label . ' Casanova Golf (' . $exp_id . ')';

    $params = [
      'mode' => 'payment',
      'success_url' => $success_url,
      'cancel_url' => $cancel_url,
      'payment_method_types' => ['card'],
      'submit_type' => 'pay',
      'locale' => $locale,
      'client_reference_id' => (string) (int) $intent->id,
      'customer_email' => !empty($context['billing_email']) ? (string) $context['billing_email'] : null,
      'line_items' => [
        [
          'quantity' => 1,
          'price_data' => [
            'currency' => 'usd',
            'unit_amount' => $usd_cents,
            'product_data' => [
              'name' => $description,
              'description' => 'Importe base EUR: ' . number_format((float) ($quote['eur_amount'] ?? 0), 2, '.', '') . ' EUR',
            ],
          ],
        ],
      ],
      'metadata' => $metadata,
      'payment_intent_data' => [
        'metadata' => $metadata,
      ],
    ];

    $session = casanova_stripe_api_request('POST', '/checkout/sessions', $params);
    if (is_wp_error($session)) {
      return $session;
    }

    $session_id = (string) ($session['id'] ?? '');
    $checkout_url = (string) ($session['url'] ?? '');
    if ($session_id === '' || $checkout_url === '') {
      return new WP_Error('stripe_checkout_missing_url', __('Stripe no devolvio un enlace de pago.', 'casanova-portal'), $session);
    }

    if (function_exists('casanova_payment_intent_update')) {
      casanova_payment_intent_update((int) $intent->id, [
        'provider_payment_id' => $session_id,
        'provider_reference' => !empty($session['payment_intent']) ? (string) $session['payment_intent'] : (string) ($intent->provider_reference ?? ''),
        'status' => 'initiated',
        'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
          'stripe_checkout' => [
            'ok' => true,
            'session_id' => $session_id,
            'url' => $checkout_url,
            'payment_status' => (string) ($session['payment_status'] ?? ''),
            'created_at' => current_time('mysql'),
          ],
        ]),
      ]);
    }

    return $session;
  }
}

if (!function_exists('casanova_stripe_retrieve_checkout_session')) {
  function casanova_stripe_retrieve_checkout_session(string $session_id) {
    $session_id = trim($session_id);
    if ($session_id === '') {
      return new WP_Error('stripe_session_missing', __('Sesion Stripe invalida.', 'casanova-portal'));
    }
    return casanova_stripe_api_request('GET', '/checkout/sessions/' . rawurlencode($session_id), []);
  }
}

if (!function_exists('casanova_stripe_redirect_url_for_intent')) {
  function casanova_stripe_redirect_url_for_intent($intent, bool $ok): string {
    $payload = [];
    if (is_object($intent)) {
      $raw = (string) ($intent->payload ?? '');
      if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $payload = $decoded;
      }
    }

    $plink = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
    $plink_token = trim((string) ($plink['token'] ?? ''));
    if ($plink_token !== '' && function_exists('casanova_payment_link_url')) {
      $url = add_query_arg([
        'payment' => $ok ? 'success' : 'failed',
        'intent_id' => is_object($intent) ? (int) $intent->id : 0,
      ], casanova_payment_link_url($plink_token));

      if (function_exists('casanova_portal_add_public_locale_arg')) {
        $url = casanova_portal_add_public_locale_arg($url, (string) ($payload['locale'] ?? ''));
      }

      return $url;
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');
    return add_query_arg([
      'view' => 'trip',
      'tab' => 'payments',
      'expediente' => is_object($intent) ? (int) $intent->id_expediente : 0,
      'pay_status' => $ok ? 'checking' : 'ko',
      'payment' => $ok ? 'success' : 'failed',
      'method' => 'stripe',
      'intent_id' => is_object($intent) ? (int) $intent->id : 0,
    ], $base);
  }
}

if (!function_exists('casanova_stripe_try_giav_cobro')) {
  function casanova_stripe_try_giav_cobro($intent, array $session): array {
    $result = [
      'giav_cobro' => null,
      'already' => false,
      'inserted' => false,
      'should_notify' => false,
    ];

    if (!is_object($intent)) return $result;

    $payload = [];
    $raw_payload = (string) ($intent->payload ?? '');
    if ($raw_payload !== '') {
      $decoded = json_decode($raw_payload, true);
      if (is_array($decoded)) $payload = $decoded;
    }

    if (!empty($payload['giav_cobro']['cobro_id']) || !empty($payload['giav_cobro']['inserted_at'])) {
      $result['giav_cobro'] = $payload['giav_cobro'];
      $result['already'] = true;
      return $result;
    }

    $id_forma_pago = casanova_stripe_giav_method_id();
    if ($id_forma_pago <= 0) {
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => 'missing_idformapago_stripe',
      ];
      return $result;
    }

    $id_oficina = 0;
    if (defined('CASANOVA_GIAV_IDOFICINA')) {
      $id_oficina = (int) CASANOVA_GIAV_IDOFICINA;
    }
    $id_oficina = (int) apply_filters('casanova_giav_idoficina_for_cobro', $id_oficina, $intent, $session);

    $payment_link = is_array($payload['payment_link'] ?? null) ? $payload['payment_link'] : [];
    $payment_link_id = (int) ($payment_link['id'] ?? 0);
    $payment_link_scope = (string) ($payment_link['scope'] ?? '');
    $plink_meta = [];
    if ($payment_link_id > 0 && function_exists('casanova_payment_link_get')) {
      $plink = casanova_payment_link_get($payment_link_id);
      if ($plink && !empty($plink->metadata)) {
        $decoded = json_decode((string) $plink->metadata, true);
        if (is_array($decoded)) $plink_meta = $decoded;
      }
    }

    $billing_dni = (string) ($payload['billing_dni'] ?? ($plink_meta['billing_dni'] ?? ''));
    $billing_name = trim((string) ($payload['billing_name'] ?? ($plink_meta['billing_name'] ?? '')));
    $billing_lastname = trim((string) ($payload['billing_lastname'] ?? ($plink_meta['billing_lastname'] ?? '')));
    $billing_fullname = trim((string) ($payload['billing_fullname'] ?? ($plink_meta['billing_fullname'] ?? '')));
    if ($billing_fullname === '') {
      $billing_fullname = trim($billing_name . ' ' . $billing_lastname);
    }
    $billing_email = trim((string) ($payload['billing_email'] ?? ($plink_meta['billing_email'] ?? '')));

    $mode = strtolower(trim((string) ($payload['mode'] ?? ($plink_meta['mode'] ?? ''))));
    $session_id = (string) ($session['id'] ?? '');
    $payment_intent = (string) ($session['payment_intent'] ?? '');
    $usd_amount = ((float) ((int) ($session['amount_total'] ?? 0))) / 100;
    $stripe_quote = is_array($payload['stripe_quote'] ?? null) ? $payload['stripe_quote'] : [];
    $rate = (string) ($stripe_quote['eur_usd_rate'] ?? '');

    $concepto = $mode === 'deposit'
      ? ('Deposito Stripe ' . $session_id)
      : ('Pago Stripe ' . $session_id);

    $notas_internas = 'Stripe USD ' . number_format($usd_amount, 2, '.', '')
      . ' | EUR base ' . number_format((float) ($intent->amount ?? 0), 2, '.', '')
      . ($rate !== '' ? (' | EUR/USD ' . $rate) : '')
      . ' | session=' . $session_id
      . ($payment_intent !== '' ? (' | payment_intent=' . $payment_intent) : '');

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
        'documento' => $payment_intent !== '' ? $payment_intent : $session_id,
        'payer_name' => $billing_fullname !== '' ? $billing_fullname : 'Portal',
        'notas_internas' => $notas_internas,
      ], 'STRIPE');
    }

    return $result;
  }
}

if (!function_exists('casanova_stripe_process_checkout_session')) {
  function casanova_stripe_process_checkout_session(array $session, string $source = 'return'): array {
    $session_id = (string) ($session['id'] ?? '');
    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $intent_id = (int) ($metadata['intent_id'] ?? ($session['client_reference_id'] ?? 0));
    $payment_status = strtolower(trim((string) ($session['payment_status'] ?? '')));
    $status = strtolower(trim((string) ($session['status'] ?? '')));
    $currency = strtolower(trim((string) ($session['currency'] ?? '')));
    $amount_total = (int) ($session['amount_total'] ?? 0);

    $intent = null;
    if ($intent_id > 0 && function_exists('casanova_payment_intent_get')) {
      $intent = casanova_payment_intent_get($intent_id);
    }
    if (!$intent && $session_id !== '' && function_exists('casanova_payment_intent_get_by_provider_payment_id')) {
      $intent = casanova_payment_intent_get_by_provider_payment_id($session_id);
    }
    if (!$intent || !is_object($intent)) {
      return ['ok' => false, 'error' => 'intent_not_found'];
    }

    $payload = [];
    $raw_payload = (string) ($intent->payload ?? '');
    if ($raw_payload !== '') {
      $decoded = json_decode($raw_payload, true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $quote = is_array($payload['stripe_quote'] ?? null) ? $payload['stripe_quote'] : [];
    $expected_cents = (int) ($quote['usd_cents'] ?? 0);
    $paid = ($payment_status === 'paid') && ($status === '' || $status === 'complete') && $currency === 'usd';
    if ($paid && $expected_cents > 0 && $amount_total !== $expected_cents) {
      $paid = false;
    }

    $giav_result = [];
    if ($paid) {
      $giav_result = casanova_stripe_try_giav_cobro($intent, $session);
    }

    $merge = [
      'stripe_' . $source => [
        'session_id' => $session_id,
        'payment_intent' => (string) ($session['payment_intent'] ?? ''),
        'payment_status' => $payment_status,
        'status' => $status,
        'currency' => $currency,
        'amount_total' => $amount_total,
        'expected_amount_total' => $expected_cents,
        'processed_at' => current_time('mysql'),
      ],
    ];
    if (!empty($giav_result['giav_cobro'])) {
      $merge['giav_cobro'] = $giav_result['giav_cobro'];
    }

    casanova_payment_intent_update((int) $intent->id, [
      'provider' => 'stripe',
      'method' => 'card',
      'provider_payment_id' => $session_id ?: (string) ($intent->provider_payment_id ?? ''),
      'provider_reference' => (string) ($session['payment_intent'] ?? ($intent->provider_reference ?? '')),
      'status' => $paid ? ($source === 'webhook' ? 'notified_ok' : 'returned_ok') : ($source === 'webhook' ? 'notified_ko' : 'returned_ko'),
      'payload' => casanova_intent_payload_merge($intent->payload ?? null, $merge),
      'last_check_at' => current_time('mysql'),
    ]);

    if (!empty($giav_result['should_notify'])) {
      do_action('casanova_payment_cobro_recorded', (int) $intent->id);
    }
    if (!empty($giav_result['inserted']) && function_exists('casanova_invalidate_customer_cache')) {
      casanova_invalidate_customer_cache((int) $intent->user_id, (int) $intent->id_cliente, (int) $intent->id_expediente);
    }
    if ($paid && !wp_next_scheduled('casanova_job_reconcile_payment', [(int) $intent->id])) {
      wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int) $intent->id]);
    }

    $updated_intent = function_exists('casanova_payment_intent_get') ? casanova_payment_intent_get((int) $intent->id) : $intent;
    return [
      'ok' => $paid,
      'intent' => $updated_intent ?: $intent,
      'giav_result' => $giav_result,
      'redirect_url' => casanova_stripe_redirect_url_for_intent($updated_intent ?: $intent, $paid),
    ];
  }
}

if (!function_exists('casanova_stripe_parse_signature_header')) {
  function casanova_stripe_parse_signature_header(string $header): array {
    $out = ['t' => '', 'v1' => []];
    foreach (explode(',', $header) as $part) {
      $pair = explode('=', trim($part), 2);
      if (count($pair) !== 2) continue;
      if ($pair[0] === 't') {
        $out['t'] = $pair[1];
      } elseif ($pair[0] === 'v1') {
        $out['v1'][] = $pair[1];
      }
    }
    return $out;
  }
}

if (!function_exists('casanova_stripe_verify_webhook_signature')) {
  function casanova_stripe_verify_webhook_signature(string $payload, string $signature_header, string $secret, int $tolerance = 300): bool {
    if ($payload === '' || $signature_header === '' || $secret === '') return false;

    $sig = casanova_stripe_parse_signature_header($signature_header);
    $timestamp = (int) ($sig['t'] ?? 0);
    $signatures = is_array($sig['v1'] ?? null) ? $sig['v1'] : [];
    if ($timestamp <= 0 || empty($signatures)) return false;

    if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
      return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $candidate) {
      if (hash_equals($expected, (string) $candidate)) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('casanova_handle_stripe_return')) {
  function casanova_handle_stripe_return(): void {
    $result = isset($_GET['result']) ? sanitize_key((string) $_GET['result']) : 'failed';
    $intent_id = isset($_GET['intent_id']) ? absint($_GET['intent_id']) : 0;
    $session_id = isset($_GET['session_id']) ? sanitize_text_field((string) $_GET['session_id']) : '';

    $intent = ($intent_id > 0 && function_exists('casanova_payment_intent_get'))
      ? casanova_payment_intent_get($intent_id)
      : null;

    if ($result !== 'success') {
      if ($intent && function_exists('casanova_payment_intent_update')) {
        casanova_payment_intent_update((int) $intent->id, [
          'status' => 'returned_ko',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'stripe_return' => [
              'status' => 'cancelled',
              'processed_at' => current_time('mysql'),
            ],
          ]),
        ]);
      }
      wp_safe_redirect(casanova_stripe_redirect_url_for_intent($intent, false));
      exit;
    }

    $session = casanova_stripe_retrieve_checkout_session($session_id);
    if (is_wp_error($session)) {
      if ($intent && function_exists('casanova_payment_intent_update')) {
        casanova_payment_intent_update((int) $intent->id, [
          'status' => 'returned_ko',
          'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
            'stripe_return' => [
              'status' => 'session_retrieve_failed',
              'error' => $session->get_error_message(),
              'processed_at' => current_time('mysql'),
            ],
          ]),
        ]);
      }
      wp_safe_redirect(casanova_stripe_redirect_url_for_intent($intent, false));
      exit;
    }

    $processed = casanova_stripe_process_checkout_session($session, 'return');
    wp_safe_redirect((string) ($processed['redirect_url'] ?? casanova_stripe_redirect_url_for_intent($intent, false)));
    exit;
  }
}

add_action('template_redirect', function () {
  if (empty($_GET['casanova_stripe_return'])) return;
  casanova_handle_stripe_return();
  exit;
});

if (!function_exists('casanova_handle_stripe_notify')) {
  function casanova_handle_stripe_notify(WP_REST_Request $request): WP_REST_Response {
    $payload = $request->get_body();
    $sig_header = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? $request->get_header('stripe-signature'));
    $cfg = casanova_stripe_config();

    if ($cfg['webhook_secret'] === '') {
      return new WP_REST_Response(['ok' => false, 'error' => 'webhook_secret_missing'], 500);
    }

    if (!casanova_stripe_verify_webhook_signature($payload, $sig_header, (string) $cfg['webhook_secret'])) {
      return new WP_REST_Response(['ok' => false, 'error' => 'invalid_signature'], 400);
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }

    $type = (string) ($event['type'] ?? '');
    $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
    if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
      casanova_stripe_process_checkout_session($object, 'webhook');
    }

    return new WP_REST_Response(['ok' => true], 200);
  }
}

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/stripe/notify', [
    'methods' => 'POST',
    'callback' => 'casanova_handle_stripe_notify',
    'permission_callback' => '__return_true',
  ]);
});
