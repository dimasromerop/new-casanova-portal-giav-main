<?php
// includes/portal-mail-templates.php
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------ *
 *  Maqueta de marca «Casanova 2026» para TODOS los emails al cliente.
 *  Misma familia visual que el email de solicitud recibida (mu-plugin cg-2026-solicitud) y el de
 *  propuesta aceptada: cabecera verde con logo (y foto opcional), etiqueta, titular serif, tarjeta
 *  de resumen, próximos pasos, botones (radio 10px = cg-radius-s, como cg-btn), bloque de contacto y pie de marca.
 * ------------------------------------------------------------------ */

/** Tokens de marca (cg-green-800 y familia). */
function casanova_mail_tokens(): array {
  return [
    'green'  => '#0e3631',
    'tint'   => '#e3eeea',
    'sand'   => '#f5f4f0',
    'line'   => '#e4e1d8',
    'ink'    => '#1c1f1a',
    'muted'  => '#5b5f55',
    'font'   => "'Satoshi','Segoe UI',Roboto,Helvetica,Arial,sans-serif",
    'serif'  => "'Playfair Display',Georgia,'Times New Roman',serif",
  ];
}

/** Idioma del email ('es'|'en') a partir de un código o del locale actual. */
function casanova_mail_lang(string $lang = ''): string {
  $lang = strtolower(substr($lang !== '' ? $lang : (function_exists('determine_locale') ? determine_locale() : get_locale()), 0, 2));
  return $lang === 'es' ? 'es' : 'en';
}

/** Contacto de la agencia para el bloque «¿Alguna pregunta?». */
function casanova_mail_contact(): array {
  $agency = function_exists('casanova_portal_agency_profile') ? (array) casanova_portal_agency_profile() : [];
  $email  = defined('CG2026_SOLICITUD_EMAIL') ? CG2026_SOLICITUD_EMAIL : sanitize_email((string) ($agency['email'] ?? get_option('admin_email')));
  $phone  = defined('CG2026_SOLICITUD_PHONE') ? CG2026_SOLICITUD_PHONE : trim((string) ($agency['telefono'] ?? get_option('wp_travel_giav_itinerary_footer_phone', '')));
  return ['email' => is_email($email) ? $email : '', 'phone' => $phone];
}

/** Textos fijos de la maqueta. */
function casanova_mail_layout_t(string $lang): array {
  if ($lang === 'es') {
    return [
      'add'     => '¿Alguna pregunta? Responde a este email o llámanos.',
      'award'   => 'Mejor turoperador emisor de golf de España · World Golf Awards',
      'members' => 'Miembros de IAGTO · Casanova Golf, S.L. · Madrid · CICMA 2969',
    ];
  }
  return [
    'add'     => 'Any questions? Just reply to this email or give us a call.',
    'award'   => 'Spain’s Best Outbound Golf Tour Operator · World Golf Awards',
    'members' => 'IAGTO members · Casanova Golf, S.L. · Madrid · CICMA 2969',
  ];
}

/**
 * Renderiza un email de marca completo (documento HTML).
 *
 * $a = [
 *   'lang'      => 'es'|'en',
 *   'title'     => asunto (para <title>),
 *   'preheader' => texto de previsualización,
 *   'hero'      => URL de foto de cabecera ('' = cabecera verde lisa),
 *   'badge'     => etiqueta píldora ('' = sin etiqueta),
 *   'badge_check' => true (por defecto) antepone ✓ a la etiqueta; false para avisos (p. ej. pago pendiente),
 *   'headline'  => titular,
 *   'body_html' => HTML de cuerpo YA escapado (párrafos),
 *   'summary'   => ['title' => '', 'rows' => [etiqueta => valor texto], 'total' => [etiqueta, valor]],
 *   'steps'     => ['title' => '', 'items' => [[done(bool), título, texto]]],
 *   'buttons'   => [[url, etiqueta, 'primary'|'secondary']],
 *   'contact'   => true|false,
 *   'why'       => línea final «Has recibido este email porque…»,
 * ]
 */
