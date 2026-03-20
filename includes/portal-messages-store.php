<?php
if (!defined('ABSPATH')) exit;

function casanova_portal_messages_user_version_key(): string {
  return 'casanova_portal_messages_version';
}

function casanova_portal_messages_user_version(int $user_id): int {
  if ($user_id <= 0) return 1;
  $version = (int) get_user_meta($user_id, casanova_portal_messages_user_version_key(), true);
  return $version > 0 ? $version : 1;
}

function casanova_portal_messages_bump_user_version(int $user_id): int {
  if ($user_id <= 0) return 1;
  $version = casanova_portal_messages_user_version($user_id) + 1;
  update_user_meta($user_id, casanova_portal_messages_user_version_key(), $version);
  return $version;
}

function casanova_portal_messages_cache_token(int $user_id): string {
  $giav_seen_version = function_exists('casanova_messages_seen_version')
    ? casanova_messages_seen_version($user_id)
    : 1;

  return $giav_seen_version . ':' . casanova_portal_messages_user_version($user_id);
}

function casanova_portal_messages_client_user_ids(int $idCliente): array {
  $idCliente = (int) $idCliente;
  if ($idCliente <= 0) return [];

  $users = get_users([
    'fields' => 'ID',
    'meta_key' => 'casanova_idcliente',
    'meta_value' => (string) $idCliente,
    'number' => 50,
  ]);

  if (!is_array($users)) return [];
  return array_values(array_unique(array_map('intval', $users)));
}

function casanova_portal_messages_bump_client_users_version(int $idCliente): void {
  foreach (casanova_portal_messages_client_user_ids($idCliente) as $user_id) {
    casanova_portal_messages_bump_user_version((int) $user_id);
  }
}

function casanova_portal_messages_normalize_role(string $role): string {
  $role = sanitize_key($role);
  return in_array($role, ['client', 'agency'], true) ? $role : 'client';
}

function casanova_portal_messages_normalize_direction(string $direction): string {
  $direction = sanitize_key($direction);
  return in_array($direction, ['agency', 'client', 'system'], true) ? $direction : 'agency';
}

function casanova_portal_messages_normalize_origin(string $origin): string {
  $origin = sanitize_key($origin);
  return in_array($origin, ['portal', 'giav', 'email', 'system', 'admin'], true) ? $origin : 'portal';
}

function casanova_portal_messages_sanitize_body(string $body): string {
  $body = preg_replace("/\r\n?/", "\n", (string) $body);
  $body = sanitize_textarea_field($body);
  $body = preg_replace("/\n{3,}/", "\n\n", $body);
  return trim((string) $body);
}

function casanova_portal_messages_body_max_length(): int {
  return 4000;
}

function casanova_portal_messages_body_length(string $body): int {
  $body = (string) $body;
  if (function_exists('mb_strlen')) {
    return (int) mb_strlen($body, 'UTF-8');
  }
  return strlen($body);
}

function casanova_portal_messages_log(string $message, array $context = [], string $level = 'warn'): void {
  if (function_exists('casanova_log')) {
    casanova_log('messages', $message, $context, $level);
    return;
  }

  if (function_exists('casanova_portal_log')) {
    casanova_portal_log('messages', [
      'level' => $level,
      'message' => $message,
      'context' => $context,
    ]);
  }
}

function casanova_portal_messages_preview(string $body, int $limit = 140): string {
  $body = trim((string) $body);
  if ($body === '') return '';
  if (mb_strlen($body, 'UTF-8') <= $limit) return $body;
  return mb_substr($body, 0, $limit, 'UTF-8') . '...';
}

function casanova_portal_messages_attachment_limits(): array {
  return [
    'max_files' => 3,
    'max_bytes' => 5 * 1024 * 1024,
    'allowed_mimes' => [
      'pdf' => 'application/pdf',
      'jpg|jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
    ],
  ];
}

function casanova_portal_messages_attachment_extensions(): array {
  return [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  ];
}

function casanova_portal_messages_format_bytes(int $bytes): string {
  $bytes = max(0, (int) $bytes);
  if ($bytes >= 1024 * 1024) {
    return number_format_i18n($bytes / (1024 * 1024), 1) . ' MB';
  }
  if ($bytes >= 1024) {
    return number_format_i18n($bytes / 1024, 1) . ' KB';
  }
  return number_format_i18n($bytes) . ' B';
}

function casanova_portal_messages_mysql_to_iso(?string $mysql_date): ?string {
  $mysql_date = trim((string) $mysql_date);
  if ($mysql_date === '') return null;

  if (function_exists('get_gmt_from_date')) {
    $gmt = get_gmt_from_date($mysql_date, 'Y-m-d H:i:s');
    if ($gmt !== '') {
      $ts = strtotime($gmt . ' UTC');
      if ($ts) return gmdate('c', $ts);
    }
  }

  $ts = strtotime($mysql_date);
  return $ts ? gmdate('c', $ts) : null;
}

function casanova_portal_messages_table_row_to_item(object $row, int $idExpediente, array $attachments = []): array {
  return [
    'id' => 'local:' . (int) ($row->id ?? 0),
    'date' => casanova_portal_messages_mysql_to_iso((string) ($row->created_at ?? '')),
    'author' => (string) ($row->author_name ?? ''),
    'direction' => (string) ($row->direction ?? 'agency'),
    'content' => (string) ($row->body ?? ''),
    'expediente_id' => (int) $idExpediente,
    'origin' => (string) ($row->origin ?? 'portal'),
    'attachments' => array_values($attachments),
  ];
}

