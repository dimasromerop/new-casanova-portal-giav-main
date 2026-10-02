<?php
if (!defined('ABSPATH')) exit;

/**
 * Verificación del email de la cuenta con un código de 6 cifras.
 *
 * Cualquiera puede registrarse (formulario de Bricks, sin confirmar el email), pero solo entra
 * al portal tras vincular su cuenta con GIAV: DNI o ID de cliente + código enviado al email que
 * GIAV tiene del cliente. El plugin de propuestas empareja también por el email de la cuenta, así
 * que ese email tiene que estar confirmado: lo está si es el mismo al que llegó el código de
 * vinculación (lo normal) o si el cliente lo confirma aquí. Queda atado a ESE email: si el
 * cliente lo cambia, deja de valer.
 */

const CASANOVA_EMAIL_VERIFIED_META = 'casanova_email_verified';
const CASANOVA_EMAIL_VERIFY_PENDING_META = 'casanova_email_verify_pending';
const CASANOVA_EMAIL_VERIFY_TTL = 15 * MINUTE_IN_SECONDS;
const CASANOVA_EMAIL_VERIFY_MAX_ATTEMPTS = 5;

function casanova_portal_email_hash(string $email): string {
  return function_exists('casanova_portal_hash_value')
    ? casanova_portal_hash_value(strtolower(trim($email)))
    : hash_hmac('sha256', strtolower(trim($email)), wp_salt('casanova_portal_otp'));
}

/**
 * ¿El email de la cuenta está confirmado? Vale de dos formas:
 *  - al vincular la cuenta con GIAV, el código se envió a ESTE mismo email (lo normal: el email
 *    de la cuenta coincide con el de GIAV), o
 *  - el cliente lo confirmó con el código de «Confirma tu email» (cuando su email no es el de GIAV).
 */
function casanova_portal_email_is_verified(int $user_id): bool {
  static $cache = []; // solo la consulta a la tabla de códigos de vinculación
  $user = $user_id > 0 ? get_userdata($user_id) : null;
  if (!$user || !is_email($user->user_email)) return false;
  $hash = casanova_portal_email_hash($user->user_email);

  $stored = (string) get_user_meta($user_id, CASANOVA_EMAIL_VERIFIED_META, true);
  if ($stored !== '' && hash_equals($stored, $hash)) {
    return true;
  }

  $key = $user_id . ':' . $hash;
  if (isset($cache[$key])) return $cache[$key];

  if (function_exists('casanova_portal_otp_table_name')) {
    global $wpdb;
    $table = casanova_portal_otp_table_name();
    $linked_with_this_email = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'verified' AND email_hash = %s",
      $user_id,
      $hash
    ));
    if ($linked_with_this_email > 0) {
      return $cache[$key] = true;
    }
  }

  return $cache[$key] = false;
}

/** Envía el código al email de la cuenta. */
function casanova_portal_email_verification_send(int $user_id) {
  $user = $user_id > 0 ? get_userdata($user_id) : null;
  if (!$user || !is_email($user->user_email)) {
    return new WP_Error('no_email', __('Tu cuenta no tiene un email válido.', 'casanova-portal'), ['status' => 400]);
  }
  if (casanova_portal_email_is_verified($user_id)) {
    return ['sent' => false, 'verified' => true];
  }
  if (function_exists('casanova_rate_limit') && !casanova_rate_limit('email_verify_send_' . $user_id, 3, 15 * MINUTE_IN_SECONDS)) {
    return new WP_Error('rate_limited', __('Demasiados intentos. Espera unos minutos.', 'casanova-portal'), ['status' => 429]);
  }

  $code = function_exists('casanova_portal_generate_otp_code')
    ? casanova_portal_generate_otp_code()
    : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

  update_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META, [
    'hash'       => wp_hash_password($code),
    'email_hash' => casanova_portal_email_hash($user->user_email),
    'expires'    => time() + CASANOVA_EMAIL_VERIFY_TTL,
    'attempts'   => 0,
  ]);

  $subject = __('Confirma tu email', 'casanova-portal');
  $message = sprintf(
    /* translators: %s: código de 6 cifras */
    __("Tu código para confirmar tu email en el área de cliente de Casanova Golf es: %s\n\nCaduca en 15 minutos.\n\nSi no lo has pedido tú, puedes ignorar este email.", 'casanova-portal'),
    $code
  );
  if (!wp_mail($user->user_email, $subject, $message)) {
    delete_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META);
    return new WP_Error('send_failed', __('No hemos podido enviar el email. Inténtalo más tarde.', 'casanova-portal'), ['status' => 500]);
  }

  return [
    'sent'        => true,
    'emailMasked' => function_exists('casanova_portal_mask_email') ? casanova_portal_mask_email($user->user_email) : '',
    'expiresIn'   => CASANOVA_EMAIL_VERIFY_TTL,
  ];
}

/** Comprueba el código y, si vale, marca el email como verificado. */
function casanova_portal_email_verification_confirm(int $user_id, string $code) {
  $user = $user_id > 0 ? get_userdata($user_id) : null;
  if (!$user) {
    return new WP_Error('no_user', __('Debes iniciar sesión.', 'casanova-portal'), ['status' => 401]);
  }
  if (function_exists('casanova_rate_limit') && !casanova_rate_limit('email_verify_confirm_' . $user_id, 10, 15 * MINUTE_IN_SECONDS)) {
    return new WP_Error('rate_limited', __('Demasiados intentos. Espera unos minutos.', 'casanova-portal'), ['status' => 429]);
  }

  $code = preg_replace('/\D+/', '', $code);
  $pending = get_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META, true);
  if (!is_array($pending) || empty($pending['hash'])) {
    return new WP_Error('no_code', __('Pide un código nuevo.', 'casanova-portal'), ['status' => 400]);
  }
  $email_changed = !hash_equals((string) ($pending['email_hash'] ?? ''), casanova_portal_email_hash($user->user_email));
  if ((int) ($pending['expires'] ?? 0) < time() || $email_changed) {
    delete_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META);
    return new WP_Error('expired', __('El código ha caducado. Pide uno nuevo.', 'casanova-portal'), ['status' => 400]);
  }
  if ((int) ($pending['attempts'] ?? 0) >= CASANOVA_EMAIL_VERIFY_MAX_ATTEMPTS) {
    delete_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META);
    return new WP_Error('too_many_attempts', __('Demasiados intentos. Pide un código nuevo.', 'casanova-portal'), ['status' => 429]);
  }
  if (strlen($code) !== 6 || !wp_check_password($code, (string) $pending['hash'])) {
    $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
    update_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META, $pending);
    return new WP_Error('invalid_code', __('El código no es correcto.', 'casanova-portal'), ['status' => 400]);
  }

  update_user_meta($user_id, CASANOVA_EMAIL_VERIFIED_META, casanova_portal_email_hash($user->user_email));
  delete_user_meta($user_id, CASANOVA_EMAIL_VERIFY_PENDING_META);

  return ['verified' => true];
}
