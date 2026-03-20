<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Trip/Expediente
 * GET /wp-json/casanova/v1/trip/{id}
 */
class Casanova_Trip_Controller {

  private static function degraded_response(string $message = ''): WP_REST_Response {
    return new WP_REST_Response([
      'status' => 'degraded',
      'giav'   => ['ok' => false, 'source' => 'live', 'error' => $message],
      'trip'   => null,
      'package' => null,
      'extras' => [],
      'passengers' => [],
      'payments' => null,
      'invoices' => [],
      'vouchers' => [],
      'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
    ], 503);
  }

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/trip', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'id'   => ['type' => 'integer', 'required' => true],
        'mock' => ['type' => 'integer', 'required' => false],
        'refresh' => ['type' => 'integer', 'required' => false],
      ],
    ]);
    register_rest_route('casanova/v1', '/trip/(?P<id>\\d+)', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'id'   => ['type' => 'integer', 'required' => true],
        'mock' => ['type' => 'integer', 'required' => false],
        'refresh' => ['type' => 'integer', 'required' => false],
      ],
    ]);
  }

  public static function permissions_check(WP_REST_Request $request): bool|WP_Error {
    if (!is_user_logged_in()) {
      return new WP_Error('rest_forbidden', __('No autorizado', 'casanova-portal'), ['status' => 401]);
    }
    return true;
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $perf_start = function_exists('casanova_perf_now') ? casanova_perf_now() : microtime(true);
    $perf_error = null;
    $response = null;
    $user_id = function_exists('casanova_portal_get_effective_user_id')
      ? casanova_portal_get_effective_user_id()
      : get_current_user_id();
    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);
    $id = (int) $request->get_param('id');
    $perf_context = [
      'user_id' => (int) $user_id,
      'idCliente' => (int) $idCliente,
      'expediente_id' => (int) $id,
      'mock' => (int) $request->get_param('mock') === 1 ? 1 : 0,
      'refresh' => (int) $request->get_param('refresh') === 1 ? 1 : 0,
    ];

    try {
      if ($id <= 0) {
        $response = new WP_REST_Response([
          'status' => 'invalid',
          'message' => __('Expediente invÃ¡lido', 'casanova-portal'),
        ], 400);
        return $response;
      }

      $refresh = (int) $request->get_param('refresh') === 1;
      if ($refresh && function_exists('casanova_invalidate_customer_cache')) {
        casanova_invalidate_customer_cache($user_id, $idCliente, $id);
      }

      $data = Casanova_Trip_Service::get_trip_for_user($user_id, $id, $request);
      if (is_wp_error($data)) {
        $response = $data;
        return $response;
      }

      if (($data['status'] ?? '') === 'degraded') {
        $perf_error = new RuntimeException((string) ($data['giav']['error'] ?? __('Servicio degradado.', 'casanova-portal')));
        $response = self::degraded_response((string) ($data['giav']['error'] ?? ''));
        return $response;
      }

      $perf_context['has_trip'] = !empty($data['trip']) ? 1 : 0;
      $perf_context['has_package'] = !empty($data['package']) ? 1 : 0;
      $perf_context['extras_count'] = count((array) ($data['extras'] ?? []));
      $perf_context['invoice_count'] = count((array) ($data['invoices'] ?? []));
      $perf_context['voucher_count'] = count((array) ($data['vouchers'] ?? []));
      $perf_context['messages_unread'] = (int) ($data['messages_meta']['unread_count'] ?? 0);

      if (!$refresh && (int) $request->get_param('mock') !== 1 && function_exists('casanova_rest_enable_private_cache')) {
        casanova_rest_enable_private_cache(60, 300);
      }

      $response = rest_ensure_response($data);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      if (function_exists('casanova_log')) {
        casanova_log('trip', 'trip endpoint failed', [
          'user_id' => (int) $user_id,
          'idCliente' => (int) $idCliente,
          'expediente_id' => (int) $id,
          'mock' => (int) ($perf_context['mock'] ?? 0),
          'refresh' => (int) ($perf_context['refresh'] ?? 0),
          'exception' => $e->getMessage(),
        ], 'error');
      }
      $response = self::degraded_response($e->getMessage());
      return $response;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('trip', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }
}