function casanova_portal_messages_attachment_base_dir(): array {
  $uploads = wp_upload_dir(null, false);
  $base_dir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
  $base_url = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

  return [
    'basedir' => $base_dir !== '' ? trailingslashit($base_dir) . 'casanova-portal/message-attachments' : '',
    'baseurl' => $base_url !== '' ? trailingslashit($base_url) . 'casanova-portal/message-attachments' : '',
  ];
}

function casanova_portal_messages_ensure_attachment_dir(): string {
  $paths = casanova_portal_messages_attachment_base_dir();
  $dir = (string) ($paths['basedir'] ?? '');
  if ($dir === '') {
    return '';
  }

  if (!is_dir($dir)) {
    wp_mkdir_p($dir);
  }

  $index_file = trailingslashit($dir) . 'index.php';
  if (!file_exists($index_file)) {
    @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
  }

  $htaccess = trailingslashit($dir) . '.htaccess';
  if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "Deny from all\n");
  }

  $web_config = trailingslashit($dir) . 'web.config';
  if (!file_exists($web_config)) {
    @file_put_contents($web_config, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization>
      <deny users="*" />
    </authorization>
  </system.webServer>
</configuration>
XML
    );
  }

  return $dir;
}

function casanova_portal_messages_normalize_uploaded_files($files_param): array {
  if (!is_array($files_param) || empty($files_param['name'])) return [];

  if (!is_array($files_param['name'])) {
    return [[
      'name' => (string) ($files_param['name'] ?? ''),
      'type' => (string) ($files_param['type'] ?? ''),
      'tmp_name' => (string) ($files_param['tmp_name'] ?? ''),
      'error' => (int) ($files_param['error'] ?? UPLOAD_ERR_NO_FILE),
      'size' => (int) ($files_param['size'] ?? 0),
    ]];
  }

  $files = [];
  foreach ($files_param['name'] as $index => $name) {
    $files[] = [
      'name' => (string) ($name ?? ''),
      'type' => (string) ($files_param['type'][$index] ?? ''),
      'tmp_name' => (string) ($files_param['tmp_name'][$index] ?? ''),
      'error' => (int) ($files_param['error'][$index] ?? UPLOAD_ERR_NO_FILE),
      'size' => (int) ($files_param['size'][$index] ?? 0),
    ];
  }

  return $files;
}

function casanova_portal_messages_validate_uploaded_files(array $files): array|WP_Error {
  $limits = casanova_portal_messages_attachment_limits();
  $files = array_values(array_filter($files, function($file) {
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
  }));

  if (empty($files)) return [];
  if (count($files) > (int) $limits['max_files']) {
    return new WP_Error(
      'message_attachments_limit',
      sprintf(
        __('Puedes adjuntar como máximo %d archivos por mensaje.', 'casanova-portal'),
        (int) $limits['max_files']
      )
    );
  }

  $validated = [];
  foreach ($files as $file) {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      return new WP_Error('message_attachment_upload', __('Uno de los archivos no se ha podido subir.', 'casanova-portal'));
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
      return new WP_Error('message_attachment_empty', __('Uno de los archivos adjuntos está vacío.', 'casanova-portal'));
    }
    if ($size > (int) $limits['max_bytes']) {
      return new WP_Error(
        'message_attachment_size',
        sprintf(
          __('Cada archivo puede ocupar como máximo %s.', 'casanova-portal'),
          casanova_portal_messages_format_bytes((int) $limits['max_bytes'])
        )
      );
    }

    $tmp_name = (string) ($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !file_exists($tmp_name)) {
      return new WP_Error('message_attachment_tmp', __('No se ha encontrado el archivo temporal del adjunto.', 'casanova-portal'));
    }

    $checked = wp_check_filetype_and_ext($tmp_name, (string) ($file['name'] ?? ''), $limits['allowed_mimes']);
    $mime_type = (string) ($checked['type'] ?? '');
    $ext = (string) ($checked['ext'] ?? '');
    if ($mime_type === '' || $ext === '') {
      return new WP_Error('message_attachment_type', __('Solo se admiten PDF, JPG, PNG o WEBP.', 'casanova-portal'));
    }

    $validated[] = [
      'name' => sanitize_file_name((string) ($file['name'] ?? 'archivo')),
      'tmp_name' => $tmp_name,
      'size' => $size,
      'mime_type' => $mime_type,
      'ext' => $ext,
    ];
  }

  return $validated;
}

function casanova_portal_messages_attachment_download_url(int $attachment_id): string {
  $url = rest_url('casanova/v1/messages/attachment/' . (int) $attachment_id);
  if (is_user_logged_in()) {
    $url = add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), $url);
  }
  return $url;
}

function casanova_portal_messages_attachment_row_to_array(object $row): array {
  $mime_type = (string) ($row->mime_type ?? '');
  return [
    'id' => (int) ($row->id ?? 0),
    'name' => (string) ($row->original_name ?? ''),
    'mimeType' => $mime_type,
    'size' => (int) ($row->file_size ?? 0),
    'sizeLabel' => casanova_portal_messages_format_bytes((int) ($row->file_size ?? 0)),
    'downloadUrl' => casanova_portal_messages_attachment_download_url((int) ($row->id ?? 0)),
    'isImage' => strpos($mime_type, 'image/') === 0,
  ];
}

