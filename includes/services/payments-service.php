<?php
if (!defined('ABSPATH')) exit;

/**
 * Servicio de pagos reutilizable por REST y templates.
 */
class Casanova_Payments_Service {

  /**
   * @return array<string,mixed>
   */
  private static function get_mulligans_snapshot(int $user_id): array {
    if ($user_id <= 0) {
      return [];
    }

    if (function_exists('casanova_mulligans_sync_user')) {
      $sync = casanova_mulligans_sync_user($user_id, false);
      if (is_array($sync)) {
        return $sync;
      }
    }

    if (function_exists('casanova_mulligans_get_user')) {
      $cached = casanova_mulligans_get_user($user_id);
      if (is_array($cached)) {
        return $cached;
      }
    }

    return [];
  }

  /**
   * Describe el estado de pagos de un expediente autorizado.
   *
   * @return array<string,mixed>|WP_Error
   */
  public static function describe_for_user(int $user_id, int $idCliente, int $idExpediente) {
    $user_id = function_exists('casanova_portal_resolve_user_id')
      ? casanova_portal_resolve_user_id($user_id)
      : $user_id;

    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : $idCliente;

    if ($idCliente <= 0) {
      return new WP_Error('payments_no_client', esc_html__('Tu cuenta no está vinculada a un cliente casanova.', 'casanova-portal'));
    }

    if ($idExpediente <= 0) {
      return new WP_Error('payments_invalid_expediente', esc_html__('Expediente inválido.', 'casanova-portal'));
    }

    if (!function_exists('casanova_user_can_access_expediente')) {
      return new WP_Error('payments_missing_helper', esc_html__('No se puede verificar la propiedad del expediente.', 'casanova-portal'));
    }

    if (!casanova_user_can_access_expediente($user_id, $idExpediente)) {
      return new WP_Error('permissions', esc_html__('No autorizado para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_giav_reservas_por_expediente')) {
      return new WP_Error('payments_missing_feature', esc_html__('Reservas no disponibles.', 'casanova-portal'));
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (is_wp_error($reservas)) {
      return new WP_Error('reservas_error', $reservas->get_error_message());
    }
    if (empty($reservas) || !is_array($reservas)) {
      return new WP_Error('reservas_empty', esc_html__('No se encontraron reservas para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_calc_pago_expediente')) {
      return new WP_Error('payments_missing_calc', esc_html__('No se puede calcular el estado de pagos.', 'casanova-portal'));
    }

    $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
    if (is_wp_error($calc)) {
      return $calc;
    }

    $total = (float) ($calc['total_objetivo'] ?? 0);
    $paid = (float) ($calc['pagado'] ?? 0);
    $pending = max(0.0, (float) ($calc['pendiente_real'] ?? 0));
    $is_paid = !empty($calc['expediente_pagado']);

    $deposit_allowed = false;
    $deposit_amount = 0.0;
    if ($paid <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
      $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
    }

    if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
      $deposit_amount = casanova_payments_calc_deposit_amount($pending, $idExpediente);
    }
    $deposit_effective = $deposit_allowed && ($deposit_amount + 0.01 < $pending);

    if ($deposit_amount < 0) $deposit_amount = 0;

    $deadline_iso = null;
    if (function_exists('casanova_payments_min_fecha_limite')) {
      $deadline = casanova_payments_min_fecha_limite($reservas);
      if ($deadline instanceof DateTimeInterface) {
        $deadline_iso = $deadline->format('Y-m-d');
      }
    }

    $can_pay_full = $pending > 0.01;
    $is_read_only = function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only();
    $payment_options = [
      'can_pay_deposit' => (bool) $deposit_effective,
      'deposit_deadline' => $deadline_iso,
      'can_pay_full' => (bool) $can_pay_full,
      'recommended' => $deposit_effective ? 'deposit' : ($can_pay_full ? 'full' : null),
      'deposit_amount' => round($deposit_amount, 2),
      'pending_amount' => round($pending, 2),
    ];

    if ($is_read_only) {
      $payment_options['can_pay_deposit'] = false;
      $payment_options['can_pay_full'] = false;
      $payment_options['recommended'] = null;
    }

    if (!function_exists('casanova_portal_pay_expediente_url')) {
      $pay_url = add_query_arg([
        'action' => 'casanova_pay_expediente',
        'expediente' => $idExpediente,
        '_wpnonce' => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
      ], admin_url('admin-post.php'));
    } else {
      $pay_url = casanova_portal_pay_expediente_url($idExpediente);
    }

    // Nombre a mostrar como "pagador" por defecto (evita el genérico "WP user X").
    $payer_default = '';
    if (function_exists('get_userdata')) {
      $u = get_userdata($user_id);
      if ($u && is_object($u)) {
        $payer_default = trim((string)($u->display_name ?? ''));
      }
    }
    if ($payer_default === '') {
      $payer_default = __('Cliente', 'casanova-portal');
    }



    // Métodos de pago disponibles en el portal (backend manda; frontend solo renderiza).
    $inespay_enabled = false;
    if (class_exists('Casanova_Inespay_Service')) {
      $k = defined('CASANOVA_INESPAY_API_KEY') ? (string)CASANOVA_INESPAY_API_KEY : '';
      $t = defined('CASANOVA_INESPAY_API_TOKEN') ? (string)CASANOVA_INESPAY_API_TOKEN : '';
      $inespay_enabled = ($k !== '' && $t !== '');
    }

    $aplazame_giav_method_id = defined('CASANOVA_GIAV_IDFORMAPAGO_APLAZAME')
      ? (int) CASANOVA_GIAV_IDFORMAPAGO_APLAZAME
      : (int) get_option('casanova_giav_idformapago_aplazame', 0);
    $aplazame_enabled = class_exists('Casanova_Aplazame_Service')
      && Casanova_Aplazame_Service::is_enabled()
      && $aplazame_giav_method_id > 0;

    $payment_methods = [
      [
        'id' => 'card',
        'enabled' => true,
        'label' => __('Tarjeta', 'casanova-portal'),
      ],
      [
        'id' => 'bank_transfer',
        'enabled' => (bool) $inespay_enabled,
        'label' => __('Transferencia bancaria', 'casanova-portal'),
      ],
      [
        'id' => 'aplazame',
        'enabled' => (bool) $aplazame_enabled,
        'label' => __('Aplazame', 'casanova-portal'),
      ],
    ];
    $mulligans = self::get_mulligans_snapshot($user_id);
    $mulligans_available = max(0, (int) ($mulligans['points'] ?? 0));
    $history = self::fetch_cobros_history($idExpediente, $idCliente, $payer_default);
    $payer_totals = self::summarize_payer_totals($history);

    if (empty($payer_totals) && !empty($calc['is_group'])) {
      $payer_totals = self::summarize_payer_totals(
        self::fetch_group_passenger_totals_history($idExpediente)
      );
    }

    return [
      'user_id' => $user_id,
      'idCliente' => $idCliente,
      'idExpediente' => $idExpediente,
      'reservas' => $reservas,
      'calc' => is_array($calc) ? $calc : [],
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'currency' => 'EUR',
      'can_pay' => !$is_read_only && $pending > 0.01,
      'pay_url' => $pay_url,
      'history' => $history,
      'payer_totals' => $payer_totals,
      'is_group' => !empty($calc['is_group']),
      'economic_scope' => (string) ($calc['economic_scope'] ?? 'cliente'),
      'expediente_pagado' => $is_paid,
      'mulligans_used' => casanova_mulligans_used_for_expediente($idExpediente, $idCliente),
      'mulligans_available' => $mulligans_available,
      'payment_options' => $payment_options,
      'payment_methods' => $payment_methods,
      'actions' => [
        'deposit' => [
          'allowed' => !$is_read_only && (bool) $payment_options['can_pay_deposit'],
          'amount' => (float) $payment_options['deposit_amount'],
        ],
        'balance' => [
          'allowed' => !$is_read_only && (bool) $payment_options['can_pay_full'],
          'amount' => (float) $payment_options['pending_amount'],
        ],
      ],
    ];
  }

  /**
   * Devuelve histórico de cobros y mejora campos para UX:
   * - "concept" identifica si fue Depósito / Saldo cuando viene de nuestro TPV.
   * - "payer" sustituye el genérico "WP user X" por el nombre del cliente.
   */
  private static function fetch_cobros_history(int $idExpediente, int $idCliente, string $payer_default = ''): array {
    if ($idExpediente <= 0 || $idCliente <= 0 || !function_exists('casanova_giav_cobros_por_expediente_all_portal_view')) {
      return [];
    }

    $items = casanova_giav_cobros_por_expediente_all_portal_view($idExpediente, $idCliente);
    if (is_wp_error($items) || !is_array($items)) {
      return [];
    }

    $rows = [];
    foreach ($items as $item) {
      if (!is_object($item)) continue;

      $externals = isset($item->DatosExternos) && is_object($item->DatosExternos)
        ? $item->DatosExternos
        : null;

      $date_raw = trim((string) ($item->FechaCobro ?? ''));
      $ts = $date_raw ? strtotime($date_raw) : 0;
      $date = $ts ? date_i18n('Y-m-d', $ts) : '';

      $importe = (float) ($item->Importe ?? 0);
      $tipo = trim((string) ($item->TipoOperacion ?? ''));
      $tipo_up = strtoupper($tipo);
      $is_refund = (strpos($tipo_up, 'REEM') !== false || strpos($tipo_up, 'DEV') !== false);

      if ($tipo === '') {
        if ($importe >= 0) {
          $tipo = __('Cobro', 'casanova-portal');
        } else {
          $tipo = __('Reembolso', 'casanova-portal');
        }
      }

      $concept = trim((string) ($item->Concepto ?? ''));
      if ($concept === '') {
        $concept = trim((string) ($item->Documento ?? ''));
      }
      if ($concept === '' && $externals) {
        $concept = trim((string) ($externals->NombreFormaPago ?? ''));
      }
      if ($concept === '') {
        $concept = __('Pago', 'casanova-portal');
      }

      $payer = trim((string) ($item->Pagador ?? ''));
      $doc = trim((string) ($item->Documento ?? ''));

      if ($payer === '' && $externals) {
        foreach ([
          $externals->NombreClienteAlias ?? '',
          $externals->PasajeroNombre ?? '',
          $externals->NombreCliente ?? '',
        ] as $candidate) {
          $candidate = trim(rtrim((string) $candidate, ', '));
          if ($candidate !== '') {
            $payer = $candidate;
            break;
          }
        }
      }

      // --- Enriquecimiento para cobros del portal (Redsys) ---
      // 1) Normalizar pagador: evitar "WP user X"
      if ($payer_default !== '') {
        if ($payer === '' || preg_match('/^wp\s*user\s*\d+/i', $payer)) {
          $payer = $payer_default;
        }
      }

      // 2) Detectar modo (deposit/full) desde nuestra tabla de intents y renombrar concepto.
      // GIAV suele guardar "Pago Redsys <ORDER>" en Concepto.
      $order = '';
      if ($concept !== '') {
        if (preg_match('/Redsys\s*([0-9A-Za-z]+)/i', $concept, $m)) {
          $order = (string)($m[1] ?? '');
        }
      }
      if ($order === '' && $doc !== '') {
        // Algunos GIAV guardan order en Documento o variaciones.
        if (preg_match('/([0-9A-Za-z]{6,})/', $doc, $m)) {
          $order = (string)($m[1] ?? '');
        }
      }

      if ($order !== '' && function_exists('casanova_payment_intent_get_by_order')) {
        $intent = casanova_payment_intent_get_by_order($order);
        if ($intent && is_object($intent)) {
          $payload = [];
          $payload_raw = (string)($intent->payload ?? '');
          if ($payload_raw !== '') {
            $decoded = json_decode($payload_raw, true);
            if (is_array($decoded)) $payload = $decoded;
          }
          $mode = strtolower(trim((string)($payload['mode'] ?? '')));
          if ($mode === 'deposit') {
            $concept = sprintf(__('Depósito (Redsys %s)', 'casanova-portal'), $order);
          } elseif ($mode === 'full') {
            $concept = sprintf(__('Pago (Redsys %s)', 'casanova-portal'), $order);
          } else {
            // Si no sabemos modo, al menos indicamos que viene del portal.
            $concept = sprintf(__('Pago (Redsys %s)', 'casanova-portal'), $order);
          }
        }
      }

      $rows[] = [
        'id' => (string) ($item->Id ?? $item->IdCobro ?? $item->IdCobroSolicitud ?? uniqid('cobro-', true)),
        'date' => $date,
        'timestamp' => $ts,
        'type' => $tipo,
        'is_refund' => $is_refund,
        'concept' => $concept,
        'payer' => $payer,
        'document' => $doc,
        'amount' => abs($importe),
      ];
    }

    usort($rows, function($a, $b) {
      return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
    });

    return $rows;
  }

  /**
   * Fallback local para expedientes de grupo cuando GIAV no devuelve cobros por expediente.
   *
   * @return array<int,array<string,mixed>>
   */
  private static function fetch_local_intents_history(int $idExpediente, string $payer_default = ''): array {
    if ($idExpediente <= 0 || !function_exists('casanova_payments_table')) {
      return [];
    }

    global $wpdb;
    if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
      return [];
    }

    $table = casanova_payments_table();
    $items = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, amount, currency, provider, method, status, order_redsys, provider_payment_id, provider_reference, payload, created_at, updated_at
         FROM {$table}
         WHERE id_expediente = %d
         ORDER BY COALESCE(updated_at, created_at) ASC, id ASC",
        $idExpediente
      )
    );

