<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_group_pay_register_rewrite')) {
  function casanova_group_pay_register_rewrite(): void {
    add_rewrite_rule('^pay/group/([^/]+)/?$', 'index.php?casanova_group_pay_token=$matches[1]', 'top');
  }
}

add_action('init', function () {
  if (function_exists('casanova_group_pay_register_rewrite')) {
    casanova_group_pay_register_rewrite();
  }
});

add_filter('query_vars', function (array $vars): array {
  $vars[] = 'casanova_group_pay_token';
  return $vars;
});

add_action('template_redirect', function () {
  $token = (string) get_query_var('casanova_group_pay_token');
  if ($token === '') {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '') {
      $path = wp_parse_url($uri, PHP_URL_PATH);
      if (is_string($path) && $path !== '') {
        if (preg_match('#/pay/group/([^/]+)/?#', $path, $m)) {
          $token = (string)($m[1] ?? '');
        }
      }
    }
  }
  if ($token === '') return;
  casanova_handle_group_pay_request($token);
  exit;
});

function casanova_handle_group_pay_request(string $token): void {
  $token = sanitize_text_field($token);
  if ($token === '') {
    wp_die(esc_html__('Enlace de grupo invalido.', 'casanova-portal'), 404);
  }

  if (!function_exists('casanova_group_token_get')) {
    wp_die(esc_html__('Sistema de grupos no disponible.', 'casanova-portal'), 500);
  }

  $group = casanova_group_token_get($token);
  if (!$group) {
    wp_die(esc_html__('Enlace de grupo no encontrado.', 'casanova-portal'), 404);
  }

  $status = strtolower(trim((string)($group->status ?? '')));
  if ($status !== 'active') {
    casanova_render_payment_link_error(__('Enlace de grupo no disponible.', 'casanova-portal'));
    exit;
  }

  if (function_exists('casanova_group_token_is_expired') && casanova_group_token_is_expired($group)) {
    casanova_group_token_update((int)$group->id, ['status' => 'expired']);
    casanova_render_payment_link_error(__('Enlace de grupo caducado.', 'casanova-portal'));
    exit;
  }

  $idExpediente = (int)($group->id_expediente ?? 0);
  $idReservaPQ = (int)($group->id_reserva_pq ?? 0);
  if ($idExpediente <= 0) {
    casanova_render_payment_link_error(__('Expediente invalido.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_giav_expediente_get')) {
    casanova_render_payment_link_error(__('Sistema GIAV no disponible.', 'casanova-portal'));
    exit;
  }

  $exp = casanova_giav_expediente_get($idExpediente);
  if (is_wp_error($exp) || !is_object($exp)) {
    casanova_render_payment_link_error(__('No se pudo cargar el expediente.', 'casanova-portal'));
    exit;
  }

  $idCliente = (int)($exp->IdCliente ?? 0);
  if ($idCliente <= 0) {
    casanova_render_payment_link_error(__('Cliente no encontrado.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_group_context_from_reservas')) {
    casanova_render_payment_link_error(__('Sistema de grupos no disponible.', 'casanova-portal'));
    exit;
  }

  $ctx = casanova_group_context_from_reservas($idExpediente, $idCliente, $idReservaPQ ?: null);
  if (is_wp_error($ctx)) {
    casanova_render_payment_link_error($ctx->get_error_message());
    exit;
  }

  $reservas = $ctx['reservas'] ?? [];
  $calc = $ctx['calc'] ?? [];
  $numPax = (int)($ctx['num_pax'] ?? 0);
  $basePending = (float)($ctx['base_pending'] ?? 0);
  $idReservaPQ = (int)($ctx['id_reserva_pq'] ?? $idReservaPQ);

  if ($numPax <= 0 || $basePending <= 0) {
    casanova_render_payment_link_error(__('No se pudieron determinar plazas del grupo.', 'casanova-portal'));
    exit;
  }

  if (!function_exists('casanova_ensure_group_slots')) {
    casanova_render_payment_link_error(__('Sistema de plazas no disponible.', 'casanova-portal'));
    exit;
  }

  casanova_ensure_group_slots($idExpediente, $idReservaPQ, $numPax, $basePending);
  $open_slots = function_exists('casanova_group_slots_open') ? casanova_group_slots_open($idExpediente, $idReservaPQ) : [];
  if (empty($open_slots)) {
    casanova_render_payment_link_error(__('No quedan plazas pendientes.', 'casanova-portal'));
    exit;
  }

  $open_count = count($open_slots);
  $open_total = 0.0;
  foreach ($open_slots as $s) {
    $open_total += max(0.0, (float)($s->base_due ?? 0) - (float)($s->base_paid ?? 0));
  }
  $open_total = round($open_total, 2);

  $deposit_allowed = function_exists('casanova_payments_is_deposit_allowed') ? casanova_payments_is_deposit_allowed($reservas) : false;
  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
  }

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'casanova_group_pay_' . (int)$group->id)) {
      casanova_render_payment_link_error(__('Solicitud no valida.', 'casanova-portal'));
      exit;
    }

    $name_raw = isset($_POST['billing_name']) ? (string)$_POST['billing_name'] : '';
    $lastname_raw = isset($_POST['billing_lastname']) ? (string)$_POST['billing_lastname'] : '';
    $billing_name = trim(sanitize_text_field($name_raw));
    $billing_lastname = trim(sanitize_text_field($lastname_raw));
    if ($billing_name === '' || $billing_lastname === '') {
      casanova_render_payment_link_error(__('Debes indicar Nombre y Apellidos.', 'casanova-portal'));
      exit;
    }
    $billing_fullname = trim($billing_name . ' ' . $billing_lastname);

    $email_raw = isset($_POST['billing_email']) ? (string)$_POST['billing_email'] : '';
    $billing_email = trim(sanitize_email($email_raw));
    if ($billing_email === '' || !is_email($billing_email)) {
      casanova_render_payment_link_error(__('Debes indicar un email válido.', 'casanova-portal'));
      exit;
    }


    $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
    $dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
    if ($dni === '') {
      casanova_render_payment_link_error(__('Debes indicar el DNI/NIF.', 'casanova-portal'));
      exit;
    }

    $slots_sel = isset($_POST['slots_count']) ? (string)$_POST['slots_count'] : '1';
    $slots_count = 0;
    if ($slots_sel === 'resto') {
      $slots_count = $open_count;
    } else {
      $slots_count = (int)$slots_sel;
    }
    if ($slots_count <= 0 || $slots_count > $open_count) {
      casanova_render_payment_link_error(__('Numero de plazas invalido.', 'casanova-portal'));
      exit;
    }

    $selected_slots = [];
    if (function_exists('casanova_group_slots_reserve')) {
      $reserved = casanova_group_slots_reserve($idExpediente, $idReservaPQ, $slots_count, 15);
      if (is_wp_error($reserved)) {
        casanova_render_payment_link_error($reserved->get_error_message());
        exit;
      }
      if (empty($reserved) || count($reserved) < $slots_count) {
        casanova_render_payment_link_error(__('No quedan plazas disponibles.', 'casanova-portal'));
        exit;
      }
      $selected_slots = $reserved;
    } else {
      // Fallback (no lock): usamos los primeros slots abiertos.
      $selected_slots = array_slice($open_slots, 0, $slots_count);
    }
    $total_due = 0.0;
    foreach ($selected_slots as $s) {
      $total_due += max(0.0, (float)($s->base_due ?? 0) - (float)($s->base_paid ?? 0));
    }
    $total_due = round($total_due, 2);

    $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
    if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
    if (!$deposit_allowed) $mode = 'full';

    $deposit_total = 0.0;
    if ($mode === 'deposit' && function_exists('casanova_payments_calc_deposit_amount')) {
      foreach ($selected_slots as $s) {
        $due = max(0.0, (float)($s->base_due ?? 0) - (float)($s->base_paid ?? 0));
        $deposit_total += casanova_payments_calc_deposit_amount($due, $idExpediente);
      }
      $deposit_total = round($deposit_total, 2);
    }

    $deposit_effective = ($mode === 'deposit') && ($deposit_total + 0.01 < $total_due);
    if ($mode === 'deposit' && !$deposit_effective) {
      $mode = 'full';
    }

    $amount_to_pay = ($mode === 'deposit') ? $deposit_total : $total_due;
    if ($amount_to_pay <= 0.01) {
      casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
      exit;
    }

    if (!function_exists('casanova_payment_link_create')) {
      casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
      exit;
    }

    $slot_ids = [];
    $slot_indexes = [];
    foreach ($selected_slots as $s) {
      $slot_ids[] = (int)($s->id ?? 0);
      $slot_indexes[] = (int)($s->slot_index ?? 0);
    }

    $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
    if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
    if ($selected_method === 'bank_transfer' && !$inespay_enabled) $selected_method = 'card';

    $link = casanova_payment_link_create([
      'id_expediente' => $idExpediente,
      'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
      'scope' => 'slot_base',
      'amount_authorized' => $amount_to_pay,
      'currency' => 'EUR',
      'status' => 'active',
      'created_by' => 'group',
      'billing_dni' => $dni,
        'billing_email' => $billing_email,
      'metadata' => [
        'mode' => $mode,
        'slots_count' => $slots_count,
        'slot_ids' => $slot_ids,
        'slot_indexes' => $slot_indexes,
        'total_due' => $total_due,
        'deposit_total' => ($mode === 'deposit') ? $deposit_total : 0,
        'group_token_id' => (int)$group->id,
        'id_reserva_pq' => $idReservaPQ,
        'billing_name' => $billing_fullname,
        'billing_lastname' => $billing_lastname,
        'billing_dni' => $dni,
        'billing_email' => $billing_email,
        'preferred_method' => $selected_method,
        'auto_start' => true,
      ],
    ]);

    if (is_wp_error($link)) {
      casanova_render_payment_link_error($link->get_error_message());
      exit;
    }

    $url = add_query_arg(['autostart' => '1'], casanova_payment_link_url((string)($link->token ?? '')));
    wp_safe_redirect($url);
    exit;
  }

  $exp_label = '';
  $exp_codigo = '';
  if (function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, $idExpediente);
    $exp_label = trim((string)($meta['label'] ?? ''));
    $exp_codigo = trim((string)($meta['codigo'] ?? ''));
  }
  if ($exp_label === '') $exp_label = sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente);

  $deadline_txt = '';
  if (function_exists('casanova_payments_min_fecha_limite')) {
    $deadline = casanova_payments_min_fecha_limite($reservas);
    if ($deadline instanceof DateTimeInterface) {
      $deadline_txt = $deadline->format('d/m/Y');
    }
  }

  $nonce = wp_create_nonce('casanova_group_pay_' . (int)$group->id);

  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:760px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
  echo '<style>
    .casanova-two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .casanova-two-col label{margin:0;}
    @media (max-width:640px){.casanova-two-col{grid-template-columns:1fr;}}
  </style>';

  echo '<h2 style="margin:0 0 6px;">' . esc_html__('Pago de plazas', 'casanova-portal') . '</h2>';
  echo '<p style="margin:0 0 14px;color:#555;">' . esc_html__('Elige cuántas plazas quieres pagar y completa los datos de facturación.', 'casanova-portal') . '</p>';
  $codigo_html = $exp_codigo ? ' <span style="color:#666;">(' . esc_html($exp_codigo) . ')</span>' : '';
  echo '<p style="margin:0 0 14px;">' . wp_kses_post(
    sprintf(__('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'), esc_html($exp_label), $codigo_html)
  ) . '</p>';

  echo '<div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';
  echo '<div>' . esc_html(sprintf(__('Plazas pendientes: %s', 'casanova-portal'), $open_count)) . '</div>';
  echo '<div style="margin-top:6px;">' . esc_html(sprintf(__('Total pendiente base: %s EUR', 'casanova-portal'), number_format($open_total, 2, ',', '.'))) . '</div>';
  if ($deadline_txt !== '') {
    echo '<div style="margin-top:6px;">' . esc_html(sprintf(__('Fecha limite: %s', 'casanova-portal'), $deadline_txt)) . '</div>';
  }
  echo '</div>';

  $slot_open_amounts = [];
  $slot_deposit_amounts = [];
  foreach ($open_slots as $s) {
    $due = max(0.0, (float)($s->base_due ?? 0) - (float)($s->base_paid ?? 0));
    $slot_open_amounts[] = round($due, 2);
    $slot_deposit_amounts[] = $deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')
      ? round(casanova_payments_calc_deposit_amount($due, $idExpediente), 2)
      : 0.0;
  }

  $default_amount = $slot_open_amounts[0] ?? $open_total;
  $default_amount = round((float)$default_amount, 2);

  echo '<form id="casanova-group-pay-form" method="post" action="' . esc_url(casanova_group_pay_url((string)$group->token)) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Nombre', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_name" autocomplete="given-name" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Apellidos', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_lastname" autocomplete="family-name" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Email', 'casanova-portal') . '</label>';
  echo '<input type="email" name="billing_email" autocomplete="email" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';


  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('DNI / NIF (obligatorio)', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_dni" autocomplete="tax-id" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<div style="margin:14px 0 6px;font-weight:600;">' . esc_html__('Método de pago', 'casanova-portal') . '</div>';
  if ($inespay_enabled) {
    echo '<div class="casanova-two-col" style="margin:6px 0 0;">';
    echo '<label style="display:block;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="method" value="card" checked style="margin-right:8px;" />' . esc_html__('Tarjeta (Redsys)', 'casanova-portal');
    echo '</label>';
    echo '<label style="display:block;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="method" value="bank_transfer" style="margin-right:8px;" />' . esc_html__('Transferencia bancaria (Inespay)', 'casanova-portal');
    echo '</label>';
    echo '</div>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div style="margin:6px 0 12px;color:#666;">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  echo '<label style="display:block;margin:14px 0 6px;font-weight:600;">' . esc_html__('Numero de plazas', 'casanova-portal') . '</label>';
  echo '<select name="slots_count" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">';
  for ($i = 1; $i <= $open_count; $i++) {
    echo '<option value="' . (int)$i . '">' . (int)$i . '</option>';
  }
  echo '<option value="resto">' . esc_html__('Resto (todas)', 'casanova-portal') . '</option>';
  echo '</select>';

  if ($deposit_allowed) {
    echo '<div class="casanova-two-col" style="margin:10px 0 0;">';
    echo '<label style="display:block;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="mode" value="deposit" style="margin-right:8px;" />' . esc_html__('Pagar deposito', 'casanova-portal');
    echo '</label>';
    echo '<label style="display:block;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="mode" value="full" checked style="margin-right:8px;" />' . esc_html__('Pagar total', 'casanova-portal');
    echo '</label>';
    echo '</div>';
  } else {
    echo '<input type="hidden" name="mode" value="full" />';
    echo '<label style="display:block;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="mode" value="full" checked style="margin-right:8px;" />' . esc_html__('Pagar total', 'casanova-portal');
    echo '</label>';
  }

  $euro_symbol = html_entity_decode('&euro;', ENT_QUOTES, 'UTF-8');
  echo '<div id="casanova-group-summary" style="margin:10px 0;color:#555;"></div>';
  echo '<button id="casanova-group-pay-button" type="submit" style="margin-top:10px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;">'
    . esc_html(sprintf(__('Pagar %s %s', 'casanova-portal'), number_format($default_amount, 2, ',', '.'), $euro_symbol))
    . '</button>';

  echo '</form>';
  echo '<p style="margin:12px 0 0;font-size:12px;color:#777;">' . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal') . '</p>';
  echo '<script>
    (function(){
      const slotAmounts = ' . wp_json_encode($slot_open_amounts) . ';
      const depositAmounts = ' . wp_json_encode($slot_deposit_amounts) . ';

      function sumFirst(arr, n){
        let total = 0;
        for (let i = 0; i < n; i++) total += Number(arr[i] || 0);
        return Math.round(total * 100) / 100;
      }

      function fmt(n){
        if (!isFinite(n)) n = 0;
        const parts = n.toFixed(2).split(".");
        const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + "," + parts[1];
      }

      function init(){
        const form = document.getElementById("casanova-group-pay-form");
        if (!form) return;
        const select = form.elements["slots_count"];
        const modeInputs = form.querySelectorAll("input[name=mode]");
        const summary = document.getElementById("casanova-group-summary");
        const btn = document.getElementById("casanova-group-pay-button");

        function getCount(){
          if (!select || !select.value) return 1;
          const v = select.value;
          if (v === "resto") return slotAmounts.length;
          const n = parseInt(v, 10);
          return isNaN(n) ? 1 : n;
        }

        function getMode(){
          let m = "full";
          Array.prototype.forEach.call(modeInputs, function(i){ if (i.checked) m = i.value; });
          return m;
        }

        function update(){
          const count = getCount();
          const mode = getMode();
          const total = sumFirst(slotAmounts, count);
          const dep = sumFirst(depositAmounts, count);
          const amount = (mode === "deposit" && dep > 0.009 && dep < total) ? dep : total;
          if (summary) summary.textContent = "Importe estimado: " + fmt(amount) + " \\u20AC";
          if (btn) btn.textContent = "Pagar " + fmt(amount) + " \\u20AC";
        }

        form.addEventListener("change", update);
        form.addEventListener("input", update);
        update();
      }

      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
      } else {
        init();
      }
    })();
  </script>';

  echo '</div>';
  exit;
}
