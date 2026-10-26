<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF Programa del viaje (v4):
 * - Lenguaje visual Casanova 2026 (tokens --cg-*, Satoshi + Playfair, iconos Tabler).
 * - Flights: independent section with segments.
 * - Hotel: Check-in card on arrival day only; subsequent days show
 *   a subtle lodging footer; Check-out card on departure day.
 * - Activities (golf, other): clear event cards per day.
 */
function casanova_render_itinerary_html(array $payload): string {
  $trip = $payload['trip'] ?? null;
  if (!is_array($trip)) {
    return '<p>' . esc_html__('Programa del viaje no disponible.', 'casanova-portal') . '</p>';
  }

  $services = [];
  $packages = is_array($payload['packages'] ?? null) ? array_values($payload['packages']) : [];
  if (empty($packages) && is_array($payload['package'] ?? null)) {
    $packages[] = $payload['package'];
  }
  foreach ($packages as $package) {
    if (!is_array($package)) continue;
    $pkg_services = is_array($package['services'] ?? null) ? $package['services'] : [];
    foreach ($pkg_services as $s) {
      if (is_array($s)) $services[] = $s;
    }
  }
  $extras = is_array($payload['extras'] ?? null) ? $payload['extras'] : [];
  foreach ($extras as $s) {
    if (is_array($s)) $services[] = $s;
  }

  $normalize_ts = function($value) {
    if (empty($value)) return null;
    $ts = strtotime((string)$value);
    return $ts === false ? null : (int)$ts;
  };

  $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

  // Separate flights; expand hotels across days.
  $flight_services = [];
  $service_entries = [];
  $all_timestamps = [];

  $extract_time = function(string $notes, string $kind): string {
    $notes = trim($notes);
    if ($notes === '') return '';
    $re = $kind === 'in'
      ? '/check[\s\-]*in\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i'
      : '/check[\s\-]*out\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i';
    if (preg_match($re, $notes, $m)) {
      return trim((string)($m[1] ?? ''));
    }
    return '';
  };

  foreach ($services as $service) {
    if (!is_array($service)) continue;

    $semantic = (string)($service['semantic_type'] ?? 'other');

    $start_raw = $service['date_from'] ?? '';
    $end_raw = $service['date_to'] ?? '';
    $start_ts = $normalize_ts($start_raw);
    $end_ts = $normalize_ts($end_raw);

    if ($start_ts !== null) $all_timestamps[] = $start_ts;
    if ($end_ts !== null) $all_timestamps[] = $end_ts;

    if ($semantic === 'flight') {
      $flight_services[] = $service;
      continue;
    }

    if ($start_ts === null && $end_ts === null) {
      continue;
    }

    if ($semantic === 'hotel') {
      $start_ts = $start_ts ?? $end_ts;
      $end_ts = $end_ts ?? $start_ts;
      if ($start_ts === null) continue;
      if ($end_ts < $start_ts) {
        $tmp = $start_ts;
        $start_ts = $end_ts;
        $end_ts = $tmp;
      }

      $start_day = date('Y-m-d', $start_ts);
      $checkout_day = date('Y-m-d', $end_ts);
      $last_lodging_ts = $end_ts - $day_seconds;
      $last_lodging_day = $last_lodging_ts >= $start_ts ? date('Y-m-d', $last_lodging_ts) : $start_day;

      $title_base = trim((string)($service['title'] ?? ''));
      if ($title_base === '') $title_base = trim((string)($service['detail']['code'] ?? 'Hotel'));

      $notes = trim((string)($service['detail']['bonus_text'] ?? ''));
      $checkin = $extract_time($notes, 'in');
      $checkout = $extract_time($notes, 'out');

      $board_meta = trim((string)($service['details']['board'] ?? ''));
      $rooming_meta = trim((string)($service['details']['rooming'] ?? ''));

      // Check-in entry on first day
      $service_entries[] = [
        'service' => $service,
        'date' => $start_day,
        'timestamp' => $start_ts,
        'itinerary' => [
          'title' => $title_base,
          'role' => 'checkin',
          'checkin' => $checkin,
          'checkout' => $checkout,
          'board' => $board_meta,
          'rooming' => $rooming_meta,
        ],
      ];

      // Stay entries for intermediate days (subtle lodging footer)
      $ts = $start_ts + $day_seconds;
      while (date('Y-m-d', $ts) <= $last_lodging_day) {
        $date_key = date('Y-m-d', $ts);
        if ($date_key !== $start_day) {
          $service_entries[] = [
            'service' => $service,
            'date' => $date_key,
            'timestamp' => $ts,
            'itinerary' => [
              'title' => $title_base,
              'role' => 'stay',
              'checkin' => '',
              'checkout' => '',
              'board' => '',
              'rooming' => '',
            ],
          ];
        }
        $ts += $day_seconds;
      }

      // Checkout entry on departure day
      $service_entries[] = [
        'service' => $service,
        'date' => $checkout_day,
        'timestamp' => $end_ts,
        'itinerary' => [
          'title' => $title_base,
          'role' => 'checkout',
          'checkin' => '',
          'checkout' => $checkout,
          'board' => '',
          'rooming' => '',
        ],
      ];

      continue;
    }

    // Default (golf, other): use start date.
    $event_ts = $start_ts ?? $end_ts;
    $service_entries[] = [
      'service' => $service,
      'date' => date('Y-m-d', $event_ts),
      'timestamp' => $event_ts,
    ];
  }

  $trip_start_ts = $normalize_ts($trip['date_start'] ?? null);
  $trip_end_ts = $normalize_ts($trip['date_end'] ?? null);
  if ($trip_start_ts !== null) $all_timestamps[] = $trip_start_ts;
  if ($trip_end_ts !== null) $all_timestamps[] = $trip_end_ts;

  if (empty($all_timestamps)) {
    $start_ts = time();
    $end_ts = $start_ts;
  } else {
    $start_ts = $trip_start_ts ?? min($all_timestamps);
    $end_ts = $trip_end_ts ?? max($all_timestamps);
    if ($start_ts > $end_ts) { $tmp = $start_ts; $start_ts = $end_ts; $end_ts = $tmp; }
  }

  // Days map
  $days = [];
  $day_index = 1;
  for ($ts = $start_ts; $ts <= $end_ts; $ts += $day_seconds) {
    $date_key = date('Y-m-d', $ts);
    $day_name = date_i18n('l', $ts);
    if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
      $day_name = mb_strtoupper(mb_substr($day_name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($day_name, 1, null, 'UTF-8');
    } else {
      $day_name = ucfirst($day_name);
    }
    $days[$date_key] = [
      'number' => $day_index,
      'day_name' => $day_name,
      'formatted' => date_i18n('j \d\e F \d\e Y', $ts),
      'timestamp' => $ts,
    ];
    $day_index++;
  }

  // Group by day
  $events_by_day = [];
  foreach ($service_entries as $entry) {
    $date = $entry['date'];
    if (!isset($events_by_day[$date])) $events_by_day[$date] = [];
    $events_by_day[$date][] = $entry;
  }

  // Sort events within each day: activities first, hotel stays last
  foreach ($events_by_day as &$day_events) {
    usort($day_events, function($a, $b) {
      $a_role = (string)(($a['itinerary'] ?? [])['role'] ?? '');
      $b_role = (string)(($b['itinerary'] ?? [])['role'] ?? '');
      // stays go to end
      $a_weight = $a_role === 'stay' ? 99 : ($a_role === 'checkout' ? 0 : ($a_role === 'checkin' ? 50 : 10));
      $b_weight = $b_role === 'stay' ? 99 : ($b_role === 'checkout' ? 0 : ($b_role === 'checkin' ? 50 : 10));
      if ($a_weight !== $b_weight) return $a_weight <=> $b_weight;
      return ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0));
    });
  }
  unset($day_events);

  $logo = casanova_itinerary_logo_data_uri();
  $trip_title = trim((string)($trip['title'] ?? ''));
  if ($trip_title === '') $trip_title = __('Programa del viaje', 'casanova-portal');

  $trip_code = trim((string)($trip['code'] ?? ''));
  $trip_id = (int)($trip['id'] ?? 0);
  if ($trip_code !== '' && $trip_id > 0) $expediente_label = $trip_code . ' (#' . $trip_id . ')';
  elseif ($trip_code !== '') $expediente_label = $trip_code;
  elseif ($trip_id > 0) $expediente_label = '#' . $trip_id;
  else $expediente_label = '';

  $display_dates = trim((string)($trip['date_range'] ?? ''));
  if ($display_dates === '' && !empty($trip['date_start']) && !empty($trip['date_end'])) {
    $display_dates = casanova_fmt_date_range($trip['date_start'], $trip['date_end']);
  }

  $resolve_service_pax = function(array $packages, array $services): int {
    $best_priority = PHP_INT_MAX;
    $best_pax = 0;

    $push_service = function($service) use (&$best_priority, &$best_pax) {
      if (!is_array($service)) return;

      $travellers = is_array($service['travellers'] ?? null) ? $service['travellers'] : [];
      $pax = (int)($travellers['pax'] ?? 0);
      if ($pax <= 0) {
        $pax = (int)($travellers['adults'] ?? 0) + (int)($travellers['children'] ?? 0);
      }
      if ($pax <= 0) return;

      $type = strtoupper(trim((string)($service['type'] ?? ($service['giav_type'] ?? ''))));
      $priority = $type === 'PQ' ? 0 : ($type === 'HT' ? 1 : 2);

      if ($priority < $best_priority || ($priority === $best_priority && $pax > $best_pax)) {
        $best_priority = $priority;
        $best_pax = $pax;
      }
    };

    foreach ($packages as $package) {
      $push_service($package);
    }
    foreach ($services as $service) {
      $push_service($service);
    }

    return $best_pax;
  };

  $pax_count = $resolve_service_pax($packages, $services);
  if ($pax_count <= 0) $pax_count = (int)($trip['pax'] ?? 0);
  if ($pax_count <= 0 && is_array($payload['passengers'] ?? null)) $pax_count = count($payload['passengers']);
  $pax_label = $pax_count > 0 ? sprintf(__('%d Pax', 'casanova-portal'), $pax_count) : '';

  $board = '';
  foreach ($services as $service) {
    $candidate = trim((string)($service['details']['board'] ?? ''));
    if ($candidate !== '') { $board = $candidate; break; }
  }

  $total_days = count($days);
  $total_nights = max(0, $total_days - 1);

  $meta_items = [];
  if ($display_dates !== '') $meta_items[] = [__('Fechas', 'casanova-portal'), $display_dates];
  if ($total_days > 0) $meta_items[] = [__('Duración', 'casanova-portal'), sprintf(__('%d días / %d noches', 'casanova-portal'), $total_days, $total_nights)];
  if ($pax_label !== '') $meta_items[] = [__('Viajeros', 'casanova-portal'), $pax_label];
  if ($board !== '') $meta_items[] = [__('Régimen', 'casanova-portal'), $board];

  $itinerary_css_path = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-itinerary.css';
  $itinerary_css = file_exists($itinerary_css_path) ? (string) file_get_contents($itinerary_css_path) : '';

  ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html($trip_title); ?></title>
  <style>
