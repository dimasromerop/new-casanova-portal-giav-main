<?php
if (!defined('ABSPATH')) exit;

/**
 * Service de Mensajes.
 *
 * - Lee comentarios de GIAV como timeline.
 * - Soporta mock para desarrollo: ?mock=1 (solo admin).
 * - Diseñado para ser consumido por React y por legacy si se necesitara.
 */
class Casanova_Messages_Service {

  /**
   * @return array<string,mixed>
   */
  public static function get_messages_for_user(int $user_id, WP_REST_Request $request): array {
    $user_id = function_exists('casanova_portal_resolve_user_id')
      ? casanova_portal_resolve_user_id($user_id)
      : $user_id;

    $mock = (int) $request->get_param('mock') === 1;
    if ($mock && current_user_can('manage_options')) {
      return self::mock_response($request);
    }

    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if (!$idCliente) {
      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    // Selección de expediente
    $expediente = (int) $request->get_param('expediente');
    if (!$expediente) {
      // fallback: usa el próximo viaje si no llega expediente
      $dash = Casanova_Dashboard_Service::build_for_user($user_id)->to_array();
      $expediente = (int) ($dash['next_trip']['id'] ?? 0);
    }

    if ($expediente && function_exists('casanova_user_can_access_expediente')
        && !casanova_user_can_access_expediente($user_id, $expediente)) {
      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    if (!$expediente || !function_exists('casanova_giav_comments_por_expediente')) {
      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    $limit = (int) $request->get_param('limit');
    if ($limit <= 0) $limit = 50;
    if ($limit > 200) $limit = 200;

    $days = 365;

    try {
      $comments = casanova_giav_comments_por_expediente($expediente, $limit, $days);
      if (is_wp_error($comments) || !is_array($comments)) $comments = [];

      $giav_items = [];
      $seen = function_exists('casanova_messages_seen_meta_key')
        ? (int) get_user_meta($user_id, casanova_messages_seen_meta_key($expediente), true)
        : 0;
      $latest_giav_ts = 0;
      $unread_giav = 0;

      foreach ($comments as $c) {
        if (!is_object($c)) continue;
        $date = (string) ($c->CreationDate ?? $c->Fecha ?? '');
        $ts = $date !== '' ? (strtotime($date) ?: 0) : 0;
        if ($ts > $seen) {
          $unread_giav++;
        }
        if ($ts > $latest_giav_ts) {
          $latest_giav_ts = $ts;
        }

        $raw_id = (string) ($c->Id ?? $c->ID ?? $c->IdComment ?? '');
        $body = trim((string) ($c->Body ?? ''));

        $giav_items[] = [
          'id'           => $raw_id !== '' ? 'giav:' . $raw_id : 'giav:' . sha1($date . '|' . $body),
          'date'         => $date,
          'author'       => (string) ($c->Author ?? $c->Usuario ?? 'Casanova Golf'),
          'direction'    => 'agency',
          'content'      => $body,
          'expediente_id'=> $expediente,
          'origin'       => 'giav',
          'attachments'  => [],
        ];
      }

      $local_items = [];
      $local_unread = 0;
      if (
        $idCliente > 0
        && function_exists('casanova_portal_messages_get_local_items')
      ) {
        $local_items = casanova_portal_messages_get_local_items($idCliente, $expediente, $limit);
      }
      if (
        $idCliente > 0
        && function_exists('casanova_portal_messages_local_unread_for_user')
      ) {
        $local_unread = (int) casanova_portal_messages_local_unread_for_user($user_id, $idCliente, $expediente);
      }

      $items = function_exists('casanova_portal_messages_merge_items')
        ? casanova_portal_messages_merge_items($giav_items, $local_items, $limit)
        : array_values(array_merge($giav_items, $local_items));

      // mark_seen: solo meta local (no toca GIAV)
      $mark_seen = (int) $request->get_param('mark_seen') === 1;
      if ($mark_seen && !casanova_portal_is_read_only()) {
        if ($latest_giav_ts > 0 && function_exists('casanova_messages_mark_seen')) {
          casanova_messages_mark_seen($user_id, $expediente, $latest_giav_ts);
        }
        if (
          $idCliente > 0
          && function_exists('casanova_portal_messages_mark_client_read_for_expediente')
        ) {
          casanova_portal_messages_mark_client_read_for_expediente($user_id, $idCliente, $expediente);
        }
        $unread_giav = 0;
        $local_unread = 0;
      }

      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => (int) ($unread_giav + $local_unread),
        'items'  => $items,
      ];

    } catch (Exception $e) {
      return [
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'unread' => 0,
        'items'  => [],
      ];
    }
  }

  /**
   * @return array<string,mixed>|WP_Error
   */
  public static function post_message_for_user(int $user_id, WP_REST_Request $request): array|WP_Error {
    $user_id = function_exists('casanova_portal_resolve_user_id')
      ? casanova_portal_resolve_user_id($user_id)
      : $user_id;

    if ($user_id <= 0) {
      return new WP_Error('messages_user', __('No se pudo identificar al usuario.', 'casanova-portal'), ['status' => 401]);
    }

    if (function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only()) {
      return new WP_Error('messages_read_only', __('Modo solo lectura activo.', 'casanova-portal'), ['status' => 403]);
    }

    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if ($idCliente <= 0) {
      return new WP_Error('messages_client', __('No se pudo resolver el cliente del portal.', 'casanova-portal'), ['status' => 400]);
    }

    $expediente = (int) $request->get_param('expediente');
    if ($expediente <= 0) {
      return new WP_Error('messages_expediente', __('Falta el expediente del mensaje.', 'casanova-portal'), ['status' => 400]);
    }

    if (function_exists('casanova_user_can_access_expediente') && !casanova_user_can_access_expediente($user_id, $expediente)) {
      return new WP_Error('forbidden_expediente', __('No tienes acceso a este expediente.', 'casanova-portal'), ['status' => 403]);
    }

    if (!function_exists('casanova_portal_messages_create_message')) {
      return new WP_Error('messages_disabled', __('La mensajería del portal no está disponible.', 'casanova-portal'), ['status' => 500]);
    }

    $body = (string) $request->get_param('body');
    $body = casanova_portal_messages_sanitize_body($body);
    $file_params = $request->get_file_params();
    $attachments_files = function_exists('casanova_portal_messages_normalize_uploaded_files')
      ? casanova_portal_messages_normalize_uploaded_files($file_params['attachments'] ?? [])
      : [];
    if ($body === '' && empty($attachments_files)) {
      return new WP_Error('messages_empty', __('Escribe un mensaje o adjunta un archivo antes de enviarlo.', 'casanova-portal'), ['status' => 400]);
    }

    $rate_key = 'portal-msg:' . $user_id . ':' . $expediente;
    if (function_exists('casanova_rate_limit') && !casanova_rate_limit($rate_key, 5, 60)) {
      return new WP_Error('messages_rate_limit', __('Estás enviando mensajes demasiado rápido. Espera un minuto.', 'casanova-portal'), ['status' => 429]);
    }

    $user = get_userdata($user_id);
    $author_name = $user instanceof WP_User
      ? trim((string) ($user->display_name ?: $user->user_login))
      : __('Cliente', 'casanova-portal');

    $created = casanova_portal_messages_create_message([
      'id_cliente' => $idCliente,
      'id_expediente' => $expediente,
      'user_id' => $user_id,
      'direction' => 'client',
      'origin' => 'portal',
      'author_name' => $author_name,
      'body' => $body,
      'attachments_files' => $attachments_files,
      'metadata' => [
        'channel' => 'portal',
      ],
    ]);

    if (is_wp_error($created)) {
      return $created;
    }

    $message = is_array($created) ? ($created['message'] ?? null) : null;
    if (!is_object($message)) {
      return new WP_Error('messages_create_missing', __('No se pudo recuperar el mensaje enviado.', 'casanova-portal'), ['status' => 500]);
    }

    return [
      'status' => 'ok',
      'item' => function_exists('casanova_portal_messages_table_row_to_item')
        ? casanova_portal_messages_table_row_to_item($message, $expediente, (array) ($created['attachments'] ?? []))
        : [
            'id' => 'local:' . (int) ($message->id ?? 0),
            'date' => function_exists('casanova_portal_messages_mysql_to_iso')
              ? casanova_portal_messages_mysql_to_iso((string) ($message->created_at ?? ''))
              : null,
            'author' => (string) ($message->author_name ?? $author_name),
            'direction' => 'client',
            'content' => (string) ($message->body ?? $body),
            'expediente_id' => $expediente,
            'origin' => 'portal',
            'attachments' => array_values((array) ($created['attachments'] ?? [])),
          ],
      'unread' => 0,
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function mock_response(WP_REST_Request $request): array {
    $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/messages.json';
    $raw = file_exists($file) ? file_get_contents($file) : '';
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
      $data = [
        'unread' => 2,
        'items' => [],
      ];
    }

    $exp = (int) $request->get_param('expediente');
    if ($exp) {
      $filtered = [];
      foreach ((array) ($data['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        if ((int) ($it['expediente_id'] ?? 0) === $exp) $filtered[] = $it;
      }
      $data['items'] = $filtered;
    }

    return [
      'status' => 'mock',
      'giav'   => ['ok' => true, 'source' => 'mock', 'error' => null],
      'unread' => (int) ($data['unread'] ?? 0),
      'items'  => array_values((array) ($data['items'] ?? [])),
    ];
  }
}
