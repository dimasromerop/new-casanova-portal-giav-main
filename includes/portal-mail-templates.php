<?php
// includes/portal-mail-templates.php
if (!defined('ABSPATH')) exit;

function casanova_mail_styles(): string {
  return '
  <style>
    .casanova-mail{font-family:Arial,Helvetica,sans-serif;line-height:1.45;color:#111}
    .casanova-mail__inner{max-width:640px;margin:0 auto;padding:24px}
    .casanova-mail__title{margin:0 0 12px;font-size:20px}
    .casanova-mail__body{font-size:14px}
    .casanova-mail__rule{border:none;border-top:1px solid #eee;margin:18px 0}
    .casanova-mail__footer{font-size:12px;color:#555}
    .casanova-mail__summary{width:70%;border-collapse:collapse;margin:12px 0;font-size:14px}
    .casanova-mail__cell{padding:8px;border-bottom:1px solid #eee}
    .casanova-mail__cell--value{text-align:right}
    .casanova-mail__button{display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#111}
    .casanova-mail__button--primary{background:#111;border-color:#111;color:#fff;font-weight:600}
    .casanova-mail__button-row{margin:16px 0 18px}
    .casanova-mail__note{color:#333;margin:0 0 10px}
    .casanova-mail__help{color:#666;font-size:12px}
  </style>';
}

function casanova_mail_summary_row_html(string $label, string $value_html): string {
  return '<tr><td class="casanova-mail__cell">' . esc_html($label) . '</td><td class="casanova-mail__cell casanova-mail__cell--value">' . $value_html . '</td></tr>';
}

function casanova_mail_button_html(string $url, string $label, string $modifier = ''): string {
  $class = 'casanova-mail__button';
  if ($modifier !== '') {
    $class .= ' ' . $modifier;
  }

  return '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}

function casanova_mail_wrap_html(string $title, string $bodyHtml): string {
  $site = esc_html(get_bloginfo('name'));
  $titleEsc = esc_html($title);

  return '
  ' . casanova_mail_styles() . '
  <div class="casanova-mail">
    <div class="casanova-mail__inner">
      <h2 class="casanova-mail__title">' . $titleEsc . '</h2>
      <div class="casanova-mail__body">' . $bodyHtml . '</div>
      <hr class="casanova-mail__rule">
      <div class="casanova-mail__footer">
        ' . $site . '
      </div>
    </div>
  </div>';
}

function casanova_portal_url_expediente(int $idExpediente): string {
  // Ajusta esta URL a tu ruta real del portal si es distinta
  // Ej: /mi-cuenta/expediente/?expediente=123
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  return add_query_arg(['expediente' => $idExpediente], $base);
}

function casanova_tpl_email_confirmacion_cobro(array $ctx): array {
  // ctx esperado: cliente_nombre, idExpediente, codigoExpediente, importe, fecha, pagado, pendiente
  $cliente = esc_html((string)($ctx['cliente_nombre'] ?? ''));
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $importe = (string)($ctx['importe'] ?? '');
  $fecha = (string)($ctx['fecha'] ?? '');
  $pagado = (string)($ctx['pagado'] ?? '');
  $pendiente = (string)($ctx['pendiente'] ?? '');
  $modalidad = (string)($ctx['modalidad'] ?? '');
  $resto_message = (string)($ctx['resto_message'] ?? '');
  $is_group_payment = !empty($ctx['is_group_payment']);

  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);
  $url = esc_url(casanova_portal_url_expediente($idExp));

  $subject = sprintf(__('Confirmación de pago recibido – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '';
  if ($cliente !== '') {
    $body .= '<p>' . sprintf(esc_html__('Hola %s,', 'casanova-portal'), $cliente) . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Hola,', 'casanova-portal') . '</p>';
  }

  if ($is_group_payment) {
    $body .= '<p>' . sprintf(wp_kses_post(__('Hemos registrado correctamente tu pago para el expediente <strong>%s</strong>.', 'casanova-portal')), $expLabel) . '</p>';
  } else {
    $body .= '<p>' . sprintf(wp_kses_post(__('Hemos registrado un pago para tu expediente <strong>%s</strong>.', 'casanova-portal')), $expLabel) . '</p>';
  }

  $body .= '<table class="casanova-mail__summary">';
  $body .= casanova_mail_summary_row_html(__('Importe', 'casanova-portal'), '<strong>' . esc_html($importe) . '</strong>');
  if ($modalidad !== '') {
    $body .= casanova_mail_summary_row_html(__('Modalidad', 'casanova-portal'), esc_html($modalidad));
  }
  if ($fecha !== '') {
    $body .= casanova_mail_summary_row_html(__('Fecha', 'casanova-portal'), esc_html($fecha));
  }
  if ($pagado !== '') {
    $body .= casanova_mail_summary_row_html(__('Total pagado', 'casanova-portal'), esc_html($pagado));
  }
  if ($pendiente !== '') {
    $body .= casanova_mail_summary_row_html(__('Pendiente', 'casanova-portal'), esc_html($pendiente));
  }
  $body .= '</table>';

  if ($resto_message !== '') {
    $body .= '<p>' . esc_html($resto_message) . '</p>';
  }

  if ($is_group_payment) {
    $body .= '<p>' . esc_html__('Si necesitas ayuda con el pago, contacta con la agencia.', 'casanova-portal') . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Puedes ver el estado actualizado aquí:', 'casanova-portal') . '</p>';
    $body .= '<p>' . casanova_mail_button_html($url, __('Ver expediente', 'casanova-portal')) . '</p>';
  }

  $html = casanova_mail_wrap_html(__('Pago recibido', 'casanova-portal'), $body);

  return ['subject' => $subject, 'html' => $html];
}

function casanova_tpl_email_expediente_pagado(array $ctx): array {
  // ctx esperado: cliente_nombre, idExpediente, codigoExpediente, total_objetivo, pagado, fecha (opcional)
  $cliente = esc_html((string)($ctx['cliente_nombre'] ?? ''));
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $total = (string)($ctx['total_objetivo'] ?? '');
  $pagado = (string)($ctx['pagado'] ?? '');
  $fecha = (string)($ctx['fecha'] ?? '');

  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);
  $url = esc_url(casanova_portal_url_expediente($idExp));

  $subject = sprintf(__('Pago completado – Documentación disponible – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '';
  if ($cliente !== '') {
    $body .= '<p>' . sprintf(esc_html__('Hola %s,', 'casanova-portal'), $cliente) . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Hola,', 'casanova-portal') . '</p>';
  }

  $body .= '<p>' . sprintf(wp_kses_post(__('Tu expediente <strong>%s</strong> está <strong>completamente pagado</strong>.', 'casanova-portal')), $expLabel) . '</p>';

  $body .= '<table class="casanova-mail__summary">';
  if ($total !== '') {
    $body .= casanova_mail_summary_row_html(__('Total expediente', 'casanova-portal'), esc_html($total));
  }
  if ($pagado !== '') {
    $body .= casanova_mail_summary_row_html(__('Total pagado', 'casanova-portal'), '<strong>' . esc_html($pagado) . '</strong>');
  }
  if ($fecha !== '') {
    $body .= casanova_mail_summary_row_html(__('Fecha', 'casanova-portal'), esc_html($fecha));
  }
  $body .= '</table>';

  $body .= '<p>' . esc_html__('Ya puedes acceder a tu documentación (bonos y facturas) desde el portal:', 'casanova-portal') . '</p>';
  $body .= '<p>' . casanova_mail_button_html($url, __('Acceder al portal', 'casanova-portal')) . '</p>';

  $html = casanova_mail_wrap_html(__('Pago completado', 'casanova-portal'), $body);

  return ['subject' => $subject, 'html' => $html];
}

/**
 * Email: link seguro para pagar el resto (Magic Link).
 * ctx esperado: to_email, cliente_nombre (opcional), idExpediente, codigoExpediente (opcional), importe, url_pago
 */
function casanova_tpl_email_resto_pago_magic_link(array $ctx): array {
  $to = (string)($ctx['to_email'] ?? '');
  $cliente = esc_html((string)($ctx['cliente_nombre'] ?? ''));
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $importe = (string)($ctx['importe'] ?? '');
  $url = esc_url((string)($ctx['url_pago'] ?? ''));

  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);

  $subject = sprintf(__('Enlace para completar tu pago – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '';
  if ($cliente !== '') {
    $body .= '<p>' . sprintf(esc_html__('Hola %s,', 'casanova-portal'), $cliente) . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Hola,', 'casanova-portal') . '</p>';
  }

  $body .= '<p>' . esc_html__('Ya hemos registrado tu depósito. Cuando quieras, puedes completar el resto del pago desde este enlace seguro:', 'casanova-portal') . '</p>';

  $btn = casanova_mail_button_html($url, __('Pagar el resto', 'casanova-portal'), 'casanova-mail__button--primary');
  $body .= '<p class="casanova-mail__button-row">' . $btn . '</p>';

  if ($importe !== '') {
    $body .= '<p class="casanova-mail__note">' . sprintf(esc_html__('Importe pendiente: %s', 'casanova-portal'), '<strong>' . esc_html($importe) . '</strong>') . '</p>';
  }

  $body .= '<p class="casanova-mail__help">' . esc_html__('Si no has solicitado este enlace, puedes ignorar este email.', 'casanova-portal') . '</p>';

  $html = casanova_mail_wrap_html($subject, $body);
  return ['subject' => $subject, 'html' => $html, 'to' => $to];
}

function casanova_tpl_email_admin_payment_notice(array $ctx): array {
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);

  $payer = trim((string)($ctx['payer_name'] ?? ''));
  $payerEmail = trim((string)($ctx['payer_email'] ?? ''));
  $importe = (string)($ctx['importe'] ?? '');
  $fecha = (string)($ctx['fecha'] ?? '');
  $modalidad = (string)($ctx['modalidad'] ?? '');
  $provider = (string)($ctx['provider'] ?? '');
  $method = (string)($ctx['method'] ?? '');
  $scope = (string)($ctx['scope'] ?? '');
  $reference = (string)($ctx['reference'] ?? '');
  $tripTitle = (string)($ctx['trip_title'] ?? '');

  $subject = sprintf(__('Nuevo pago registrado – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '<p>' . sprintf(wp_kses_post(__('Se ha registrado un nuevo pago en el expediente <strong>%s</strong>.', 'casanova-portal')), $expLabel) . '</p>';

  $body .= '<table class="casanova-mail__summary">';
  if ($tripTitle !== '') {
    $body .= casanova_mail_summary_row_html(__('Expediente', 'casanova-portal'), esc_html($tripTitle . ' (' . $expLabel . ')'));
  } else {
    $body .= casanova_mail_summary_row_html(__('Expediente', 'casanova-portal'), esc_html($expLabel));
  }
  if ($payer !== '') {
    $body .= casanova_mail_summary_row_html(__('Pagador', 'casanova-portal'), esc_html($payer));
  }
  if ($payerEmail !== '') {
    $body .= casanova_mail_summary_row_html(__('Email', 'casanova-portal'), esc_html($payerEmail));
  }
  if ($importe !== '') {
    $body .= casanova_mail_summary_row_html(__('Importe', 'casanova-portal'), '<strong>' . esc_html($importe) . '</strong>');
  }
  if ($modalidad !== '') {
    $body .= casanova_mail_summary_row_html(__('Modalidad', 'casanova-portal'), esc_html($modalidad));
  }
  if ($fecha !== '') {
    $body .= casanova_mail_summary_row_html(__('Fecha', 'casanova-portal'), esc_html($fecha));
  }
  if ($provider !== '') {
    $body .= casanova_mail_summary_row_html(__('Proveedor', 'casanova-portal'), esc_html($provider));
  }
  if ($method !== '') {
    $body .= casanova_mail_summary_row_html(__('Metodo', 'casanova-portal'), esc_html($method));
  }
  if ($scope !== '') {
    $body .= casanova_mail_summary_row_html(__('Origen', 'casanova-portal'), esc_html($scope));
  }
  if ($reference !== '') {
    $body .= casanova_mail_summary_row_html(__('Referencia', 'casanova-portal'), esc_html($reference));
  }
  $body .= '</table>';

  $html = casanova_mail_wrap_html(__('Aviso interno de pago', 'casanova-portal'), $body);

  return ['subject' => $subject, 'html' => $html];
}
