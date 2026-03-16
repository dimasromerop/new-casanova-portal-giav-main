<?php
if (!defined('ABSPATH')) exit;

/**
 * Service del Dashboard.
 *
 * - Centraliza la lógica de datos.
 * - Reutilizable por shortcodes (legacy) y por REST/React.
 * - Mantiene enfoque "sin romper": si faltan helpers, degrada de forma segura.
 */
class Casanova_Dashboard_Service {

  /**
   * @var array<string,mixed>
   */
  private static array $perf_breakdown = [];

  private static function perf_reset(): void {
    self::$perf_breakdown = [];
  }

  private static function perf_mark(string $key, float $started_at): void {
    self::$perf_breakdown[$key] = round(max(0, (microtime(true) - $started_at) * 1000), 2);
  }

  private static function perf_add(string $key, float $duration_ms): void {
    $current = isset(self::$perf_breakdown[$key]) ? (float) self::$perf_breakdown[$key] : 0.0;
    self::$perf_breakdown[$key] = round($current + max(0, $duration_ms), 2);
  }

  /**
   * @param mixed $value
   */
  private static function perf_set(string $key, $value): void {
    self::$perf_breakdown[$key] = $value;
  }

  /**
   * @return array<string,mixed>
   */
  private static function perf_context(): array {
    return self::$perf_breakdown;
  }

  public static function build_for_user(int $user_id, bool $refresh = false): Casanova_Dashboard_DTO {
    $perf_total_start = microtime(true);
    self::perf_reset();

    $user_id = function_exists('casanova_portal_resolve_user_id')
      ? casanova_portal_resolve_user_id($user_id)
      : $user_id;

    $idCliente = function_exists('casanova_portal_get_effective_client_id')
      ? casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);

