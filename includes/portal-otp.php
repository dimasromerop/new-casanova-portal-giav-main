<?php

/**
 * Email OTP for secure GIAV linking.
 *
 * Uses wp_mail(), so if the site is configured with Brevo/SMTP, delivery goes through that.
 */

function casanova_portal_mask_email(string $email): string {
  $email = trim(strtolower($email));
  if ($email === '' || !str_contains($email, '@')) return '';
  [$local, $domain] = explode('@', $email, 2);
  $local = trim($local);
  $domain = trim($domain);
  if ($local === '' || $domain === '') return '';
  $first = substr($local, 0, 1);
  return $first . '***@' . $domain;
}

function casanova_portal_generate_otp_code(): string {
  // 6 digits, leading zeros allowed
  return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function casanova_portal_hash_value(string $value): string {
  // Stable, non-reversible hash for lookups
  return hash_hmac('sha256', $value, wp_salt('casanova_portal_otp'));
}

function casanova_portal_otp_is_rate_limited(int $user_id, string $dni, string $ip): bool {
  global $wpdb;
  $table = casanova_portal_otp_table_name();
  $dni_hash = casanova_portal_hash_value($dni);

  // Limit: max 3 sends per 15 minutes per (user_id + dni)
  $since = gmdate('Y-m-d H:i:s', time() - 15 * 60);
  $count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(sent_count) FROM {$table} WHERE user_id=%d AND dni_hash=%s AND last_sent_at >= %s",
    $user_id,
    $dni_hash,
    $since
  ));

  if ($count >= 3) return true;

  // Extra: max 10 sends per day per IP
  $since_day = gmdate('Y-m-d H:i:s', time() - 24 * 60 * 60);
  $count_ip = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(sent_count) FROM {$table} WHERE ip=%s AND last_sent_at >= %s",
    $ip,
    $since_day
  ));

  return $count_ip >= 10;
}

function casanova_portal_send_linking_otp(int $user_id, string $dni, int $giav_customer_id): WP_Error|array {
  global $wpdb;

  $dni = preg_replace('/\s+/', '', strtoupper(trim($dni)));
  if ($dni === '') return new WP_Error('otp_missing_dni', __('DNI inválido.', 'casanova-portal'));
  if ($giav_customer_id <= 0) return new WP_Error('otp_missing_customer', __('Cliente inválido.', 'casanova-portal'));

  // Fetch email from GIAV
  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;
  // IMPORTANTE: Cliente_GET exige la propiedad "id" (no idCliente / idsCliente)
  $p->id = (int) $giav_customer_id;
  $resp = casanova_giav_call('Cliente_GET', $p);
  if (is_wp_error($resp)) return $resp;

  $c = null;
  if (is_object($resp) && isset($resp->Cliente_GETResult) && is_object($resp->Cliente_GETResult)) {
    $c = $resp->Cliente_GETResult;
  }

  $email = '';
  if (is_object($c)) {
    // Prefer CustomerPortal_Email if present
    $email = (string) ($c->CustomerPortal_Email ?? $c->customerPortal_Email ?? '');
    if ($email === '') $email = (string) ($c->Email ?? $c->email ?? '');
  }

  $email = sanitize_email($email);
  if ($email === '') {
    return new WP_Error('otp_no_email', __('No hemos podido enviar el código porque no hay un email válido asociado a tu reserva. Escríbenos y lo revisamos.', 'casanova-portal'));
  }

  $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 250) : '';

  if (casanova_portal_otp_is_rate_limited($user_id, $dni, $ip)) {
    return new WP_Error('otp_rate_limited', __('Has hecho demasiados intentos. Espera unos minutos y vuelve a intentarlo.', 'casanova-portal'));
  }

  $code = casanova_portal_generate_otp_code();
  $otp_hash = wp_hash_password($code);
  $dni_hash = casanova_portal_hash_value($dni);
  $email_hash = casanova_portal_hash_value(strtolower($email));
  $email_masked = casanova_portal_mask_email($email);

  $now = gmdate('Y-m-d H:i:s');
  $expires = gmdate('Y-m-d H:i:s', time() + 10 * 60);

  $table = casanova_portal_otp_table_name();
  $wpdb->insert($table, [
    'user_id' => $user_id,
    'dni_hash' => $dni_hash,
    'giav_customer_id' => $giav_customer_id,
    'email_masked' => $email_masked,
    'email_hash' => $email_hash,
    'otp_hash' => $otp_hash,
    'sent_count' => 1,
    'verify_attempts' => 0,
    'created_at' => $now,
    'expires_at' => $expires,
    'last_sent_at' => $now,
    'ip' => $ip,
    'user_agent' => $ua,
    'status' => 'pending',
  ]);

  $subject = __('Tu código de verificación', 'casanova-portal');
  $message = sprintf(
    /* translators: %s: OTP code */
    __("Tu código de verificación para vincular tu cuenta es: %s\n\nCaduca en 10 minutos.\n\nSi no has solicitado este código, puedes ignorar este email.", 'casanova-portal'),
    $code
  );

  $headers = [];

  $sent = wp_mail($email, $subject, $message, $headers);
  if (!$sent) {
    // Mark failed (keeping record for rate limiting / audit)
    $wpdb->update($table, ['status' => 'failed'], ['id' => (int) $wpdb->insert_id]);
    return new WP_Error('otp_send_failed', __('No hemos podido enviar el email. Inténtalo más tarde.', 'casanova-portal'));
  }

  // Store pending info for UI / next step
  update_user_meta($user_id, 'casanova_pending_idcliente', (string) $giav_customer_id);
  update_user_meta($user_id, 'casanova_pending_dni', $dni);

  return [
    'emailMasked' => $email_masked,
    'expiresIn' => 600,
  ];
}

