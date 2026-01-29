<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_casanova_create_group_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_group_token');

  $idExpediente = isset($_POST['group_id_expediente']) ? absint($_POST['group_id_expediente']) : 0;
  $idReservaPQ = isset($_POST['group_id_reserva_pq']) ? absint($_POST['group_id_reserva_pq']) : 0;
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

  $base = admin_url('options-general.php?page=casanova-payments&tab=links');
  if ($idExpediente <= 0) {
    wp_safe_redirect(add_query_arg(['group_error' => 'expediente'], $base));
    exit;
  }

  if (!function_exists('casanova_group_tokens_create')) {
    wp_safe_redirect(add_query_arg(['group_error' => 'missing'], $base));
    exit;
  }

  $token = casanova_group_tokens_create([
    'id_expediente' => $idExpediente,
    'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
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

