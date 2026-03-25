<?php
if (!defined('ABSPATH')) exit;

/**
 * Fecha límite mínima entre reservas (la más restrictiva).
 * Devuelve DateTimeImmutable en timezone WP o null si no hay fecha.
 */
if (!function_exists('casanova_payments_min_fecha_limite')) {
  function casanova_payments_min_fecha_limite(array $reservas): ?DateTimeImmutable {
    $min = null;

    foreach ($reservas as $r) {
      if (!is_object($r)) continue;

	      // Nota: GIAV puede devolver FechaLimite o FechaLimitePago según contexto.
	      $raw = $r->FechaLimite ?? ($r->FechaLimitePago ?? null);
      if (!$raw) continue;

      $s = (string)$raw;
      $s = substr($s, 0, 10);

      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) continue;

      try {
        $dt = new DateTimeImmutable($s, wp_timezone());
      } catch (Throwable $e) {
        continue;
      }

      if ($min === null || $dt < $min) $min = $dt;
    }

    return $min;
  }
}

/**
 * ' . esc_html__('Depósito', 'casanova-portal') . ' permitido si estamos ANTES del final del día de la fecha límite mínima.
 * Si no hay fecha límite, permitimos.
 */
if (!function_exists('casanova_payments_is_deposit_allowed')) {
  function casanova_payments_is_deposit_allowed(array $reservas): bool {
    $min = casanova_payments_min_fecha_limite($reservas);
    if ($min === null) return true;

    try {
      // Ahora mismo, con hora real (WP timezone)
      $now = new DateTimeImmutable(current_time('Y-m-d H:i:s'), wp_timezone());

      // Permitimos todo el día de la fecha límite (hasta justo antes del día siguiente)
      $deadline_end = $min->modify('+1 day');
    } catch (Throwable $e) {
      return true; // fail-open
    }

    return ($now < $deadline_end);
  }
}

if (!function_exists('casanova_payments_calc_deposit_amount')) {
  function casanova_payments_calc_deposit_amount(float $total_pend, int $idExpediente): float {
    $percent = function_exists('casanova_payments_get_deposit_percent')
      ? (float)casanova_payments_get_deposit_percent($idExpediente)
      : 10.0;

    $min = function_exists('casanova_payments_get_deposit_min_amount')
      ? (float)casanova_payments_get_deposit_min_amount()
      : 50.0;

    $amt = round($total_pend * ($percent / 100.0), 2);

    if ($amt < $min) $amt = $min;
    if ($amt > $total_pend) $amt = $total_pend;

    return round($amt, 2);
  }
}

/**
 * ============================================================
 * DEBUG CONTROLADO: comprobar qué callbacks hay en el hook
 * SOLO cuando entramos por admin-post.php?action=casanova_pay_expediente
 * ============================================================
 */
if (!function_exists('casanova_payments_update_slot_allocation')) {
  function casanova_payments_update_slot_allocation(object $intent, int $payment_link_id, string $payment_link_scope, int $cobro_id): void {
    if ($payment_link_id <= 0 || $payment_link_scope !== 'slot_base' || !function_exists('casanova_payment_link_get')) {
      return;
    }

    $plink = casanova_payment_link_get($payment_link_id);
    if (!$plink) {
      return;
    }

    $meta = [];
    $raw = (string) ($plink->metadata ?? '');
    if ($raw !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $meta = $decoded;
      }
    }

    $already = isset($meta['slot_allocation']['cobro_id']) && (int) $meta['slot_allocation']['cobro_id'] === $cobro_id;
    if ($already) {
      return;
    }

    if (!function_exists('casanova_group_context_from_reservas') || !function_exists('casanova_ensure_group_slots') || !function_exists('casanova_allocate_payment_to_slots')) {
      return;
    }

    $id_reserva_pq = (int) ($meta['id_reserva_pq'] ?? 0);
    $ctx = casanova_group_context_from_reservas((int) $intent->id_expediente, (int) $intent->id_cliente, $id_reserva_pq ?: null);
    if (!is_wp_error($ctx)) {
      casanova_ensure_group_slots(
        (int) $intent->id_expediente,
        (int) ($ctx['id_reserva_pq'] ?? $id_reserva_pq),
        (int) ($ctx['num_pax'] ?? 0),
        (float) ($ctx['base_total'] ?? ($ctx['base_pending'] ?? 0))
      );
    }

    $slot_ids = [];
    if (!empty($meta['slot_ids']) && is_array($meta['slot_ids'])) {
      $slot_ids = $meta['slot_ids'];
    }

    if (!empty($slot_ids) && function_exists('casanova_allocate_payment_to_slot_ids')) {
      $alloc = casanova_allocate_payment_to_slot_ids($slot_ids, (float) $intent->amount);
    } else {
      $alloc = casanova_allocate_payment_to_slots((int) $intent->id_expediente, (float) $intent->amount, $id_reserva_pq);
    }

    if (function_exists('casanova_payment_link_update') && function_exists('casanova_payment_link_merge_metadata')) {
      casanova_payment_link_update($payment_link_id, [
        'metadata' => casanova_payment_link_merge_metadata($plink->metadata ?? null, [
          'slot_allocation' => [
            'cobro_id' => $cobro_id,
            'amount' => (float) $intent->amount,
            'allocations' => $alloc,
            'allocated_at' => current_time('mysql'),
          ],
        ]),
      ]);
    }
  }
}

