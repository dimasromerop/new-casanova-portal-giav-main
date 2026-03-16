<?php
if (!defined('ABSPATH')) exit;

if (!defined('CASANOVA_PERF_METRICS_ENABLED')) {
  define('CASANOVA_PERF_METRICS_ENABLED', true);
}

if (!defined('CASANOVA_PERF_LOG_ALL')) {
  define('CASANOVA_PERF_LOG_ALL', (defined('WP_DEBUG') && WP_DEBUG));
}

if (!defined('CASANOVA_PERF_SLOW_MS')) {
  define('CASANOVA_PERF_SLOW_MS', 700);
}

if (!defined('CASANOVA_PERF_HEADERS_ENABLED')) {
  define('CASANOVA_PERF_HEADERS_ENABLED', (defined('WP_DEBUG') && WP_DEBUG));
}

function casanova_perf_now(): float {
  return microtime(true);
}

/**
 * Compat wrapper: some WP versions expose request attributes only via
 * get_attributes()/set_attributes().
 *
 * @param mixed $value
 */
function casanova_perf_request_set(WP_REST_Request $request, string $key, $value): void {
  if (method_exists($request, 'set_attribute')) {
    $request->set_attribute($key, $value);
    return;
  }

  $attributes = method_exists($request, 'get_attributes')
    ? (array) $request->get_attributes()
    : [];
  $attributes[$key] = $value;

  if (method_exists($request, 'set_attributes')) {
    $request->set_attributes($attributes);
  }
}

/**
 * @return mixed|null
 */
function casanova_perf_request_get(WP_REST_Request $request, string $key) {
  if (method_exists($request, 'get_attribute')) {
    return $request->get_attribute($key);
  }

  $attributes = method_exists($request, 'get_attributes')
    ? (array) $request->get_attributes()
    : [];

  return $attributes[$key] ?? null;
}

/**
 * @return array<string,mixed>
 */
function casanova_perf_context(array $context): array {
  $out = [];
  foreach ($context as $key => $value) {
    if ($value === null) continue;
    if (is_string($value) && trim($value) === '') continue;
    if (is_array($value) && empty($value)) continue;
    $out[(string) $key] = $value;
  }
  return $out;
}

/**
 * @return array<string,mixed>
 */
function casanova_perf_response_summary($response): array {
  if ($response instanceof WP_Error) {
    $error_data = $response->get_error_data();
    return [
      'status' => 'wp_error',
      'http_status' => is_array($error_data) ? (int) ($error_data['status'] ?? 500) : 500,
      'error_code' => (string) $response->get_error_code(),
    ];
  }

  if ($response instanceof WP_REST_Response) {
    $data = $response->get_data();
    $summary = [
      'status' => '',
      'http_status' => (int) $response->get_status(),
    ];

    if (is_array($data) && isset($data['status'])) {
      $summary['status'] = (string) $data['status'];
    } elseif (is_array($data) && array_key_exists('ok', $data)) {
      $summary['status'] = !empty($data['ok']) ? 'ok' : 'error';
    } else {
      $summary['status'] = ($summary['http_status'] >= 400) ? 'error' : 'ok';
    }

    return $summary;
  }

  if (is_array($response)) {
    if (isset($response['status'])) {
      return ['status' => (string) $response['status']];
    }
    if (array_key_exists('ok', $response)) {
      return ['status' => !empty($response['ok']) ? 'ok' : 'error'];
    }
  }

  return ['status' => 'unknown'];
}

/**
 * @return array<string,mixed>
 */
function casanova_perf_measure(string $metric, float $started_at, array $context = [], $response = null, ?Throwable $error = null): array {
  if (!CASANOVA_PERF_METRICS_ENABLED) {
    return [];
  }

  $duration_ms = round(max(0, (microtime(true) - $started_at) * 1000), 2);
  $summary = casanova_perf_response_summary($response);
  $payload = casanova_perf_context(array_merge([
    'metric' => $metric,
    'duration_ms' => $duration_ms,
  ], $summary, $context));

  $payload['slow_threshold_ms'] = (int) CASANOVA_PERF_SLOW_MS;
  $payload['slow'] = $duration_ms >= (float) CASANOVA_PERF_SLOW_MS;

  if ($error instanceof Throwable) {
    $payload['error_class'] = get_class($error);
    $payload['error_message'] = $error->getMessage();
  }

  $status = (string) ($payload['status'] ?? '');
  $http_status = (int) ($payload['http_status'] ?? 200);
  $is_error = ($error instanceof Throwable)
    || ($response instanceof WP_Error)
    || in_array($status, ['degraded', 'error', 'invalid', 'wp_error'], true)
    || ($http_status >= 400);

  $should_log = $is_error || !empty($payload['slow']) || CASANOVA_PERF_LOG_ALL;
  $level = ($is_error || !empty($payload['slow'])) ? 'warn' : 'info';

  do_action('casanova_perf_metric', $metric, $payload, $response, $error);

  if ($should_log && function_exists('casanova_log')) {
    casanova_log('perf', $metric, $payload, $level);
  }

  return $payload;
}

/**
 * @return array<string,mixed>
 */
function casanova_perf_observe_rest(string $metric, float $started_at, $response = null, array $context = [], ?Throwable $error = null): array {
  $payload = casanova_perf_measure($metric, $started_at, $context, $response, $error);
  if (empty($payload) || !CASANOVA_PERF_HEADERS_ENABLED || !($response instanceof WP_REST_Response)) {
    return $payload;
  }

  $dur = number_format((float) ($payload['duration_ms'] ?? 0), 2, '.', '');
  $headers = $response->get_headers();
  $server_timing = 'casanova;dur=' . $dur;
  if (!empty($headers['Server-Timing'])) {
    $server_timing = (string) $headers['Server-Timing'] . ', ' . $server_timing;
  }

  $response->header('Server-Timing', $server_timing);
  $response->header('X-Casanova-Perf', $metric . ';dur=' . $dur);

  return $payload;
}

add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {
  if (!CASANOVA_PERF_METRICS_ENABLED || !($request instanceof WP_REST_Request)) {
    return $response;
  }

  if ($request->get_route() === '/casanova/v1/profile' && strtoupper((string) $request->get_method()) === 'GET') {
    casanova_perf_request_set($request, '_casanova_perf_profile_start', microtime(true));
  }

  return $response;
}, 10, 3);

add_filter('rest_post_dispatch', function ($response, $server, $request) {
  if (!CASANOVA_PERF_METRICS_ENABLED || !($request instanceof WP_REST_Request)) {
    return $response;
  }

  if ($request->get_route() !== '/casanova/v1/profile' || strtoupper((string) $request->get_method()) !== 'GET') {
    return $response;
  }

  $started_at = (float) casanova_perf_request_get($request, '_casanova_perf_profile_start');
  if ($started_at <= 0) {
    return $response;
  }

  $user_id = function_exists('casanova_portal_get_effective_user_id')
    ? (int) casanova_portal_get_effective_user_id()
    : (int) get_current_user_id();
  $idCliente = function_exists('casanova_portal_get_effective_client_id')
    ? (int) casanova_portal_get_effective_client_id($user_id)
    : (int) get_user_meta($user_id, 'casanova_idcliente', true);

  casanova_perf_observe_rest('profile', $started_at, $response, [
    'user_id' => $user_id,
    'idCliente' => $idCliente,
    'auto' => 1,
  ]);

  return $response;
}, 25, 3);
