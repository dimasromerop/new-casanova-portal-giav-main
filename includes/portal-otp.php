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

function casanova_portal_linking_normalize_identifier_type(?string $type): string {
  $type = sanitize_key((string) $type);

  if (in_array($type, ['giav', 'giav_id', 'giavid', 'id_giav', 'idcliente', 'cliente_id', 'client_id'], true)) {
    return 'giav_id';
  }

  return 'dni';
}

function casanova_portal_linking_normalize_identifier(string $identifier, string $identifier_type = 'dni'): string {
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);
  $identifier = sanitize_text_field($identifier);

  if ($identifier_type === 'giav_id') {
    return (string) preg_replace('/\D+/', '', trim($identifier));
  }

  return (string) preg_replace('/\s+/', '', strtoupper(trim($identifier)));
}

function casanova_portal_linking_missing_identifier_message(string $identifier_type): string {
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);

  if ($identifier_type === 'giav_id') {
    return __('Introduce tu ID de GIAV.', 'casanova-portal');
  }

  return __('Introduce tu DNI.', 'casanova-portal');
}

function casanova_portal_linking_invalid_identifier_message(string $identifier_type): string {
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);

  if ($identifier_type === 'giav_id') {
    return __('Introduce un ID de GIAV válido.', 'casanova-portal');
  }

  return __('DNI inválido.', 'casanova-portal');
}

function casanova_portal_linking_not_found_message(string $identifier_type): string {
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);

  if ($identifier_type === 'giav_id') {
    return __('No encontramos ningún cliente con ese ID de GIAV. Si ya has viajado con nosotros, escríbenos y lo revisamos.', 'casanova-portal');
  }

  return __('No encontramos ninguna reserva asociada a ese DNI. Si ya has viajado con nosotros, escríbenos y lo revisamos.', 'casanova-portal');
}

function casanova_portal_linking_fetch_customer_by_id(int $giav_customer_id) {
  if ($giav_customer_id <= 0) {
    return new WP_Error('bad_id', __('ID de cliente inválido.', 'casanova-portal'));
  }

  if (function_exists('casanova_giav_cliente_get_by_id')) {
    return casanova_giav_cliente_get_by_id($giav_customer_id);
  }

  if (!function_exists('casanova_giav_call')) {
    return new WP_Error('giav_unavailable', __('No podemos consultar el sistema en este momento. Inténtalo más tarde.', 'casanova-portal'));
  }

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';
  $p->id = $giav_customer_id;

  $resp = casanova_giav_call('Cliente_GET', $p);
  if (is_wp_error($resp)) {
    return $resp;
  }

  $customer = $resp->Cliente_GETResult ?? null;
  if ($customer && is_object($customer)) {
    return $customer;
  }

  return new WP_Error('giav_empty', __('No se han podido cargar los datos del cliente.', 'casanova-portal'));
}

function casanova_portal_linking_resolve_customer_id(string $identifier_type, string $identifier): WP_Error|int {
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);
  $identifier = casanova_portal_linking_normalize_identifier($identifier, $identifier_type);

  if ($identifier === '') {
    return new WP_Error('missing_identifier', casanova_portal_linking_missing_identifier_message($identifier_type));
  }

  if ($identifier_type === 'giav_id') {
    $giav_customer_id = (int) $identifier;
    if ($giav_customer_id <= 0) {
      return new WP_Error('invalid_identifier', casanova_portal_linking_invalid_identifier_message($identifier_type));
    }

    $customer = casanova_portal_linking_fetch_customer_by_id($giav_customer_id);
    if (is_wp_error($customer)) {
      if (in_array($customer->get_error_code(), ['bad_id', 'giav_empty'], true)) {
        return new WP_Error('not_found', casanova_portal_linking_not_found_message($identifier_type));
      }

      return new WP_Error('giav_error', __('No hemos podido consultar el sistema. Inténtalo más tarde.', 'casanova-portal'));
    }

    return $giav_customer_id;
  }

  if (!function_exists('casanova_giav_cliente_search_por_dni')) {
    return new WP_Error('giav_unavailable', __('No podemos consultar el sistema en este momento. Inténtalo más tarde.', 'casanova-portal'));
  }

  $resp = casanova_giav_cliente_search_por_dni($identifier);
  if (is_wp_error($resp)) {
    return new WP_Error('giav_error', __('No hemos podido consultar el sistema. Inténtalo más tarde.', 'casanova-portal'));
  }

  $giav_customer_id = function_exists('casanova_giav_extraer_idcliente')
    ? (int) casanova_giav_extraer_idcliente($resp)
    : 0;

  if ($giav_customer_id <= 0) {
    return new WP_Error('not_found', casanova_portal_linking_not_found_message($identifier_type));
  }

  return $giav_customer_id;
}