if (!function_exists('casanova_payments_record_cobro')) {
  function casanova_payments_record_cobro(object $intent, array $provider_data, string $label): array {
    $result = [
      'giav_cobro' => null,
      'already' => false,
      'inserted' => false,
      'should_notify' => false,
    ];

    $billing_dni = (string) ($provider_data['billing_dni'] ?? '');
    $billing_email = trim((string) ($provider_data['billing_email'] ?? ''));
    $billing_name = trim((string) ($provider_data['billing_name'] ?? ''));
    $billing_lastname = trim((string) ($provider_data['billing_lastname'] ?? ''));
    $payment_link_id = (int) ($provider_data['payment_link_id'] ?? 0);
    $payment_link_scope = (string) ($provider_data['payment_link_scope'] ?? '');
    $id_forma_pago = (int) ($provider_data['id_forma_pago'] ?? 0);
    $id_oficina = (int) ($provider_data['id_oficina'] ?? 0);
    $concepto = (string) ($provider_data['concepto'] ?? '');
    $documento = (string) ($provider_data['documento'] ?? '');
    $payer_name = trim((string) ($provider_data['payer_name'] ?? ''));
    if ($payer_name === '') {
      $payer_name = 'Portal';
    }
    $notas_internas = (string) ($provider_data['notas_internas'] ?? '');

    $expediente_cliente_id = (int) ($intent->id_cliente ?? 0);
    $payer_id_cliente = $expediente_cliente_id;
    $payer_lookup_resolved = false;
    if ($billing_dni !== '' && function_exists('casanova_giav_cliente_search_por_dni') && function_exists('casanova_giav_extraer_idcliente')) {
      try {
        $resp_cli = casanova_giav_cliente_search_por_dni($billing_dni);
        $idc = casanova_giav_extraer_idcliente($resp_cli);
        if ($idc !== null && $idc !== '') {
          $payer_id_cliente = (int) $idc;
          $payer_lookup_resolved = true;
          error_log('[CASANOVA][' . $label . '][GIAV] payer resolved by DNI dni=' . $billing_dni . ' idCliente=' . $payer_id_cliente);
        } else {
          error_log('[CASANOVA][' . $label . '][GIAV] payer not found by DNI dni=' . $billing_dni);
        }
      } catch (Throwable $e) {
        error_log('[CASANOVA][' . $label . '][GIAV] payer search exception dni=' . $billing_dni . ' msg=' . $e->getMessage());
      }
    }

    if ($payer_id_cliente <= 0 && $billing_dni !== '' && function_exists('casanova_giav_cliente_create_from_billing')) {
      $bid = casanova_giav_cliente_create_from_billing([
        'dni' => $billing_dni,
        'email' => $billing_email,
        'nombre' => $billing_name,
        'apellidos' => $billing_lastname,
      ]);
      if (!empty($bid)) {
        $payer_id_cliente = (int) $bid;
        $payer_lookup_resolved = true;
        error_log('[CASANOVA][' . $label . '][GIAV] payer created from billing dni=' . $billing_dni . ' idCliente=' . $payer_id_cliente);
      } else {
        error_log('[CASANOVA][' . $label . '][GIAV] payer create failed dni=' . $billing_dni . ' email=' . $billing_email);
      }
    }

    if ($billing_dni !== '' && !$payer_lookup_resolved) {
      error_log('[CASANOVA][' . $label . '][GIAV] payer fallback using expediente client idCliente=' . $expediente_cliente_id . ' dni=' . $billing_dni);
    }

    $giav_params = [
      'idFormaPago' => $id_forma_pago,
      'idOficina' => ($id_oficina > 0 ? $id_oficina : null),
      'idExpediente' => (int) $intent->id_expediente,
      'idCliente' => $payer_id_cliente,
      'idRelacionPasajeroReserva' => null,
      'idTipoOperacion' => 'Cobro',
      'importe' => (double) $intent->amount,
      'fechaCobro' => current_time('Y-m-d'),
      'concepto' => $concepto,
      'documento' => $documento,
      'pagador' => $payer_name,
      'notasInternas' => $notas_internas,
      'autocompensar' => true,
      'idEntityStage' => null,
    ];
    if ($id_oficina <= 0) {
      unset($giav_params['idOficina']);
    }

    if (!function_exists('casanova_giav_call')) {
      error_log('[CASANOVA][' . $label . '][GIAV] casanova_giav_call missing');
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => 'casanova_giav_call_missing',
      ];
      return $result;
    }

    error_log('[CASANOVA][' . $label . '][GIAV] Cobro_POST attempt intent_id=' . (int) $intent->id . ' exp=' . (int) $intent->id_expediente . ' cliente=' . (int) $intent->id_cliente . ' importe=' . (string) $intent->amount);
    $res = casanova_giav_call('Cobro_POST', $giav_params);

    if (is_wp_error($res)) {
      error_log('[CASANOVA][' . $label . '][GIAV] Cobro_POST ERROR: ' . $res->get_error_message());
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'ok' => false,
        'error' => $res->get_error_message(),
      ];
      return $result;
    }

    $cobro_id = 0;
    if (is_object($res) && isset($res->Cobro_POSTResult)) {
      $cobro_id = (int) $res->Cobro_POSTResult;
    } elseif (is_numeric($res)) {
      $cobro_id = (int) $res;
    }

    if ($cobro_id > 0) {
      error_log('[CASANOVA][' . $label . '][GIAV] Cobro_POST OK cobro_id=' . $cobro_id . ' intent_id=' . (int) $intent->id);
      $result['giav_cobro'] = [
        'attempted_at' => current_time('mysql'),
        'inserted_at' => current_time('mysql'),
        'ok' => true,
        'cobro_id' => $cobro_id,
      ];
      $result['inserted'] = true;
      $result['should_notify'] = true;

      if ($payment_link_id > 0 && function_exists('casanova_payment_link_sync_after_cobro')) {
        casanova_payment_link_sync_after_cobro($intent, $payment_link_id, $payment_link_scope, $cobro_id, $billing_dni);
      } elseif ($payment_link_id > 0 && function_exists('casanova_payment_link_mark_paid')) {
        casanova_payment_link_mark_paid($payment_link_id, $cobro_id, $billing_dni);
      }
      casanova_payments_update_slot_allocation($intent, $payment_link_id, $payment_link_scope, $cobro_id);

      return $result;
    }

    error_log('[CASANOVA][' . $label . '][GIAV] Cobro_POST unexpected response intent_id=' . (int) $intent->id . ' res=' . print_r($res, true));
    $result['giav_cobro'] = [
      'attempted_at' => current_time('mysql'),
      'ok' => false,
      'error' => 'unexpected_response',
      'raw' => is_scalar($res) ? (string) $res : null,
    ];

    return $result;
  }
}

