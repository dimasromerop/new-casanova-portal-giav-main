<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Messages
 * GET /wp-json/casanova/v1/messages
 */
class Casanova_Messages_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/messages', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'expediente' => ['type' => 'integer', 'required' => false],
        'limit'      => ['type' => 'integer', 'required' => false],
        'mark_seen'  => ['type' => 'integer', 'required' => false],
        'mock'       => ['type' => 'integer', 'required' => false],
      ],
    ]);
  }

  public static function permissions_check(WP_REST_Request $request): bool|WP_Error {
    if (!is_user_logged_in()) {
      return false;
    }

    $expediente = (int) $request->get_param('expediente');
    if ($expediente > 0 && function_exists('casanova_user_can_access_expediente')) {
      $user_id = get_current_user_id();
      if (!casanova_user_can_access_expediente($user_id, $expediente)) {
        return new WP_Error(
          'forbidden_expediente',
          __('No tienes acceso a este expediente.', 'casanova-portal'),
          ['status' => 403]
        );
      }
    }

    return true;
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $perf_start = function_exists('casanova_perf_now') ? casanova_perf_now() : microtime(true);
    $perf_error = null;
    $response = null;
    $user_id = (int) get_current_user_id();
    $perf_context = [
      'user_id' => $user_id,
      'expediente_id' => (int) $request->get_param('expediente'),
      'limit' => (int) $request->get_param('limit'),
      'mark_seen' => (int) $request->get_param('mark_seen') === 1 ? 1 : 0,
      'mock' => (int) $request->get_param('mock') === 1 ? 1 : 0,
    ];

    try {
      $data = Casanova_Messages_Service::get_messages_for_user($user_id, $request);
      $perf_context['item_count'] = count((array) ($data['items'] ?? []));
      $perf_context['unread'] = (int) ($data['unread'] ?? 0);

      $response = rest_ensure_response($data);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      $response = new WP_REST_Response([
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'unread' => 0,
        'items'  => [],
      ], 200);
      return $response;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('messages', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }
}