function casanova_portal_messages_get_attachments_for_message_ids(array $message_ids): array {
  global $wpdb;

  $message_ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
  if (empty($message_ids)) return [];

  $table = casanova_portal_message_files_table();
  $placeholders = implode(',', array_fill(0, count($message_ids), '%d'));
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE message_id IN ({$placeholders}) ORDER BY id ASC",
    ...$message_ids
  );

  $rows = $wpdb->get_results($sql);
  if (!is_array($rows)) return [];

  $map = [];
  foreach ($rows as $row) {
    if (!is_object($row)) continue;
    $message_id = (int) ($row->message_id ?? 0);
    if ($message_id <= 0) continue;
    if (!isset($map[$message_id])) $map[$message_id] = [];
    $map[$message_id][] = casanova_portal_messages_attachment_row_to_array($row);
  }

  return $map;
}

function casanova_portal_messages_get_attachment(int $attachment_id): ?object {
  global $wpdb;

  $attachment_id = (int) $attachment_id;
  if ($attachment_id <= 0) return null;

  $table = casanova_portal_message_files_table();
  $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $attachment_id);
  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_attachment_absolute_path(object $attachment): string {
  $relative = ltrim(str_replace(['\\', '..'], ['/', ''], (string) ($attachment->relative_path ?? '')), '/');
  if ($relative === '') return '';

  $uploads = wp_upload_dir(null, false);
  $uploads_root = (string) ($uploads['basedir'] ?? '');
  $attachment_root = (string) (casanova_portal_messages_attachment_base_dir()['basedir'] ?? '');

  $candidates = [];
  if ($uploads_root !== '') {
    $candidates[] = trailingslashit($uploads_root) . $relative;
  }

  if ($attachment_root !== '') {
    $candidates[] = trailingslashit($attachment_root) . $relative;
    $candidates[] = trailingslashit($attachment_root) . basename($relative);

    if (strpos($relative, 'message-attachments/') === 0) {
      $candidates[] = trailingslashit(dirname($attachment_root)) . $relative;
    }
  }

  foreach ($candidates as $candidate) {
    if ($candidate !== '' && file_exists($candidate)) {
      return $candidate;
    }
  }

  if ($uploads_root !== '') {
    return trailingslashit($uploads_root) . $relative;
  }

  return '';
}

function casanova_portal_messages_delete_attachment_files(array $paths): void {
  foreach ($paths as $path) {
    $path = (string) $path;
    if ($path !== '' && file_exists($path)) {
      @unlink($path);
    }
  }
}

function casanova_portal_messages_delete_message_record(int $message_id): void {
  global $wpdb;

  $message_id = (int) $message_id;
  if ($message_id <= 0) return;

  $files_table = casanova_portal_message_files_table();
  $attachments = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$files_table} WHERE message_id = %d", $message_id)
  );
  $paths = [];
  if (is_array($attachments)) {
    foreach ($attachments as $attachment) {
      if (!is_object($attachment)) continue;
      $paths[] = casanova_portal_messages_attachment_absolute_path($attachment);
    }
  }
  casanova_portal_messages_delete_attachment_files($paths);
  $wpdb->delete($files_table, ['message_id' => $message_id], ['%d']);

  $items_table = casanova_portal_message_items_table();
  $wpdb->delete($items_table, ['id' => $message_id], ['%d']);
}

function casanova_portal_messages_store_attachments(int $thread_id, int $message_id, array $validated_files): array|WP_Error {
  global $wpdb;

  if (empty($validated_files)) return [];

  $root_dir = casanova_portal_messages_ensure_attachment_dir();
  if ($root_dir === '') {
    casanova_portal_messages_log('attachment directory unavailable', [
      'thread_id' => $thread_id,
      'message_id' => $message_id,
    ], 'error');
    return new WP_Error('message_attachment_dir', __('No se pudo preparar la carpeta de adjuntos.', 'casanova-portal'));
  }

  $subdir = gmdate('Y/m');
  $target_dir = trailingslashit($root_dir) . $subdir;
  if (!is_dir($target_dir) && !wp_mkdir_p($target_dir)) {
    casanova_portal_messages_log('attachment target directory create failed', [
      'thread_id' => $thread_id,
      'message_id' => $message_id,
      'target_dir' => $target_dir,
    ], 'error');
    return new WP_Error('message_attachment_dir', __('No se pudo crear la carpeta del adjunto.', 'casanova-portal'));
  }

  $table = casanova_portal_message_files_table();
  $stored_paths = [];
  $stored_ids = [];
  $attachments = [];

  foreach ($validated_files as $index => $file) {
    $extension_map = casanova_portal_messages_attachment_extensions();
    $extension = (string) ($extension_map[$file['mime_type']] ?? $file['ext'] ?? 'bin');
    $stored_name = 'msg-' . $message_id . '-' . ($index + 1) . '-' . wp_generate_uuid4() . '.' . $extension;
    $absolute_path = trailingslashit($target_dir) . $stored_name;

    if (!@move_uploaded_file((string) $file['tmp_name'], $absolute_path)) {
      casanova_portal_messages_delete_attachment_files($stored_paths);
      foreach ($stored_ids as $stored_id) {
        $wpdb->delete($table, ['id' => (int) $stored_id], ['%d']);
      }
      casanova_portal_messages_log('attachment move failed', [
        'thread_id' => $thread_id,
        'message_id' => $message_id,
        'target_path' => $absolute_path,
        'original_name' => (string) ($file['name'] ?? ''),
      ], 'error');
      return new WP_Error('message_attachment_move', __('No se pudo guardar uno de los adjuntos.', 'casanova-portal'));
    }

    $stored_paths[] = $absolute_path;
    $relative_path = 'casanova-portal/message-attachments/' . trim(str_replace('\\', '/', $subdir), '/') . '/' . $stored_name;

    $inserted = $wpdb->insert(
      $table,
      [
        'message_id' => $message_id,
        'thread_id' => $thread_id,
        'original_name' => sanitize_file_name((string) $file['name']),
        'stored_name' => $stored_name,
        'relative_path' => $relative_path,
        'mime_type' => (string) $file['mime_type'],
        'file_size' => (int) $file['size'],
        'created_at' => current_time('mysql'),
      ],
      ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
    );

    if ($inserted === false) {
      casanova_portal_messages_delete_attachment_files($stored_paths);
      foreach ($stored_ids as $stored_id) {
        $wpdb->delete($table, ['id' => (int) $stored_id], ['%d']);
      }
      casanova_portal_messages_log('attachment database insert failed', [
        'thread_id' => $thread_id,
        'message_id' => $message_id,
        'original_name' => (string) ($file['name'] ?? ''),
        'relative_path' => $relative_path,
      ], 'error');
      return new WP_Error('message_attachment_db', __('No se pudo registrar uno de los adjuntos.', 'casanova-portal'));
    }

    $stored_id = (int) $wpdb->insert_id;
    $stored_ids[] = $stored_id;
    $row = casanova_portal_messages_get_attachment($stored_id);
    if ($row) {
      $attachments[] = casanova_portal_messages_attachment_row_to_array($row);
    }
  }

  return $attachments;
}