add_action('init', function () {

  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (stripos($uri, 'admin-post.php') === false) return;
  if (($_REQUEST['action'] ?? '') !== 'casanova_pay_expediente') return;

  global $wp_filter;
  $hook = 'admin_post_casanova_pay_expediente';

  if (empty($wp_filter[$hook])) {
    error_log('[CASANOVA][DEBUG] no wp_filter entry for ' . $hook);
    return;
  }

  $h = $wp_filter[$hook];

  if (!is_object($h) || !property_exists($h, 'callbacks') || !is_array($h->callbacks)) {
    error_log('[CASANOVA][DEBUG] hook has no callbacks array');
    return;
  }

  foreach ($h->callbacks as $priority => $items) {
    foreach ($items as $cb) {
      $fn = $cb['function'] ?? null;

      if (is_string($fn)) {
        $name = $fn;
      } elseif (is_array($fn)) {
        $name = (is_object($fn[0]) ? get_class($fn[0]) : (string)$fn[0]) . '::' . (string)$fn[1];
      } elseif ($fn instanceof Closure) {
        $name = 'Closure';
      } else {
        $name = 'Unknown';
      }

      error_log('[CASANOVA][DEBUG] hook=' . $hook . ' priority=' . $priority . ' cb=' . $name);
    }
  }
}, 2);

/**
 * ============================================================
 * REGISTRO DE HOOKS (PRIORIDAD 1 PARA ENTRAR LOS PRIMEROS)
 * ============================================================
 */
add_action('admin_post_casanova_pay_expediente', 'casanova_handle_pay_expediente', 1);
add_action('admin_post_nopriv_casanova_pay_expediente', 'casanova_handle_pay_expediente', 1);

