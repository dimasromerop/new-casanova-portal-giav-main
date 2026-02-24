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
      if (is_string($path) && $path !== '' && preg_match('#/pay/group/([^/]+)/?#', $path, $m)) {
        $token = (string)($m[1] ?? '');
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
  $baseTotal = (float)($ctx['base_total'] ?? 0);
  $idReservaPQ = (int)($ctx['id_reserva_pq'] ?? $idReservaPQ);

  if ($numPax <= 0) {
    casanova_render_payment_link_error(__('No se pudieron determinar pasajeros del grupo.', 'casanova-portal'));
    exit;
  }

  if ($baseTotal <= 0) {
    $baseTotal = max(0.0, $basePending + (float)($calc['pagado'] ?? 0));
  }
  if ($baseTotal <= 0) {
    $baseTotal = $basePending;
  }

  $unit_total = (float)($group->unit_total ?? 0);
  if ($unit_total <= 0 && $numPax > 0 && $baseTotal > 0) {
    $unit_total = round($baseTotal / $numPax, 2);
  }
  if ($unit_total <= 0) {
    casanova_render_payment_link_error(__('No se pudo calcular el importe fijo por persona.', 'casanova-portal'));
    exit;
  }

  $deposit_allowed = function_exists('casanova_payments_is_deposit_allowed')
    ? casanova_payments_is_deposit_allowed($reservas)
    : false;

  $unit_deposit = 0.0;
  if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
    $unit_deposit = round(casanova_payments_calc_deposit_amount($unit_total, $idExpediente), 2);
    if ($unit_deposit > $unit_total) {
      $unit_deposit = $unit_total;
    }
  }

  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
  }

  $notice = null;

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'casanova_group_pay_' . (int)$group->id)) {
      casanova_render_payment_link_error(__('Solicitud no valida.', 'casanova-portal'));
      exit;
    }

    $action = isset($_POST['action']) ? sanitize_key((string)$_POST['action']) : 'pay';

    if ($action === 'resend_magic') {
      $resend_email = isset($_POST['resend_email']) ? trim(sanitize_email((string)$_POST['resend_email'])) : '';
      $resend_dni_raw = isset($_POST['resend_dni']) ? (string)$_POST['resend_dni'] : '';
      $resend_dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($resend_dni_raw)));

      $deposit_row = null;
      if ($resend_email !== '' && is_email($resend_email) && $resend_dni !== '' && function_exists('casanova_payment_links_find_deposit_with_rest')) {
        $deposit_row = casanova_payment_links_find_deposit_with_rest($idExpediente, $resend_dni, $resend_email);
      }

      $resent = false;
      if ($deposit_row && function_exists('casanova_payment_links_resend_rest_magic')) {
        $resent = casanova_payment_links_resend_rest_magic($deposit_row, $resend_email);
      }

      if ($resent) {
        $notice = [
          'type' => 'success',
          'text' => __('Si el enlace existia, se ha reenviado a tu email.', 'casanova-portal'),
        ];
      } else {
        $notice = [
          'type' => 'error',
          'text' => __('No encontrado, contacta con la agencia.', 'casanova-portal'),
        ];
      }
    } else {
      $name_raw = isset($_POST['billing_name']) ? (string)$_POST['billing_name'] : '';
      $lastname_raw = isset($_POST['billing_lastname']) ? (string)$_POST['billing_lastname'] : '';
      $billing_name = trim(sanitize_text_field($name_raw));
      $billing_lastname = trim(sanitize_text_field($lastname_raw));
      if ($billing_name === '' || $billing_lastname === '') {
        casanova_render_payment_link_error(__('Debes indicar Nombre y Apellidos.', 'casanova-portal'));
        exit;
      }

      $email_raw = isset($_POST['billing_email']) ? (string)$_POST['billing_email'] : '';
      $billing_email = trim(sanitize_email($email_raw));
      if ($billing_email === '' || !is_email($billing_email)) {
        casanova_render_payment_link_error(__('Debes indicar un email valido.', 'casanova-portal'));
        exit;
      }

      $dni_raw = isset($_POST['billing_dni']) ? (string)$_POST['billing_dni'] : '';
      $billing_dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));
      if ($billing_dni === '') {
        casanova_render_payment_link_error(__('Debes indicar el DNI/NIF.', 'casanova-portal'));
        exit;
      }

      $units = isset($_POST['units']) ? (int)$_POST['units'] : 1;
      if ($units < 1 || $units > 10) {
        casanova_render_payment_link_error(__('Numero de personas invalido.', 'casanova-portal'));
        exit;
      }
      $others_names_raw = isset($_POST['others_names']) ? (string)$_POST['others_names'] : '';
      $others_names = trim(sanitize_textarea_field($others_names_raw));
      $internal_note = '';

      $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
      if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
      if (!$deposit_allowed) $mode = 'full';

      $unit_amount = $unit_total;
      if ($mode === 'deposit' && $unit_deposit > 0) {
        $unit_amount = $unit_deposit;
        if ($unit_deposit + 0.01 >= $unit_total) {
          $mode = 'full';
          $unit_amount = $unit_total;
        }
      }

      $amount_to_pay = round($unit_amount * $units, 2);
      if ($amount_to_pay <= 0.01) {
        casanova_render_payment_link_error(__('Importe invalido.', 'casanova-portal'));
        exit;
      }

      if (!function_exists('casanova_payment_link_create')) {
        casanova_render_payment_link_error(__('Sistema de pago no disponible.', 'casanova-portal'));
        exit;
      }

      $selected_method = isset($_POST['method']) ? strtolower(trim((string)$_POST['method'])) : 'card';
      if ($selected_method !== 'card' && $selected_method !== 'bank_transfer') $selected_method = 'card';
      if ($selected_method === 'bank_transfer' && !$inespay_enabled) $selected_method = 'card';

      $link = casanova_payment_link_create([
        'id_expediente' => $idExpediente,
        'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
        'scope' => 'group_base',
        'amount_authorized' => $amount_to_pay,
        'currency' => 'EUR',
        'status' => 'active',
        'created_by' => 'group',
        'billing_dni' => $billing_dni,
        'metadata' => [
          'mode' => $mode,
          'units' => $units,
          'unit_total' => $unit_total,
          'unit_deposit' => $unit_deposit,
          'others_names' => $others_names,
          'internal_note' => $internal_note,
          'group_token_id' => (int)$group->id,
          'id_reserva_pq' => $idReservaPQ,
          'billing_name' => $billing_name,
          'billing_lastname' => $billing_lastname,
          'billing_email' => $billing_email,
          'billing_dni' => $billing_dni,
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
  $unit_full_fmt = number_format($unit_total, 2, ',', '.');
  $unit_dep_fmt = number_format($unit_deposit, 2, ',', '.');

  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:760px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
  echo '<style>
    .casanova-two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .casanova-two-col label{margin:0;}
    @media (max-width:640px){.casanova-two-col{grid-template-columns:1fr;}}
  </style>';

  echo '<h2 style="margin:0 0 6px;">' . esc_html__('Pago de importe fijo', 'casanova-portal') . '</h2>';
  echo '<p style="margin:0 0 14px;color:#555;">' . esc_html__('Selecciona cuantas personas incluyes y completa los datos de facturacion.', 'casanova-portal') . '</p>';

  $codigo_html = $exp_codigo ? ' <span style="color:#666;">(' . esc_html($exp_codigo) . ')</span>' : '';
  echo '<p style="margin:0 0 14px;">' . wp_kses_post(
    sprintf(__('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'), esc_html($exp_label), $codigo_html)
  ) . '</p>';

  if (is_array($notice) && !empty($notice['text'])) {
    $is_ok = (($notice['type'] ?? '') === 'success');
    $bg = $is_ok ? '#f3fff4' : '#fff7f7';
    $border = $is_ok ? '#b5e2bd' : '#f0c2c2';
    echo '<div style="background:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';border-radius:8px;padding:10px;margin:0 0 14px;">' . esc_html((string)$notice['text']) . '</div>';
  }

  echo '<div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';
  echo '<div>' . esc_html(sprintf(__('Importe fijo por persona: %s EUR', 'casanova-portal'), $unit_full_fmt)) . '</div>';
  if ($deposit_allowed) {
    echo '<div style="margin-top:6px;">' . esc_html(sprintf(__('Deposito por persona: %s EUR', 'casanova-portal'), $unit_dep_fmt)) . '</div>';
  }
  if ($deadline_txt !== '') {
    echo '<div style="margin-top:6px;">' . esc_html(sprintf(__('Fecha limite: %s', 'casanova-portal'), $deadline_txt)) . '</div>';
  }
  echo '</div>';

  echo '<form id="casanova-group-pay-form" method="post" action="' . esc_url(casanova_group_pay_url((string)$group->token)) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="pay" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Nombre', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_name" autocomplete="given-name" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Apellidos', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_lastname" autocomplete="family-name" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Email', 'casanova-portal') . '</label>';
  echo '<input type="email" name="billing_email" autocomplete="email" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('DNI / NIF', 'casanova-portal') . '</label>';
  echo '<input type="text" name="billing_dni" autocomplete="tax-id" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Personas incluidas en este pago', 'casanova-portal') . '</label>';
  echo '<select name="units" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">';
  for ($u = 1; $u <= 10; $u++) {
    $sel = ($u === 1) ? ' selected' : '';
    echo '<option value="' . (int)$u . '"' . $sel . '>' . (int)$u . '</option>';
  }
  echo '</select>';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Nombres de los viajeros (opcional)', 'casanova-portal') . '</label>';
  echo '<textarea name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></textarea>';
  echo '<div style="margin:6px 0 0;color:#666;font-size:12px;">' . esc_html__('Solo para referencia de la agencia. 1 nombre por línea.', 'casanova-portal') . '</div>';

  echo '<div style="margin:14px 0 6px;font-weight:600;">' . esc_html__('Metodo de pago', 'casanova-portal') . '</div>';
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
  }

  echo '<div id="casanova-group-summary" style="margin:14px 0;padding:12px;border:1px solid #eee;border-radius:8px;background:#fafafa;">';
  echo '<div style="font-weight:600;margin-bottom:6px;">' . esc_html__('Resumen', 'casanova-portal') . '</div>';
  echo '<div id="casanova-summary-unit"></div>';
  echo '<div id="casanova-summary-units" style="margin-top:4px;"></div>';
  echo '<div id="casanova-summary-deposit" style="margin-top:4px;"></div>';
  echo '<div id="casanova-summary-total" style="margin-top:6px;font-weight:600;"></div>';
  echo '</div>';
  echo '<button id="casanova-group-pay-button" type="submit" style="margin-top:10px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;">'
    . esc_html__('Continuar al pago', 'casanova-portal')
    . '</button>';

  echo '</form>';

  echo '<div style="margin-top:12px;">';
  echo '<button type="button" id="casanova-resend-toggle" aria-expanded="false" style="padding:0;border:0;background:none;color:#4b5563;text-decoration:underline;cursor:pointer;font-size:13px;">'
    . esc_html__('¿Pagaste un depósito antes? Reenviar enlace de pago final', 'casanova-portal')
    . '</button>';
  echo '</div>';

  echo '<div id="casanova-resend-panel" style="display:none;border:1px solid #eee;border-radius:8px;padding:12px;margin:10px 0 0;">';
  echo '<form method="post" action="' . esc_url(casanova_group_pay_url((string)$group->token)) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="resend_magic" />';
  echo '<div class="casanova-two-col">';
  echo '<label><span style="display:block;font-size:12px;color:#555;margin:0 0 4px;">' . esc_html__('Email', 'casanova-portal') . '</span><input type="email" name="resend_email" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" /></label>';
  echo '<label><span style="display:block;font-size:12px;color:#555;margin:0 0 4px;">' . esc_html__('DNI', 'casanova-portal') . '</span><input type="text" name="resend_dni" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" /></label>';
  echo '</div>';
  echo '<button type="submit" style="margin-top:10px;padding:10px 14px;border:0;border-radius:8px;background:#4b5563;color:#fff;cursor:pointer;">' . esc_html__('Reenviar enlace', 'casanova-portal') . '</button>';
  echo '</form>';
  echo '</div>';

  echo '<p style="margin:12px 0 0;font-size:12px;color:#777;">' . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal') . '</p>';

  echo '<script>
    (function(){
      const unitTotal = ' . wp_json_encode((float)$unit_total) . ';
      const unitDeposit = ' . wp_json_encode((float)$unit_deposit) . ';
      const depositAllowed = ' . wp_json_encode((bool)$deposit_allowed) . ';

      function fmt(n){
        if (!isFinite(n)) n = 0;
        const parts = n.toFixed(2).split(".");
        const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + "," + parts[1];
      }

      function init(){
        const form = document.getElementById("casanova-group-pay-form");
        if (!form) return;

        const unitsInput = form.elements["units"];
        const modeInputs = form.querySelectorAll("input[name=\"mode\"]");
        const summaryUnit = document.getElementById("casanova-summary-unit");
        const summaryUnits = document.getElementById("casanova-summary-units");
        const summaryDeposit = document.getElementById("casanova-summary-deposit");
        const summaryTotal = document.getElementById("casanova-summary-total");
        const btn = document.getElementById("casanova-group-pay-button");
        const resendToggle = document.getElementById("casanova-resend-toggle");
        const resendPanel = document.getElementById("casanova-resend-panel");

        function getMode(){
          let mode = "full";
          Array.prototype.forEach.call(modeInputs, function(i){ if (i.checked) mode = i.value; });
          return mode;
        }

        function getUnits(){
          const raw = unitsInput ? parseInt(unitsInput.value, 10) : 1;
          const n = isNaN(raw) ? 1 : raw;
          return Math.max(1, Math.min(10, n));
        }

        function update(){
          const units = getUnits();
          const mode = getMode();
          const useDeposit = depositAllowed && mode === "deposit" && unitDeposit > 0 && unitDeposit < unitTotal;
          const amount = Math.max(0, (useDeposit ? unitDeposit : unitTotal) * units);

          if (summaryUnit) {
            summaryUnit.textContent = "Importe por persona: " + fmt(unitTotal) + " EUR";
          }
          if (summaryUnits) {
            summaryUnits.textContent = "Personas: " + units;
          }
          if (summaryDeposit) {
            if (useDeposit) {
              summaryDeposit.style.display = "block";
              summaryDeposit.textContent = "Depósito por persona: " + fmt(unitDeposit) + " EUR";
            } else {
              summaryDeposit.style.display = "none";
              summaryDeposit.textContent = "";
            }
          }
          if (summaryTotal) {
            summaryTotal.textContent = "Total a pagar hoy: " + fmt(amount) + " EUR";
          }

          if (btn) {
            btn.textContent = "Pagar " + fmt(amount) + " EUR";
          }
        }

        if (resendToggle && resendPanel) {
          resendToggle.addEventListener("click", function(){
            const open = resendPanel.style.display !== "none";
            resendPanel.style.display = open ? "none" : "block";
            resendToggle.setAttribute("aria-expanded", open ? "false" : "true");
          });
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
