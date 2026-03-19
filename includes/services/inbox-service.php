<?php
if (!defined('ABSPATH')) exit;

/**
 * Service de Inbox Global.
 *
 * Devuelve un item por expediente con el último mensaje (si existe) y
 * un contador global de no leídos.
 */
class Casanova_Inbox_Service {

  /**
   * @return array<string,mixed>
   */
  public static function get_inbox_for_user(int $user_id, WP_REST_Request $request): array {
    $user_id = function_exists('casanova_portal_resolve_user_id')
      ? casanova_portal_resolve_user_id($user_id)
      : $user_id;

    $mock = (int) $request->get_param('mock');
    if ($mock && !current_user_can('manage_options')) $mock = 0;

    $refresh = (int) $request->get_param('refresh') === 1;
    $seen_version = function_exists('casanova_messages_seen_version')
      ? casanova_messages_seen_version($user_id)
      : 1;
    $local_version = function_exists('casanova_portal_messages_user_version')
      ? casanova_portal_messages_user_version($user_id)
      : 1;

    if (!$refresh && function_exists('casanova_cache_remember')) {
      return casanova_cache_remember(
        'inbox:' . $user_id . ':' . (int) $mock . ':' . $seen_version . ':' . $local_version,
        60,
        function () use ($user_id, $mock) {
          return self::build_inbox_for_user($user_id, (bool) $mock);
        }
      );
    }

    return self::build_inbox_for_user($user_id, (bool) $mock);
  }