function casanova_portal_messages_get_thread(int $idCliente, int $idExpediente): ?object {
  global $wpdb;

  $idCliente = (int) $idCliente;
  $idExpediente = (int) $idExpediente;
  if ($idCliente <= 0 || $idExpediente <= 0) return null;

  $table = casanova_portal_message_threads_table();
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE id_cliente = %d AND id_expediente = %d LIMIT 1",
    $idCliente,
    $idExpediente
  );

  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_get_thread_by_id(int $thread_id): ?object {
  global $wpdb;

  $thread_id = (int) $thread_id;
  if ($thread_id <= 0) return null;

  $table = casanova_portal_message_threads_table();
  $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $thread_id);
  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_resolve_client_id_for_expediente(int $idExpediente): int {
  global $wpdb;

  $idExpediente = (int) $idExpediente;
  if ($idExpediente <= 0) return 0;

  $table = casanova_portal_message_threads_table();
  $sql = $wpdb->prepare(
    "SELECT id_cliente FROM {$table} WHERE id_expediente = %d ORDER BY last_message_at DESC, id DESC LIMIT 1",
    $idExpediente
  );
  $idCliente = (int) $wpdb->get_var($sql);
  if ($idCliente > 0) return $idCliente;

  if (function_exists('casanova_giav_expediente_get')) {
    $expediente = casanova_giav_expediente_get($idExpediente);
    if (is_object($expediente)) {
      $idCliente = (int) ($expediente->IdCliente ?? 0);
      if ($idCliente > 0) return $idCliente;
    }
  }

  return 0;
}

function casanova_portal_messages_get_or_create_thread(int $idCliente, int $idExpediente, array $args = []) {
  global $wpdb;

  $idCliente = (int) $idCliente;
  $idExpediente = (int) $idExpediente;
  if ($idCliente <= 0 || $idExpediente <= 0) {
    return new WP_Error('messages_thread_invalid', __('No se pudo crear la conversación.', 'casanova-portal'));
  }

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if ($thread) return $thread;

  $table = casanova_portal_message_threads_table();
  $inserted = $wpdb->insert(
    $table,
    [
      'id_cliente' => $idCliente,
      'id_expediente' => $idExpediente,
      'status' => sanitize_key((string) ($args['status'] ?? 'open')) ?: 'open',
      'subject' => sanitize_text_field((string) ($args['subject'] ?? '')),
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ],
    ['%d', '%d', '%s', '%s', '%s', '%s']
  );

  if ($inserted === false) {
    $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
    if ($thread) return $thread;
    return new WP_Error('messages_thread_create_failed', __('No se pudo iniciar la conversación.', 'casanova-portal'));
  }

  $thread = casanova_portal_messages_get_thread_by_id((int) $wpdb->insert_id);
  if ($thread) return $thread;

  return new WP_Error('messages_thread_missing', __('No se pudo recuperar la conversación.', 'casanova-portal'));
}

function casanova_portal_messages_get_message(int $message_id): ?object {
  global $wpdb;

  $message_id = (int) $message_id;
  if ($message_id <= 0) return null;

  $table = casanova_portal_message_items_table();
  $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $message_id);
  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_update_thread_from_message(int $thread_id, object $message): void {
  global $wpdb;

  $thread_id = (int) $thread_id;
  if ($thread_id <= 0 || !is_object($message)) return;

  $table = casanova_portal_message_threads_table();
  $body = (string) ($message->body ?? '');
  $preview = casanova_portal_messages_preview($body, 180);

  $wpdb->update(
    $table,
    [
      'last_message_at' => (string) ($message->created_at ?? current_time('mysql')),
      'last_message_direction' => (string) ($message->direction ?? 'agency'),
      'last_message_origin' => (string) ($message->origin ?? 'portal'),
      'last_message_preview' => $preview,
      'updated_at' => current_time('mysql'),
    ],
    ['id' => $thread_id],
    ['%s', '%s', '%s', '%s', '%s'],
    ['%d']
  );
}

