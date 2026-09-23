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

if (!function_exists('casanova_payment_links_parse_expires_at')) {
  /**
   * Normaliza una fecha de caducidad introducida por un humano (YYYY-MM-DD o
   * cualquier formato que entienda DateTimeImmutable) a fin de día en la zona
   * horaria de WP. Devuelve null si está vacía o no se puede interpretar.
   */
  function casanova_payment_links_parse_expires_at($raw): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    try {
      $dt = new DateTimeImmutable($raw, wp_timezone());
      return $dt->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('casanova_payment_links_service_create_individual')) {
  /**
   * Crea un enlace de pago individual (scope individual_link) con las mismas
   * reglas que el formulario de wp-admin, pero sin depender de $_POST ni de
   * redirecciones. Lo usan el formulario de admin y otros plugins (gestor de
   * propuestas).
   *
   * $input:
   *  - expediente_ref       string  ID interno o código visible (se resuelve en GIAV), o
   *  - id_expediente        int     ID interno ya resuelto (se salta el lookup)
   *  - amount               float|null  Importe EUR a imputar. Vacío/0 => pendiente real en GIAV.
   *  - expires_at           string|null Fecha (se normaliza a fin de día) o ya en 'Y-m-d H:i:s'.
   *  - stripe_only, offer_usd_payment, disable_bank_transfer  bool
   *  - usd_fixed_amount     float   Precio pactado en USD (fuerza stripe_only + sin transferencia).
   *  - created_by           string  default 'admin'
   *  - source               string  default 'admin_screen' (metadata.source)
   *  - metadata             array   Claves extra que se fusionan en metadata (p.ej. managed_by, proposal_id).
   *  - skip_pending_check   bool    No consultar GIAV para validar el tope (solo si amount > 0).
   *
   * @return object|WP_Error  Enlace creado. Los códigos de error coinciden con los
   *                          'link_error' del formulario de admin: usd_fixed_amount,
   *                          usd_fixed_stripe, usd_fixed_eur, expediente, expediente_lookup,
   *                          expediente_ambiguous, giav_amount, amount, amount_exceeds_pending,
   *                          missing, create.
   */
  function casanova_payment_links_service_create_individual(array $input) {
    $amount = isset($input['amount']) && $input['amount'] !== '' && $input['amount'] !== null
      ? round((float) str_replace(',', '.', (string) $input['amount']), 2)
      : 0.0;
    $amount_source = 'manual';
    $stripe_only = !empty($input['stripe_only']);
    $offer_usd_payment = !empty($input['offer_usd_payment']) || $stripe_only;
    $disable_bank_transfer = !empty($input['disable_bank_transfer']);

    // Precio pactado directamente en dolares: Stripe cobra esta cifra exacta y el
    // importe en EUR pasa a ser solo el que se imputa al expediente en GIAV.
    $usd_fixed_raw = isset($input['usd_fixed_amount']) ? trim((string) $input['usd_fixed_amount']) : '';
    $usd_fixed_amount = $usd_fixed_raw !== '' ? round((float) str_replace(',', '.', $usd_fixed_raw), 2) : 0.0;
    $usd_fixed = $usd_fixed_amount > 0;

    if ($usd_fixed) {
      // Stripe rechaza importes por debajo del minimo de la moneda.
      if ($usd_fixed_amount < 0.50) {
        return new WP_Error('usd_fixed_amount', __('El precio en USD debe ser de al menos 0,50.', 'casanova-portal'));
      }
      if (!function_exists('casanova_stripe_is_available') || !casanova_stripe_is_available()) {
        return new WP_Error('usd_fixed_stripe', __('Stripe no esta disponible para cobrar en USD.', 'casanova-portal'));
      }
      // El EUR a imputar en GIAV no se puede deducir del precio en USD, asi que
      // no vale el fallback al pendiente: lo tiene que decidir el admin.
      if ($amount <= 0) {
        return new WP_Error('usd_fixed_eur', __('Indica el importe en EUR a imputar en GIAV.', 'casanova-portal'));
      }
      // Sin seleccion de moneda, sin transferencia y solo Stripe: el cliente no
      // debe poder desviarse del precio acordado pagando en euros.
      $stripe_only = true;
      $offer_usd_payment = true;
      $disable_bank_transfer = true;
    }

    $expires_at = null;
    if (!empty($input['expires_at'])) {
      $expires_at = casanova_payment_links_parse_expires_at($input['expires_at']);
    }

    // Expediente: ID ya resuelto o referencia (ID/código) a resolver en GIAV.
    $idExpediente = isset($input['id_expediente']) ? (int) $input['id_expediente'] : 0;
    $expediente_lookup = null;
    if ($idExpediente <= 0) {
      if (!function_exists('casanova_payment_links_resolve_expediente_reference')) {
        return new WP_Error('expediente_lookup', __('No se pudo resolver el expediente.', 'casanova-portal'));
      }
      $expediente_ref = trim((string) ($input['expediente_ref'] ?? ''));
      $resolved = casanova_payment_links_resolve_expediente_reference($expediente_ref);
      if (is_wp_error($resolved)) {
        $code = match ($resolved->get_error_code()) {
          'payment_link_ambiguous_expediente' => 'expediente_ambiguous',
          'payment_link_expediente_not_found',
          'payment_link_missing_expediente_lookup' => 'expediente_lookup',
          default => 'expediente',
        };
        return new WP_Error($code, $resolved->get_error_message());
      }
      $idExpediente = (int) ($resolved['id'] ?? 0);
      if ($idExpediente <= 0) {
        return new WP_Error('expediente_lookup', __('No se pudo resolver el expediente.', 'casanova-portal'));
      }
      $expediente_lookup = [
        'input' => (string) ($resolved['input'] ?? $expediente_ref),
        'source' => (string) ($resolved['source'] ?? ''),
        'codigo' => (string) ($resolved['codigo'] ?? ''),
      ];
    }

    // Pendiente real en GIAV: fallback del importe y tope del importe manual.
    $pending_amount = null;
    $skip_pending_check = !empty($input['skip_pending_check']) && $amount > 0;
    if (!$skip_pending_check && function_exists('casanova_payment_links_admin_resolve_pending_amount')) {
      $pending_amount = casanova_payment_links_admin_resolve_pending_amount($idExpediente);
      if ($amount <= 0) {
        if (is_wp_error($pending_amount)) {
          return new WP_Error('giav_amount', $pending_amount->get_error_message());
        }
        $amount = (float) $pending_amount;
        $amount_source = 'giav_pending';
      }
    }

    if ($amount <= 0) {
      return new WP_Error('amount', __('Importe invalido.', 'casanova-portal'));
    }

    if (!is_wp_error($pending_amount) && $pending_amount !== null && $amount - (float) $pending_amount > 0.01) {
      return new WP_Error('amount_exceeds_pending', __('El importe supera el pendiente del expediente en GIAV.', 'casanova-portal'));
    }

    if (!function_exists('casanova_payment_link_create')) {
      return new WP_Error('missing', __('Payment links no disponible.', 'casanova-portal'));
    }

    $metadata = [
      'source' => (string) ($input['source'] ?? 'admin_screen'),
      'link_kind' => 'individual',
      'amount_source' => $amount_source,
      'offer_usd_payment' => $offer_usd_payment,
      'stripe_only' => $stripe_only,
      'disable_bank_transfer' => $disable_bank_transfer,
      'usd_fixed' => $usd_fixed,
      'usd_fixed_amount' => $usd_fixed ? $usd_fixed_amount : null,
      'usd_fixed_cents' => $usd_fixed ? (int) round($usd_fixed_amount * 100) : null,
      'usd_fixed_rate_at_create' => $usd_fixed ? (float) get_option('casanova_stripe_last_eur_usd_rate', 0) : null,
      'giav_pending_amount' => (!is_wp_error($pending_amount) && $pending_amount !== null) ? (float) $pending_amount : null,
      'created_by_user' => (int) get_current_user_id(),
    ];
    if ($expediente_lookup !== null) {
      $metadata['expediente_lookup'] = $expediente_lookup;
    }
    if (!empty($input['metadata']) && is_array($input['metadata'])) {
      $metadata = array_merge($metadata, $input['metadata']);
    }

    $link = casanova_payment_link_create([
      'id_expediente' => $idExpediente,
      'scope' => 'individual_link',
      'amount_authorized' => $amount,
      'currency' => 'EUR',
      'status' => 'active',
      'expires_at' => $expires_at,
      'created_by' => (string) ($input['created_by'] ?? 'admin'),
      'metadata' => $metadata,
    ]);

    if (is_wp_error($link)) {
      return new WP_Error('create', $link->get_error_message());
    }
    if (!$link) {
      return new WP_Error('create', __('No se pudo crear el enlace.', 'casanova-portal'));
    }

    return $link;
  }
}

add_action('admin_post_casanova_create_payment_link', function () {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_create_payment_link');

  $base = casanova_payment_links_admin_base_url();

  $link = casanova_payment_links_service_create_individual([
    'expediente_ref' => isset($_POST['id_expediente']) ? sanitize_text_field((string) $_POST['id_expediente']) : '',
    'amount' => isset($_POST['amount_authorized']) ? trim((string) $_POST['amount_authorized']) : '',
    'expires_at' => isset($_POST['expires_at']) ? sanitize_text_field((string) $_POST['expires_at']) : '',
    'stripe_only' => !empty($_POST['stripe_only']),
    'offer_usd_payment' => !empty($_POST['offer_usd_payment']),
    'disable_bank_transfer' => !empty($_POST['disable_bank_transfer']),
    'usd_fixed_amount' => isset($_POST['usd_fixed_amount']) ? trim((string) $_POST['usd_fixed_amount']) : '',
    'created_by' => 'admin',
    'source' => 'admin_screen',
  ]);

  if (is_wp_error($link)) {
    wp_safe_redirect(add_query_arg(['link_error' => $link->get_error_code()], $base));
    exit;
  }

  wp_safe_redirect(add_query_arg([
    'link_created' => '1',
    'token' => (string) ($link->token ?? ''),
  ], $base));
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