add_action('plugins_loaded', function () {
  error_log('[CASANOVA][HOOK] admin_post_casanova_pay_expediente has_handlers=' . (string) has_action('admin_post_casanova_pay_expediente'));
}, 20);

/**
 * ============================================================
 * Helper URL admin-post (sin hardcodeos)
 * ============================================================
 */
function casanova_canonical_admin_post_url(array $args): string {
  return add_query_arg($args, admin_url('admin-post.php'));
}

/**
 * ============================================================
 * URL FRONTEND para iniciar el pago (evita bloqueos a /wp-admin/)
 *
 * Importante: muchos setups redirigen /wp-admin/* para roles no admin,
 * lo que rompe admin-post.php. Por eso el flujo de pago se inicia desde
 * el portal (frontend) y reutiliza el mismo handler.
 * ============================================================
 */
function casanova_portal_pay_expediente_url(int $idExpediente, string $mode = ''): string {
  // Base del portal (filtro/constante ya existente en el plugin)
  $base = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/');

  $args = [
    'casanova_action' => 'pay_expediente',
    'expediente'      => (int) $idExpediente,
    '_wpnonce'        => wp_create_nonce('casanova_pay_expediente_' . (int)$idExpediente),
  ];
  if ($mode === 'deposit' || $mode === 'full') {
    $args['mode'] = $mode;
  }

  return add_query_arg($args, $base);
}

/**
 * Entrada FRONTEND para el flujo de pago.
 *
 * Esto permite que clientes (no admin) paguen aunque exista una redirección
 * global que bloquee /wp-admin/.
 */
add_action('init', function () {
  if (empty($_REQUEST['casanova_action']) || (string)$_REQUEST['casanova_action'] !== 'pay_expediente') return;

  // Normalizamos para reutilizar el handler existente.
  $_REQUEST['action'] = 'casanova_pay_expediente';

  if (!function_exists('casanova_handle_pay_expediente')) {
    wp_die(esc_html__('Sistema de pago no disponible', 'casanova-portal'), 500);
  }

  casanova_handle_pay_expediente();
  exit;
}, 0);

/**
 * ============================================================
 * HANDLER PRINCIPAL DE PAGO
 * ============================================================
 */