function casanova_portal_messages_get_read_row(int $thread_id, string $role = 'client', int $user_id = 0): ?object {
  global $wpdb;

  $thread_id = (int) $thread_id;
  $user_id = (int) $user_id;
  $role = casanova_portal_messages_normalize_role($role);
  if ($thread_id <= 0) return null;

  $table = casanova_portal_message_reads_table();
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE thread_id = %d AND user_id = %d AND role = %s LIMIT 1",
    $thread_id,
    $user_id,
    $role
  );
  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_last_read_id(int $thread_id, string $role = 'client', int $user_id = 0): int {
  $row = casanova_portal_messages_get_read_row($thread_id, $role, $user_id);
  return $row ? (int) ($row->last_read_message_id ?? 0) : 0;
}

function casanova_portal_messages_mark_read(int $thread_id, string $role = 'client', int $user_id = 0, int $message_id = 0): void {
  global $wpdb;

  $thread_id = (int) $thread_id;
  $user_id = (int) $user_id;
  $message_id = (int) $message_id;
  $role = casanova_portal_messages_normalize_role($role);
  if ($thread_id <= 0 || $message_id <= 0) return;

  $table = casanova_portal_message_reads_table();
  $existing = casanova_portal_messages_get_read_row($thread_id, $role, $user_id);
  $previous = $existing ? (int) ($existing->last_read_message_id ?? 0) : 0;
  if ($message_id <= $previous) return;

  $payload = [
    'thread_id' => $thread_id,
    'user_id' => $user_id,
    'role' => $role,
    'last_read_message_id' => $message_id,
    'last_read_at' => current_time('mysql'),
  ];

  if ($existing) {
    $wpdb->update(
      $table,
      [
        'last_read_message_id' => $message_id,
        'last_read_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ],
      ['id' => (int) $existing->id],
      ['%d', '%s', '%s'],
      ['%d']
    );
  } else {
    $payload['created_at'] = current_time('mysql');
    $payload['updated_at'] = current_time('mysql');
    $wpdb->insert(
      $table,
      $payload,
      ['%d', '%d', '%s', '%d', '%s', '%s', '%s']
    );
  }

  if ($role === 'client' && $user_id > 0) {
    casanova_portal_messages_bump_user_version($user_id);
  }
}

function casanova_portal_messages_get_local_items(int $idCliente, int $idExpediente, int $limit = 100): array {
  global $wpdb;

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return [];

  $limit = max(1, min(200, (int) $limit));
  $table = casanova_portal_message_items_table();
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT %d",
    (int) $thread->id,
    $limit
  );

  $rows = $wpdb->get_results($sql);
  if (!is_array($rows)) return [];
  $rows = array_reverse($rows);

  $message_ids = [];
  foreach ($rows as $row) {
    if (!is_object($row)) continue;
    $message_ids[] = (int) ($row->id ?? 0);
  }
  $attachments_map = casanova_portal_messages_get_attachments_for_message_ids($message_ids);

  $items = [];
  foreach ($rows as $row) {
    if (!is_object($row)) continue;
    $message_id = (int) ($row->id ?? 0);
    $items[] = casanova_portal_messages_table_row_to_item(
      $row,
      $idExpediente,
      (array) ($attachments_map[$message_id] ?? [])
    );
  }

  return $items;
}

function casanova_portal_messages_get_latest_local_message(int $thread_id): ?object {
  global $wpdb;

  $thread_id = (int) $thread_id;
  if ($thread_id <= 0) return null;

  $table = casanova_portal_message_items_table();
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT 1",
    $thread_id
  );

  $row = $wpdb->get_row($sql);
  return is_object($row) ? $row : null;
}

function casanova_portal_messages_local_unread_for_user(int $user_id, int $idCliente, int $idExpediente): int {
  global $wpdb;

  $user_id = (int) $user_id;
  if ($user_id <= 0) return 0;

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return 0;

  $last_read_id = casanova_portal_messages_last_read_id((int) $thread->id, 'client', $user_id);
  $table = casanova_portal_message_items_table();
  $sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE thread_id = %d AND id > %d AND direction IN ('agency', 'system')",
    (int) $thread->id,
    $last_read_id
  );

  return (int) $wpdb->get_var($sql);
}

function casanova_portal_messages_local_unread_for_agency(int $idCliente, int $idExpediente): int {
  global $wpdb;

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return 0;

  $last_read_id = casanova_portal_messages_last_read_id((int) $thread->id, 'agency', 0);
  $table = casanova_portal_message_items_table();
  $sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE thread_id = %d AND id > %d AND direction = 'client'",
    (int) $thread->id,
    $last_read_id
  );

  return (int) $wpdb->get_var($sql);
}

