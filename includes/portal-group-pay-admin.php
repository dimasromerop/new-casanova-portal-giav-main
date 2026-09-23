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

/**
 * Normaliza listas paralelas de etiquetas e importes (tal y como llegan de un
 * formulario o de otro plugin) a conceptos [{id,label,unit_total}] con ids
 * únicos. Sin dependencia de $_POST.
 *
 * @param array $labels   string[]
 * @param array $amounts  string[]|float[]
 */
function casanova_group_pay_normalize_concepts(array $labels, array $amounts): array {
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

function casanova_group_pay_admin_collect_concepts(): array {
  $labels = isset($_POST['group_concept_label']) && is_array($_POST['group_concept_label'])
    ? $_POST['group_concept_label']
    : [];
  $amounts = isset($_POST['group_concept_amount']) && is_array($_POST['group_concept_amount'])
    ? $_POST['group_concept_amount']
    : [];

  return casanova_group_pay_normalize_concepts($labels, $amounts);
}

if (!function_exists('casanova_group_pay_service_create_token')) {
  /**
   * Crea un token reusable de pago de grupo con las mismas reglas que el
   * formulario de wp-admin, sin depender de $_POST ni de redirecciones. Lo usan
   * el formulario de admin y otros plugins (gestor de propuestas).
   *
   * $input:
   *  - expediente_ref  string  ID interno o código visible (se resuelve en GIAV), o
   *  - id_expediente   int     ID interno ya resuelto
   *  - id_reserva_pq   int     Reserva paquete (opcional)
   *  - units           int     Nº de unidades/personas previstas (opcional)
   *  - unit_total      float|string  Importe por unidad. Si vacío, se toma del primer concepto.
   *  - concepts        array   [{id?,label,unit_total}] ya normalizados, o
   *  - concept_labels + concept_amounts  listas paralelas a normalizar
   *  - stripe_only, offer_usd_payment, disable_bank_transfer  bool
   *  - expires_at      string|null
   *  - metadata        array   Claves extra que se fusionan en metadata (p.ej. managed_by, proposal_id)
   *
   * @return object|WP_Error  Token creado. Códigos de error = 'group_error' del admin:
   *                          expediente, expediente_lookup, expediente_ambiguous, unit_total, missing, create.
   */
  function casanova_group_pay_service_create_token(array $input) {
    $idReservaPQ = isset($input['id_reserva_pq']) ? absint($input['id_reserva_pq']) : 0;
    $group_units = isset($input['units']) ? absint($input['units']) : 0;
    $unit_total = casanova_group_pay_admin_amount((string)($input['unit_total'] ?? ''));
    $stripe_only = !empty($input['stripe_only']);
    $offer_usd_payment = !empty($input['offer_usd_payment']) || $stripe_only;
    $disable_bank_transfer = !empty($input['disable_bank_transfer']);
    $expires_at = null;
    if (!empty($input['expires_at'])) {
      $expires_at = function_exists('casanova_payment_links_parse_expires_at')
        ? casanova_payment_links_parse_expires_at($input['expires_at'])
        : null;
    }

    $concepts = [];
    if (!empty($input['concepts']) && is_array($input['concepts'])) {
      $labels = [];
      $amounts = [];
      foreach ($input['concepts'] as $c) {
        $labels[] = (string)($c['label'] ?? '');
        $amounts[] = (string)($c['unit_total'] ?? '');
      }
      $concepts = casanova_group_pay_normalize_concepts($labels, $amounts);
    } elseif (!empty($input['concept_labels']) || !empty($input['concept_amounts'])) {
      $concepts = casanova_group_pay_normalize_concepts(
        is_array($input['concept_labels'] ?? null) ? $input['concept_labels'] : [],
        is_array($input['concept_amounts'] ?? null) ? $input['concept_amounts'] : []
      );
    }

    $idExpediente = isset($input['id_expediente']) ? (int)$input['id_expediente'] : 0;
    if ($idExpediente <= 0) {
      if (!function_exists('casanova_payment_links_resolve_expediente_reference')) {
        return new WP_Error('expediente', __('No se pudo resolver el expediente.', 'casanova-portal'));
      }
      $resolved = casanova_payment_links_resolve_expediente_reference(trim((string)($input['expediente_ref'] ?? '')));
      if (is_wp_error($resolved)) {
        $code = match ($resolved->get_error_code()) {
          'payment_link_ambiguous_expediente' => 'expediente_ambiguous',
          'payment_link_expediente_not_found',
          'payment_link_missing_expediente_lookup' => 'expediente_lookup',
          default => 'expediente',
        };
        return new WP_Error($code, $resolved->get_error_message());
      }
      $idExpediente = (int)($resolved['id'] ?? 0);
      if ($idExpediente <= 0) {
        return new WP_Error('expediente_lookup', __('No se pudo resolver el expediente.', 'casanova-portal'));
      }
    }

    if ($unit_total <= 0.0 && !empty($concepts)) {
      $unit_total = (float)($concepts[0]['unit_total'] ?? 0);
    }
    if ($unit_total <= 0.0) {
      return new WP_Error('unit_total', __('Indica el importe por unidad.', 'casanova-portal'));
    }

    if (!function_exists('casanova_group_tokens_create')) {
      return new WP_Error('missing', __('Tokens de grupo no disponibles.', 'casanova-portal'));
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
    if ($offer_usd_payment) {
      $metadata['offer_usd_payment'] = true;
    }
    if ($stripe_only) {
      $metadata['stripe_only'] = true;
    }
    if ($disable_bank_transfer) {
      $metadata['disable_bank_transfer'] = true;
    }
    if (!empty($input['metadata']) && is_array($input['metadata'])) {
      $metadata = array_merge($metadata, $input['metadata']);
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
      return new WP_Error('create', $token->get_error_message());
    }
    if (!$token) {
      return new WP_Error('create', __('No se pudo crear el token.', 'casanova-portal'));
    }

    return $token;
  }
}

add_action('admin_post_casanova_create_group_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_group_token');

  $base = function_exists('casanova_payment_links_admin_base_url')
    ? casanova_payment_links_admin_base_url()
    : admin_url('admin.php?page=casanova-payments-links');

  $token = casanova_group_pay_service_create_token([
    'expediente_ref' => isset($_POST['group_id_expediente']) ? sanitize_text_field((string) $_POST['group_id_expediente']) : '',
    'id_reserva_pq' => isset($_POST['group_id_reserva_pq']) ? absint($_POST['group_id_reserva_pq']) : 0,
    'units' => isset($_POST['group_units']) ? absint($_POST['group_units']) : 0,
    'unit_total' => isset($_POST['group_unit_total']) ? (string)$_POST['group_unit_total'] : '',
    'concepts' => casanova_group_pay_admin_collect_concepts(),
    'stripe_only' => !empty($_POST['group_stripe_only']),
    'offer_usd_payment' => !empty($_POST['group_offer_usd_payment']),
    'disable_bank_transfer' => !empty($_POST['group_disable_bank_transfer']),
    'expires_at' => isset($_POST['group_expires_at']) ? sanitize_text_field((string)$_POST['group_expires_at']) : '',
  ]);

  if (is_wp_error($token)) {
    wp_safe_redirect(add_query_arg(['group_error' => $token->get_error_code()], $base));
    exit;
  }

  wp_safe_redirect(add_query_arg([
    'group_created' => '1',
    'group_token' => (string)($token->token ?? ''),
  ], $base));
  exit;
});