  /**
   * @return array<string,mixed>
   */
  private static function build_inbox_for_user(int $user_id, bool $mock = false): array {
    $mock = $mock ? 1 : 0;

    // Dashboard ya resuelve trips y (en mock) estructura base.
    $dash = Casanova_Dashboard_Service::build_for_user($user_id)->to_array();
    $trips = array_values((array) ($dash['trips'] ?? []));

    // En modo mock, si el dashboard no devuelve viajes (por usuario, permisos, etc.),
    // generamos una lista mínima desde includes/mock/trip.json para poder testear UI.
    if ($mock && (!$trips || !is_array($trips) || count($trips) === 0)) {
      $trip_file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/trip.json';
      $raw = file_exists($trip_file) ? file_get_contents($trip_file) : '';
      $all = $raw ? json_decode($raw, true) : null;

      if (is_array($all) && !isset($all['trip'])) {
        foreach ($all as $k => $v) {
          if (!is_array($v) || !isset($v['trip']) || !is_array($v['trip'])) continue;
          $tr = $v['trip'];
          $trips[] = [
            'id'         => (int) ($tr['id'] ?? 0),
            'title'      => (string) ($tr['title'] ?? ''),
            'code'       => (string) ($tr['code'] ?? ''),
            'status'     => (string) ($tr['status'] ?? ''),
            'date_start' => (string) ($tr['date_start'] ?? ''),
            'date_end'   => (string) ($tr['date_end'] ?? ''),
          ];
        }
      } elseif (is_array($all) && isset($all['trip']) && is_array($all['trip'])) {
        $tr = $all['trip'];
        $trips[] = [
          'id'         => (int) ($tr['id'] ?? 0),
          'title'      => (string) ($tr['title'] ?? ''),
          'code'       => (string) ($tr['code'] ?? ''),
          'status'     => (string) ($tr['status'] ?? ''),
          'date_start' => (string) ($tr['date_start'] ?? ''),
          'date_end'   => (string) ($tr['date_end'] ?? ''),
        ];
      }

      // Normaliza: elimina ids vacíos
      $trips = array_values(array_filter($trips, function($t){
        return !empty($t['id']);
      }));
    }


    if (!$trips) {
      return [
        'status' => $mock ? 'mock' : 'ok',
        'giav'   => ['ok' => true, 'source' => $mock ? 'mock' : 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    $items = [];
    $unread_total = 0;
    $mock_summaries = $mock ? self::build_mock_summary_map() : [];

    foreach ($trips as $t) {
      $exp = (int) ($t['id'] ?? 0);
      if (!$exp) continue;

      $summary = $mock
        ? ($mock_summaries[$exp] ?? self::empty_thread_summary())
        : self::get_live_thread_summary($user_id, $exp);

      $unread = (int) ($summary['unread'] ?? 0);
      $unread_total += $unread;

      $items[] = [
        'expediente_id'   => $exp,
        'trip_title'      => (string) ($t['title'] ?? ''),
        'trip_code'       => (string) ($t['code'] ?? ''),
        'trip_status'     => (string) ($t['status'] ?? ''),
        'date_start'      => (string) ($t['date_start'] ?? ''),
        'date_end'        => (string) ($t['date_end'] ?? ''),
        'last_message_at' => $summary['last_message_at'] ?? null,
        'author'          => (string) ($summary['author'] ?? ''),
        'direction'       => (string) ($summary['direction'] ?? ''),
        'content'         => (string) ($summary['content'] ?? ''),
        'unread'          => $unread,
      ];
    }

    usort($items, function($a,$b){
      $ta = !empty($a['last_message_at']) ? strtotime((string)$a['last_message_at']) : 0;
      $tb = !empty($b['last_message_at']) ? strtotime((string)$b['last_message_at']) : 0;
      return $tb <=> $ta;
    });

    return [
      'status' => $mock ? 'mock' : 'ok',
      'giav'   => ['ok' => true, 'source' => $mock ? 'mock' : 'live', 'error' => null],
      'unread' => (int) $unread_total,
      'items'  => $items,
    ];
  }

  /**
   * @return array{unread:int,last_message_at:?string,author:string,direction:string,content:string}
   */
  private static function empty_thread_summary(): array {
    return [
      'unread' => 0,
      'last_message_at' => null,
      'author' => '',
      'direction' => '',
      'content' => '',
    ];
  }

  /**
   * @return array{unread:int,last_message_at:?string,author:string,direction:string,content:string}
   */
  private static function get_live_thread_summary(int $user_id, int $expediente_id): array {
    if (function_exists('casanova_messages_thread_summary_for_expediente')) {
      $summary = casanova_messages_thread_summary_for_expediente($user_id, $expediente_id, 30);
      return [
        'unread' => (int) ($summary['unread'] ?? 0),
        'last_message_at' => $summary['last_message_at'] ?? null,
        'author' => (string) ($summary['author'] ?? ''),
        'direction' => (string) ($summary['direction'] ?? ''),
        'content' => (string) ($summary['content'] ?? ''),
      ];
    }

    $req = new WP_REST_Request('GET', '/casanova/v1/messages');
    $req->set_param('expediente', $expediente_id);
    $data = Casanova_Messages_Service::get_messages_for_user($user_id, $req);
    $list = array_values((array) ($data['items'] ?? []));
    $last = $list ? $list[0] : null;

    return [
      'unread' => (int) ($data['unread'] ?? 0),
      'last_message_at' => $last ? (string) ($last['date'] ?? '') : null,
      'author' => $last ? (string) ($last['author'] ?? '') : '',
      'direction' => $last ? (string) ($last['direction'] ?? '') : '',
      'content' => $last ? (string) ($last['content'] ?? '') : '',
    ];
  }

  /**
   * @return array<int,array{unread:int,last_message_at:?string,author:string,direction:string,content:string}>
   */
  private static function build_mock_summary_map(): array {
    $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/messages.json';
    $raw = file_exists($file) ? file_get_contents($file) : '';
    $data = $raw ? json_decode($raw, true) : null;
    $items = is_array($data) ? (array) ($data['items'] ?? []) : [];

    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) continue;
      $exp = (int) ($item['expediente_id'] ?? 0);
      if ($exp <= 0) continue;

      if (!isset($out[$exp])) {
        $out[$exp] = self::empty_thread_summary();
      }

      $out[$exp]['unread']++;

      $current_ts = !empty($out[$exp]['last_message_at']) ? (strtotime((string) $out[$exp]['last_message_at']) ?: 0) : 0;
      $item_ts = !empty($item['date']) ? (strtotime((string) $item['date']) ?: 0) : 0;
      if ($item_ts >= $current_ts) {
        $out[$exp]['last_message_at'] = !empty($item['date']) ? (string) $item['date'] : null;
        $out[$exp]['author'] = (string) ($item['author'] ?? '');
        $out[$exp]['direction'] = (string) ($item['direction'] ?? '');
        $out[$exp]['content'] = (string) ($item['content'] ?? '');
      }
    }

    return $out;
  }
}