function casanova_portal_messages_local_summary_for_expediente(int $user_id, int $idCliente, int $idExpediente): array {
  $summary = [
    'unread' => 0,
    'last_message_at' => null,
    'last_message_ts' => 0,
    'author' => '',
    'direction' => '',
    'content' => '',
    'snippet' => '',
  ];

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return $summary;

  $latest = casanova_portal_messages_get_latest_local_message((int) $thread->id);
  if (!$latest) return $summary;

  $ts = strtotime((string) ($latest->created_at ?? '')) ?: 0;
  $body = trim((string) ($latest->body ?? ''));

  $summary['unread'] = casanova_portal_messages_local_unread_for_user($user_id, $idCliente, $idExpediente);
  $summary['last_message_at'] = casanova_portal_messages_mysql_to_iso((string) ($latest->created_at ?? ''));
  $summary['last_message_ts'] = $ts;
  $summary['author'] = (string) ($latest->author_name ?? '');
  $summary['direction'] = (string) ($latest->direction ?? 'agency');
  $summary['content'] = $body;
  $summary['snippet'] = casanova_portal_messages_preview($body, 140);

  return $summary;
}

function casanova_portal_messages_mark_client_read_for_expediente(int $user_id, int $idCliente, int $idExpediente): void {
  $user_id = (int) $user_id;
  $idCliente = (int) $idCliente;
  $idExpediente = (int) $idExpediente;
  if ($user_id <= 0 || $idCliente <= 0 || $idExpediente <= 0) return;

  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return;

  $latest = casanova_portal_messages_get_latest_local_message((int) $thread->id);
  if (!$latest) return;

  casanova_portal_messages_mark_read((int) $thread->id, 'client', $user_id, (int) ($latest->id ?? 0));
}

function casanova_portal_messages_mark_agency_read_for_expediente(int $idCliente, int $idExpediente): void {
  $thread = casanova_portal_messages_get_thread($idCliente, $idExpediente);
  if (!$thread) return;

  $latest = casanova_portal_messages_get_latest_local_message((int) $thread->id);
  if (!$latest) return;

  casanova_portal_messages_mark_read((int) $thread->id, 'agency', 0, (int) ($latest->id ?? 0));
}

function casanova_portal_messages_build_giav_items(int $idExpediente, int $limit = 100): array {
  if ($idExpediente <= 0 || !function_exists('casanova_giav_comments_por_expediente')) {
    return [];
  }

  $comments = casanova_giav_comments_por_expediente($idExpediente, max(1, min(200, (int) $limit)), 365);
  if (is_wp_error($comments) || !is_array($comments)) return [];

  $items = [];
  foreach ($comments as $comment) {
    if (!is_object($comment)) continue;

    $body = trim((string) ($comment->Body ?? ''));
    $body = $body !== '' ? wp_strip_all_tags($body) : '';
    $raw_id = (string) ($comment->Id ?? $comment->ID ?? $comment->IdComment ?? '');
    $date = !empty($comment->CreationDate) ? (string) $comment->CreationDate : null;

    $items[] = [
      'id' => $raw_id !== '' ? 'giav:' . $raw_id : 'giav:' . sha1($date . '|' . $body),
      'date' => $date,
      'author' => (string) ($comment->Author ?? $comment->Usuario ?? 'Casanova Golf'),
      'direction' => 'agency',
      'content' => $body,
      'expediente_id' => $idExpediente,
      'origin' => 'giav',
      'attachments' => [],
    ];
  }

  return $items;
}

function casanova_portal_messages_merge_items(array $giav_items, array $local_items, int $limit = 100): array {
  $merged = array_merge($giav_items, $local_items);

  usort($merged, function($left, $right) {
    $left_ts = !empty($left['date']) ? (strtotime((string) $left['date']) ?: 0) : 0;
    $right_ts = !empty($right['date']) ? (strtotime((string) $right['date']) ?: 0) : 0;
    if ($left_ts === $right_ts) {
      return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
    }
    return $left_ts <=> $right_ts;
  });

  $limit = max(1, min(200, (int) $limit));
  if (count($merged) > $limit) {
    $merged = array_slice($merged, -$limit);
  }

  return array_values($merged);
}

function casanova_portal_messages_timeline_items(int $idCliente, int $idExpediente, int $limit = 100): array {
  $giav_items = casanova_portal_messages_build_giav_items($idExpediente, $limit);
  $local_items = casanova_portal_messages_get_local_items($idCliente, $idExpediente, $limit);
  return casanova_portal_messages_merge_items($giav_items, $local_items, $limit);
}