    // Cache ligero: GIAV manda, WP consume.
    if (!$refresh && $idCliente > 0) {
      $cache_lookup_start = microtime(true);
      $cache_key = 'casanova_dash_v3_' . $idCliente;
      $cached = get_transient($cache_key);
      self::perf_mark('cache_lookup_ms', $cache_lookup_start);
      if (is_array($cached) && !empty($cached)) {
        self::perf_set('cache_hit', 1);
        self::perf_set('trip_count', count((array) ($cached['trips'] ?? [])));
        self::perf_set('next_trip_id', (int) ($cached['next_trip']['id'] ?? 0));
        if (function_exists('casanova_perf_measure')) {
          casanova_perf_measure('dashboard_breakdown', $perf_total_start, array_merge([
            'user_id' => $user_id,
            'idCliente' => $idCliente,
            'refresh' => $refresh ? 1 : 0,
          ], self::perf_context()), ['status' => 'ok']);
        }
        return Casanova_Dashboard_DTO::from_array($cached);
      }
    } else {
      self::perf_set('cache_lookup_ms', 0.0);
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');

    // 1) Mulligans (datos locales)
    $mulligans_start = microtime(true);

    $m_user = function_exists('casanova_mulligans_get_user') ? (array) casanova_mulligans_get_user($user_id) : [];
    $m_points = isset($m_user['points']) ? (int) $m_user['points'] : 0;
    $m_tier   = isset($m_user['tier']) ? (string) $m_user['tier'] : '';
    $m_last   = isset($m_user['last_sync']) ? (int) $m_user['last_sync'] : 0;
    $m_spend  = isset($m_user['spend']) ? (float) $m_user['spend'] : 0.0;
    $m_earned = isset($m_user['earned']) ? (int) $m_user['earned'] : 0;
    $m_bonus  = isset($m_user['bonus']) ? (int) $m_user['bonus'] : 0;
    $m_used   = isset($m_user['used']) ? (int) $m_user['used'] : 0;

    $m_ledger = [];
    $ledger_raw = (string) get_user_meta($user_id, CASANOVA_MULL_META_LEDGER, true);
    if ($ledger_raw) {
      $decoded = json_decode($ledger_raw, true);
      if (is_array($decoded)) {
        // ordena por ts desc
        usort($decoded, function($a, $b){
          $ta = (int)($a['ts'] ?? 0);
          $tb = (int)($b['ts'] ?? 0);
          return $tb <=> $ta;
        });
        $m_ledger = array_slice($decoded, 0, 20);
      }
    }

    $m_mult = null;
    $m_progress = 0;
    $m_remaining = null;
    $m_next_label = null;
    $m_tier_cfg = function_exists('casanova_mulligans_tier_cfg') ? casanova_mulligans_tier_cfg($m_tier) : [];
    if (isset($m_tier_cfg['mult'])) {
      $m_mult = (float) $m_tier_cfg['mult'];
    }

    if (function_exists('casanova_mulligans_tiers')) {
      $tiers = casanova_mulligans_tiers();
      $tier_keys = array_keys($tiers);
      $idx = array_search($m_tier, $tier_keys, true);
      $next_key = ($idx !== false && isset($tier_keys[$idx + 1])) ? $tier_keys[$idx + 1] : null;
      if ($next_key && isset($tiers[$next_key])) {
        $next_cfg = $tiers[$next_key];
        $next_min = (float) ($next_cfg['min'] ?? 0);
        $prev_min = (float) ($m_tier_cfg['min'] ?? 0);
        $span = max(1.0, $next_min - $prev_min);
        $m_progress = (int) max(0, min(100, round((($m_spend - $prev_min) / $span) * 100)));
        $m_remaining = max(0.0, $next_min - $m_spend);
        $m_next_label = (string) ($next_cfg['label'] ?? '');
      } else {
        $m_progress = 100;
      }
    }
    self::perf_mark('mulligans_ms', $mulligans_start);


    // 2) Viajes (GIAV: expedientes)
    // Solo mostramos futuros/en curso. Si no hay viaje activo visible para
    // el cliente, el dashboard debe entrar en empty state.
    $trips_start = microtime(true);
    $trips_pack = self::get_trips_for_dashboard($idCliente);
    self::perf_mark('trips_ms', $trips_start);
    $trips = (array) ($trips_pack['trips'] ?? []);
    $next  = !empty($trips) ? $trips[0] : null;
    $active_trip_exists = is_array($next) && !empty($next['id']);
    $trip_summary_start = microtime(true);
    $next_trip_summary = self::get_dashboard_trip_summary($idCliente, $next);
    self::perf_mark('trip_summary_ms', $trip_summary_start);

    // 4) Pagos (sobre próximo viaje)
    $payments_start = microtime(true);
    $payments = self::get_payments_summary($idCliente, $next);
    self::perf_mark('payments_ms', $payments_start);

    // 5) Mensajes (sobre próximo viaje)
    $messages_start = microtime(true);
    $messages = self::get_messages_summary($user_id, $idCliente, $next);
    self::perf_mark('messages_ms', $messages_start);

    // 6) Próxima acción (prioriza siguiente viaje si el actual está al día)
    $next_action_start = microtime(true);
    $next_action = self::get_next_action($idCliente, $next, $trips, $payments);
    self::perf_mark('next_action_ms', $next_action_start);

    $data = [
      'mulligans' => [
        'points'    => $m_points,
        'tier'      => $m_tier,
        'mult'      => $m_mult,
        'last_sync' => $m_last,
        'spend'     => $m_spend,
        'earned'    => $m_earned,
        'bonus'     => $m_bonus,
        'used'      => $m_used,
        'progress_pct'     => $m_progress,
        'remaining_to_next' => $m_remaining,
        'next_tier_label'   => $m_next_label,
        'ledger'    => $m_ledger,
      ],
      'trips'    => $trips,
      'next_trip' => $next,
      'next_trip_summary' => $next_trip_summary,
      'post_trip' => null,
      'active_trip_exists' => $active_trip_exists,
      'payments' => $payments,
      'messages' => $messages,
      'next_action' => $next_action,
    ];

    if ($idCliente > 0) {
      $cache_store_start = microtime(true);
      set_transient('casanova_dash_v3_' . $idCliente, $data, 60); // TTL corto
      self::perf_mark('cache_store_ms', $cache_store_start);
    }

    self::perf_set('cache_hit', 0);
    self::perf_set('trip_count', count($trips));
    self::perf_set('next_trip_id', (int) ($next['id'] ?? 0));
    self::perf_set('messages_unread', (int) ($messages['unread'] ?? 0));
    self::perf_set('payments_pending', round((float) ($payments['pending'] ?? 0), 2));
    self::perf_set('next_action_status', (string) ($next_action['status'] ?? ''));

    if (function_exists('casanova_perf_measure')) {
      casanova_perf_measure('dashboard_breakdown', $perf_total_start, array_merge([
        'user_id' => $user_id,
        'idCliente' => $idCliente,
        'refresh' => $refresh ? 1 : 0,
      ], self::perf_context()), ['status' => 'ok']);
    }

    return new Casanova_Dashboard_DTO($data);
  }