<?php echo casanova_itinerary_font_faces(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php echo $itinerary_css; // phpcs:ignore WordPress.Security.EscapeOutput ?>
  </style>
</head>
<body class="casanova-itinerary-body">

  <!-- Pie fijo: Dompdf lo repite en todas las páginas -->
  <div class="it-footer">
    <strong>Casanova Golf</strong> &middot; <?php echo esc_html__('Programa sujeto a posibles cambios. Confirma siempre con tu asesor.', 'casanova-portal'); ?>
  </div>

  <div class="page">

    <!-- ══════ CABECERA ══════ -->
    <table class="it-brand" cellspacing="0" cellpadding="0">
      <tr>
        <td class="it-brand-logo">
          <?php if ($logo !== ''): ?>
            <img src="<?php echo esc_attr($logo); ?>" class="it-logo" alt="Casanova Golf">
          <?php endif; ?>
        </td>
        <td class="it-brand-doc">
          <span class="it-eyebrow"><?php echo esc_html__('Programa del viaje', 'casanova-portal'); ?></span>
          <?php if ($expediente_label !== ''): ?>
            <span class="it-doc-code"><?php echo esc_html($expediente_label); ?></span>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <h1 class="it-title"><?php echo esc_html($trip_title); ?></h1>

    <?php if (!empty($meta_items)): ?>
      <table class="it-meta" cellspacing="0" cellpadding="0">
        <tr>
          <?php foreach ($meta_items as $i => $item): ?>
            <td class="<?php echo $i === 0 ? 'is-first' : ''; ?>">
              <span class="it-meta-label"><?php echo esc_html($item[0]); ?></span>
              <span class="it-meta-value"><?php echo esc_html($item[1]); ?></span>
            </td>
          <?php endforeach; ?>
        </tr>
      </table>
    <?php endif; ?>

    <!-- ══════ VUELOS ══════ -->
    <?php if (!empty($flight_services)): ?>
      <h2 class="it-section"><?php echo esc_html__('Vuelos', 'casanova-portal'); ?></h2>
      <div class="it-rule"></div>
      <?php foreach ($flight_services as $fs): ?>
        <?php
          $title = trim((string)($fs['title'] ?? ''));
          if ($title === '') $title = __('Billetes', 'casanova-portal');
          $details = is_array($fs['details'] ?? null) ? $fs['details'] : [];
          $route = trim((string)($details['route'] ?? ''));
          $code = trim((string)($details['flight_code'] ?? ''));
          $schedule = trim((string)($details['schedule'] ?? ''));
          $locator = trim((string)($details['locator'] ?? ''));
          $pax = trim((string)($details['passengers'] ?? ''));
          $segments = is_array($details['segments'] ?? null) ? $details['segments'] : [];
          $date = casanova_fmt_date_range($fs['date_from'] ?? null, $fs['date_to'] ?? null);

          $flight_meta = array_values(array_filter([$date, $code, $schedule, $pax], static fn($v) => $v !== ''));
        ?>
        <table class="it-event" cellspacing="0" cellpadding="0">
          <tr>
            <td class="it-event-icon"><img src="<?php echo esc_attr(casanova_itinerary_icon('plane')); ?>" alt=""></td>
            <td class="it-event-body">
              <div class="it-event-title"><?php echo esc_html($route !== '' ? $route : $title); ?></div>
              <?php if (!empty($flight_meta)): ?>
                <div class="it-event-detail"><?php echo esc_html(implode('  ·  ', $flight_meta)); ?></div>
              <?php endif; ?>
              <?php if ($locator !== ''): ?>
                <div class="it-event-detail"><?php echo esc_html__('Localizador', 'casanova-portal'); ?>: <strong><?php echo esc_html($locator); ?></strong></div>
              <?php endif; ?>
              <?php if (!empty($segments)): ?>
                <ul class="it-segments">
                  <?php foreach ($segments as $seg): ?>
                    <li><?php echo esc_html((string)$seg); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
            <td class="it-event-tag"><span class="it-tag"><?php echo esc_html__('Vuelo', 'casanova-portal'); ?></span></td>
          </tr>
        </table>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- ══════ ITINERARIO ══════ -->
    <?php if (!empty($days)): ?>
      <h2 class="it-section"><?php echo esc_html__('Itinerario', 'casanova-portal'); ?></h2>
      <div class="it-rule"></div>

      <?php $is_first_day = true; ?>
      <?php foreach ($days as $date_key => $day): ?>
        <?php
          $day_class = $is_first_day ? 'it-day is-first' : 'it-day';
          $is_first_day = false;
          $stay_entries = [];
          $regular_entries = [];
          foreach (($events_by_day[$date_key] ?? []) as $event) {
            $role = (string)(($event['itinerary'] ?? [])['role'] ?? '');
            if ($role === 'stay') {
              $stay_entries[] = $event;
            } else {
              $regular_entries[] = $event;
            }
          }

          $hotel_names = [];
          foreach ($stay_entries as $stay) {
            $name = trim((string)(($stay['itinerary'] ?? [])['title'] ?? ''));
            if ($name !== '' && !in_array($name, $hotel_names, true)) {
              $hotel_names[] = $name;
            }
          }
        ?>
        <table class="<?php echo esc_attr($day_class); ?>" cellspacing="0" cellpadding="0">
          <tr>
            <td class="it-day-rail">
              <span class="it-day-label"><?php echo esc_html__('Día', 'casanova-portal'); ?></span>
              <span class="it-day-num"><?php echo esc_html((string)$day['number']); ?></span>
            </td>
            <td class="it-day-body">
              <div class="it-day-date"><?php echo esc_html($day['day_name'] . ', ' . $day['formatted']); ?></div>

              <?php foreach ($regular_entries as $event): ?>
                <?php
                  $service = $event['service'] ?? [];
                  $semantic = (string)($service['semantic_type'] ?? 'other');
                  $override = is_array($event['itinerary'] ?? null) ? $event['itinerary'] : [];
                  $role = (string)($override['role'] ?? '');

                  $title = trim((string)($service['title'] ?? ''));
                  if ($title === '') $title = trim((string)($service['detail']['code'] ?? __('Servicio', 'casanova-portal')));
                  if (!empty($override['title'])) $title = (string)$override['title'];

                  $detail_items = [];
                  $notes = '';
                  $tee_time = '';

                  if ($semantic === 'hotel') {
                    $checkin_time = trim((string)($override['checkin'] ?? ''));
                    $checkout_time = trim((string)($override['checkout'] ?? ''));
                    $board_meta = trim((string)($override['board'] ?? ''));
                    $rooming_meta = trim((string)($override['rooming'] ?? ''));
                    if ($role === 'checkin' && $checkin_time !== '') $detail_items[] = __('Check-in', 'casanova-portal') . ': ' . $checkin_time;
                    if ($role === 'checkout' && $checkout_time !== '') $detail_items[] = __('Check-out', 'casanova-portal') . ': ' . $checkout_time;
                  } else {
                    $range = casanova_fmt_date_range($service['date_from'] ?? null, $service['date_to'] ?? null);
                    $board_meta = trim((string)($service['details']['board'] ?? ''));
                    $rooming_meta = trim((string)($service['details']['rooming'] ?? ''));
                    $players = (int)($service['details']['players'] ?? 0);
                    $notes = trim((string)($service['detail']['bonus_text'] ?? ''));
                    $single_day = (string)($service['date_from'] ?? '') !== '' && casanova_fmt_date_range($service['date_from'], $service['date_from']) === $range;
                    if ($range !== '' && !$single_day) $detail_items[] = $range;
                    if ($players > 0 && $semantic === 'golf') $detail_items[] = __('Jugadores', 'casanova-portal') . ': ' . $players;
                    if ($semantic === 'golf') $tee_time = casanova_itinerary_tee_time((string)($service['observations'] ?? ''));
                  }
                  if ($board_meta !== '') $detail_items[] = __('Régimen', 'casanova-portal') . ': ' . $board_meta;
                  if ($rooming_meta !== '') $detail_items[] = __('Rooming', 'casanova-portal') . ': ' . $rooming_meta;

                  if ($semantic === 'hotel') {
                    $icon = 'bed';
                    $tag_class = $role === 'checkin' ? 'is-brand' : '';
                    $tag_label = $role === 'checkin' ? __('Check-in', 'casanova-portal') : __('Check-out', 'casanova-portal');
                  } elseif ($semantic === 'golf') {
                    $icon = 'golf';
                    $tag_class = 'is-brand';
                    $tag_label = __('Golf', 'casanova-portal');
                  } elseif ($semantic === 'transfer') {
                    $icon = 'car';
                    $tag_class = '';
                    $tag_label = __('Servicio', 'casanova-portal');
                  } else {
                    $icon = 'sparkles';
                    $tag_class = '';
                    $tag_label = __('Servicio', 'casanova-portal');
                  }
                ?>
                <table class="it-event" cellspacing="0" cellpadding="0">
                  <tr>
                    <td class="it-event-icon"><img src="<?php echo esc_attr(casanova_itinerary_icon($icon)); ?>" alt=""></td>
                    <td class="it-event-body">
                      <div class="it-event-title"><?php echo esc_html($title); ?></div>
                      <?php if ($tee_time !== ''): ?>
                        <div class="it-tee"><?php echo esc_html($tee_time); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($detail_items)): ?>
                        <div class="it-event-detail"><?php echo esc_html(implode('  ·  ', $detail_items)); ?></div>
                      <?php endif; ?>
                      <?php if ($notes !== ''): ?>
                        <div class="it-event-note"><?php echo esc_html($notes); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="it-event-tag"><span class="it-tag <?php echo esc_attr($tag_class); ?>"><?php echo esc_html($tag_label); ?></span></td>
                  </tr>
                </table>
              <?php endforeach; ?>

              <?php if (!empty($hotel_names)): ?>
                <table class="it-lodging" cellspacing="0" cellpadding="0">
                  <tr>
                    <td class="it-lodging-icon"><img src="<?php echo esc_attr(casanova_itinerary_icon('bed', '#5b5f55')); ?>" alt=""></td>
                    <td><?php echo esc_html(sprintf(__('Alojamiento: %s', 'casanova-portal'), implode(' / ', $hotel_names))); ?></td>
                  </tr>
                </table>
              <?php endif; ?>

              <?php if (empty($regular_entries) && empty($hotel_names)): ?>
                <div class="it-empty"><?php echo esc_html__('Sin servicios programados para este día.', 'casanova-portal'); ?></div>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="it-empty"><?php echo esc_html__('No hay servicios programados.', 'casanova-portal'); ?></p>
    <?php endif; ?>

  </div>