function casanova_handle_pay_expediente(): void {

  error_log('[CASANOVA][PAY] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') .
    ' logged_in=' . (is_user_logged_in() ? '1' : '0') .
    ' host=' . ($_SERVER['HTTP_HOST'] ?? '?')
  );

  if (!is_user_logged_in()) {
    error_log('[CASANOVA][PAY] STOP not logged in');
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  $idExpediente = isset($_REQUEST['expediente']) ? (int)$_REQUEST['expediente'] : 0;
  error_log('[CASANOVA][PAY] A expediente=' . $idExpediente);

  if ($idExpediente <= 0) {
    error_log('[CASANOVA][PAY] STOP expediente invalid');
    wp_die(esc_html__('Expediente inválido', 'casanova-portal'), 400);
  }

  if (empty($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'casanova_pay_expediente_' . $idExpediente)) {
    error_log('[CASANOVA][PAY] STOP nonce invalid expediente=' . $idExpediente);
    wp_die(esc_html__('Nonce inválido', 'casanova-portal'), 403);
  }
  error_log('[CASANOVA][PAY] B nonce ok');

  if (function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only()) {
    wp_die(casanova_portal_impersonation_message(), 403);
  }

  $user_id   = casanova_portal_get_effective_user_id();
  $idCliente = casanova_portal_get_effective_client_id($user_id);

  error_log('[CASANOVA][PAY] C user_id=' . $user_id . ' idCliente=' . $idCliente);

  if ($idCliente <= 0) {
    error_log('[CASANOVA][PAY] STOP cliente no vinculado user_id=' . $user_id);
    wp_die(esc_html__('Cliente no vinculado', 'casanova-portal'), 403);
  }

  if (!function_exists('casanova_user_can_access_expediente')) {
    error_log('[CASANOVA][PAY] STOP ownership helper missing');
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  $can = casanova_user_can_access_expediente($user_id, $idExpediente);
  if (!$can) {
    error_log('[CASANOVA][PAY] STOP ownership failed user_id=' . $user_id . ' expediente=' . $idExpediente . ' idCliente=' . $idCliente);
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }
  error_log('[CASANOVA][PAY] D ownership ok');

  // ==========================
  // Reservas GIAV
  // ==========================
  if (!function_exists('casanova_giav_reservas_por_expediente')) {
    error_log('[CASANOVA][PAY] STOP reservas helper missing');
    wp_die(esc_html__('Sistema de reservas no disponible', 'casanova-portal'), 500);
  }

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas)) {
    error_log('[CASANOVA][PAY] STOP reservas WP_Error: ' . $reservas->get_error_message());
    wp_die(esc_html__('No se pudieron cargar reservas', 'casanova-portal'), 500);
  }
  if (empty($reservas)) {
    error_log('[CASANOVA][PAY] STOP reservas empty');
    wp_die(esc_html__('No se pudieron cargar reservas', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] E reservas ok count=' . (is_array($reservas) ? count($reservas) : -1));

  // ==========================
  // Cálculo real pendiente
  // ==========================
  if (!function_exists('casanova_calc_pago_expediente')) {
    error_log('[CASANOVA][PAY] STOP calc helper missing');
    wp_die(esc_html__('Sistema de pagos no disponible', 'casanova-portal'), 500);
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) {
    error_log('[CASANOVA][PAY] STOP calc WP_Error: ' . $calc->get_error_message());
    wp_die(esc_html__('No se pudo calcular el estado de pago', 'casanova-portal'), 500);
  }

  $pagado_now = (float)($calc['pagado'] ?? 0);
  $total_pend = (float)($calc['pendiente_real'] ?? 0);

  error_log('[CASANOVA][PAY] F calc ok pendiente_real=' . $total_pend . ' pagado=' . $pagado_now);

  // Si no hay nada que pagar, fuera.
  if ($total_pend <= 0.01) {
    error_log('[CASANOVA][PAY] redirect: nothing to pay expediente=' . $idExpediente);
    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');
    // ' . esc_html__('Volver', 'casanova-portal') . ' al mismo contexto del portal. Si estamos usando el router (?view=...)
    // forzamos la vista de expedientes para no caer en Principal tras pagar.
    $view = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : '';
    if ($view === '') $view = 'expedientes';
    wp_safe_redirect(add_query_arg(['view' => $view, 'expediente' => $idExpediente], $base));
    exit;
  }

  // ==========================
  // Mínimo pagos parciales
  // ==========================
  $min_amount = (float) apply_filters(
    'casanova_min_partial_payment_amount',
    function_exists('casanova_payments_get_deposit_min_amount') ? (float)casanova_payments_get_deposit_min_amount() : 50.00,
    $idExpediente,
    $idCliente
  );

  // ==========================
  // GET: mostrar selector
  // ==========================
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if ($method === 'GET') {

    // ' . esc_html__('Depósito', 'casanova-portal') . ' permitido solo si:
    // - no se ha pagado nada aún
    // - fecha límite lo permite
    $deposit_allowed = ($pagado_now <= 0.01) && casanova_payments_is_deposit_allowed($reservas);
    $deposit_amt = $deposit_allowed ? casanova_payments_calc_deposit_amount($total_pend, $idExpediente) : 0.0;

    // ' . esc_html__('Depósito', 'casanova-portal') . ' efectivo: solo si es menor que el total pendiente (para que no sea un "depósito" que en realidad paga todo)
    $deposit_effective = ($deposit_allowed && ($deposit_amt + 0.01 < $total_pend));

    $percent = function_exists('casanova_payments_get_deposit_percent') ? (float)casanova_payments_get_deposit_percent($idExpediente) : 10.0;

    $deadline = casanova_payments_min_fecha_limite($reservas);
    $deadline_txt = $deadline ? $deadline->format('d/m/Y') : '';

    $pref_mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
    if ($pref_mode !== 'deposit' && $pref_mode !== 'full') $pref_mode = '';
    $auto_start = !empty($_GET['autostart']);
    $raw_card_brand = isset($_GET['card_brand']) ? trim((string)$_GET['card_brand']) : '';

    if (function_exists('casanova_redsys_normalize_card_brand')) {
      $pref_card_brand = casanova_redsys_normalize_card_brand($_GET['card_brand'] ?? '');
    } else {
      $pref_card_brand_raw = strtolower(trim((string)($_GET['card_brand'] ?? '')));
      $pref_card_brand = ($pref_card_brand_raw === 'amex' || $pref_card_brand_raw === 'american_express') ? 'amex' : 'other';
    }

    $checked_deposit = ($deposit_effective && ($pref_mode === 'deposit' || $pref_mode === ''));
    $checked_full    = !$checked_deposit;

    // IMPORTANTE: usar endpoint frontend para no depender de /wp-admin/admin-post.php
    $action_url = function_exists('casanova_portal_pay_expediente_url')
      ? casanova_portal_pay_expediente_url($idExpediente)
      : admin_url('admin-post.php');
    $nonce = wp_create_nonce('casanova_pay_expediente_' . $idExpediente);

    if ($auto_start && $pref_mode !== '' && $raw_card_brand !== '') {
      casanova_portal_render_public_document_start(__('Redirigiendo al pago', 'casanova-portal'));
      echo '<section class="casanova-public-page">';
      echo casanova_portal_public_logo_html();
      echo '<h2 class="casanova-public-page__title">' . esc_html__('Redirigiendo al pago', 'casanova-portal') . '</h2>';
      echo '<p class="casanova-public-page__intro">' . esc_html__('Estamos preparando tu pago seguro. Seras redirigido automaticamente en unos segundos.', 'casanova-portal') . '</p>';
      echo '<form id="casanova-pay-autostart" method="post" action="' . esc_url($action_url) . '" class="casanova-public-form">';
      echo '<input type="hidden" name="action" value="casanova_pay_expediente" />';
      echo '<input type="hidden" name="expediente" value="' . (int) $idExpediente . '" />';
      echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
      echo '<input type="hidden" name="mode" value="' . esc_attr($pref_mode) . '" />';
      echo '<input type="hidden" name="card_brand" value="' . esc_attr($pref_card_brand) . '" />';
      echo '<noscript><button type="submit" class="casanova-public-button">' . esc_html__('Continuar al pago', 'casanova-portal') . '</button></noscript>';
      echo '</form>';
      echo '<script>document.getElementById("casanova-pay-autostart").submit();</script>';
      echo '</section>';
      casanova_portal_render_public_document_end();
      exit;
    }


    // Etiqueta legible del expediente (título/código humano) para evitar confusión
    $exp_meta = function_exists('casanova_portal_expediente_meta') ? casanova_portal_expediente_meta($idCliente, $idExpediente) : ['titulo'=>'','codigo'=>'','label'=>(sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente))];
    $exp_titulo = trim((string)($exp_meta['titulo'] ?? ''));
    $exp_codigo = trim((string)($exp_meta['codigo'] ?? ''));
    $exp_label  = trim((string)($exp_meta['label'] ?? ''));

    casanova_portal_render_public_document_start(__('Pago de expediente', 'casanova-portal'));
echo '<section class="casanova-public-page">';
echo casanova_portal_public_logo_html();
echo '<h2 class="casanova-public-page__title">' . esc_html__('Pago de expediente', 'casanova-portal') . '</h2>';

$viaje_label = esc_html((string) $exp_label);
$codigo_html = '';
if ($exp_titulo && $exp_codigo) {
  $codigo_html = ' <span class="casanova-public-page__code">(' . esc_html((string) $exp_codigo) . ')</span>';
}
echo '<p class="casanova-public-page__trip">' . wp_kses_post(
  sprintf(
    /* translators: %1$s is the trip/expediente label (title), %2$s is the human code in parentheses (may be empty). */
    __('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'),
    $viaje_label,
    $codigo_html
  )
) . '</p>';

echo '<div class="casanova-public-page__summary">';

$pendiente_html = '<strong>' . esc_html(number_format($total_pend, 2, ',', '.')) . ' €</strong>';
echo '<div class="casanova-public-page__summary-line">' . wp_kses_post(
  sprintf(
    /* translators: %s is a formatted amount like "<strong>1.234,56 €</strong>" */
    __('Pendiente: %s', 'casanova-portal'),
    $pendiente_html
  )
) . '</div>';

if ($pagado_now > 0.01) {
  echo '<div class="casanova-public-page__summary-line">' . esc_html(
    sprintf(
      /* translators: %s is a formatted amount like "1.234,56 €" */
      __('Pagado: %s €', 'casanova-portal'),
      number_format($pagado_now, 2, ',', '.')
    )
  ) . '</div>';
}

if ($deadline_txt) {
  echo '<div class="casanova-public-page__summary-line">' . esc_html(
    sprintf(
      /* translators: %s is a date text like "23/12/2025" */
      __('Fecha límite: %s', 'casanova-portal'),
      $deadline_txt
    )
  ) . '</div>';
}

echo '</div>';

echo '<form method="post" action="' . esc_url($action_url) . '" class="casanova-public-form">';
echo '<input type="hidden" name="action" value="casanova_pay_expediente" />';
echo '<input type="hidden" name="expediente" value="' . (int) $idExpediente . '" />';
echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

echo '<div class="casanova-public-section-label">' . esc_html__('Tipo de tarjeta', 'casanova-portal') . '</div>';
echo '<div class="casanova-public-form__grid casanova-public-choice-group">';
echo '<label class="casanova-public-choice casanova-public-choice--compact">';
echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="other" ' . ($pref_card_brand === 'amex' ? '' : 'checked') . ' />' . esc_html__('Otra tarjeta', 'casanova-portal');
echo '<span class="casanova-public-choice__hint">' . esc_html__('Visa, Mastercard y similares.', 'casanova-portal') . '</span>';
echo '</label>';
echo '<label class="casanova-public-choice casanova-public-choice--compact">';
echo '<input class="casanova-public-choice__control" type="radio" name="card_brand" value="amex" ' . ($pref_card_brand === 'amex' ? 'checked' : '') . ' />' . esc_html__('American Express (AMEX)', 'casanova-portal');
echo '<span class="casanova-public-choice__hint">' . esc_html__('Selecciona esta opcion si vas a pagar con AMEX.', 'casanova-portal') . '</span>';
echo '</label>';
echo '</div>';
echo '<div class="casanova-public-field__hint">' . esc_html__('Elige con que tarjeta quieres realizar el pago.', 'casanova-portal') . '</div>';

if ($deposit_effective) {
  echo '<label class="casanova-public-choice">';
  echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="deposit" ' . ($checked_deposit ? 'checked' : '') . ' />';

  $deposit_amount_html = '<strong>' . esc_html(number_format($deposit_amt, 2, ',', '.')) . ' €</strong>';
  echo wp_kses_post(
    sprintf(
      /* translators: 1: percent (e.g. 30,00), 2: deposit amount HTML (e.g. "<strong>300,00 €</strong>") */
      __('Pagar depósito (%1$s%%): %2$s', 'casanova-portal'),
      esc_html(number_format($percent, 2, ',', '.')),
      $deposit_amount_html
    )
  );

  echo '</label>';
} else {
  echo '<label class="casanova-public-choice casanova-public-choice--disabled">';
  echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="deposit" disabled />';

  echo esc_html__('Depósito no disponible', 'casanova-portal');

  echo '</label>';
}

echo '<label class="casanova-public-choice">';
echo '<input class="casanova-public-choice__control" type="radio" name="mode" value="full" ' . ($checked_full ? 'checked' : '') . ' />';

$amount_html = '<strong>' . esc_html(number_format($total_pend, 2, ',', '.')) . ' €</strong>';
echo wp_kses_post(
  sprintf(
    /* translators: %s is an amount HTML like "<strong>1.234,56 €</strong>" */
    __('Pagar el total pendiente: %s', 'casanova-portal'),
    $amount_html
  )
);

echo '</label>';

echo '<button type="submit" class="casanova-public-button">'
  . esc_html__('Continuar al pago', 'casanova-portal')
  . '</button>';

echo '</form>';

echo '<p class="casanova-public-page__footer">'
  . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal')
  . '</p>';

echo '</section>';
casanova_portal_render_public_document_end();
exit;

  }

  // ==========================
  // POST: procesar pago
  // ==========================
  $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
  if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';

  // Si ya hay algo pagado, solo permitimos full
  if ($pagado_now > 0.01) {
    $mode = 'full';
  }

  $deposit_allowed = ($pagado_now <= 0.01) && casanova_payments_is_deposit_allowed($reservas);
  $deposit_amt = $deposit_allowed ? casanova_payments_calc_deposit_amount($total_pend, $idExpediente) : 0.0;
  $deposit_effective = ($deposit_allowed && ($deposit_amt + 0.01 < $total_pend));

  if (function_exists('casanova_redsys_normalize_card_brand')) {
    $card_brand = casanova_redsys_normalize_card_brand($_POST['card_brand'] ?? '');
  } else {
    $card_brand_raw = strtolower(trim((string)($_POST['card_brand'] ?? '')));
    $card_brand = ($card_brand_raw === 'amex' || $card_brand_raw === 'american_express') ? 'amex' : 'other';
  }

  if ($mode === 'deposit' && $deposit_effective) {
    $amount_to_pay = $deposit_amt;
  } else {
    $amount_to_pay = $total_pend;
    $mode = 'full';
  }

  $amount_to_pay = round((float)$amount_to_pay, 2);
  $is_full = ($amount_to_pay + 0.01 >= $total_pend);

  error_log('[CASANOVA][PAY] G mode=' . $mode . ' amount_to_pay=' . $amount_to_pay . ' total_pend=' . $total_pend . ' is_full=' . ($is_full ? '1' : '0') . ' min=' . $min_amount);

  if ($amount_to_pay < 0.01) {
    error_log('[CASANOVA][PAY] STOP amount invalid amount_to_pay=' . $amount_to_pay);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' inválido', 400);
  }

  if ($amount_to_pay - $total_pend > 0.01) {
    error_log('[CASANOVA][PAY] STOP amount > pending amount_to_pay=' . $amount_to_pay . ' total_pend=' . $total_pend);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' superior al pendiente', 400);
  }

  if (!$is_full && $amount_to_pay < $min_amount) {
    error_log('[CASANOVA][PAY] STOP amount < min amount_to_pay=' . $amount_to_pay . ' min=' . $min_amount);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' inferior al mínimo permitido', 400);
  }

  // ==========================
  // Crear intent
  // ==========================
  if (!function_exists('casanova_payment_intent_create') || !function_exists('casanova_payment_intent_update')) {
    error_log('[CASANOVA][PAY] STOP intent helpers missing');
    wp_die(esc_html__('Sistema de pago no disponible', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] H creating intent amount=' . $amount_to_pay . ' expediente=' . $idExpediente . ' cliente=' . $idCliente);

  $intent = casanova_payment_intent_create([
    'user_id' => $user_id,
    'id_cliente' => $idCliente,
    'id_expediente' => $idExpediente,
    'amount' => $amount_to_pay,
    'currency' => 'EUR',
    'status' => 'created',
    'payload' => [
      'source' => 'portal',
      'requested_amount' => $amount_to_pay,
      'pending_at_create' => round($total_pend, 2),
      'mode' => $mode,
      'card_brand' => $card_brand,
    ],
  ]);

  if (is_wp_error($intent)) {
    error_log('[CASANOVA][PAY] STOP intent WP_Error: ' . $intent->get_error_message());
    wp_die($intent->get_error_message(), 500);
  }

  if (!is_object($intent) || empty($intent->id) || empty($intent->token)) {
    error_log('[CASANOVA][PAY] STOP intent invalid object/id/token');
    wp_die(esc_html__('Intent inválido', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] I intent ok id=' . (int)$intent->id . ' token=' . (string)$intent->token);

  // ==========================
  // Order Redsys
  // ==========================
  if (!function_exists('casanova_redsys_order_from_intent_id')) {
    error_log('[CASANOVA][PAY] STOP redsys_order helper missing');
    wp_die(esc_html__('Redsys no disponible', 'casanova-portal'), 500);
  }

  $order = casanova_redsys_order_from_intent_id((int)$intent->id);
  error_log('[CASANOVA][PAY] J order generated=' . $order);

  casanova_payment_intent_update((int)$intent->id, ['order_redsys' => $order]);
  $intent->order_redsys = $order;

  // ==========================
  // Preparación Redsys / TPV
  // ==========================
  if (!function_exists('casanova_redsys_prepare_redirect_data')) {
    error_log('[CASANOVA][PAY] STOP redsys_prepare_redirect_data missing');
    wp_die(esc_html__('Config Redsys no disponible', 'casanova-portal'), 500);
  }

  $redsys_redirect = casanova_redsys_prepare_redirect_data($intent, [
    'source' => 'portal',
    'mode' => $mode,
    'card_brand' => $card_brand,
    'id_expediente' => $idExpediente,
    'id_cliente' => $idCliente,
    'user_id' => $user_id,
  ]);
  if (is_wp_error($redsys_redirect)) {
    error_log('[CASANOVA][PAY] STOP redsys prepare failed: ' . $redsys_redirect->get_error_message());
    wp_die($redsys_redirect->get_error_message(), 500);
  }

  if (function_exists('casanova_redsys_attach_intent_tpv')) {
    casanova_redsys_attach_intent_tpv(
      (int)$intent->id,
      $intent->payload ?? null,
      (string)($redsys_redirect['tpv_key'] ?? 'default')
    );
  }

  error_log(
    '[CASANOVA][PAY] K redsys prepared tpv=' . (string)($redsys_redirect['tpv_key'] ?? 'default') .
    ' endpoint=' . (string)($redsys_redirect['endpoint'] ?? '') .
    ' amount_cents=' . (string)($redsys_redirect['amount_cents'] ?? '')
  );

  casanova_payment_intent_update((int)$intent->id, ['status' => 'redirecting']);

  while (ob_get_level()) ob_end_clean();

  echo '<form id="redsys" action="' . esc_url((string)$redsys_redirect['endpoint']) . '" method="post">';
    echo '<input type="hidden" name="Ds_SignatureVersion" value="' . esc_attr((string)$redsys_redirect['sig_version']) . '">';
    echo '<input type="hidden" name="Ds_MerchantParameters" value="' . esc_attr((string)$redsys_redirect['merchant_parameters']) . '">';
    echo '<input type="hidden" name="Ds_Signature" value="' . esc_attr((string)$redsys_redirect['signature']) . '">';
  echo '</form>';
  echo '<script>document.getElementById("redsys").submit();</script>';
  exit;
}