function casanova_mail_layout(array $a): string {
  $k    = casanova_mail_tokens();
  $lang = casanova_mail_lang((string) ($a['lang'] ?? ''));
  $t    = casanova_mail_layout_t($lang);
  $hero = (string) ($a['hero'] ?? '');
  $logo_file = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR . '/cg-2026-assets/casanova-logo-blanco-email.png' : '';
  $logo = ($logo_file !== '' && file_exists($logo_file)) ? content_url('mu-plugins/cg-2026-assets/casanova-logo-blanco-email.png') : '';
  $contact = !empty($a['contact']) ? casanova_mail_contact() : ['email' => '', 'phone' => ''];
  $tel  = preg_replace('/[^0-9+]/', '', (string) $contact['phone']);
  $summary = (array) ($a['summary'] ?? []);
  $rows    = array_filter((array) ($summary['rows'] ?? []), static fn($v) => (string) $v !== '');
  $total   = (array) ($summary['total'] ?? []);
  $steps   = (array) ($a['steps'] ?? []);
  $buttons = (array) ($a['buttons'] ?? []);

  ob_start();
  ?>
<!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html((string) ($a['title'] ?? '')); ?></title>
<style>
@media (max-width: 620px) {
  .wrap { width: 100% !important; }
  .px { padding-left: 22px !important; padding-right: 22px !important; }
  .hero-title { font-size: 28px !important; }
  .lbl { width: 96px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:<?php echo $k['sand']; ?>;">
<?php if (!empty($a['preheader'])) : ?><div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html((string) $a['preheader']); ?></div><?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo $k['sand']; ?>;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid <?php echo $k['line']; ?>;border-radius:16px;overflow:hidden;font-family:<?php echo esc_attr($k['font']); ?>;color:<?php echo $k['ink']; ?>;">
  <tr>
    <td bgcolor="<?php echo $k['green']; ?>"<?php echo $hero !== '' ? ' background="' . esc_url($hero) . '"' : ''; ?> style="background-color:<?php echo $k['green']; ?>;<?php echo $hero !== '' ? "background-image:url('" . esc_url($hero) . "');background-position:center;background-size:cover;" : ''; ?>">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo $hero !== '' ? 'linear-gradient(180deg,rgba(8,22,19,.30) 0%,rgba(8,22,19,.82) 100%)' : 'transparent'; ?>;">
        <tr><td class="px" style="padding:24px 32px 0;">
          <?php if ($logo !== '') : ?><img src="<?php echo esc_url($logo); ?>" width="104" alt="Casanova Golf" style="display:block;border:0;width:104px;height:auto;"><?php else : ?><span style="font-family:<?php echo esc_attr($k['serif']); ?>;font-size:20px;color:#ffffff;letter-spacing:.04em;">Casanova Golf</span><?php endif; ?>
        </td></tr>
        <tr><td class="px" style="padding:<?php echo $hero !== '' ? '84' : '32'; ?>px 32px 30px;">
          <?php if (!empty($a['badge'])) : ?><span style="display:inline-block;background:<?php echo $k['tint']; ?>;color:<?php echo $k['green']; ?>;font-size:13px;font-weight:700;padding:6px 12px;border-radius:999px;"><?php echo (!array_key_exists('badge_check', $a) || $a['badge_check']) ? '&#10003;&nbsp; ' : ''; ?><?php echo esc_html((string) $a['badge']); ?></span><?php endif; ?>
          <h1 class="hero-title" style="margin:<?php echo !empty($a['badge']) ? '14' : '0'; ?>px 0 0;font-family:<?php echo esc_attr($k['serif']); ?>;font-weight:500;font-size:<?php echo $hero !== '' ? '34' : '30'; ?>px;line-height:1.15;color:#ffffff;"><?php echo esc_html((string) ($a['headline'] ?? '')); ?></h1>
        </td></tr>
      </table>
    </td>
  </tr>
  <?php if (!empty($a['body_html'])) : ?>
  <tr><td class="px" style="padding:28px 32px 8px;font-size:16px;line-height:1.6;">
    <?php echo $a['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado por el llamador ?>
  </td></tr>
  <?php endif; ?>
  <?php if ($rows || $total) : ?>
  <tr><td class="px" style="padding:20px 32px 4px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo $k['sand']; ?>;border-radius:12px;">
      <tr><td style="padding:20px 22px;">
        <?php if (!empty($summary['title'])) : ?><p style="margin:0 0 12px;font-family:<?php echo esc_attr($k['serif']); ?>;font-size:20px;font-weight:500;"><?php echo esc_html((string) $summary['title']); ?></p><?php endif; ?>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:15px;line-height:1.5;">
          <?php foreach ($rows as $label => $value) : ?>
            <tr><td class="lbl" style="padding:4px 12px 4px 0;color:<?php echo $k['muted']; ?>;width:130px;vertical-align:top;"><?php echo esc_html((string) $label); ?></td><td style="padding:4px 0;font-weight:600;"><?php echo nl2br(esc_html((string) $value)); ?></td></tr>
          <?php endforeach; ?>
          <?php if (count($total) === 2) : ?>
            <tr><td class="lbl" style="padding:10px 12px 0 0;color:<?php echo $k['muted']; ?>;vertical-align:top;border-top:1px solid <?php echo $k['line']; ?>;"><?php echo esc_html((string) $total[0]); ?></td><td style="padding:10px 0 0;font-family:<?php echo esc_attr($k['serif']); ?>;font-size:22px;font-weight:500;border-top:1px solid <?php echo $k['line']; ?>;"><?php echo esc_html((string) $total[1]); ?></td></tr>
          <?php endif; ?>
        </table>
      </td></tr>
    </table>
  </td></tr>
  <?php endif; ?>
  <?php if (!empty($steps['items'])) : ?>
  <tr><td class="px" style="padding:26px 32px 4px;">
    <?php if (!empty($steps['title'])) : ?><p style="margin:0 0 14px;font-family:<?php echo esc_attr($k['serif']); ?>;font-size:20px;font-weight:500;"><?php echo esc_html((string) $steps['title']); ?></p><?php endif; ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:15px;line-height:1.5;">
      <?php foreach (array_values((array) $steps['items']) as $n => $step) : [$done, $s_title, $s_text] = array_pad((array) $step, 3, ''); ?>
      <tr>
        <td width="40" style="vertical-align:top;padding:0 0 14px;"><span style="display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;border-radius:50%;background:<?php echo $done ? $k['green'] : $k['tint']; ?>;color:<?php echo $done ? '#ffffff' : $k['green']; ?>;font-weight:700;font-size:14px;"><?php echo $done ? '&#10003;' : (int) ($n + 1); ?></span></td>
        <td style="vertical-align:top;padding:2px 0 14px;"><strong><?php echo esc_html((string) $s_title); ?></strong><?php if ((string) $s_text !== '') : ?><br><span style="color:<?php echo $k['muted']; ?>;"><?php echo esc_html((string) $s_text); ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </td></tr>
  <?php endif; ?>
  <?php if ($buttons) : ?>
  <tr><td class="px" align="center" style="padding:14px 32px 8px;">
    <?php foreach ($buttons as $i => $b) : [$b_url, $b_label, $b_kind] = array_pad((array) $b, 3, 'primary'); if ((string) $b_url === '') { continue; } $primary = $b_kind !== 'secondary'; ?>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:<?php echo $i > 0 ? '10' : '0'; ?>px auto 0;"><tr>
      <td bgcolor="<?php echo $primary ? $k['green'] : '#ffffff'; ?>" style="border-radius:10px;<?php echo $primary ? '' : 'border:1px solid ' . $k['green'] . ';'; ?>"><a href="<?php echo esc_url((string) $b_url); ?>" style="display:inline-block;padding:<?php echo $primary ? '15px 30px' : '12px 26px'; ?>;font-size:<?php echo $primary ? '16' : '15'; ?>px;font-weight:700;color:<?php echo $primary ? '#ffffff' : $k['green']; ?>;text-decoration:none;border-radius:10px;"><?php echo esc_html((string) $b_label); ?></a></td>
    </tr></table>
    <?php endforeach; ?>
  </td></tr>
  <?php endif; ?>
  <?php if ($contact['email'] !== '' || $contact['phone'] !== '') : ?>
  <tr><td class="px" style="padding:24px 32px 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid <?php echo $k['line']; ?>;border-radius:12px;">
      <tr><td style="padding:16px 18px;font-size:14px;line-height:1.55;">
        <span style="color:<?php echo $k['muted']; ?>;"><?php echo esc_html($t['add']); ?></span><br>
        <?php if ($contact['email'] !== '') : ?><a href="mailto:<?php echo esc_attr($contact['email']); ?>" style="color:<?php echo $k['green']; ?>;font-weight:600;text-decoration:none;"><?php echo esc_html($contact['email']); ?></a><?php endif; ?>
        <?php if ($contact['phone'] !== '') : ?><?php echo $contact['email'] !== '' ? ' · ' : ''; ?><a href="tel:<?php echo esc_attr($tel); ?>" style="color:<?php echo $k['green']; ?>;font-weight:600;text-decoration:none;"><?php echo esc_html($contact['phone']); ?></a><?php endif; ?>
      </td></tr>
    </table>
  </td></tr>
  <?php else : ?>
  <tr><td style="padding:12px 0 0;"></td></tr>
  <?php endif; ?>
  <tr><td class="px" align="center" style="padding:20px 32px 24px;background:<?php echo $k['sand']; ?>;font-size:12px;line-height:1.6;color:<?php echo $k['muted']; ?>;">
    <span style="color:<?php echo $k['ink']; ?>;font-weight:600;"><?php echo esc_html($t['award']); ?></span><br>
    <?php echo esc_html($t['members']); ?>
    <?php if (!empty($a['why'])) : ?><br><?php echo esc_html((string) $a['why']); ?><?php endif; ?>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
  <?php
  return trim((string) ob_get_clean());
}

/** Clases de compatibilidad (los cuerpos ya generados las usan); el estilo real va en línea. */
function casanova_mail_styles(): string {
  $k = casanova_mail_tokens();
  return '<style>.casanova-mail__note{margin:0 0 10px}.casanova-mail__help{color:' . $k['muted'] . ';font-size:13px}.casanova-mail__button-row{margin:18px 0}</style>';
}

function casanova_mail_summary_row_html(string $label, string $value_html): string {
  $k = casanova_mail_tokens();
  return '<tr><td class="casanova-mail__cell" style="padding:6px 12px 6px 0;color:' . $k['muted'] . ';vertical-align:top;">' . esc_html($label) . '</td>'
    . '<td class="casanova-mail__cell casanova-mail__cell--value" style="padding:6px 0;text-align:right;font-weight:600;">' . $value_html . '</td></tr>';
}

function casanova_mail_button_html(string $url, string $label, string $modifier = ''): string {
  $k = casanova_mail_tokens();
  // Botones de marca (cg-btn: radio 10px): verde relleno (primario) u outline verde.
  $primary = $modifier === '' || strpos($modifier, 'primary') !== false;
  $style = $primary
    ? 'display:inline-block;padding:14px 28px;border-radius:10px;background:' . $k['green'] . ';border:1px solid ' . $k['green'] . ';color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;'
    : 'display:inline-block;padding:12px 24px;border-radius:10px;background:#ffffff;border:1px solid ' . $k['green'] . ';color:' . $k['green'] . ';font-weight:700;font-size:15px;text-decoration:none;';
  $class = 'casanova-mail__button' . ($modifier !== '' ? ' ' . $modifier : '');
  return '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '" style="' . $style . '">' . esc_html($label) . '</a>';
}

/**
 * Envoltorio de marca para cuerpos HTML ya construidos (cobros del portal, avisos de cobro de
 * propuestas…). Mismo diseño que el resto de emails al cliente: cabecera verde con el título.
 */
function casanova_mail_wrap_html(string $title, string $bodyHtml): string {
  $k = casanova_mail_tokens();
  // Las tablas de resumen de los cuerpos (.casanova-mail__summary) toman la tarjeta arena.
  $bodyHtml = str_replace(
    '<table class="casanova-mail__summary">',
    '<table class="casanova-mail__summary" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:14px 0 18px;background:' . $k['sand'] . ';border-radius:12px;padding:12px 18px;font-size:15px;line-height:1.5;">',
    $bodyHtml
  );
  return casanova_mail_layout([
    'lang'      => casanova_mail_lang(),
    'title'     => $title,
    'headline'  => $title,
    'body_html' => casanova_mail_styles() . $bodyHtml,
    'contact'   => true,
  ]);
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