    if (!is_array($items) || empty($items)) {
      return [];
    }

    $rows = [];
    foreach ($items as $item) {
      if (!is_object($item)) {
        continue;
      }

      $payload = [];
      $payload_raw = (string) ($item->payload ?? '');
      if ($payload_raw !== '') {
        $decoded = json_decode($payload_raw, true);
        if (is_array($decoded)) {
          $payload = $decoded;
        }
      }

      $giav_cobro = is_array($payload['giav_cobro'] ?? null) ? $payload['giav_cobro'] : [];
      $has_success_marker = !empty($giav_cobro['cobro_id']) || !empty($giav_cobro['inserted_at']) || (string) ($item->status ?? '') === 'reconciled';
      if (!$has_success_marker) {
        continue;
      }

      $date_raw = '';
      foreach ([
        $giav_cobro['inserted_at'] ?? null,
        $payload['inespay_callback']['received_at'] ?? null,
        $payload['redsys_notify']['time'] ?? null,
        $payload['redsys_return']['time'] ?? null,
        $item->updated_at ?? null,
        $item->created_at ?? null,
      ] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
          $date_raw = $candidate;
          break;
        }
      }

      $ts = $date_raw !== '' ? strtotime($date_raw) : 0;
      $date = $ts ? date_i18n('Y-m-d', $ts) : '';

