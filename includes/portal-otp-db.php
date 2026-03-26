<?php

/**
 * OTP DB table for secure account linking (identifier -> GIAV customer).
 *
 * We keep OTPs server-side only (hashed), with rate limiting metadata.
 */

function casanova_portal_otp_table_name(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_portal_otps';
}

function casanova_portal_otp_install(): void {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $table = casanova_portal_otp_table_name();
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$table} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    lookup_type VARCHAR(20) NOT NULL DEFAULT 'dni',
    lookup_hash VARCHAR(128) NOT NULL DEFAULT '',
    dni_hash VARCHAR(128) NOT NULL,
    giav_customer_id BIGINT(20) UNSIGNED NOT NULL,
    email_masked VARCHAR(190) NULL,
    email_hash VARCHAR(128) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    sent_count INT(11) NOT NULL DEFAULT 1,
    verify_attempts INT(11) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_sent_at DATETIME NOT NULL,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    PRIMARY KEY  (id),
    KEY user_id (user_id),
    KEY lookup_key (lookup_type, lookup_hash),
    KEY dni_hash (dni_hash),
    KEY giav_customer_id (giav_customer_id),
    KEY status (status),
    KEY expires_at (expires_at)
  ) {$charset_collate};";

  dbDelta($sql);

  $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
  if (is_array($columns)) {
    if (in_array('lookup_hash', $columns, true) && in_array('dni_hash', $columns, true)) {
      $wpdb->query("UPDATE {$table} SET lookup_hash = dni_hash WHERE lookup_hash = '' AND dni_hash <> ''");
    }
    if (in_array('lookup_type', $columns, true)) {
      $wpdb->query("UPDATE {$table} SET lookup_type = 'dni' WHERE lookup_type = '' OR lookup_type IS NULL");
    }
  }
}
