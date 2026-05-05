<?php
if (!defined('ABSPATH')) exit;

function casanova_group_pay_admin_amount(string $raw): float {
  $raw = trim($raw);
  if ($raw === '') return 0.0;
  $raw = preg_replace('/[^0-9,\.\-]/', '', $raw);
  if (!is_string($raw) || $raw === '') return 0.0;

  $last_comma = strrpos($raw, ',');
  $last_dot = strrpos($raw, '.');
  if ($last_comma !== false && $last_dot !== false) {
    $decimal_pos = max($last_comma, $last_dot);
    $int = preg_replace('/[^0-9]/', '', substr($raw, 0, $decimal_pos));
    $dec = preg_replace('/[^0-9]/', '', substr($raw, $decimal_pos + 1));
    $raw = (string)$int . '.' . (string)$dec;
  } elseif ($last_comma !== false) {
    $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
  } elseif ($last_dot !== false) {
    $after_dot = substr($raw, $last_dot + 1);
    if (strlen($after_dot) === 3 && preg_match('/^\d{3}$/', $after_dot)) {
      $raw = str_replace('.', '', $raw);
    }
  }

  return round(max(0.0, (float)$raw), 2);
}

function casanova_group_pay_admin_collect_concepts(): array {
  $labels = isset($_POST['group_concept_label']) && is_array($_POST['group_concept_label'])
    ? $_POST['group_concept_label']
    : [];
  $amounts = isset($_POST['group_concept_amount']) && is_array($_POST['group_concept_amount'])
    ? $_POST['group_concept_amount']
    : [];

  $concepts = [];
  $seen = [];
  $max = max(count($labels), count($amounts));
  for ($i = 0; $i < $max; $i++) {
    $label = trim(sanitize_text_field((string)($labels[$i] ?? '')));
    $amount = casanova_group_pay_admin_amount((string)($amounts[$i] ?? ''));
    if ($label === '' && $amount <= 0.0) continue;
    if ($label === '') $label = sprintf('Opcion %d', count($concepts) + 1);
    if ($amount <= 0.0) continue;

    $base_id = sanitize_title($label);
    if ($base_id === '') $base_id = 'concepto-' . (count($concepts) + 1);
    $id = $base_id;
    $suffix = 2;
    while (isset($seen[$id])) {
      $id = $base_id . '-' . $suffix;
      $suffix++;
    }
    $seen[$id] = true;

    $concepts[] = [
      'id' => $id,
      'label' => $label,
      'unit_total' => $amount,
    ];
  }

  return $concepts;
}

add_action('admin_post_casanova_create_group_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_group_token');

  $expediente_ref = isset($_POST['group_id_expediente']) ? sanitize_text_field((string) $_POST['group_id_expediente']) : '';
  $idExpediente = 0;
  $idReservaPQ = isset($_POST['group_id_reserva_pq']) ? absint($_POST['group_id_reserva_pq']) : 0;
  $group_units = isset($_POST['group_units']) ? absint($_POST['group_units']) : 0;
  $unit_total_raw = isset($_POST['group_unit_total']) ? (string)$_POST['group_unit_total'] : '';
  $unit_total = casanova_group_pay_admin_amount($unit_total_raw);
  $concepts = casanova_group_pay_admin_collect_concepts();
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

  if ($unit_total <= 0.0 && !empty($concepts)) {
    $unit_total = (float)($concepts[0]['unit_total'] ?? 0);
  }

  if ($unit_total <= 0.0) {
    wp_safe_redirect(add_query_arg(['group_error' => 'unit_total'], $base));
    exit;
  }

  if (!function_exists('casanova_group_tokens_create')) {
    wp_safe_redirect(add_query_arg(['group_error' => 'missing'], $base));
    exit;
  }

  $metadata = [];
  if (!empty($concepts)) {
    $metadata['concepts'] = $concepts;
    $metadata['concepts_enabled'] = true;
  }
  if ($group_units > 0) {
    $metadata['group_units'] = $group_units;
    $metadata['group_units_source'] = 'manual';
  }

  $token = casanova_group_tokens_create([
    'id_expediente' => $idExpediente,
    'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
    'unit_total' => $unit_total,
    'status' => 'active',
    'expires_at' => $expires_at,
    'metadata' => !empty($metadata) ? $metadata : null,
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
