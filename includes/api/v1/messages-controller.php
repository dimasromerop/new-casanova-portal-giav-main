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

    register_rest_route('casanova/v1', '/messages', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle_post'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'expediente' => ['type' => 'integer', 'required' => true],
        'body'       => ['type' => 'string', 'required' => false],
      ],
    ]);

    register_rest_route('casanova/v1', '/messages/attachment/(?P<id>\d+)', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle_attachment'],
      'permission_callback' => [self::class, 'attachment_permissions_check'],
      'args'                => [
        'id' => ['type' => 'integer', 'required' => true],
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

  public static function attachment_permissions_check(WP_REST_Request $request): bool|WP_Error {
    if (!is_user_logged_in()) {
      return false;
    }

    $attachment_id = (int) $request->get_param('id');
    if ($attachment_id <= 0) {
      return new WP_Error(
        'messages_attachment_invalid',
        __('Adjunto no válido.', 'casanova-portal'),
        ['status' => 400]
      );
    }

    if (!function_exists('casanova_portal_messages_user_can_access_attachment')) {
      return new WP_Error(
        'messages_attachment_disabled',
        __('Los adjuntos del portal no están disponibles.', 'casanova-portal'),
        ['status' => 500]
      );
    }

    if (!casanova_portal_messages_user_can_access_attachment((int) get_current_user_id(), $attachment_id)) {
      return new WP_Error(
        'forbidden_attachment',
        __('No tienes acceso a este adjunto.', 'casanova-portal'),
        ['status' => 403]
      );
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

  public static function handle_post(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $perf_start = function_exists('casanova_perf_now') ? casanova_perf_now() : microtime(true);
    $perf_error = null;
    $response = null;
    $user_id = (int) get_current_user_id();
    $perf_context = [
      'user_id' => $user_id,
      'expediente_id' => (int) $request->get_param('expediente'),
      'body_length' => function_exists('mb_strlen')
        ? mb_strlen((string) $request->get_param('body'), 'UTF-8')
        : strlen((string) $request->get_param('body')),
      'attachments_count' => count(
        function_exists('casanova_portal_messages_normalize_uploaded_files')
          ? casanova_portal_messages_normalize_uploaded_files(($request->get_file_params())['attachments'] ?? [])
          : []
      ),
    ];

    try {
      $data = Casanova_Messages_Service::post_message_for_user($user_id, $request);
      if (is_wp_error($data)) {
        $perf_error = $data;
        $response = new WP_REST_Response([
          'status' => 'error',
          'code' => $data->get_error_code(),
          'message' => $data->get_error_message(),
        ], (int) ($data->get_error_data()['status'] ?? 400));
        return $response;
      }

      $response = rest_ensure_response($data);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      $response = new WP_REST_Response([
        'status' => 'error',
        'code' => 'messages_post_failed',
        'message' => $e->getMessage(),
      ], 500);
      return $response;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('messages_post', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }

  public static function handle_attachment(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $attachment_id = (int) $request->get_param('id');
    if ($attachment_id <= 0 || !function_exists('casanova_portal_messages_get_attachment')) {
      return new WP_REST_Response([
        'status' => 'error',
        'code' => 'messages_attachment_invalid',
        'message' => __('Adjunto no válido.', 'casanova-portal'),
      ], 400);
    }

    $attachment = casanova_portal_messages_get_attachment($attachment_id);
    if (!is_object($attachment)) {
      return new WP_REST_Response([
        'status' => 'error',
        'code' => 'messages_attachment_missing',
        'message' => __('El adjunto ya no está disponible.', 'casanova-portal'),
      ], 404);
    }

    $path = function_exists('casanova_portal_messages_attachment_absolute_path')
      ? casanova_portal_messages_attachment_absolute_path($attachment)
      : '';
    if ($path === '' || !file_exists($path)) {
      return new WP_REST_Response([
        'status' => 'error',
        'code' => 'messages_attachment_missing_file',
        'message' => __('No se encontró el archivo del adjunto.', 'casanova-portal'),
      ], 404);
    }

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    $mime_type = (string) ($attachment->mime_type ?? 'application/octet-stream');
    $file_name = sanitize_file_name((string) ($attachment->original_name ?? basename($path)));
    $file_size = (int) (@filesize($path) ?: 0);

    nocache_headers();
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . rawurlencode($file_name) . '"; filename*=UTF-8\'\'' . rawurlencode($file_name));
    header('X-Content-Type-Options: nosniff');
    if ($file_size > 0) {
      header('Content-Length: ' . $file_size);
    }

    readfile($path);
    exit;
  }
}