function casanova_portal_verify_linking_otp(int $user_id, string $dni, string $otp): WP_Error|array {
  global $wpdb;
  $dni = preg_replace('/\s+/', '', strtoupper(trim($dni)));
  $otp = preg_replace('/\s+/', '', trim($otp));

  if ($dni === '' || $otp === '') {
    return new WP_Error('otp_missing', __('Introduce el código que te hemos enviado.', 'casanova-portal'));
  }

  $dni_hash = casanova_portal_hash_value($dni);
  $table = casanova_portal_otp_table_name();
  $now = gmdate('Y-m-d H:i:s');

  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table} WHERE user_id=%d AND dni_hash=%s AND status='pending' ORDER BY id DESC LIMIT 1",
    $user_id,
    $dni_hash
  ));

  if (!$row) {
    return new WP_Error('otp_not_found', __('No encontramos un código activo. Solicita uno nuevo.', 'casanova-portal'));
  }

  if (strtotime((string) $row->expires_at) < strtotime($now)) {
    $wpdb->update($table, ['status' => 'expired'], ['id' => (int) $row->id]);
    return new WP_Error('otp_expired', __('El código ha caducado. Solicita uno nuevo.', 'casanova-portal'));
  }

  if ((int) $row->verify_attempts >= 5) {
    $wpdb->update($table, ['status' => 'locked'], ['id' => (int) $row->id]);
    return new WP_Error('otp_locked', __('Has superado el número de intentos. Solicita un código nuevo.', 'casanova-portal'));
  }

  $ok = wp_check_password($otp, (string) $row->otp_hash, (int) $row->id);
  $wpdb->update($table, ['verify_attempts' => (int) $row->verify_attempts + 1], ['id' => (int) $row->id]);

  if (!$ok) {
    return new WP_Error('otp_invalid', __('El código no es válido. Revisa el email e inténtalo de nuevo.', 'casanova-portal'));
  }

  // Success: link permanently
  $giav_customer_id = (int) $row->giav_customer_id;
  update_user_meta($user_id, 'casanova_idcliente', (string) $giav_customer_id);
  delete_user_meta($user_id, 'casanova_pending_idcliente');
  delete_user_meta($user_id, 'casanova_pending_dni');

  $wpdb->update($table, ['status' => 'verified'], ['id' => (int) $row->id]);

  return [
    'giavCustomerId' => $giav_customer_id,
  ];
}