  /**
   * Devuelve solo viajes futuros/en curso para el dashboard.
   *
   * @return array{trips: array<int,array<string,mixed>>}
   */
  private static function get_trips_for_dashboard(int $idCliente): array {

    if (!$idCliente || !function_exists('casanova_giav_expedientes_por_cliente')) {
      return ['trips' => []];
    }

    $tz = wp_timezone();
    $today = new DateTimeImmutable('today', $tz);
    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');

    $exps_fetch_start = microtime(true);
    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    self::perf_mark('trips_expedientes_fetch_ms', $exps_fetch_start);
    if (!is_array($exps)) {
      return ['trips' => []];
    }
    self::perf_set('trips_source_count', count($exps));

    $upcoming = [];
    $loop_start = microtime(true);
    $payments_calc_ms = 0.0;

    foreach ($exps as $e) {
      if (!is_object($e)) continue;

      $idExp = (int) ($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
      if (!$idExp && isset($e->Codigo)) $idExp = (int) $e->Codigo;
      if ($idExp <= 0) continue;

      $title  = (string) ($e->Titulo ?? $e->Nombre ?? 'Viaje');
      $code   = (string) ($e->Codigo ?? '');

      $is_closed = self::bool_from_giav($e->Cerrado ?? null);
      if ($is_closed) continue;

      $dates = self::resolve_trip_dates($e, $idCliente, $idExp, $tz);
      $ini_raw = $dates['ini_raw'];
      $fin_raw = $dates['fin_raw'];
      $ini_dt = $dates['ini_dt'];
      $fin_dt = $dates['fin_dt'];

      // Un expediente sin fechas fiables no puede presentarse como "viaje activo".
      if (!$ini_dt && !$fin_dt) continue;

      $ini_iso = $ini_dt ? $ini_dt->format('Y-m-d') : null;
      $fin_iso = $fin_dt ? $fin_dt->format('Y-m-d') : null;

      // Estado configurable (EntityStages) si existe
      $stage_id = isset($e->IdEntityStage) ? (int) $e->IdEntityStage : 0;
      $stage_name = ($stage_id > 0 && function_exists('casanova_giav_entity_stage_name'))
        ? casanova_giav_entity_stage_name('Expediente', $stage_id)
        : null;
      $status_raw = (string) ($stage_name ?: ($e->Estado ?? $e->Situacion ?? ''));

      // Clasificación temporal (sin depender del “estado” textual)
      $is_past = false;
      $is_active = false;
      if ($fin_dt) {
        $is_past = $fin_dt < $today;
        $is_active = ($ini_dt ? ($ini_dt <= $today && $fin_dt >= $today) : ($fin_dt >= $today));
      } elseif ($ini_dt) {
        $is_past = $ini_dt < $today;
        $is_active = ($ini_dt <= $today);
      }

      // Fallback: si GIAV no trae estado, inferimos por fechas
      $status = $status_raw;
      if ($status === '' && ($ini_dt || $fin_dt)) {
        $status = $is_past ? 'Finalizado' : 'En curso';
      }

      $date_range = function_exists('casanova_fmt_date_range')
        ? (string) casanova_fmt_date_range($ini_raw ?? null, $fin_raw)
        : '';

      $url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);

      $days_left = null;
      if ($ini_dt) {
        // Si ya empezó, 0.
        $days_left = (int) max(0, $today->diff($ini_dt)->format('%r%a'));
        if ($days_left < 0) $days_left = 0;
      }

      $ics_url = function_exists('casanova_portal_ics_url')
        ? (string) casanova_portal_ics_url($idExp)
        : add_query_arg([
            'casanova_action' => 'download_ics',
            'expediente'      => (int) $idExp,
            '_wpnonce'        => wp_create_nonce('casanova_download_ics_' . (int)$idExp),
          ], $base);

      $trip = [
        'id'         => $idExp,
        'title'      => $title,
        'code'       => $code,
        'status'     => $status,
        'stage'      => [
          'id'   => $stage_id > 0 ? $stage_id : null,
          'name' => $stage_name,
        ],
        'date_start' => $ini_iso,
        'date_end'   => $fin_iso,
        'date_range' => $date_range,
        'url'        => $url,
        'days_left'  => $days_left,
        'calendar_url' => $ics_url,
        // El dashboard solo necesita pagos completos para el prÃ³ximo viaje.
        // El resto se difiere a la vista detalle / expedientes.
        'payments'   => [],
        'bonuses'    => ['available' => null],
        '_flags'     => [
          'is_past' => $is_past,
          'is_active' => $is_active,
          'is_closed' => $is_closed,
        ],
      ];

      if (!$is_past) $upcoming[] = $trip;
    }

    // Orden: próximos por fecha de inicio asc (nulos al final)
    self::perf_mark('trips_loop_ms', $loop_start);
    self::perf_add('trips_payments_calc_ms', $payments_calc_ms);
    self::perf_set('trips_payments_mode', 'next_trip_only');
    $sort_start = microtime(true);
    usort($upcoming, function($a, $b) {
      $ad = $a['date_start'] ?? null;
      $bd = $b['date_start'] ?? null;
      if ($ad === $bd) return 0;
      if ($ad === null) return 1;
      if ($bd === null) return -1;
      return strcmp((string)$ad, (string)$bd);
    });
    self::perf_mark('trips_sort_ms', $sort_start);
    $upcoming = array_slice($upcoming, 0, 10);
    self::perf_set('trips_upcoming_count', count($upcoming));
    self::perf_set('trips_payments_deferred_count', count($upcoming));

    return ['trips' => $upcoming];
  }

