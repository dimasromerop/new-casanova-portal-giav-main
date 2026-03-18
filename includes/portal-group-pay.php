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
  $baseTotal = (float)($ctx['base_total'] ?? 0);
  if ($baseTotal <= 0) {
    // Fallback: si no viene Venta desde GIAV, aproximamos como pendiente + ya pagado en slots.
    $slots_now = function_exists('casanova_group_slots_get') ? casanova_group_slots_get($idExpediente, $idReservaPQ ?: 0) : [];
    $paid_now = 0.0;
    if (!empty($slots_now)) {
      foreach ($slots_now as $s) { $paid_now += (float)($s->base_paid ?? 0); }
    }
    $baseTotal = max(0.0, $basePending + $paid_now);
  }
  $idReservaPQ = (int)($ctx['id_reserva_pq'] ?? $idReservaPQ);

  // Nuevo modelo: importe fijo por persona (unit_total), sin slots.
  $unit_total = (float)($group->unit_total ?? 0);
  if ($unit_total <= 0.0 && $baseTotal > 0 && $numPax > 0) {
    $unit_total = round(((float)$baseTotal) / ((float)$numPax), 2);
  }
  if ($unit_total <= 0.0) {
    casanova_render_payment_link_error(__('No se pudo determinar el importe por persona.', 'casanova-portal'));
    exit;
  }

  $deposit_allowed = function_exists('casanova_payments_is_deposit_allowed') ? casanova_payments_is_deposit_allowed($reservas) : false;
  $inespay_enabled = false;
  if (class_exists('Casanova_Inespay_Service')) {
    $cfg = Casanova_Inespay_Service::config();
    $inespay_enabled = !is_wp_error($cfg);
  }

  $flash_msg = '';
  $flash_type = 'info';

  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'casanova_group_pay_' . (int)$group->id)) {
      casanova_render_payment_link_error(__('Solicitud no valida.', 'casanova-portal'));
      exit;
    }

    $action = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : 'pay';
    if ($action === 'resend_magic') {
      $email_raw = isset($_POST['resend_email']) ? (string)$_POST['resend_email'] : '';
      $resend_email = trim(sanitize_email($email_raw));
      $dni_raw = isset($_POST['resend_dni']) ? (string)$_POST['resend_dni'] : '';
      $resend_dni = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($dni_raw)));

      if ($resend_email === '' || !is_email($resend_email) || $resend_dni === '') {
        $flash_msg = __('Debes indicar email y DNI.', 'casanova-portal');
        $flash_type = 'error';
      } elseif (!function_exists('casanova_payment_links_find_deposit_with_rest') || !function_exists('casanova_payment_links_resend_rest_magic')) {
        $flash_msg = __('Función no disponible. Contacta con la agencia.', 'casanova-portal');
        $flash_type = 'error';
      } else {
        $row = casanova_payment_links_find_deposit_with_rest($idExpediente, $resend_dni, $resend_email);
        if ($row) {
          $ok = casanova_payment_links_resend_rest_magic($row, $resend_email);
          if ($ok) {
            $flash_msg = __('Te hemos reenviado el enlace de pago final si existía un depósito registrado.', 'casanova-portal');
            $flash_type = 'success';
          } else {
            $flash_msg = __('No se pudo reenviar el enlace. Contacta con la agencia.', 'casanova-portal');
            $flash_type = 'error';
          }
        } else {
          $flash_msg = __('No encontrado. Contacta con la agencia.', 'casanova-portal');
          $flash_type = 'error';
        }
      }

      // Continuamos para renderizar la página con el mensaje.
    } else {

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

    $units = isset($_POST['units']) ? (int)$_POST['units'] : 1;
    if ($units < 1) $units = 1;
    if ($units > 10) $units = 10;

    $others_raw = isset($_POST['others_names']) ? (string)$_POST['others_names'] : '';
    $others_names = trim(sanitize_textarea_field($others_raw));
    $others_list = [];
    if ($others_names !== '') {
      $lines = preg_split('/\r\n|\r|\n/', $others_names);
      foreach ($lines as $ln) {
        $ln = trim(sanitize_text_field($ln));
        if ($ln !== '') $others_list[] = $ln;
      }
    }
    if ($units <= 0) {
      casanova_render_payment_link_error(__('Debes seleccionar al menos 1 persona.', 'casanova-portal'));
      exit;
    }

    $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
    if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';
    if (!$deposit_allowed) $mode = 'full';

    $unit_deposit = 0.0;
    if ($mode === 'deposit' && function_exists('casanova_payments_calc_deposit_amount')) {
      $unit_deposit = (float) casanova_payments_calc_deposit_amount($unit_total, $idExpediente);
      $unit_deposit = round(max(0.0, $unit_deposit), 2);
    }

    $total_due = round($unit_total * (float)$units, 2);
    $deposit_total = round($unit_deposit * (float)$units, 2);
    $deposit_effective = ($mode === 'deposit') && ($deposit_total + 0.01 < $total_due);
    if ($mode === 'deposit' && !$deposit_effective) $mode = 'full';

    $amount_to_pay = ($mode === 'deposit') ? $deposit_total : $total_due;
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
      'billing_dni' => $dni,
      'metadata' => [
        'mode' => $mode,
        'units' => $units,
        'unit_total' => $unit_total,
        'unit_deposit' => $unit_deposit,
        'billing_name' => $billing_name,
        'billing_lastname' => $billing_lastname,
        'billing_fullname' => $billing_fullname,
        'billing_dni' => $dni,
        'billing_email' => $billing_email,
        'others_names' => $others_list,
        'group_token_id' => (int)$group->id,
        'id_reserva_pq' => $idReservaPQ,
        'preferred_method' => $selected_method,
        'auto_start' => true,
        'total_due' => $total_due,
        'deposit_total' => ($mode === 'deposit') ? $deposit_total : 0,
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

  header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
  echo '<div style="max-width:760px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
  echo '<style>
    .casanova-two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .casanova-two-col label{margin:0;}
    @media (max-width:640px){.casanova-two-col{grid-template-columns:1fr;}}
  </style>';

  echo '<h2 style="margin:0 0 6px;">' . esc_html__('Pago del viaje', 'casanova-portal') . '</h2>';
  echo '<p style="margin:0 0 14px;color:#555;">' . esc_html__('Selecciona cuántas personas quedan incluidas en este pago y completa los datos del pagador.', 'casanova-portal') . '</p>';
  $codigo_html = $exp_codigo ? ' <span style="color:#666;">(' . esc_html($exp_codigo) . ')</span>' : '';
  echo '<p style="margin:0 0 14px;">' . wp_kses_post(
    sprintf(__('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'), esc_html($exp_label), $codigo_html)
  ) . '</p>';

  echo '<div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';
  echo '<div>' . esc_html(sprintf(__('Importe por persona: %s EUR', 'casanova-portal'), number_format($unit_total, 2, ',', '.'))) . '</div>';
  if ($deadline_txt !== '') {
    echo '<div style="margin-top:6px;">' . esc_html(sprintf(__('Fecha limite: %s', 'casanova-portal'), $deadline_txt)) . '</div>';
  }
  echo '</div>';

  $unit_deposit_preview = $deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')
    ? round((float) casanova_payments_calc_deposit_amount($unit_total, $idExpediente), 2)
    : 0.0;

  $default_amount = $deposit_allowed && $unit_deposit_preview > 0.009 && $unit_deposit_preview + 0.01 < $unit_total
    ? $unit_deposit_preview
    : $unit_total;
  $transfer_note = __('El pago por transferencia bancaria online PSD2 no tiene recargo y es completamente seguro. Serás redirigido a una página de pago donde podrás seleccionar tu banco y acceder a tu banca online para autorizar la transferencia. Una vez completado el pago, volverás automáticamente a nuestra página. Este método es compatible con la mayoría de bancos españoles y portugueses.', 'casanova-portal');

  if ($flash_msg !== '') {
    $bg = ($flash_type === 'success') ? '#f0fff4' : (($flash_type === 'error') ? '#fff7f7' : '#f7f7ff');
    $bd = ($flash_type === 'success') ? '#c6f6d5' : (($flash_type === 'error') ? '#f0c2c2' : '#d6d6ff');
    echo '<div style="margin:0 0 14px;padding:10px 12px;border:1px solid ' . esc_attr($bd) . ';background:' . esc_attr($bg) . ';border-radius:8px;">'
      . esc_html($flash_msg)
      . '</div>';
  }

  echo '<details style="border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';
  echo '<summary style="font-weight:600;cursor:pointer;">' . esc_html__('¿Ya pagaste el depósito? Reenviar enlace para pagar el resto', 'casanova-portal') . '</summary>';
  echo '<div style="margin-top:10px;color:#555;">' . esc_html__('Usa el mismo email y DNI/NIF con el que pagaste el depósito y te reenviamos el enlace del pago restante.', 'casanova-portal') . '</div>';
  echo '<form method="post" action="' . esc_url(casanova_group_pay_url((string)$group->token)) . '" style="margin:0;">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="resend_magic" />';
  echo '<div class="casanova-two-col">';
  echo '<label style="display:block;">' . esc_html__('Email', 'casanova-portal') . '<br />'
    . '<input type="email" name="resend_email" autocomplete="email" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />'
    . '</label>';
  echo '<label style="display:block;">' . esc_html__('DNI / NIF', 'casanova-portal') . '<br />'
    . '<input type="text" name="resend_dni" autocomplete="tax-id" required value="" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;" />'
    . '</label>';
  echo '</div>';
  echo '<button type="submit" style="margin-top:10px;padding:10px 14px;border:1px solid #111;border-radius:8px;background:#fff;color:#111;cursor:pointer;">'
    . esc_html__('Enviar enlace del resto', 'casanova-portal')
    . '</button>';
  echo '</form>';
  echo '</details>';

  echo '<form id="casanova-group-pay-form" method="post" action="' . esc_url(casanova_group_pay_url((string)$group->token)) . '">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
  echo '<input type="hidden" name="action" value="pay" />';

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
    echo '<input type="radio" name="method" value="card" checked style="margin-right:8px;" />' . esc_html__('Tarjeta', 'casanova-portal');
    echo '<span style="display:block;margin-left:24px;margin-top:4px;font-size:12px;color:#666;">' . esc_html__('Pago inmediato y seguro.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '<label style="display:block;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
    echo '<input type="radio" name="method" value="bank_transfer" style="margin-right:8px;" />' . esc_html__('Transferencia bancaria online', 'casanova-portal');
    echo '<span style="display:block;margin-left:24px;margin-top:4px;font-size:12px;color:#666;">' . esc_html__('PSD2 · Sin recargo.', 'casanova-portal') . '</span>';
    echo '</label>';
    echo '</div>';
    echo '<div id="casanova-method-note" style="display:none;margin-top:8px;padding:10px 12px;border:1px solid #eee;border-radius:8px;background:#fafafa;color:#555;font-size:12px;line-height:1.5;">' . esc_html($transfer_note) . '</div>';
  } else {
    echo '<input type="hidden" name="method" value="card" />';
    echo '<div style="margin:6px 0 12px;color:#666;">' . esc_html__('Solo tarjeta disponible.', 'casanova-portal') . '</div>';
  }

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Personas incluidas en este pago', 'casanova-portal') . '</label>';
  echo '<select name="units" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">';
  for ($i = 1; $i <= 10; $i++) {
    echo '<option value="' . esc_attr((string)$i) . '">' . esc_html((string)$i) . '</option>';
  }
  echo '</select>';

  echo '<label style="display:block;margin:10px 0 6px;font-weight:600;">' . esc_html__('Nombres de viajeros (opcional)', 'casanova-portal') . '</label>';
  echo '<textarea name="others_names" rows="4" placeholder="Nombre 1&#10;Nombre 2" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></textarea>';
  echo '<div style="margin-top:6px;font-size:12px;color:#666;">' . esc_html__('Solo para referencia de la agencia. 1 nombre por línea.', 'casanova-portal') . '</div>';

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
      const unitTotal = ' . wp_json_encode(round($unit_total, 2)) . ';
      const unitDeposit = ' . wp_json_encode(round($unit_deposit_preview, 2)) . ';

      function fmt(n){
        if (!isFinite(n)) n = 0;
        const parts = n.toFixed(2).split(".");
        const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + "," + parts[1];
      }

      function init(){
        const form = document.getElementById("casanova-group-pay-form");
        if (!form) return;
        const unitsSelect = form.elements["units"];
        const modeInputs = form.querySelectorAll("input[name=mode]");
        const methodInputs = form.querySelectorAll("input[name=method]");
        const methodNote = document.getElementById("casanova-method-note");
        const summary = document.getElementById("casanova-group-summary");
        const btn = document.getElementById("casanova-group-pay-button");

        function getUnits(){
          const raw = parseInt(unitsSelect && unitsSelect.value ? unitsSelect.value : "1", 10);
          if (!isFinite(raw) || raw < 1) return 1;
          if (raw > 10) return 10;
          return raw;
        }

        function getMode(){
          let m = "full";
          Array.prototype.forEach.call(modeInputs, function(i){ if (i.checked) m = i.value; });
          return m;
        }

        function getMethod(){
          let m = "card";
          Array.prototype.forEach.call(methodInputs, function(i){ if (i.checked) m = i.value; });
          return m;
        }

        function update(){
          const units = getUnits();
          const mode = getMode();
          const method = getMethod();
          const total = Math.round((Number(unitTotal) * Number(units)) * 100) / 100;
          const dep = Math.round((Number(unitDeposit) * Number(units)) * 100) / 100;
          const isDeposit = (mode === "deposit" && dep > 0.009 && dep + 0.01 < total);
          const amount = isDeposit ? dep : total;
          if (methodNote) methodNote.style.display = (method === "bank_transfer") ? "block" : "none";
          if (summary) {
            const lines = [
              "Importe por persona: " + fmt(unitTotal) + " \\u20AC",
              "Personas: " + String(units)
            ];
            if (isDeposit) {
              lines.push("Dep\\u00f3sito por persona: " + fmt(unitDeposit) + " \\u20AC");
            }
            lines.push("Total a pagar hoy: " + fmt(amount) + " \\u20AC");
            summary.innerHTML = lines.join("<br />");
          }
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
