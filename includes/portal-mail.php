<?php
// includes/portal-mail.php
if (!defined('ABSPATH')) exit;

function casanova_mail_defaults(): array {
  $agency = function_exists('casanova_portal_agency_profile')
    ? casanova_portal_agency_profile()
    : [];

  $from_email = sanitize_email($agency['email'] ?? get_option('admin_email'));
  if (!$from_email) $from_email = get_option('admin_email');

  $from_name  = sanitize_text_field($agency['nombre'] ?? get_bloginfo('name'));

  return [$from_email, $from_name];
}

function casanova_mail_admin_payment_recipients(): array {
  $raw = (string) get_option('casanova_payment_admin_notification_emails', '');
  if ($raw === '') {
    return [];
  }

  $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
  $emails = [];
  foreach ($parts as $part) {
    $email = sanitize_email(trim((string) $part));
    if ($email !== '' && is_email($email)) {
      $emails[$email] = $email;
    }
  }

  return array_values($emails);
}

function casanova_mail_send($to, string $subject, string $html, array $args = []): bool {
  if (empty($to)) return false;

  [$from_email, $from_name] = casanova_mail_defaults();

  $headers = [];
  $headers[] = 'Content-Type: text/html; charset=UTF-8';
  $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';

  if (!empty($args['reply_to']) && is_email($args['reply_to'])) {
    $headers[] = 'Reply-To: ' . $args['reply_to'];
  }

  // Opcional: copia interna
  if (!empty($args['bcc']) && is_email($args['bcc'])) {
    $headers[] = 'Bcc: ' . $args['bcc'];
  }

  $subject = wp_strip_all_tags($subject);

  // wp_mail ya lo enruta WP Mail SMTP, tú no te preocupas del “cómo”
  $ok = wp_mail($to, $subject, $html, $headers);

  // Log mínimo (si quieres)
  if (defined('CASANOVA_GIAV_DEBUG') && CASANOVA_GIAV_DEBUG) {
    error_log('[CASANOVA MAIL] to=' . (is_array($to) ? implode(',', $to) : $to) . ' subject=' . $subject . ' ok=' . ($ok ? '1' : '0'));
  }

  return (bool)$ok;
}

function casanova_mail_send_payment_confirmed(array $ctx): bool {
  $to = $ctx['to'] ?? '';
  $tpl = casanova_tpl_email_confirmacion_cobro($ctx);
  $subject = $tpl['subject'] ?? 'Confirmación de pago recibido';
  $html = $tpl['html'] ?? '';
  return casanova_mail_send($to, $subject, $html);
}

function casanova_mail_send_expediente_paid(array $ctx): bool {
  $to = $ctx['to'] ?? '';
  $tpl = casanova_tpl_email_expediente_pagado($ctx);
  $subject = $tpl['subject'] ?? 'Pago completado';
  $html = $tpl['html'] ?? '';
  return casanova_mail_send($to, $subject, $html);
}

function casanova_mail_send_admin_payment_notice(array $ctx): bool {
  $to = $ctx['to'] ?? [];
  if (empty($to)) {
    return false;
  }

  $tpl = casanova_tpl_email_admin_payment_notice($ctx);
  $subject = $tpl['subject'] ?? 'Nuevo pago registrado';
  $html = $tpl['html'] ?? '';
  return casanova_mail_send($to, $subject, $html);
}