function casanova_portal_messages_create_message(array $args): array|WP_Error {
  global $wpdb;

  $thread_id = (int) ($args['thread_id'] ?? 0);
  $idCliente = (int) ($args['id_cliente'] ?? 0);
  $idExpediente = (int) ($args['id_expediente'] ?? 0);
  $user_id = (int) ($args['user_id'] ?? 0);
  $direction = casanova_portal_messages_normalize_direction((string) ($args['direction'] ?? 'agency'));
  $origin = casanova_portal_messages_normalize_origin((string) ($args['origin'] ?? 'portal'));
  $body = casanova_portal_messages_sanitize_body((string) ($args['body'] ?? ''));
  $attachments_input = is_array($args['attachments_files'] ?? null) ? $args['attachments_files'] : [];

  if (casanova_portal_messages_body_length($body) > casanova_portal_messages_body_max_length()) {
    return new WP_Error(
      'message_too_long',
      sprintf(
        __('El mensaje no puede superar %d caracteres.', 'casanova-portal'),
        casanova_portal_messages_body_max_length()
      )
    );
  }

  $validated_attachments = casanova_portal_messages_validate_uploaded_files($attachments_input);
  if (is_wp_error($validated_attachments)) {
    return $validated_attachments;
  }
  if ($body === '' && empty($validated_attachments)) {
    return new WP_Error('message_empty', __('Escribe un mensaje o adjunta un archivo antes de enviarlo.', 'casanova-portal'));
  }

  if ($thread_id > 0) {
    $thread = casanova_portal_messages_get_thread_by_id($thread_id);
    if (!$thread) {
      return new WP_Error('message_thread_missing', __('La conversación no existe.', 'casanova-portal'));
    }
    $idCliente = (int) ($thread->id_cliente ?? $idCliente);
    $idExpediente = (int) ($thread->id_expediente ?? $idExpediente);
  } else {
    if ($idCliente <= 0 && $idExpediente > 0) {
      $idCliente = casanova_portal_messages_resolve_client_id_for_expediente($idExpediente);
    }
    $thread = casanova_portal_messages_get_or_create_thread($idCliente, $idExpediente);
    if (is_wp_error($thread)) return $thread;
  }

  if (!is_object($thread)) {
    return new WP_Error('message_thread_invalid', __('No se pudo preparar la conversación.', 'casanova-portal'));
  }

  $author_name = trim((string) ($args['author_name'] ?? ''));
  if ($author_name === '') {
    if ($user_id > 0) {
      $user = get_userdata($user_id);
      if ($user instanceof WP_User) {
        $author_name = trim((string) ($user->display_name ?: $user->user_login));
      }
    }
    if ($author_name === '') {
      $author_name = $direction === 'client' ? __('Cliente', 'casanova-portal') : 'Casanova Golf';
    }
  }

  $metadata = $args['metadata'] ?? null;
  if (is_array($metadata)) {
    $metadata = wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } elseif ($metadata !== null) {
    $metadata = (string) $metadata;
  }

  $table = casanova_portal_message_items_table();
  $created_at = current_time('mysql');
  $inserted = $wpdb->insert(
    $table,
    [
      'thread_id' => (int) $thread->id,
      'user_id' => $user_id,
      'direction' => $direction,
      'origin' => $origin,
      'author_name' => sanitize_text_field($author_name),
      'body' => $body,
      'metadata' => $metadata,
      'created_at' => $created_at,
    ],
    ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
  );

  if ($inserted === false) {
    casanova_portal_messages_log('message database insert failed', [
      'thread_id' => (int) ($thread->id ?? 0),
      'id_cliente' => $idCliente,
      'id_expediente' => $idExpediente,
      'direction' => $direction,
      'origin' => $origin,
      'user_id' => $user_id,
    ], 'error');
    return new WP_Error('message_create_failed', __('No se pudo guardar el mensaje.', 'casanova-portal'));
  }

  $message = casanova_portal_messages_get_message((int) $wpdb->insert_id);
  if (!$message) {
    casanova_portal_messages_log('message fetch after insert failed', [
      'message_id' => (int) $wpdb->insert_id,
      'thread_id' => (int) ($thread->id ?? 0),
      'id_expediente' => $idExpediente,
    ], 'error');
    return new WP_Error('message_missing', __('No se pudo recuperar el mensaje enviado.', 'casanova-portal'));
  }

  $attachments = casanova_portal_messages_store_attachments((int) $thread->id, (int) $message->id, $validated_attachments);
  if (is_wp_error($attachments)) {
    casanova_portal_messages_log('attachment store failed; rolling back message', [
      'message_id' => (int) ($message->id ?? 0),
      'thread_id' => (int) ($thread->id ?? 0),
      'id_expediente' => $idExpediente,
      'error_code' => $attachments->get_error_code(),
      'error_message' => $attachments->get_error_message(),
    ], 'error');
    casanova_portal_messages_delete_message_record((int) $message->id);
    return $attachments;
  }

  casanova_portal_messages_update_thread_from_message((int) $thread->id, $message);
  $thread = casanova_portal_messages_get_thread_by_id((int) $thread->id) ?: $thread;

  if ($direction === 'client' && $user_id > 0) {
    casanova_portal_messages_mark_read((int) $thread->id, 'client', $user_id, (int) $message->id);
  } else {
    casanova_portal_messages_mark_read((int) $thread->id, 'agency', 0, (int) $message->id);
    casanova_portal_messages_bump_client_users_version((int) ($thread->id_cliente ?? 0));
  }

  do_action('casanova_portal_message_created', $message, $thread);

  return [
    'thread' => $thread,
    'message' => $message,
    'attachments' => $attachments,
  ];
}

function casanova_portal_messages_get_attachment_with_context(int $attachment_id): ?array {
  global $wpdb;

  $attachment = casanova_portal_messages_get_attachment($attachment_id);
  if (!$attachment) return null;

  $message = casanova_portal_messages_get_message((int) ($attachment->message_id ?? 0));
  $thread = $message ? casanova_portal_messages_get_thread_by_id((int) ($message->thread_id ?? 0)) : null;

  if (!$message || !$thread) return null;

  return [
    'attachment' => $attachment,
    'message' => $message,
    'thread' => $thread,
  ];
}