      $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
      $reference = trim((string) ($item->provider_payment_id ?? $item->provider_reference ?? $item->order_redsys ?? ''));

      if ($mode === 'deposit') {
        $concept = $reference !== ''
          ? sprintf(__('Depósito (portal %s)', 'casanova-portal'), $reference)
          : __('Depósito', 'casanova-portal');
      } elseif ($mode === 'full') {
        $concept = $reference !== ''
          ? sprintf(__('Pago (portal %s)', 'casanova-portal'), $reference)
          : __('Pago', 'casanova-portal');
      } else {
        $concept = $reference !== ''
          ? sprintf(__('Pago (portal %s)', 'casanova-portal'), $reference)
          : __('Pago', 'casanova-portal');
      }

      $payer = trim((string) ($payload['billing_fullname'] ?? ''));
      if ($payer === '') {
        $payer = trim((string) ($payload['billing_name'] ?? ''));
      }
      if ($payer === '') {
        $payer = trim((string) ($payload['payer_name'] ?? ''));
      }
      if ($payer === '') {
        $payer = $payer_default;
      }

      $rows[] = [
        'id' => 'intent-' . (int) ($item->id ?? 0),
        'date' => $date,
        'timestamp' => $ts,
        'type' => __('Cobro', 'casanova-portal'),
        'is_refund' => false,
        'concept' => $concept,
        'payer' => $payer,
        'document' => $reference,
        'amount' => abs((float) ($item->amount ?? 0)),
      ];
    }

    usort($rows, static function($a, $b) {
      return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
    });

    return $rows;
  }

  /**
   * Fallback final para grupos: usa el total acumulado por pasajero que GIAV expone en el expediente.
   *
   * @return array<int,array<string,mixed>>
   */
  private static function fetch_group_passenger_totals_history(int $idExpediente): array {
    if ($idExpediente <= 0 || !function_exists('casanova_giav_pasajeros_por_expediente')) {
      return [];
    }

    $items = casanova_giav_pasajeros_por_expediente($idExpediente);
    if (!is_array($items) || empty($items)) {
      return [];
    }

    $rows = [];
    foreach ($items as $item) {
      if (!is_object($item)) {
        continue;
      }

      $externals = isset($item->DatosExternos) && is_object($item->DatosExternos)
        ? $item->DatosExternos
        : null;

      $amount = (float) ($externals->TotalCobrosPasajeroExp ?? 0);
      if ($amount <= 0.0) {
        continue;
      }

      $payer = trim((string) ($externals->NombrePasajero ?? $externals->Nombre ?? $item->NombrePasajero ?? $item->Nombre ?? ''));
      $document = trim((string) ($item->Documento ?? ($externals->Documento ?? '')));
      if ($payer === '') {
        $payer = $document !== '' ? $document : __('Pasajero', 'casanova-portal');
      }

      $rows[] = [
        'id' => 'pasajero-exp-' . (int) ($item->IdPasajero ?? $item->Id ?? count($rows) + 1),
        'date' => '',
        'timestamp' => 0,
        'type' => __('Aportación', 'casanova-portal'),
        'is_refund' => false,
        'concept' => __('Total registrado por pasajero', 'casanova-portal'),
        'payer' => $payer,
        'document' => $document,
        'amount' => abs($amount),
      ];
    }

    usort($rows, static function(array $a, array $b): int {
      $amount_cmp = ((float) ($b['amount'] ?? 0)) <=> ((float) ($a['amount'] ?? 0));
      if ($amount_cmp !== 0) {
        return $amount_cmp;
      }

      return strcmp((string) ($a['payer'] ?? ''), (string) ($b['payer'] ?? ''));
    });

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private static function summarize_payer_totals(array $rows): array {
    if (empty($rows)) {
      return [];
    }

    $summary = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $payer = trim((string) ($row['payer'] ?? ''));
      $document = trim((string) ($row['document'] ?? ''));
      $label = $payer !== '' ? $payer : ($document !== '' ? $document : __('Pago sin identificar', 'casanova-portal'));
      $key = function_exists('mb_strtolower')
        ? mb_strtolower($label, 'UTF-8')
        : strtolower($label);

      if (!isset($summary[$key])) {
        $summary[$key] = [
          'id' => md5($key),
          'payer' => $label,
          'count' => 0,
          'payments_count' => 0,
          'refunds_count' => 0,
          'last_date' => '',
          'last_timestamp' => 0,
          'amount' => 0.0,
        ];
      }

      $amount = abs((float) ($row['amount'] ?? 0));
      $is_refund = !empty($row['is_refund']);
      $timestamp = (int) ($row['timestamp'] ?? 0);
      $date = trim((string) ($row['date'] ?? ''));

      $summary[$key]['count']++;
      $summary[$key]['amount'] += $is_refund ? -$amount : $amount;

      if ($is_refund) {
        $summary[$key]['refunds_count']++;
      } else {
        $summary[$key]['payments_count']++;
      }

      if ($timestamp >= (int) $summary[$key]['last_timestamp']) {
        $summary[$key]['last_timestamp'] = $timestamp;
        $summary[$key]['last_date'] = $date;
      }
    }

    $rows = array_values(array_map(static function(array $item): array {
      $item['amount'] = round((float) ($item['amount'] ?? 0), 2);
      return $item;
    }, $summary));

    usort($rows, static function(array $a, array $b): int {
      $amount_cmp = abs((float) ($b['amount'] ?? 0)) <=> abs((float) ($a['amount'] ?? 0));
      if ($amount_cmp !== 0) {
        return $amount_cmp;
      }

      return (int) ($b['last_timestamp'] ?? 0) <=> (int) ($a['last_timestamp'] ?? 0);
    });

    return $rows;
  }

}