  /**
   * Normaliza fechas GIAV a DateTimeImmutable en la TZ de WordPress.
   */
  private static function dt_from_giav($value, DateTimeZone $tz): ?DateTimeImmutable {
    if (!$value) return null;
    // Si existe helper de vuestro stack, lo usamos.
    if (function_exists('casanova_date_to_iso')) {
      $iso = casanova_date_to_iso($value);
      if ($iso) {
        try { return new DateTimeImmutable((string)$iso, $tz); } catch (Throwable $e) {}
      }
    }
    // Fallback genérico
    $ts = strtotime((string)$value);
    if (!$ts) return null;
    try {
      return (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    } catch (Throwable $e) {
      return null;
    }
  }

  /**
   * Intenta resolver las fechas del expediente y, si no existen, las infiere
   * desde las reservas asociadas para clasificar bien viajes heredados/importados.
   *
   * @return array{ini_raw:mixed,fin_raw:mixed,ini_dt:?DateTimeImmutable,fin_dt:?DateTimeImmutable}
   */
  private static function resolve_trip_dates($expediente, int $idCliente, int $idExpediente, DateTimeZone $tz): array {
    $ini_raw = $expediente->FechaDesde ?? $expediente->Desde ?? $expediente->FechaInicio ?? $expediente->FechaInicioViaje ?? null;
    $fin_raw = $expediente->FechaHasta ?? $expediente->Hasta ?? $expediente->FechaFin ?? $expediente->FechaFinViaje ?? null;

    $ini_dt = self::dt_from_giav($ini_raw, $tz);
    $fin_dt = self::dt_from_giav($fin_raw, $tz);

    if ($ini_dt || $fin_dt) {
      return [
        'ini_raw' => $ini_raw,
        'fin_raw' => $fin_raw,
        'ini_dt' => $ini_dt,
        'fin_dt' => $fin_dt,
      ];
    }

    return self::infer_trip_dates_from_reservas($idCliente, $idExpediente, $tz);
  }

  /**
   * @return array{ini_raw:mixed,fin_raw:mixed,ini_dt:?DateTimeImmutable,fin_dt:?DateTimeImmutable}
   */
  private static function infer_trip_dates_from_reservas(int $idCliente, int $idExpediente, DateTimeZone $tz): array {
    static $cache = [];

    $cache_key = $idCliente . ':' . $idExpediente;
    if (isset($cache[$cache_key])) {
      return $cache[$cache_key];
    }

    $empty = [
      'ini_raw' => null,
      'fin_raw' => null,
      'ini_dt' => null,
      'fin_dt' => null,
    ];

    if ($idCliente <= 0 || $idExpediente <= 0 || !function_exists('casanova_giav_reservas_por_expediente')) {
      $cache[$cache_key] = $empty;
      return $cache[$cache_key];
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (!is_array($reservas) || empty($reservas)) {
      $cache[$cache_key] = $empty;
      return $cache[$cache_key];
    }

    $min_start = null;
    $min_start_raw = null;
    $max_end = null;
    $max_end_raw = null;

    foreach ($reservas as $reserva) {
      if (!is_object($reserva)) continue;

      $res_ini_raw = $reserva->FechaDesde ?? $reserva->Desde ?? $reserva->FechaInicio ?? $reserva->FechaInicioViaje ?? null;
      $res_fin_raw = $reserva->FechaHasta ?? $reserva->Hasta ?? $reserva->FechaFin ?? $reserva->FechaFinViaje ?? null;

      $res_ini_dt = self::dt_from_giav($res_ini_raw, $tz);
      $res_fin_dt = self::dt_from_giav($res_fin_raw, $tz);

      if (!$res_ini_dt && $res_fin_dt) {
        $res_ini_dt = $res_fin_dt;
        $res_ini_raw = $res_fin_raw;
      }
      if (!$res_fin_dt && $res_ini_dt) {
        $res_fin_dt = $res_ini_dt;
        $res_fin_raw = $res_ini_raw;
      }

      if ($res_ini_dt && ($min_start === null || $res_ini_dt < $min_start)) {
        $min_start = $res_ini_dt;
        $min_start_raw = $res_ini_raw;
      }
      if ($res_fin_dt && ($max_end === null || $res_fin_dt > $max_end)) {
        $max_end = $res_fin_dt;
        $max_end_raw = $res_fin_raw;
      }
    }

    $cache[$cache_key] = [
      'ini_raw' => $min_start_raw,
      'fin_raw' => $max_end_raw,
      'ini_dt' => $min_start,
      'fin_dt' => $max_end,
    ];

    return $cache[$cache_key];
  }

  private static function bool_from_giav($value): bool {
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return ((int) $value) === 1;

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'si', 'sí'], true);
  }

