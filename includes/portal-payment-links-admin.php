<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_payment_links_admin_resolve_pending_amount')) {
  function casanova_payment_links_admin_resolve_pending_amount(int $idExpediente) {
    if ($idExpediente <= 0) {
      return new WP_Error('expediente', __('Expediente invalido.', 'casanova-portal'));
    }

    if (!function_exists('casanova_giav_expediente_get') || !function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
      return new WP_Error('giav_missing', __('No se pudo consultar GIAV para calcular el importe.', 'casanova-portal'));
    }

    $exp = casanova_giav_expediente_get($idExpediente);
    if (is_wp_error($exp)) {
      return $exp;
    }
    if (!is_object($exp)) {
      return new WP_Error('expediente_not_found', __('No se encontro el expediente en GIAV.', 'casanova-portal'));
    }

    $idCliente = (int)($exp->IdCliente ?? 0);
    if ($idCliente <= 0) {
      return new WP_Error('cliente', __('No se pudo resolver el cliente del expediente.', 'casanova-portal'));
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (is_wp_error($reservas)) {
      return $reservas;
    }
    if (!is_array($reservas) || empty($reservas)) {
      return new WP_Error('reservas', __('No se pudieron cargar las reservas del expediente.', 'casanova-portal'));
    }

    $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
    if (is_wp_error($calc) || !is_array($calc)) {
      return new WP_Error('calc', __('No se pudo calcular el pendiente del expediente.', 'casanova-portal'));
    }

    $pending = round((float)($calc['pendiente_real'] ?? 0), 2);
    if ($pending <= 0.01) {
      return new WP_Error('no_pending', __('El expediente no tiene importe pendiente en GIAV.', 'casanova-portal'));
    }

    return $pending;
  }
}

if (!function_exists('casanova_payment_links_admin_base_url')) {
  function casanova_payment_links_admin_base_url(): string {
    if (function_exists('casanova_portal_admin_url')) {
      return casanova_portal_admin_url('links');
    }

    return admin_url('admin.php?page=casanova-payments-links');
  }
}

add_action('admin_post_casanova_create_payment_link', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_payment_link');

  $idExpediente = isset($_POST['id_expediente']) ? absint($_POST['id_expediente']) : 0;
  $amount_raw = isset($_POST['amount_authorized']) ? (string)$_POST['amount_authorized'] : '';
  $amount_raw = trim($amount_raw);
  $amount = $amount_raw !== '' ? (float) str_replace(',', '.', $amount_raw) : 0.0;
  $currency = 'EUR';
  $scope = 'individual_link';
  $amount_source = 'manual';

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

  if ($idExpediente <= 0) {
    $base = casanova_payment_links_admin_base_url();
    $url = add_query_arg(['link_error' => 'expediente'], $base);
    wp_safe_redirect($url);
    exit;
  }

  $pending_amount = null;
  if (function_exists('casanova_payment_links_admin_resolve_pending_amount')) {
    $pending_amount = casanova_payment_links_admin_resolve_pending_amount($idExpediente);
    if ($amount <= 0) {
      if (is_wp_error($pending_amount)) {
        $base = casanova_payment_links_admin_base_url();
        $url = add_query_arg(['link_error' => 'giav_amount'], $base);
        wp_safe_redirect($url);
        exit;
      }
      $amount = (float)$pending_amount;
      $amount_source = 'giav_pending';
    }
  }

  if ($amount <= 0) {
    $base = casanova_payment_links_admin_base_url();
    $url = add_query_arg(['link_error' => 'amount'], $base);
    wp_safe_redirect($url);
    exit;
  }

  if (!is_wp_error($pending_amount) && $pending_amount !== null && $amount - (float)$pending_amount > 0.01) {
    $base = casanova_payment_links_admin_base_url();
    $url = add_query_arg(['link_error' => 'amount_exceeds_pending'], $base);
    wp_safe_redirect($url);
    exit;
  }

  if (!function_exists('casanova_payment_link_create')) {
    $base = casanova_payment_links_admin_base_url();
    $url = add_query_arg(['link_error' => 'missing'], $base);
    wp_safe_redirect($url);
    exit;
  }

  $link = casanova_payment_link_create([
    'id_expediente' => $idExpediente,
    'scope' => $scope,
    'amount_authorized' => $amount,
    'currency' => $currency,
    'status' => 'active',
    'expires_at' => $expires_at,
    'created_by' => 'admin',
    'metadata' => [
      'source' => 'admin_screen',
      'link_kind' => 'individual',
      'amount_source' => $amount_source,
      'giav_pending_amount' => (!is_wp_error($pending_amount) && $pending_amount !== null) ? (float)$pending_amount : null,
      'created_by_user' => (int) get_current_user_id(),
    ],
  ]);

  $base = casanova_payment_links_admin_base_url();
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



add_action('admin_post_casanova_delete_payment_links', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }
  check_admin_referer('casanova_delete_payment_links');

  $ids = [];
  if (isset($_REQUEST['id'])) {
    $ids[] = absint($_REQUEST['id']);
  }
  if (!empty($_POST['link_ids']) && is_array($_POST['link_ids'])) {
    foreach ($_POST['link_ids'] as $v) $ids[] = absint($v);
  }
  $ids = array_values(array_filter(array_unique($ids)));

  $base = casanova_payment_links_admin_base_url();
  if (empty($ids)) {
    wp_safe_redirect(add_query_arg(['link_deleted' => '0'], $base));
    exit;
  }

  if (!function_exists('casanova_payment_links_table')) {
    wp_safe_redirect(add_query_arg(['link_deleted' => '0'], $base));
    exit;
  }

  global $wpdb;
  $table = casanova_payment_links_table();
  $placeholders = implode(',', array_fill(0, count($ids), '%d'));
  $sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
  $wpdb->query($wpdb->prepare($sql, ...$ids));

  wp_safe_redirect(add_query_arg(['link_deleted' => (string)count($ids)], $base));
  exit;
});