add_action('admin_post_casanova_delete_group_tokens', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }
  check_admin_referer('casanova_delete_group_tokens');

  $ids = [];
  if (isset($_REQUEST['id'])) {
    $ids[] = absint($_REQUEST['id']);
  }
  if (!empty($_POST['group_token_ids']) && is_array($_POST['group_token_ids'])) {
    foreach ($_POST['group_token_ids'] as $v) $ids[] = absint($v);
  }
  $ids = array_values(array_filter(array_unique($ids)));

  $base = function_exists('casanova_payment_links_admin_base_url')
    ? casanova_payment_links_admin_base_url()
    : admin_url('admin.php?page=casanova-payments-links');

  if (empty($ids)) {
    wp_safe_redirect(add_query_arg(['group_deleted' => '0'], $base));
    exit;
  }

  if (!function_exists('casanova_group_pay_tokens_table')) {
    wp_safe_redirect(add_query_arg(['group_deleted' => '0'], $base));
    exit;
  }

  global $wpdb;
  $table = casanova_group_pay_tokens_table();
  $placeholders = implode(',', array_fill(0, count($ids), '%d'));
  $sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
  $wpdb->query($wpdb->prepare($sql, ...$ids));

  wp_safe_redirect(add_query_arg(['group_deleted' => (string)count($ids)], $base));
  exit;
});

