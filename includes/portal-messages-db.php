<?php
if (!defined('ABSPATH')) exit;

function casanova_portal_message_threads_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_message_threads';
}

function casanova_portal_message_items_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_message_items';
}

function casanova_portal_message_reads_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_message_reads';
}

function casanova_portal_message_files_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_message_files';
}

function casanova_portal_messages_install(): void {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $threads_table = casanova_portal_message_threads_table();
  $items_table = casanova_portal_message_items_table();
  $reads_table = casanova_portal_message_reads_table();
  $files_table = casanova_portal_message_files_table();
  $charset_collate = $wpdb->get_charset_collate();

  $sql_threads = "CREATE TABLE {$threads_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    subject VARCHAR(190) NULL,
    last_message_at DATETIME NULL,
    last_message_direction VARCHAR(20) NULL,
    last_message_origin VARCHAR(20) NULL,
    last_message_preview VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cliente_expediente (id_cliente, id_expediente),
    KEY idx_expediente (id_expediente),
    KEY idx_status (status),
    KEY idx_last_message_at (last_message_at)
  ) {$charset_collate};";

  dbDelta($sql_threads);

  $sql_items = "CREATE TABLE {$items_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    direction VARCHAR(20) NOT NULL DEFAULT 'agency',
    origin VARCHAR(20) NOT NULL DEFAULT 'portal',
    author_name VARCHAR(190) NOT NULL DEFAULT '',
    body LONGTEXT NOT NULL,
    metadata LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread_id (thread_id),
    KEY idx_thread_created (thread_id, created_at),
    KEY idx_direction (direction),
    KEY idx_origin (origin)
  ) {$charset_collate};";

  dbDelta($sql_items);

  $sql_reads = "CREATE TABLE {$reads_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    role VARCHAR(20) NOT NULL DEFAULT 'client',
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_thread_reader (thread_id, user_id, role),
    KEY idx_role (role),
    KEY idx_last_read_message_id (last_read_message_id)
  ) {$charset_collate};";

  dbDelta($sql_reads);

  $sql_files = "CREATE TABLE {$files_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    relative_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_message_id (message_id),
    KEY idx_thread_id (thread_id)
  ) {$charset_collate};";

  dbDelta($sql_files);
}
