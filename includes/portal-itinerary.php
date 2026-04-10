<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF Programa del viaje (v3):
 * - Redesign aligned with client portal aesthetics.
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
  $package = is_array($payload['package'] ?? null) ? $payload['package'] : null;
  if (is_array($package)) {
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
      ? '/check\s*in\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i'
      : '/check\s*out\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i';
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

  $logo = function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '';
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

  $resolve_service_pax = function($package, array $services): int {
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

    $push_service($package);
    foreach ($services as $service) {
      $push_service($service);
    }

    return $best_pax;
  };

  $pax_count = $resolve_service_pax($package, $services);
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

  $itinerary_css_path = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-itinerary.css';
  $itinerary_css_href = add_query_arg(
    'ver',
    rawurlencode(file_exists($itinerary_css_path) ? (string) filemtime($itinerary_css_path) : '1'),
    CASANOVA_GIAV_PLUGIN_URL . 'assets/portal-itinerary.css'
  );

  ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html($trip_title); ?></title>
  <link rel="stylesheet" href="<?php echo esc_url($itinerary_css_href); ?>">
</head>
<body class="casanova-itinerary-body">
  <div class="page">

    <!-- ══════ HEADER ══════ -->
    <div class="it-header">
      <div class="it-header-top">
        <?php if ($logo !== ''): ?>
          <img src="<?php echo esc_attr($logo); ?>" class="it-logo" alt="<?php echo esc_attr__('Logo', 'casanova-portal'); ?>">
        <?php endif; ?>
        <div class="it-title"><?php echo esc_html($trip_title); ?></div>
        <div class="it-subtitle">
          <?php echo esc_html__('Programa del viaje', 'casanova-portal'); ?>
          <?php if ($expediente_label !== ''): ?>
            &middot; <?php echo esc_html($expediente_label); ?>
          <?php endif; ?>
        </div>
      </div>
      <table class="it-meta-row" cellspacing="0" cellpadding="0">
        <tr>
          <?php if ($display_dates !== ''): ?>
            <td>
              <span class="it-meta-label"><?php echo esc_html__('Fechas', 'casanova-portal'); ?></span>
              <span class="it-meta-value"><?php echo esc_html($display_dates); ?></span>
            </td>
          <?php endif; ?>
          <?php if ($total_days > 0): ?>
            <td>
              <span class="it-meta-label"><?php echo esc_html__('Duración', 'casanova-portal'); ?></span>
              <span class="it-meta-value"><?php echo esc_html(sprintf(__('%d días / %d noches', 'casanova-portal'), $total_days, $total_nights)); ?></span>
            </td>
          <?php endif; ?>
          <?php if ($pax_label !== ''): ?>
            <td>
              <span class="it-meta-label"><?php echo esc_html__('Viajeros', 'casanova-portal'); ?></span>
              <span class="it-meta-value"><?php echo esc_html($pax_label); ?></span>
            </td>
          <?php endif; ?>
          <?php if ($board !== ''): ?>
            <td>
              <span class="it-meta-label"><?php echo esc_html__('Régimen', 'casanova-portal'); ?></span>
              <span class="it-meta-value"><?php echo esc_html($board); ?></span>
            </td>
          <?php endif; ?>
        </tr>
      </table>
    </div>

    <!-- ══════ FLIGHTS ══════ -->
    <?php if (!empty($flight_services)): ?>
      <div class="it-section"><?php echo esc_html__('Vuelos', 'casanova-portal'); ?></div>
      <?php foreach ($flight_services as $fs): ?>
        <?php
          $title = trim((string)($fs['title'] ?? ''));
          if ($title === '') $title = esc_html__('Billetes', 'casanova-portal');
          $details = is_array($fs['details'] ?? null) ? $fs['details'] : [];
          $route = trim((string)($details['route'] ?? ''));
          $code = trim((string)($details['flight_code'] ?? ''));
          $schedule = trim((string)($details['schedule'] ?? ''));
          $locator = trim((string)($details['locator'] ?? ''));
          $pax = trim((string)($details['passengers'] ?? ''));
          $segments = is_array($details['segments'] ?? null) ? $details['segments'] : [];
          $date = casanova_fmt_date_range($fs['date_from'] ?? null, $fs['date_to'] ?? null);
        ?>
        <div class="it-flight">
          <div class="it-flight-title">
            <?php echo esc_html($route !== '' ? $route : $title); ?>
            <span class="it-flight-badge"><?php echo esc_html__('Vuelo', 'casanova-portal'); ?></span>
          </div>
          <div class="it-flight-meta">
            <?php if ($code !== ''): ?><span><?php echo esc_html($code); ?></span><?php endif; ?>
            <?php if ($schedule !== ''): ?><span><?php echo esc_html($schedule); ?></span><?php endif; ?>
            <?php if ($locator !== ''): ?><span><strong><?php echo esc_html__('Localizador', 'casanova-portal'); ?>:</strong> <?php echo esc_html($locator); ?></span><?php endif; ?>
            <?php if ($pax !== ''): ?><span><?php echo esc_html($pax); ?></span><?php endif; ?>
            <?php if ($date !== ''): ?><span><?php echo esc_html($date); ?></span><?php endif; ?>
          </div>
          <?php if (!empty($segments)): ?>
            <div class="it-segments">
              <strong><?php echo esc_html__('Segmentos', 'casanova-portal'); ?></strong>
              <ul>
                <?php foreach ($segments as $seg): ?>
                  <li><?php echo esc_html((string)$seg); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- ══════ ITINERARY ══════ -->
    <?php if (!empty($days)): ?>
      <div class="it-section"><?php echo esc_html__('Itinerario', 'casanova-portal'); ?></div>

      <?php foreach ($days as $date_key => $day): ?>
        <div class="it-day">
          <div class="it-day-header">
            <span class="it-day-num"><?php echo esc_html(sprintf(__('DÍA %d', 'casanova-portal'), $day['number'])); ?></span>
            <span class="it-day-date"><?php echo esc_html($day['day_name'] . ', ' . $day['formatted']); ?></span>
          </div>

          <?php if (!empty($events_by_day[$date_key])): ?>
            <?php
              $stay_entries = [];
              $regular_entries = [];
              foreach ($events_by_day[$date_key] as $event) {
                $role = (string)(($event['itinerary'] ?? [])['role'] ?? '');
                if ($role === 'stay') {
                  $stay_entries[] = $event;
                } else {
                  $regular_entries[] = $event;
                }
              }
            ?>

            <?php foreach ($regular_entries as $event): ?>
              <?php
                $service = $event['service'] ?? [];
                $semantic = (string)($service['semantic_type'] ?? 'other');
                $override = is_array($event['itinerary'] ?? null) ? $event['itinerary'] : [];
                $role = (string)($override['role'] ?? '');

                $title = trim((string)($service['title'] ?? ''));
                if ($title === '') $title = trim((string)($service['detail']['code'] ?? __('Servicio', 'casanova-portal')));
                if (!empty($override['title'])) $title = (string)$override['title'];

                $range = '';
                $board_meta = '';
                $rooming_meta = '';
                $players = 0;
                $notes = '';
                $checkin_time = '';
                $checkout_time = '';

                if ($semantic === 'hotel') {
                  $checkin_time = trim((string)($override['checkin'] ?? ''));
                  $checkout_time = trim((string)($override['checkout'] ?? ''));
                  $board_meta = trim((string)($override['board'] ?? ''));
                  $rooming_meta = trim((string)($override['rooming'] ?? ''));
                } else {
                  $range = casanova_fmt_date_range($service['date_from'] ?? null, $service['date_to'] ?? null);
                  $board_meta = trim((string)($service['details']['board'] ?? ''));
                  $rooming_meta = trim((string)($service['details']['rooming'] ?? ''));
                  $players = (int)($service['details']['players'] ?? 0);
                  $notes = trim((string)($service['detail']['bonus_text'] ?? ''));
                }

                // Determine CSS class and tag
                if ($semantic === 'hotel') {
                  $event_class = 'is-hotel';
                  if ($role === 'checkin') {
                    $tag_class = 'tag-checkin';
                    $tag_label = __('Check-in', 'casanova-portal');
                  } else {
                    $tag_class = 'tag-checkout';
                    $tag_label = __('Check-out', 'casanova-portal');
                  }
                } elseif ($semantic === 'golf') {
                  $event_class = 'is-golf';
                  $tag_class = 'tag-golf';
                  $tag_label = __('Golf', 'casanova-portal');
                } else {
                  $event_class = 'is-other';
                  $tag_class = 'tag-other';
                  $tag_label = __('Servicio', 'casanova-portal');
                }
              ?>
              <div class="it-event <?php echo esc_attr($event_class); ?>">
                <div class="it-event-row">
                  <span class="it-event-title"><?php echo esc_html($title); ?></span>
                  <span class="it-event-tag <?php echo esc_attr($tag_class); ?>"><?php echo esc_html($tag_label); ?></span>
                </div>
                <?php
                  $has_detail = ($range !== '' && $semantic !== 'hotel')
                    || ($board_meta !== '')
                    || ($rooming_meta !== '')
                    || ($players > 0 && $semantic === 'golf')
                    || ($semantic === 'hotel' && $role === 'checkin' && $checkin_time !== '')
                    || ($semantic === 'hotel' && $role === 'checkout' && $checkout_time !== '');
                ?>
                <?php if ($has_detail): ?>
                  <div class="it-event-detail">
                    <?php if ($semantic !== 'hotel' && $range !== ''): ?>
                      <span><?php echo esc_html($range); ?></span>
                    <?php endif; ?>
                    <?php if ($board_meta !== ''): ?>
                      <span><?php echo esc_html__('Régimen', 'casanova-portal'); ?>: <?php echo esc_html($board_meta); ?></span>
                    <?php endif; ?>
                    <?php if ($rooming_meta !== ''): ?>
                      <span><?php echo esc_html__('Rooming', 'casanova-portal'); ?>: <?php echo esc_html($rooming_meta); ?></span>
                    <?php endif; ?>
                    <?php if ($players > 0 && $semantic === 'golf'): ?>
                      <span><?php echo esc_html__('Jugadores', 'casanova-portal'); ?>: <?php echo esc_html((string)$players); ?></span>
                    <?php endif; ?>
                    <?php if ($semantic === 'hotel' && $role === 'checkin' && $checkin_time !== ''): ?>
                      <span><?php echo esc_html__('Check-in', 'casanova-portal'); ?>: <?php echo esc_html($checkin_time); ?></span>
                    <?php endif; ?>
                    <?php if ($semantic === 'hotel' && $role === 'checkout' && $checkout_time !== ''): ?>
                      <span><?php echo esc_html__('Check-out', 'casanova-portal'); ?>: <?php echo esc_html($checkout_time); ?></span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($notes !== '' && $semantic !== 'hotel'): ?>
                  <div class="it-event-note"><?php echo esc_html($notes); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php
              // Render subtle lodging footer for "stay" entries (hotel continues)
              if (!empty($stay_entries)):
                $hotel_names = [];
                foreach ($stay_entries as $stay) {
                  $name = trim((string)(($stay['itinerary'] ?? [])['title'] ?? ''));
                  if ($name !== '' && !in_array($name, $hotel_names, true)) {
                    $hotel_names[] = $name;
                  }
                }
            ?>
              <?php if (!empty($hotel_names)): ?>
                <div class="it-lodging-footer">
                  <span class="it-lodging-icon">&#9790;</span>
                  <?php echo esc_html(sprintf(
                    __('Alojamiento: %s', 'casanova-portal'),
                    implode(' / ', $hotel_names)
                  )); ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>

          <?php else: ?>
            <div class="it-empty"><?php echo esc_html__('Sin servicios programados para este día.', 'casanova-portal'); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="it-empty"><?php echo esc_html__('No hay servicios programados.', 'casanova-portal'); ?></p>
    <?php endif; ?>

    <!-- ══════ FOOTER ══════ -->
    <div class="it-footer">
      <strong>Casanova Golf</strong> &middot; <?php echo esc_html__('Programa sujeto a posibles cambios. Confirma siempre con tu asesor.', 'casanova-portal'); ?>
    </div>

  </div>
</body>
</html>
<?php
  return ob_get_clean();
}