</body>
</html>
<?php
  return ob_get_clean();
}

/**
 * Logo verde Casanova 2026 (PNG generado desde mu-plugins/cg-2026-assets/casanova-logo-verde.svg).
 */
function casanova_itinerary_logo_data_uri(): string {
  $path = CASANOVA_GIAV_PLUGIN_PATH . 'assets/pdf/casanova-logo-verde.png';
  if (!file_exists($path)) {
    return function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '';
  }
  $bin = file_get_contents($path);
  return $bin === false ? '' : 'data:image/png;base64,' . base64_encode($bin);
}

/**
 * Satoshi y Playfair Display en TTF estático: Dompdf no lee woff2 ni fuentes variables.
 * Se cargan desde disco (dentro del chroot de Dompdf), sin petición HTTP al propio sitio.
 */
function casanova_itinerary_font_faces(): string {
  $dir = str_replace('\\', '/', CASANOVA_GIAV_PLUGIN_PATH . 'assets/pdf/fonts/');
  $faces = [
    ['Satoshi', 400, 'Satoshi-Regular.ttf'],
    ['Satoshi', 500, 'Satoshi-Medium.ttf'],
    ['Satoshi', 700, 'Satoshi-Bold.ttf'],
    ['Playfair Display', 400, 'PlayfairDisplay-Regular.ttf'],
  ];
  $css = '';
  foreach ($faces as [$family, $weight, $file]) {
    if (!file_exists($dir . $file)) continue;
    $css .= sprintf(
      "@font-face { font-family: '%s'; font-style: normal; font-weight: %d; src: url('%s') format('truetype'); }\n",
      $family,
      $weight,
      $dir . $file
    );
  }
  return $css;
}

