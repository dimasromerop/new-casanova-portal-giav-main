<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Dashboard
 * GET /wp-json/casanova/v1/dashboard
 */
class Casanova_Dashboard_Controller {

  private static function degraded_response(string $message = ''): WP_REST_Response {
    return new WP_REST_Response([
      'status' => 'degraded',
      'giav'   => ['ok' => false, 'source' => 'live', 'error' => $message],
      'mulligans' => ['points' => 0, 'tier' => '', 'last_sync' => 0],
      'trips' => [],
      'next_trip' => null,
      'next_trip_summary' => null,
      'post_trip' => null,
      'active_trip_exists' => false,
      'payments' => [],
      'messages' => [],
    ], 503);
  }

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/dashboard', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
    ]);
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $perf_start = function_exists('casanova_perf_now') ? casanova_perf_now() : microtime(true);
    $perf_error = null;
    $response = null;
    $perf_context = [
      'mock' => (int) $request->get_param('mock') === 1 ? 1 : 0,
      'refresh' => (int) $request->get_param('refresh') === 1 ? 1 : 0,
    ];

    try {
      $mock = (int) $request->get_param('mock') === 1;
      $refresh = (int) $request->get_param('refresh') === 1;

      if ($refresh) {
        // Invalida caches sin borrar transients uno a uno.
        if (function_exists('casanova_cache_buster_bump')) {
          casanova_cache_buster_bump();
        }

        $effective_user_id = function_exists('casanova_portal_get_effective_user_id')
          ? casanova_portal_get_effective_user_id()
          : get_current_user_id();
        $idCliente = function_exists('casanova_portal_get_effective_client_id')
          ? casanova_portal_get_effective_client_id($effective_user_id)
          : (int) get_user_meta($effective_user_id, 'casanova_idcliente', true);

        $perf_context['user_id'] = (int) $effective_user_id;
        $perf_context['idCliente'] = (int) $idCliente;

        if ($idCliente > 0) {
          delete_transient('casanova_dash_v3_' . $idCliente);
          if (class_exists('Casanova_Dashboard_Service') && method_exists('Casanova_Dashboard_Service', 'cache_key_for_user')) {
            delete_transient(Casanova_Dashboard_Service::cache_key_for_user($idCliente, $effective_user_id));
          }
        }

        if (
          $effective_user_id > 0
          && function_exists('casanova_mulligans_schedule_sync_user')
          && (!function_exists('casanova_portal_is_read_only') || !casanova_portal_is_read_only())
        ) {
          casanova_mulligans_schedule_sync_user($effective_user_id, true, 5);
        }
      }

      if ($mock && current_user_can('manage_options')) {
        $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/dashboard.json';
        $raw = file_exists($file) ? file_get_contents($file) : '';
        $data = $raw ? json_decode($raw, true) : null;

        if (!is_array($data)) {
          $data = [
            'status' => 'mock',
            'giav'   => ['ok' => true, 'source' => 'mock', 'error' => null],
            'mulligans' => ['points' => 0, 'tier' => '', 'last_sync' => 0],
            'trips' => [],
            'next_trip' => null,
            'next_trip_summary' => null,
            'post_trip' => null,
            'active_trip_exists' => false,
            'payments' => [],
            'messages' => [],
          ];
        }

        if (!array_key_exists('next_trip_summary', $data)) $data['next_trip_summary'] = null;
        if (!array_key_exists('post_trip', $data)) $data['post_trip'] = null;
        if (!array_key_exists('active_trip_exists', $data)) {
          $data['active_trip_exists'] = !empty($data['next_trip']['id']);
        }

        $perf_context['trip_count'] = count((array) ($data['trips'] ?? []));
        $perf_context['next_trip_id'] = (int) ($data['next_trip']['id'] ?? 0);
        $perf_context['active_trip_exists'] = !empty($data['active_trip_exists']) ? 1 : 0;

        $response = rest_ensure_response($data);
        return $response;
      }

      $user_id = function_exists('casanova_portal_get_effective_user_id')
        ? casanova_portal_get_effective_user_id()
        : get_current_user_id();
      $idCliente = function_exists('casanova_portal_get_effective_client_id')
        ? casanova_portal_get_effective_client_id($user_id)
        : (int) get_user_meta($user_id, 'casanova_idcliente', true);

      $perf_context['user_id'] = (int) $user_id;
      $perf_context['idCliente'] = (int) $idCliente;

      $dto = Casanova_Dashboard_Service::build_for_user($user_id, $refresh);
      $out = $dto->to_array();

      if (!isset($out['status'])) $out['status'] = 'ok';
      if (!isset($out['giav'])) $out['giav'] = ['ok' => true, 'source' => 'live', 'error' => null];
      if (!array_key_exists('next_trip_summary', $out)) $out['next_trip_summary'] = null;
      if (!array_key_exists('post_trip', $out)) $out['post_trip'] = null;
      if (!array_key_exists('active_trip_exists', $out)) {
        $out['active_trip_exists'] = !empty($out['next_trip']['id']);
      }

      if (($out['status'] ?? '') === 'degraded') {
        $perf_error = new RuntimeException((string) ($out['giav']['error'] ?? __('Servicio degradado.', 'casanova-portal')));
        $response = self::degraded_response((string) ($out['giav']['error'] ?? ''));
        return $response;
      }

      $perf_context['trip_count'] = count((array) ($out['trips'] ?? []));
      $perf_context['next_trip_id'] = (int) ($out['next_trip']['id'] ?? 0);
      $perf_context['active_trip_exists'] = !empty($out['active_trip_exists']) ? 1 : 0;

      if (!$refresh && !$mock && function_exists('casanova_rest_enable_private_cache')) {
        casanova_rest_enable_private_cache(60, 300);
      }

      $response = rest_ensure_response($out);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      if (function_exists('casanova_log')) {
        casanova_log('dashboard', 'dashboard endpoint failed', [
          'user_id' => (int) ($perf_context['user_id'] ?? 0),
          'idCliente' => (int) ($perf_context['idCliente'] ?? 0),
          'mock' => (int) ($perf_context['mock'] ?? 0),
          'refresh' => (int) ($perf_context['refresh'] ?? 0),
          'exception' => $e->getMessage(),
        ], 'error');
      }
      $response = self::degraded_response($e->getMessage());
      return $response;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('dashboard', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }
}
