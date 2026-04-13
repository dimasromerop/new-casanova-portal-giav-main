<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_casanova_create_group_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_group_token');

  $expediente_ref = isset($_POST['group_id_expediente']) ? sanitize_text_field((string) $_POST['group_id_expediente']) : '';
  $idExpediente = 0;
  $idReservaPQ = isset($_POST['group_id_reserva_pq']) ? absint($_POST['group_id_reserva_pq']) : 0;
  $unit_total_raw = isset($_POST['group_unit_total']) ? (string)$_POST['group_unit_total'] : '';
  $unit_total = (float) str_replace(',', '.', preg_replace('/[^0-9\,\.]/', '', $unit_total_raw));
  $expires_at = null;
  $exp_raw = isset($_POST['group_expires_at']) ? sanitize_text_field((string)$_POST['group_expires_at']) : '';
  if ($exp_raw !== '') {
    try {
      $dt = new DateTimeImmutable($exp_raw, wp_timezone());
      $expires_at = $dt->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      $expires_at = null;
    }
  }

  $base = function_exists('casanova_payment_links_admin_base_url')
    ? casanova_payment_links_admin_base_url()
    : admin_url('admin.php?page=casanova-payments-links');
  if (!function_exists('casanova_payment_links_resolve_expediente_reference')) {
    wp_safe_redirect(add_query_arg(['group_error' => 'expediente'], $base));
    exit;
  }

  $resolved_expediente = casanova_payment_links_resolve_expediente_reference($expediente_ref);
  if (is_wp_error($resolved_expediente)) {
    $error_code = match ($resolved_expediente->get_error_code()) {
      'payment_link_ambiguous_expediente' => 'expediente_ambiguous',
      'payment_link_expediente_not_found',
      'payment_link_missing_expediente_lookup' => 'expediente_lookup',
      default => 'expediente',
    };
    wp_safe_redirect(add_query_arg(['group_error' => $error_code], $base));
    exit;
  }

  $idExpediente = (int) ($resolved_expediente['id'] ?? 0);
  if ($idExpediente <= 0) {
    wp_safe_redirect(add_query_arg(['group_error' => 'expediente_lookup'], $base));
    exit;
  }

  if ($unit_total <= 0.0) {
    wp_safe_redirect(add_query_arg(['group_error' => 'unit_total'], $base));
    exit;
  }

  if (!function_exists('casanova_group_tokens_create')) {
    wp_safe_redirect(add_query_arg(['group_error' => 'missing'], $base));
    exit;
  }

  $token = casanova_group_tokens_create([
    'id_expediente' => $idExpediente,
    'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
    'unit_total' => $unit_total,
    'status' => 'active',
    'expires_at' => $expires_at,
  ]);

  if (is_wp_error($token)) {
    wp_safe_redirect(add_query_arg(['group_error' => 'create'], $base));
    exit;
  }

  wp_safe_redirect(add_query_arg([
    'group_created' => '1',
    'group_token' => (string)($token->token ?? ''),
  ], $base));
  exit;
});