  /**
   * @param array<string,mixed>|null $next_trip
   * @return array<string,mixed>
   */
  private static function get_payments_summary(int $idCliente, ?array $next_trip): array {

    if (!$idCliente || empty($next_trip['id'])) {
      return [];
    }

    $idExp = (int) $next_trip['id'];
    if (!$idExp) return [];

    $calc = self::get_payments_for_expediente($idCliente, $idExp);
    if (empty($calc)) return [];

    $total = (float) ($calc['total'] ?? 0);
    $paid = (float) ($calc['paid'] ?? 0);
    $pending = (float) ($calc['pending'] ?? 0);

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base) . '#pagos';

    return [
      'total'   => $total,
      'paid'    => $paid,
      'pending' => $pending,
      'url'     => $url,
    ];
  }

  /**
   * @param array<string,mixed>|null $next_trip
   * @return array<string,mixed>|null
   */
  private static function get_dashboard_trip_summary(int $idCliente, ?array $next_trip): ?array {
    $idExp = (int) ($next_trip['id'] ?? 0);
    if ($idCliente <= 0 || $idExp <= 0) {
      return null;
    }

    if (!class_exists('Casanova_Trip_Service') || !method_exists('Casanova_Trip_Service', 'get_dashboard_summary')) {
      return null;
    }

    $summary = Casanova_Trip_Service::get_dashboard_summary($idCliente, $idExp);
    return is_array($summary) ? $summary : null;
  }

  /**
   * @return array<string,mixed>
   */
  private static function get_payments_for_expediente(int $idCliente, int $idExp): array {
    static $cache = [];

    $cache_key = $idCliente . ':' . $idExp;
    if (isset($cache[$cache_key])) {
      return $cache[$cache_key];
    }

    if ($idCliente <= 0 || $idExp <= 0) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }
    if (!function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }

    $reservas = casanova_giav_reservas_por_expediente($idExp, $idCliente);
    if (!is_array($reservas)) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }

    $p = casanova_calc_pago_expediente($idExp, $idCliente, $reservas);
    if (!is_array($p)) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }

    $total = (float) ($p['total_objetivo'] ?? 0);
    $paid = (float) ($p['pagado'] ?? ($p['pagado_real'] ?? 0));
    $pending = isset($p['pendiente_real']) ? (float) $p['pendiente_real'] : max(0, $total - $paid);
    if ($pending < 0) $pending = 0;
    $is_paid = !empty($p['expediente_pagado']) || ($pending <= 0.01);

    $deposit_allowed = false;
    $deposit_amount = 0.0;
    if ($paid <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
      $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
    }
    if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
      $deposit_amount = casanova_payments_calc_deposit_amount($pending, $idExp);
    }
    $deposit_effective = $deposit_allowed && ($deposit_amount + 0.01 < $pending);

    $deadline_iso = null;
    if (function_exists('casanova_payments_min_fecha_limite')) {
      $deadline = casanova_payments_min_fecha_limite($reservas);
      if ($deadline instanceof DateTimeInterface) {
        $deadline_iso = $deadline->format('Y-m-d');
      }
    }

    $can_pay_full = $pending > 0.01;
    $payment_options = [
      'can_pay_deposit' => (bool) $deposit_effective,
      'deposit_deadline' => $deadline_iso,
      'can_pay_full' => (bool) $can_pay_full,
      'recommended' => $deposit_effective ? 'deposit' : ($can_pay_full ? 'full' : null),
      'deposit_amount' => round($deposit_amount, 2),
      'pending_amount' => round($pending, 2),
    ];

    $cache[$cache_key] = [
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'is_paid' => $is_paid,
      'payment_options' => $payment_options,
    ];

    return $cache[$cache_key];
  }


  /**
   * Devuelve un listado normalizado de facturas (GIAV) para un expediente.
   * @return array<int,array<string,mixed>>
   */
  private static function get_invoices_for_expediente(int $idCliente, int $idExpediente): array {
    static $cache = [];

    $cache_key = $idCliente . ':' . $idExpediente;
    if (isset($cache[$cache_key])) {
      return $cache[$cache_key];
    }

    if ($idCliente <= 0 || $idExpediente <= 0 || !function_exists('casanova_giav_facturas_por_expediente')) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }
    $rows = casanova_giav_facturas_por_expediente($idExpediente, $idCliente, 50, 0);
    if (is_wp_error($rows) || !is_array($rows)) {
      $cache[$cache_key] = [];
      return $cache[$cache_key];
    }
    $out = [];
    foreach ($rows as $f) {
      if (!is_object($f)) continue;
      $id = (int) ($f->Id ?? $f->ID ?? 0);
      if ($id <= 0) continue;

      $num = (string) ($f->Numero ?? $f->NumFactura ?? $f->Codigo ?? ('F' . $id));
      $fecha = (string) ($f->Fecha ?? $f->FechaFactura ?? '');
      $iso = $fecha ? gmdate('Y-m-d', strtotime($fecha)) : null;

      $importe = null;
      foreach (['Importe', 'Total', 'ImporteTotal', 'ImporteFactura'] as $k) {
        if (isset($f->$k) && $f->$k !== '') { $importe = (float) $f->$k; break; }
      }

      $estado = (string) ($f->Estado ?? $f->Situacion ?? '');
      $out[] = [
        'id' => $id,
        'title' => $num,
        'date' => $iso,
        'amount' => $importe,
        'status' => $estado,
        'download_url' => '', // se rellena en /trip, aquí solo contamos
      ];
    }
    $cache[$cache_key] = $out;
    return $cache[$cache_key];
  }

  private static function get_invoices_count_for_expediente(int $idCliente, int $idExpediente): int {
    $inv = self::get_invoices_for_expediente($idCliente, $idExpediente);
    return is_array($inv) ? count($inv) : 0;
  }


  /**
   * @param array<int,array<string,mixed>> $trips
   * @param array<string,mixed> $payments
   * @return array<string,mixed>
   */
  private static function get_next_action(int $idCliente, ?array $next_trip, array $trips, array $payments): array {
    if (!$next_trip || empty($next_trip['id'])) {
      return [
        'status' => 'empty',
        'badge' => __('Info', 'casanova-portal'),
        'description' => __('No hay viajes próximos para mostrar aquí.', 'casanova-portal'),
      ];
    }

    $trip_label = self::format_trip_label($next_trip);
    $pending = (float) ($payments['pending'] ?? 0);
    $has_payments = !empty($payments);

    if ($has_payments && $pending > 0.01) {
      return [
        'status' => 'pending',
        'badge' => __('Pendiente', 'casanova-portal'),
        'description' => sprintf(
          __('Tienes un pago pendiente de %s.', 'casanova-portal'),
          casanova_fmt_money($pending)
        ),
        'expediente_id' => (int) $next_trip['id'],
        'trip_label' => $trip_label,
      ];
    }
    // Si no hay pagos pendientes, revisamos si hay facturas disponibles para descargar
    $inv_count = self::get_invoices_count_for_expediente($idCliente, (int) $next_trip['id']);
    if ($inv_count > 0) {
      return [
        'status' => 'invoices',
        'badge' => __('Facturas', 'casanova-portal'),
        'description' => sprintf(__('Tienes %d facturas disponibles.', 'casanova-portal'), $inv_count),
        'expediente_id' => (int) $next_trip['id'],
        'trip_label' => $trip_label,
        'invoice_count' => $inv_count,
      ];
    }



    $note = null;
    if (count($trips) >= 2) {
      $second = $trips[1];
      $second_id = (int) ($second['id'] ?? 0);
      if ($second_id > 0 && $second_id !== (int) $next_trip['id']) {
        $calc = self::get_payments_for_expediente($idCliente, $second_id);
        if (!empty($calc)) {
          $pend2 = (float) ($calc['pending'] ?? 0);
          if ($pend2 > 0.01) {
            $note = [
              'label' => self::format_trip_label($second),
              'expediente_id' => $second_id,
              'pending' => casanova_fmt_money($pend2),
            ];
          }
        }
      }
    }

    return [
      'status' => 'ok',
      'badge' => __('Todo listo', 'casanova-portal'),
      'description' => __('Tu próximo viaje está al día. No tienes acciones pendientes ahora mismo.', 'casanova-portal'),
      'expediente_id' => (int) $next_trip['id'],
      'trip_label' => $trip_label,
      'note' => $note,
    ];
  }

  /**
   * @param array<string,mixed> $trip
   */
  private static function format_trip_label(array $trip): string {
    $title = trim((string) ($trip['title'] ?? ''));
    $code = trim((string) ($trip['code'] ?? ''));
    if ($title && $code) return $title . ' (' . $code . ')';
    if ($title) return $title;
    if ($code) return sprintf(__('Expediente %s', 'casanova-portal'), $code);
    $id = (int) ($trip['id'] ?? 0);
    return $id ? sprintf(__('Expediente %s', 'casanova-portal'), (string) $id) : __('Viaje', 'casanova-portal');
  }

  /**
   * @param array<string,mixed>|null $next_trip
   * @return array<string,mixed>
   */
  private static function get_messages_summary(int $user_id, int $idCliente, ?array $next_trip): array {

    $idExp = (int) ($next_trip['id'] ?? 0);
    if (!$idExp) {
      return [];
    }

    if (!function_exists('casanova_messages_thread_summary_for_expediente')) {
      return [];
    }

    $thread = casanova_messages_thread_summary_for_expediente($user_id, $idExp, 30);
    $unread = (int) ($thread['unread'] ?? 0);
    $snippet = (string) ($thread['snippet'] ?? '');
    $last_message_ts = (int) ($thread['last_message_ts'] ?? 0);
    $when = $last_message_ts ? sprintf(esc_html__('Hace %s', 'casanova-portal'), human_time_diff($last_message_ts, time())) : '';

    if (false) {
      $latest = $comments[0];
      $b = is_object($latest) ? trim((string) ($latest->Body ?? '')) : '';
      $b = $b !== '' ? wp_strip_all_tags($b) : '';
      if ($b !== '' && mb_strlen($b, 'UTF-8') > 140) {
        $b = mb_substr($b, 0, 140, 'UTF-8') . '…';
      }
      $snippet = $b;

      $ts = is_object($latest) ? (strtotime((string) ($latest->CreationDate ?? '')) ?: 0) : 0;
      $when = $ts ? sprintf(esc_html__('Hace %s', 'casanova-portal'), human_time_diff($ts, time())) : '';
    }

    $trip_label = '';
    if (!empty($next_trip['title']) || !empty($next_trip['code'])) {
      $t = trim((string) ($next_trip['title'] ?? ''));
      $c = trim((string) ($next_trip['code'] ?? ''));
      if ($t && $c) $trip_label = $t . ' (' . $c . ')';
      elseif ($t) $trip_label = $t;
      elseif ($c) $trip_label = sprintf(__('Expediente %s', 'casanova-portal'), $c);
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = add_query_arg(['view' => 'mensajes', 'expediente' => $idExp], $base);

    return [
      'unread'    => $unread,
      'snippet'   => $snippet,
      'when'      => $when,
      'trip_label'=> $trip_label,
      'url'       => $url,
    ];
  }
}
