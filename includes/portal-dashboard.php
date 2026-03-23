<?php
if (!defined('ABSPATH')) exit;

/**
 * Dashboard principal del portal — Rediseño v2.
 *
 * Hero del viaje + KPIs + pagos + includes + equipo + mensajes + mulligans.
 * Mantiene el enfoque "sin romper": todo es lectura, usa wrappers GIAV existentes.
 */
function casanova_portal_render_dashboard(int $user_id): string {
  // Nuevo flujo (API-first): el dashboard deja de calcular datos aquí.
  if (class_exists('Casanova_Dashboard_Service') && class_exists('Casanova_Dashboard_DTO')) {
    try {
      $dto  = Casanova_Dashboard_Service::build_for_user($user_id);
      $data = $dto ? $dto->to_array() : [];

      if (is_array($data) && !empty($data)) {
        return casanova_portal_render_dashboard_from_data($user_id, $data);
      }
    } catch (Exception $e) {
      // Silencioso: si algo falla, caemos al legacy para no romper el portal.
    }
  }

  $mulligans_enabled = function_exists('casanova_portal_mulligans_enabled')
    ? casanova_portal_mulligans_enabled()
    : true;

  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  // 1) Mulligans (datos locales)
  $m = function_exists('casanova_mulligans_get_user') ? casanova_mulligans_get_user($user_id) : [];
  $m_points = isset($m['points']) ? (int)$m['points'] : 0;
  $m_tier   = isset($m['tier']) ? (string)$m['tier'] : '';

  // 2) Próximo viaje (GIAV: expedientes)
  $next = null;
  $today = new DateTimeImmutable('today', wp_timezone());

  if ($idCliente && function_exists('casanova_giav_expedientes_por_cliente')) {
    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    if (is_array($exps)) {
      foreach ($exps as $e) {
        if (!is_object($e)) continue;
        $ini = $e->FechaInicio ?? $e->FechaDesde ?? $e->Desde ?? null;
        if (!$ini) continue;
        $ts = strtotime((string)$ini);
        if (!$ts) continue;

        $d = (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
        if ($d < $today) continue;

        if ($next === null || $d < $next['date']) {
          $next = [
            'obj' => $e,
            'date' => $d,
          ];
        }
      }
    }
  }

  // Build legacy-compatible data array from raw GIAV objects
  $data = [
    'mulligans' => ['points' => $m_points, 'tier' => $m_tier, 'progress_pct' => 0, 'next_tier_label' => ''],
    'next_trip' => null,
    'payments'  => [],
    'messages'  => [],
    'active_trip_exists' => false,
  ];

  if ($next) {
    $e = $next['obj'];
    $idExp = (int)($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
    if (!$idExp && isset($e->Codigo)) $idExp = (int)$e->Codigo;

    $titulo = (string)($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $codigo = (string)($e->Codigo ?? '');
    $estado = (string)($e->Estado ?? $e->Situacion ?? '');

    $fin = $e->FechaFin ?? $e->FechaHasta ?? $e->Hasta ?? null;
    $rango = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($e->FechaInicio ?? null, $fin) : '';

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $exp_url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);

    $days_left = max(0, (int)$today->diff($next['date'])->days);

    $data['next_trip'] = [
      'id'         => $idExp,
      'title'      => $titulo,
      'code'       => $codigo,
      'status'     => $estado,
      'date_range' => $rango,
      'url'        => $exp_url,
      'days_left'  => $days_left,
    ];
    $data['active_trip_exists'] = true;

    // Pagos
    if ($idCliente && $idExp && function_exists('casanova_giav_reservas_por_expediente') && function_exists('casanova_calc_pago_expediente')) {
      $reservas = casanova_giav_reservas_por_expediente($idExp, $idCliente);
      if (is_array($reservas)) {
        $p = casanova_calc_pago_expediente($idExp, $idCliente, $reservas);
        if (is_array($p)) {
          $total = (float)($p['total_objetivo'] ?? 0);
          $pagado = (float)($p['pagado_real'] ?? 0);
          $pend = max(0, $total - $pagado);
          $data['payments'] = [
            'total'   => $total,
            'paid'    => $pagado,
            'pending' => $pend,
            'url'     => $exp_url . '#pagos',
          ];
        }
      }
    }

    // Mensajes
    if ($idExp && function_exists('casanova_giav_comments_por_expediente')) {
      $n_new = function_exists('casanova_messages_new_count_for_expediente') ? (int) casanova_messages_new_count_for_expediente($user_id, $idExp, 30) : 0;
      $comments = casanova_giav_comments_por_expediente($idExp, 10, 365);

      if (!is_wp_error($comments) && is_array($comments) && !empty($comments)) {
        $latest = $comments[0];
        $b = is_object($latest) ? trim((string)($latest->Body ?? '')) : '';
        $b = $b !== '' ? wp_strip_all_tags($b) : '';
        if (mb_strlen($b, 'UTF-8') > 140) $b = mb_substr($b, 0, 140, 'UTF-8') . "\u{2026}";
        $ts_msg = is_object($latest) ? (strtotime((string)($latest->CreationDate ?? '')) ?: 0) : 0;
        $when = $ts_msg ? sprintf(esc_html__('Hace %s', 'casanova-portal'), human_time_diff($ts_msg, time())) : '';

        $msg_url = add_query_arg(['view' => 'mensajes', 'expediente' => $idExp], casanova_portal_base_url());
        $data['messages'] = [
          'unread'  => $n_new,
          'snippet' => $b,
          'when'    => $when,
          'url'     => $msg_url,
        ];
      }
    }
  }

  return casanova_portal_render_dashboard_from_data($user_id, $data);
}

/**
 * Renderiza el dashboard v2 a partir de datos (DTO/API).
 */
function casanova_portal_render_dashboard_from_data(int $user_id, array $data): string {
  $mulligans_enabled = function_exists('casanova_portal_mulligans_enabled')
    ? casanova_portal_mulligans_enabled()
    : true;

  $m = is_array($data['mulligans'] ?? null) ? $data['mulligans'] : [];
  $m_points     = (int)($m['points'] ?? 0);
  $m_tier       = (string)($m['tier'] ?? '');
  $m_progress   = (int)($m['progress_pct'] ?? 0);
  $m_next_tier  = (string)($m['next_tier_label'] ?? '');

  $next = is_array($data['next_trip'] ?? null) ? $data['next_trip'] : null;
  $has_active_trip = !empty($data['active_trip_exists']) && is_array($next) && !empty($next['id']);
  $pay  = is_array($data['payments'] ?? null) ? $data['payments'] : [];
  $msg  = is_array($data['messages'] ?? null) ? $data['messages'] : [];

  // Agency info for team card
  $agency = function_exists('casanova_portal_agency_profile') ? casanova_portal_agency_profile() : [];
  $agency_name  = !empty($agency['nombre']) ? $agency['nombre'] : 'Casanova Golf';
  $agency_email = !empty($agency['email']) ? $agency['email'] : '';
  $agency_phone = !empty($agency['tel']) ? $agency['tel'] : '';

  // Current user
  $current_user = wp_get_current_user();
  $display_name = $current_user->display_name ?: $current_user->user_login;

  // SVG icon helpers (inline, stroke-based)
  $svg_location = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>';
  $svg_calendar = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  $svg_file     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';
  $svg_check    = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>';
  $svg_hotel    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21V7a2 2 0 0 1 2-2h6v16"/><path d="M21 21V11a2 2 0 0 0-2-2h-4v12"/><path d="M7 9h2M7 13h2M15 13h2M15 17h2"/></svg>';
  $svg_golf     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="18" r="3"/><path d="M12 2v13"/><path d="M12 5l6-2v7l-6 2"/></svg>';
  $svg_transport = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-3M1 16h16"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
  $svg_clock    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
  $svg_phone    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
  $svg_mail     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>';
  $svg_message  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

  $html = '<div class="casanova-dashboard">';

  // ─── Topbar ───
  $html .= '<div class="casanova-topbar casanova-fade-up casanova-d1">';
  $html .= '  <div>';
  $html .= '    <span class="casanova-topbar__greeting-label">' . esc_html__('Bienvenido de nuevo', 'casanova-portal') . '</span>';
  $html .= '    <h1 class="casanova-topbar__greeting-name">' . esc_html(sprintf(__('Hola, %s', 'casanova-portal'), $display_name)) . '</h1>';
  $html .= '  </div>';
  $html .= '</div>';

  // ─── Empty state: no active trip ───
  if (!$has_active_trip) {
    $html .= '<div class="casanova-dash-empty casanova-fade-up casanova-d2">';
    $html .= '  <p class="casanova-dash-empty__title">' . esc_html__('Tu viaje', 'casanova-portal') . '</p>';
    $html .= '  <p><strong>' . esc_html__('Aún no tienes un viaje confirmado.', 'casanova-portal') . '</strong></p>';
    $html .= '  <p class="casanova-dash-empty__text">' . esc_html__('Cuando tu reserva esté lista, aquí verás todos los detalles de tu viaje: hotel, campos de golf, pagos y documentación.', 'casanova-portal') . '</p>';
    $html .= '</div>';

    // Still show Mulligans if enabled
    if ($mulligans_enabled) {
      $html .= casanova_portal_render_dashboard_loyalty($m_points, $m_tier, $m_progress, $m_next_tier);
    }

    $html .= '</div>'; // close .casanova-dashboard
    return $html;
  }

  // ─── Trip data ───
  $titulo   = (string)($next['title'] ?? __('Viaje', 'casanova-portal'));
  $codigo   = (string)($next['code'] ?? '');
  $estado   = (string)($next['status'] ?? '');
  $rango    = (string)($next['date_range'] ?? '');
  $exp_url  = (string)($next['url'] ?? '');
  $days_left = isset($next['days_left']) ? (int)$next['days_left'] : null;

  // ─── Hero section ───
  $html .= '<div class="casanova-hero casanova-fade-up casanova-d2">';

  // Left: trip info
  $html .= '  <div class="casanova-hero-trip">';
  $html .= '    <div class="casanova-hero-trip__label"><span class="casanova-hero-trip__label-dot"></span> ' . esc_html__('Tu próximo viaje', 'casanova-portal') . '</div>';
  $html .= '    <h2 class="casanova-hero-trip__title">' . ($exp_url ? '<a href="' . esc_url($exp_url) . '">' . esc_html($titulo) . '</a>' : esc_html($titulo)) . '</h2>';
  $html .= '    <div class="casanova-hero-trip__meta">';
  if ($rango) {
    $html .= '      <span class="casanova-hero-trip__meta-item">' . $svg_calendar . ' ' . esc_html($rango) . '</span>';
  }
  if ($codigo) {
    $html .= '      <span class="casanova-hero-trip__meta-item">' . $svg_file . ' ' . esc_html(sprintf(__('Ref. %s', 'casanova-portal'), $codigo)) . '</span>';
  }
  $html .= '    </div>';
  if ($estado) {
    $html .= '    <div class="casanova-hero-trip__status">' . $svg_check . ' ' . esc_html($estado) . '</div>';
  }
  $html .= '    <div class="casanova-hero-trip__cta">';
  if ($exp_url) {
    $html .= '      <a href="' . esc_url($exp_url) . '" class="is-primary">' . esc_html__('Ver detalles', 'casanova-portal') . '</a>';
    $html .= '      <a href="' . esc_url($exp_url . '#pagos') . '" class="is-secondary">' . esc_html__('Pagos', 'casanova-portal') . '</a>';
  }
  $html .= '    </div>';
  $html .= '  </div>';

  // Right: featured card with countdown
  $html .= '  <div class="casanova-hero-featured">';
  $html .= '    <div class="casanova-hero-scene"><div class="casanova-hero-scene__sky"></div><div class="casanova-hero-scene__hills"></div><div class="casanova-hero-scene__flag"></div></div>';
  if ($days_left !== null) {
    $html .= '    <div class="casanova-hero-featured__countdown">' . esc_html(sprintf(__('Tu viaje empieza en %d días', 'casanova-portal'), $days_left)) . '</div>';
  }
  $html .= '    <div class="casanova-hero-featured__caption">';
  $html .= '      <p class="casanova-hero-featured__tag">' . esc_html__('Viaje destacado', 'casanova-portal') . '</p>';
  $html .= '      <h3 class="casanova-hero-featured__title">' . esc_html($titulo) . '</h3>';
  $html .= '    </div>';
  $html .= '  </div>';

  $html .= '</div>'; // close .casanova-hero

  // ─── KPI Grid ───
  $total_pay  = (float)($pay['total'] ?? 0);
  $paid_pay   = (float)($pay['paid'] ?? 0);
  $pend_pay   = (float)($pay['pending'] ?? max(0, $total_pay - $paid_pay));

  $html .= '<p class="casanova-section-label casanova-fade-up casanova-d3">' . esc_html__('Tu viaje de un vistazo', 'casanova-portal') . '</p>';
  $html .= '<div class="casanova-kpi-grid casanova-fade-up casanova-d3">';

  // KPI: Next step / Payment
  $html .= '  <div class="casanova-kpi">';
  $html .= '    <div class="casanova-kpi__icon casanova-kpi__icon--payment">' . $svg_clock . '</div>';
  $html .= '    <p class="casanova-kpi__cat">' . esc_html__('Próximo paso', 'casanova-portal') . '</p>';
  if ($pend_pay > 0) {
    $html .= '    <p class="casanova-kpi__val">' . esc_html__('Pago pendiente', 'casanova-portal') . '</p>';
    $html .= '    <p class="casanova-kpi__detail">' . esc_html(number_format_i18n($pend_pay, 2)) . ' &euro; ' . esc_html__('pendiente', 'casanova-portal') . '</p>';
  } else {
    $html .= '    <p class="casanova-kpi__val">' . esc_html__('Al día', 'casanova-portal') . '</p>';
  }
  $html .= '  </div>';

  $html .= '</div>'; // close .casanova-kpi-grid

  // ─── Bottom grid: Payments + Messages ───
  $html .= '<div class="casanova-bottom-grid casanova-fade-up casanova-d4">';

  // Payments card
  $html .= '  <div class="casanova-dash-pay">';
  $html .= '    <h3 class="casanova-dash-pay__title">' . esc_html__('Pagos del viaje', 'casanova-portal') . '</h3>';
  if (!empty($pay)) {
    $pct = $total_pay > 0 ? min(100, round(($paid_pay / $total_pay) * 100)) : 0;
    $html .= '    <table class="casanova-dash-pay__table">';
    $html .= '      <tr><td>' . esc_html__('Pagado', 'casanova-portal') . '</td><td>' . esc_html(number_format_i18n($paid_pay, 2)) . ' &euro;</td></tr>';
    $html .= '      <tr><td class="is-danger">' . esc_html__('Pendiente', 'casanova-portal') . '</td><td class="is-danger">' . esc_html(number_format_i18n($pend_pay, 2)) . ' &euro;</td></tr>';
    $html .= '      <tr class="is-total"><td>' . esc_html__('Total', 'casanova-portal') . '</td><td>' . esc_html(number_format_i18n($total_pay, 2)) . ' &euro;</td></tr>';
    $html .= '    </table>';
    $html .= '    <div class="casanova-dash-pay__progress">';
    $html .= '      <div class="casanova-dash-pay__bar"><div class="casanova-dash-pay__bar-fill" style="width:' . (int)$pct . '%"></div></div>';
    $html .= '      <p class="casanova-dash-pay__bar-label">' . esc_html(sprintf(
      /* translators: %1$s = amount paid, %2$s = total amount */
      __('Has pagado %1$s de %2$s', 'casanova-portal'),
      number_format_i18n($paid_pay, 2) . ' €',
      number_format_i18n($total_pay, 2) . ' €'
    )) . '</p>';
    $html .= '    </div>';
    $pay_url = (string)($pay['url'] ?? '');
    if ($pay_url) {
      $html .= '    <a href="' . esc_url($pay_url) . '" class="casanova-dash-pay__cta">' . esc_html__('Ver pagos', 'casanova-portal') . '</a>';
    }
  } else {
    $html .= '    <p class="casanova-muted">' . esc_html__('No disponible.', 'casanova-portal') . '</p>';
  }
  $html .= '  </div>';

  // Messages card
  $html .= '  <div class="casanova-dash-msg">';
  $html .= '    <h3 class="casanova-dash-msg__title">';
  $html .= '      ' . $svg_message . ' ' . esc_html__('Mensajes', 'casanova-portal');
  $unread = (int)($msg['unread'] ?? 0);
  if ($unread > 0) {
    $html .= '      <span class="casanova-dash-msg__badge">' . (int)$unread . '</span>';
  }
  $html .= '    </h3>';
  if (!empty($msg)) {
    $snippet = (string)($msg['snippet'] ?? '');
    $when    = (string)($msg['when'] ?? '');
    $msg_url = (string)($msg['url'] ?? '');
    if ($snippet !== '') {
      $html .= '    <div class="casanova-dash-msg__bubble">';
      $html .= '      <p>' . esc_html($snippet) . '</p>';
      if ($when !== '') {
        $html .= '      <p class="casanova-dash-msg__time">' . esc_html($when) . '</p>';
      }
      $html .= '    </div>';
    }
    if ($msg_url) {
      $html .= '    <a href="' . esc_url($msg_url) . '" class="casanova-dash-msg__cta">' . esc_html__('Abrir mensajes', 'casanova-portal') . '</a>';
    }
  } else {
    $html .= '    <p class="casanova-muted">' . esc_html__('No hay mensajes recientes.', 'casanova-portal') . '</p>';
  }
  $html .= '  </div>';

  $html .= '</div>'; // close .casanova-bottom-grid

  // ─── Team + Contact ───
  $html .= '<div class="casanova-team-grid casanova-fade-up casanova-d5">';
  $html .= '  <div class="casanova-team-card">';
  $html .= '    <p class="casanova-team-card__label">' . esc_html__('Tu equipo Casanova', 'casanova-portal') . '</p>';
  $html .= '    <h3 class="casanova-team-card__name">' . esc_html($agency_name) . '</h3>';
  $html .= '    <p class="casanova-team-card__desc">' . esc_html__('Si necesitas ajustar algo, resolver una duda o revisar el viaje con nosotros, aquí tienes acceso directo al equipo que te acompaña.', 'casanova-portal') . '</p>';
  $html .= '    <div class="casanova-team-card__pills">';
  if ($agency_phone) {
    $html .= '      <a href="tel:' . esc_attr($agency_phone) . '" class="casanova-team-card__pill">' . $svg_phone . ' ' . esc_html($agency_phone) . '</a>';
  }
  if ($agency_email) {
    $html .= '      <a href="mailto:' . esc_attr($agency_email) . '" class="casanova-team-card__pill">' . $svg_mail . ' ' . esc_html($agency_email) . '</a>';
  }
  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '</div>';

  // ─── Loyalty / Mulligans ───
  if ($mulligans_enabled) {
    $html .= casanova_portal_render_dashboard_loyalty($m_points, $m_tier, $m_progress, $m_next_tier);
  }

  $html .= '</div>'; // close .casanova-dashboard

  return $html;
}

/**
 * Renders the loyalty/mulligans section for the dashboard.
 */
function casanova_portal_render_dashboard_loyalty(int $points, string $tier, int $progress_pct, string $next_tier_label): string {
  $html = '<div class="casanova-dash-loyalty casanova-fade-up casanova-d6">';
  $html .= '  <div>';
  $html .= '    <p class="casanova-dash-loyalty__label">' . esc_html__('Programa de fidelización', 'casanova-portal') . '</p>';
  $html .= '    <h3 class="casanova-dash-loyalty__name">' . esc_html__('Tus Mulligans', 'casanova-portal') . '</h3>';
  $html .= '    <div class="casanova-dash-loyalty__level">';
  if ($tier) {
    $html .= '      <span class="casanova-dash-loyalty__current">' . esc_html($tier) . '</span>';
    if ($next_tier_label) {
      $html .= '      <span class="casanova-dash-loyalty__arrow">&rarr;</span>';
      $html .= '      <span class="casanova-dash-loyalty__next">' . esc_html(sprintf(__('Próximo nivel: %s', 'casanova-portal'), $next_tier_label)) . '</span>';
    }
  }
  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '  <div class="casanova-dash-loyalty__points">';
  $html .= '    <p class="casanova-dash-loyalty__pts-label">' . esc_html__('Puntos', 'casanova-portal') . '</p>';
  $html .= '    <p class="casanova-dash-loyalty__pts-val">' . esc_html(number_format_i18n($points)) . '</p>';
  $html .= '  </div>';
  if ($progress_pct > 0 || $next_tier_label) {
    $html .= '  <div class="casanova-dash-loyalty__progress">';
    $html .= '    <div class="casanova-dash-loyalty__bar"><div class="casanova-dash-loyalty__bar-fill" style="width:' . (int)$progress_pct . '%"></div></div>';
    $html .= '    <div class="casanova-dash-loyalty__bar-labels">';
    if ($tier) $html .= '      <span>' . esc_html($tier) . '</span>';
    if ($next_tier_label) $html .= '      <span>' . esc_html($next_tier_label) . '</span>';
    $html .= '    </div>';
    $html .= '  </div>';
  }
  $html .= '</div>';
  return $html;
}