function casanova_portal_messages_user_can_access_attachment(int $user_id, int $attachment_id): bool {
  $context = casanova_portal_messages_get_attachment_with_context($attachment_id);
  if (!is_array($context)) return false;

  $thread = $context['thread'] ?? null;
  if (!is_object($thread)) return false;

  if (current_user_can('manage_options')) {
    return true;
  }

  $user_id = function_exists('casanova_portal_resolve_user_id')
    ? casanova_portal_resolve_user_id($user_id)
    : $user_id;
  if ($user_id <= 0) return false;

  $idExpediente = (int) ($thread->id_expediente ?? 0);
  if ($idExpediente <= 0) return false;

  if (function_exists('casanova_user_can_access_expediente')) {
    return casanova_user_can_access_expediente($user_id, $idExpediente);
  }

  $idCliente = function_exists('casanova_portal_get_effective_client_id')
    ? casanova_portal_get_effective_client_id($user_id)
    : (int) get_user_meta($user_id, 'casanova_idcliente', true);

  return $idCliente > 0 && $idCliente === (int) ($thread->id_cliente ?? 0);
}

function casanova_portal_messages_admin_url(array $args = []): string {
  return add_query_arg($args, admin_url('admin.php?page=casanova-payments-messages'));
}

function casanova_portal_messages_thread_portal_url(int $idExpediente): string {
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');
  return add_query_arg([
    'view' => 'trip',
    'expediente' => (int) $idExpediente,
    'tab' => 'messages',
  ], $base);
}

function casanova_portal_messages_expediente_label(int $idExpediente): string {
  $idExpediente = (int) $idExpediente;
  if ($idExpediente <= 0) return __('Viaje', 'casanova-portal');

  if (function_exists('casanova_giav_expediente_get')) {
    $exp = casanova_giav_expediente_get($idExpediente);
    if (is_object($exp) && function_exists('casanova_portal_expediente_label_from_obj')) {
      $label = (string) casanova_portal_expediente_label_from_obj($exp);
      if ($label !== '') return $label;
    }
  }

  return sprintf(__('Expediente %s', 'casanova-portal'), (string) $idExpediente);
}

function casanova_portal_messages_email_excerpt(object $message): string {
  $body = trim((string) ($message->body ?? ''));
  if ($body !== '') {
    return '<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #d7dfd0;background:#f8faf6;">' . nl2br(esc_html($body)) . '</blockquote>';
  }

  $attachments_map = casanova_portal_messages_get_attachments_for_message_ids([(int) ($message->id ?? 0)]);
  $attachments = (array) ($attachments_map[(int) ($message->id ?? 0)] ?? []);
  if (empty($attachments)) {
    return '';
  }

  $count = count($attachments);
  return '<p>' . esc_html(
    sprintf(
      _n(
        'El mensaje incluye %d adjunto.',
        'El mensaje incluye %d adjuntos.',
        $count,
        'casanova-portal'
      ),
      $count
    )
  ) . '</p>';
}

function casanova_portal_messages_notify_agency(object $thread, object $message): bool {
  if (!function_exists('casanova_mail_send')) return false;

  $agency = function_exists('casanova_portal_agency_profile') ? casanova_portal_agency_profile() : [];
  $to = sanitize_email((string) ($agency['email'] ?? get_option('admin_email')));
  if (!$to || !is_email($to)) return false;

  $idExpediente = (int) ($thread->id_expediente ?? 0);
  $portal_url = casanova_portal_messages_admin_url(['expediente' => $idExpediente]);
  $subject = sprintf(
    __('Nuevo mensaje del cliente · %s', 'casanova-portal'),
    casanova_portal_messages_expediente_label($idExpediente)
  );

  $html  = '<p><strong>' . esc_html((string) ($message->author_name ?? __('Cliente', 'casanova-portal'))) . '</strong> ';
  $html .= esc_html__('ha enviado un mensaje desde el portal de cliente.', 'casanova-portal') . '</p>';
  $html .= casanova_portal_messages_email_excerpt($message);
  $html .= '<p><a href="' . esc_url($portal_url) . '">' . esc_html__('Abrir conversación en el admin', 'casanova-portal') . '</a></p>';

  return (bool) casanova_mail_send($to, $subject, $html);
}

function casanova_portal_messages_notify_client(object $thread, object $message): int {
  if (!function_exists('casanova_mail_send')) return 0;

  $idCliente = (int) ($thread->id_cliente ?? 0);
  $user_ids = casanova_portal_messages_client_user_ids($idCliente);
  if (empty($user_ids)) return 0;

  $portal_url = casanova_portal_messages_thread_portal_url((int) ($thread->id_expediente ?? 0));
  $subject = sprintf(
    __('Nuevo mensaje sobre tu viaje · %s', 'casanova-portal'),
    casanova_portal_messages_expediente_label((int) ($thread->id_expediente ?? 0))
  );

  $html  = '<p>' . esc_html__('Tienes un nuevo mensaje de Casanova Golf en tu portal de cliente.', 'casanova-portal') . '</p>';
  $html .= casanova_portal_messages_email_excerpt($message);
  $html .= '<p><a href="' . esc_url($portal_url) . '">' . esc_html__('Abrir conversación en el portal', 'casanova-portal') . '</a></p>';

  $sent = 0;
  foreach ($user_ids as $user_id) {
    $user = get_userdata((int) $user_id);
    if (!$user instanceof WP_User || !is_email($user->user_email)) continue;
    if (casanova_mail_send($user->user_email, $subject, $html)) {
      $sent++;
    }
  }

  return $sent;
}

add_action('casanova_portal_message_created', function($message, $thread): void {
  if (!is_object($message) || !is_object($thread)) return;

  $direction = (string) ($message->direction ?? '');
  if ($direction === 'client') {
    casanova_portal_messages_notify_agency($thread, $message);
    return;
  }

  if ($direction === 'agency') {
    casanova_portal_messages_notify_client($thread, $message);
  }
}, 10, 2);