/**
 * Icono Tabler (MIT) en SVG como data URI. Mismos trazos que el portal (Icon.jsx).
 */
function casanova_itinerary_icon(string $name, string $color = '#134640'): string {
  $paths = [
    'golf' => ['M12 18v-15l7 4l-7 4', 'M9 17.67c-.62 .36 -1 .82 -1 1.33c0 1.1 1.8 2 4 2s4 -.9 4 -2c0 -.5 -.38 -.97 -1 -1.33'],
    'bed' => ['M3 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0', 'M22 17v-3h-20', 'M2 8v9', 'M12 14h10v-2a3 3 0 0 0 -3 -3h-7v5z'],
    'plane' => ['M16 10h4a2 2 0 0 1 0 4h-4l-4 7h-3l2 -7h-4l-2 2h-3l2 -4l-2 -4h3l2 2h4l-2 -7h3z'],
    'car' => ['M5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0', 'M15 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0', 'M5 17h-2v-6l2 -5h9l4 5h1a2 2 0 0 1 2 2v4h-2m-4 0h-6m-6 -6h15m-6 0v-5'],
    'sparkles' => ['M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm0 -12a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm-7 12a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z'],
  ];
  $d = $paths[$name] ?? $paths['sparkles'];
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">';
  foreach ($d as $path) {
    $svg .= '<path d="' . $path . '"/>';
  }
  $svg .= '</svg>';
  return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * En GIAV la hora de salida del green fee se escribe tal cual en Observaciones al cliente ("11:10").
 * Misma regla que el portal (ServiceList.jsx): si empieza por una hora y no menciona ya el tee time, se etiqueta.
 */
function casanova_itinerary_tee_time(string $text): string {
  $text = trim((string) preg_replace('/\s+/', ' ', $text));
  if ($text === '') return '';
  if (stripos($text, 'tee') !== false) return $text;
  if (!preg_match('/^\d{1,2}[:.h]\d{2}\b/i', $text)) return '';
  return __('Tee-time', 'casanova-portal') . ' ' . $text;
}