function casanova_portal_otp_is_rate_limited(int $user_id, string $identifier, string $ip, string $identifier_type = 'dni'): bool {
  global $wpdb;
  $table = casanova_portal_otp_table_name();
  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);
  $identifier = casanova_portal_linking_normalize_identifier($identifier, $identifier_type);
  if ($identifier === '') return false;

  $identifier_hash = casanova_portal_hash_value($identifier);

  // Limit: max 3 sends per 15 minutes per (user_id + identifier)
  $since = gmdate('Y-m-d H:i:s', time() - 15 * 60);
  if ($identifier_type === 'dni') {
    $count = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(sent_count) FROM {$table} WHERE user_id=%d AND last_sent_at >= %s AND ((lookup_type=%s AND lookup_hash=%s) OR dni_hash=%s)",
      $user_id,
      $since,
      'dni',
      $identifier_hash,
      $identifier_hash
    ));
  } else {
    $count = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(sent_count) FROM {$table} WHERE user_id=%d AND last_sent_at >= %s AND lookup_type=%s AND lookup_hash=%s",
      $user_id,
      $since,
      $identifier_type,
      $identifier_hash
    ));
  }

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

function casanova_portal_send_linking_otp(int $user_id, string $identifier, int $giav_customer_id, string $identifier_type = 'dni'): WP_Error|array {
  global $wpdb;

  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);
  $identifier = casanova_portal_linking_normalize_identifier($identifier, $identifier_type);

  if ($identifier === '') {
    return new WP_Error('otp_missing_identifier', casanova_portal_linking_missing_identifier_message($identifier_type));
  }
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

  if (casanova_portal_otp_is_rate_limited($user_id, $identifier, $ip, $identifier_type)) {
    return new WP_Error('otp_rate_limited', __('Has hecho demasiados intentos. Espera unos minutos y vuelve a intentarlo.', 'casanova-portal'));
  }

  $code = casanova_portal_generate_otp_code();
  $otp_hash = wp_hash_password($code);
  $identifier_hash = casanova_portal_hash_value($identifier);
  $dni_hash = $identifier_type === 'dni' ? $identifier_hash : '';
  $email_hash = casanova_portal_hash_value(strtolower($email));
  $email_masked = casanova_portal_mask_email($email);

  $now = gmdate('Y-m-d H:i:s');
  $expires = gmdate('Y-m-d H:i:s', time() + 10 * 60);

  $table = casanova_portal_otp_table_name();
  $wpdb->insert($table, [
    'user_id' => $user_id,
    'lookup_type' => $identifier_type,
    'lookup_hash' => $identifier_hash,
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
  update_user_meta($user_id, 'casanova_pending_identifier', $identifier);
  update_user_meta($user_id, 'casanova_pending_identifier_type', $identifier_type);
  if ($identifier_type === 'dni') {
    update_user_meta($user_id, 'casanova_pending_dni', $identifier);
  } else {
    delete_user_meta($user_id, 'casanova_pending_dni');
  }

  return [
    'emailMasked' => $email_masked,
    'expiresIn' => 600,
  ];
}

function casanova_portal_verify_linking_otp(int $user_id, string $identifier, string $otp, string $identifier_type = 'dni'): WP_Error|array {
  global $wpdb;

  $identifier_type = casanova_portal_linking_normalize_identifier_type($identifier_type);
  $identifier = casanova_portal_linking_normalize_identifier($identifier, $identifier_type);
  $otp = preg_replace('/\s+/', '', trim($otp));

  $pending_identifier = (string) get_user_meta($user_id, 'casanova_pending_identifier', true);
  $pending_identifier_type = (string) get_user_meta($user_id, 'casanova_pending_identifier_type', true);

  if ($pending_identifier === '') {
    $legacy_pending_dni = (string) get_user_meta($user_id, 'casanova_pending_dni', true);
    if ($legacy_pending_dni !== '') {
      $pending_identifier = $legacy_pending_dni;
      $pending_identifier_type = 'dni';
    }
  }

  if ($identifier === '' && $pending_identifier !== '') {
    $identifier_type = casanova_portal_linking_normalize_identifier_type($pending_identifier_type);
    $identifier = casanova_portal_linking_normalize_identifier($pending_identifier, $identifier_type);
  }

  if ($identifier === '' || $otp === '') {
    return new WP_Error('otp_missing', __('Introduce el código que te hemos enviado.', 'casanova-portal'));
  }

  $identifier_hash = casanova_portal_hash_value($identifier);
  $table = casanova_portal_otp_table_name();
  $now = gmdate('Y-m-d H:i:s');

  if ($identifier_type === 'dni') {
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE user_id=%d AND status='pending' AND ((lookup_type=%s AND lookup_hash=%s) OR dni_hash=%s) ORDER BY id DESC LIMIT 1",
      $user_id,
      'dni',
      $identifier_hash,
      $identifier_hash
    ));
  } else {
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE user_id=%d AND lookup_type=%s AND lookup_hash=%s AND status='pending' ORDER BY id DESC LIMIT 1",
      $user_id,
      $identifier_type,
      $identifier_hash
    ));
  }

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
  delete_user_meta($user_id, 'casanova_pending_identifier');
  delete_user_meta($user_id, 'casanova_pending_identifier_type');
  delete_user_meta($user_id, 'casanova_pending_dni');

  $wpdb->update($table, ['status' => 'verified'], ['id' => (int) $row->id]);

  return [
    'giavCustomerId' => $giav_customer_id,
  ];
}
