<?php
if (!defined('ABSPATH')) exit;

function casanova_payments_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_payment_intents';
}

function casanova_payment_links_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_payment_links';
}

function casanova_group_slots_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_group_slots';
}

function casanova_charges_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_charges';
}

function casanova_group_pay_tokens_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_group_pay_tokens';
}

function casanova_payments_install(): void {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $table = casanova_payments_table();
  $links_table = casanova_payment_links_table();
  $slots_table = casanova_group_slots_table();
  $charges_table = casanova_charges_table();
  $group_tokens_table = casanova_group_pay_tokens_table();
  $charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE $table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_cliente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(32) NOT NULL DEFAULT 'created',
    provider VARCHAR(32) NOT NULL DEFAULT 'redsys',
    method VARCHAR(32) NOT NULL DEFAULT 'card',
    order_redsys VARCHAR(32) DEFAULT NULL,
    provider_payment_id VARCHAR(96) DEFAULT NULL,
    provider_reference VARCHAR(128) DEFAULT NULL,
    payload LONGTEXT NULL,
    mail_cobro_sent_at DATETIME NULL,
    mail_expediente_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
attempts INT NOT NULL DEFAULT 0,
last_check_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY idx_user (user_id),
    KEY idx_expediente (id_expediente),
    KEY idx_status (status),
    KEY idx_order (order_redsys),
    KEY idx_provider_payment (provider_payment_id),
    KEY idx_provider_reference (provider_reference)
) $charset_collate;";

  dbDelta($sql);

  $sql_links = "CREATE TABLE $links_table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(80) NOT NULL,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_reserva_pq BIGINT UNSIGNED NULL,
    scope VARCHAR(32) NOT NULL DEFAULT 'group_total',
    id_pasajero BIGINT UNSIGNED NULL,
    amount_authorized DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    created_by VARCHAR(32) NOT NULL DEFAULT 'admin',
    paid_at DATETIME NULL,
    giav_payment_id BIGINT UNSIGNED NULL,
    billing_dni VARCHAR(32) NULL,
    metadata LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY idx_expediente (id_expediente),
    KEY idx_reserva_pq (id_reserva_pq),
    KEY idx_status (status),
    KEY idx_expires (expires_at)
  ) $charset_collate;";

  dbDelta($sql_links);

  $sql_slots = "CREATE TABLE $slots_table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_reserva_pq BIGINT UNSIGNED NULL,
    slot_index INT NOT NULL DEFAULT 0,
    base_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    base_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    reserved_until DATETIME NULL,
    reserved_token VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expediente (id_expediente),
    KEY idx_reserva_pq (id_reserva_pq),
    KEY idx_status (status),
    KEY idx_reserved_until (reserved_until),
    KEY idx_reserved_token (reserved_token),
    UNIQUE KEY uq_slot (id_expediente, id_reserva_pq, slot_index)
  ) $charset_collate;";

  dbDelta($sql_slots);

  $sql_charges = "CREATE TABLE $charges_table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_reserva_pq BIGINT UNSIGNED NULL,
    slot_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL DEFAULT '',
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    type VARCHAR(32) DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expediente (id_expediente),
    KEY idx_reserva_pq (id_reserva_pq),
    KEY idx_slot (slot_id),
    KEY idx_status (status),
    KEY idx_type (type)
  ) $charset_collate;";

  dbDelta($sql_charges);

  $sql_group_tokens = "CREATE TABLE $group_tokens_table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(80) NOT NULL,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_reserva_pq BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY idx_expediente (id_expediente),
    KEY idx_reserva_pq (id_reserva_pq),
    KEY idx_status (status)
  ) $charset_collate;";

  dbDelta($sql_group_tokens);
}
