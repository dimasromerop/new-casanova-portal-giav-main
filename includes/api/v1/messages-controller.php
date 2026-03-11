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
    try {
      $user_id = get_current_user_id();
      $data = Casanova_Messages_Service::get_messages_for_user($user_id, $request);
      return rest_ensure_response($data);
    } catch (Exception $e) {
      return new WP_REST_Response([
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'unread' => 0,
        'items'  => [],
      ], 200);
    }
  }
}
