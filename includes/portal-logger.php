<?php
if (!defined('ABSPATH')) exit;

/**
 * Simple file logger for production debugging when WP_DEBUG_LOG is not available.
 * Writes to wp-content/uploads/casanova-portal/log-YYYYMMDD.log
 */
if (!function_exists('casanova_portal_log')) {
  function casanova_portal_log(string $channel, $data = null): void {
    try {
      $uploads = wp_upload_dir(null, false);
      $base = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
      if ($base === '') return;

      $dir = trailingslashit($base) . 'casanova-portal';
      if (!is_dir($dir)) {
        wp_mkdir_p($dir);
      }

      $date = gmdate('Ymd');
      $file = trailingslashit($dir) . 'log-' . $date . '.log';

      $ts = gmdate('c');
      if (is_array($data) || is_object($data)) {
        $payload = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      } else if (is_bool($data)) {
        $payload = $data ? 'true' : 'false';
      } else if ($data === null) {
        $payload = '';
      } else {
        $payload = (string)$data;
      }

      $line = '[' . $ts . '][' . $channel . '] ' . $payload . "\n";
      @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
      // Never break the request because of logging.
    }
  }
}
