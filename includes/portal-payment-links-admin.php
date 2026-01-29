<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_casanova_create_payment_link', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_payment_link');

  $idExpediente = isset($_POST['id_expediente']) ? absint($_POST['id_expediente']) : 0;
  $idReservaPQ = isset($_POST['id_reserva_pq']) ? absint($_POST['id_reserva_pq']) : 0;
  $idPasajero = isset($_POST['id_pasajero']) ? absint($_POST['id_pasajero']) : 0;
  $scope = isset($_POST['scope']) ? sanitize_key((string)$_POST['scope']) : 'group_total';
  $amount_raw = isset($_POST['amount_authorized']) ? (string)$_POST['amount_authorized'] : '';
  $amount = (float) str_replace(',', '.', $amount_raw);
  $currency = isset($_POST['currency']) ? strtoupper(sanitize_text_field((string)$_POST['currency'])) : 'EUR';
  if (strlen($currency) !== 3) $currency = 'EUR';

  $expires_at = null;
  $exp_raw = isset($_POST['expires_at']) ? sanitize_text_field((string)$_POST['expires_at']) : '';
  if ($exp_raw !== '') {
    try {
      $dt = new DateTimeImmutable($exp_raw, wp_timezone());
      $expires_at = $dt->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      $expires_at = null;
    }
  }

  $allowed_scopes = ['passenger_share','group_partial','group_total','custom_amount'];
  if (!in_array($scope, $allowed_scopes, true)) $scope = 'group_total';

  if ($scope === 'group_total') {
    $amount = 0.0;
  } elseif ($amount <= 0) {
    $base = admin_url('options-general.php?page=casanova-payments&tab=links');
    $url = add_query_arg(['link_error' => 'amount'], $base);
    wp_safe_redirect($url);
    exit;
  }

  if ($idExpediente <= 0) {
    $base = admin_url('options-general.php?page=casanova-payments&tab=links');
    $url = add_query_arg(['link_error' => 'expediente'], $base);
    wp_safe_redirect($url);
    exit;
  }

  if (!function_exists('casanova_payment_link_create')) {
    $base = admin_url('options-general.php?page=casanova-payments&tab=links');
    $url = add_query_arg(['link_error' => 'missing'], $base);
    wp_safe_redirect($url);
    exit;
  }

  $link = casanova_payment_link_create([
    'id_expediente' => $idExpediente,
    'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
    'scope' => $scope,
    'id_pasajero' => ($idPasajero > 0 ? $idPasajero : null),
    'amount_authorized' => $amount,
    'currency' => $currency,
    'status' => 'active',
    'expires_at' => $expires_at,
    'created_by' => 'admin',
    'metadata' => [
      'source' => 'admin_screen',
      'created_by_user' => (int) get_current_user_id(),
    ],
  ]);

  $base = admin_url('options-general.php?page=casanova-payments&tab=links');
  if (is_wp_error($link)) {
    $url = add_query_arg(['link_error' => 'create'], $base);
    wp_safe_redirect($url);
    exit;
  }

  $url = add_query_arg([
    'link_created' => '1',
    'token' => (string)($link->token ?? ''),
  ], $base);
  wp_safe_redirect($url);
  exit;
});