add_action('admin_post_casanova_update_group_token', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }
  check_admin_referer('casanova_update_group_token');

  $base = function_exists('casanova_payment_links_admin_base_url')
    ? casanova_payment_links_admin_base_url()
    : admin_url('admin.php?page=casanova-payments-links');

  $id = isset($_POST['group_token_id']) ? absint($_POST['group_token_id']) : 0;
  if ($id <= 0 || !function_exists('casanova_group_token_get_by_id') || !function_exists('casanova_group_token_update')) {
    wp_safe_redirect(add_query_arg(['group_error' => 'update'], $base));
    exit;
  }

  $existing = casanova_group_token_get_by_id($id);
  if (!$existing) {
    wp_safe_redirect(add_query_arg(['group_error' => 'update'], $base));
    exit;
  }

  $group_units = isset($_POST['group_units']) ? absint($_POST['group_units']) : 0;
  $unit_total = casanova_group_pay_admin_amount(isset($_POST['group_unit_total']) ? (string)$_POST['group_unit_total'] : '');
  $concepts = casanova_group_pay_admin_collect_concepts();
  $stripe_only = !empty($_POST['group_stripe_only']);
  $offer_usd_payment = !empty($_POST['group_offer_usd_payment']) || $stripe_only;
  $disable_bank_transfer = !empty($_POST['group_disable_bank_transfer']);

  $status = isset($_POST['group_status']) ? sanitize_key((string)$_POST['group_status']) : 'active';
  if (!in_array($status, ['active', 'expired'], true)) {
    $status = 'active';
  }

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

  if ($unit_total <= 0.0 && !empty($concepts)) {
    $unit_total = (float)($concepts[0]['unit_total'] ?? 0);
  }
  if ($unit_total <= 0.0) {
    wp_safe_redirect(add_query_arg(['group_error' => 'unit_total'], $base));
    exit;
  }

  $meta = [];
  $raw_meta = (string)($existing->metadata ?? '');
  if ($raw_meta !== '') {
    $decoded = json_decode($raw_meta, true);
    if (is_array($decoded)) $meta = $decoded;
  }

  if (!empty($concepts)) {
    $meta['concepts'] = $concepts;
    $meta['concepts_enabled'] = true;
  } else {
    unset($meta['concepts'], $meta['concepts_enabled']);
  }

  if ($group_units > 0) {
    $meta['group_units'] = $group_units;
    $meta['group_units_source'] = 'manual';
  } else {
    unset($meta['group_units'], $meta['group_units_source']);
  }

  if ($offer_usd_payment) {
    $meta['offer_usd_payment'] = true;
  } else {
    unset($meta['offer_usd_payment']);
  }

  if ($stripe_only) {
    $meta['stripe_only'] = true;
  } else {
    unset($meta['stripe_only']);
  }

  if ($disable_bank_transfer) {
    $meta['disable_bank_transfer'] = true;
  } else {
    unset($meta['disable_bank_transfer']);
  }

  $ok = casanova_group_token_update($id, [
    'status' => $status,
    'unit_total' => $unit_total,
    'expires_at' => $expires_at,
    'metadata' => !empty($meta) ? $meta : '',
  ]);

  if (!$ok) {
    wp_safe_redirect(add_query_arg(['group_error' => 'update'], $base));
    exit;
  }

  wp_safe_redirect(add_query_arg(['group_updated' => '1'], $base));
  exit;
});
