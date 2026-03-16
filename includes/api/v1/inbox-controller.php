<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Inbox
 * GET /wp-json/casanova/v1/inbox
 */
class Casanova_Inbox_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/inbox', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions'],
      'args'                => [
        'mock' => [
          'description' => 'Devuelve datos mock (solo admins).',
          'type'        => 'integer',
          'required'    => false,
        ],
        'refresh' => [
          'description' => 'Fuerza recarga y evita usar cache corta.',
          'type'        => 'integer',
          'required'    => false,
        ],
      ],
    ]);
  }

  public static function permissions(WP_REST_Request $request): bool {
    return is_user_logged_in();
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
    $perf_context = [
      'user_id' => (int) $user_id,
      'idCliente' => (int) $idCliente,
      'mock' => (int) $request->get_param('mock') === 1 ? 1 : 0,
      'refresh' => (int) $request->get_param('refresh') === 1 ? 1 : 0,
    ];

    try {
      $data = Casanova_Inbox_Service::get_inbox_for_user($user_id, $request);
      $perf_context['item_count'] = count((array) ($data['items'] ?? []));
      $perf_context['unread'] = (int) ($data['unread'] ?? 0);

      if ((int) $request->get_param('mock') !== 1 && (int) $request->get_param('refresh') !== 1 && function_exists('casanova_rest_enable_private_cache')) {
        casanova_rest_enable_private_cache(60, 300);
      }

      $response = rest_ensure_response($data);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      throw $e;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('inbox', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }
}
