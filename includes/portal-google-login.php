<?php

/**
 * Acceso con Google (OAuth2), sin plugins de terceros.
 *
 * Antes era el plugin «Casanova Google Login» (1.5.0); se ha movido aquí sin cambiar
 * su comportamiento: mismas URLs (/google-login y /google-callback), mismo shortcode
 * [casanova_google_login] y mismo meta de usuario (google_id).
 *
 * Necesita en wp-config.php: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET y GOOGLE_REDIRECT_URI.
 */

if (!defined('ABSPATH')) exit;

// Si el plugin antiguo sigue activo, él se encarga (carga antes que el portal).
if (class_exists('Casanova_Google_Login')) return;

class Casanova_Google_Login {
  const QV_START    = 'casanova_google_start';
  const QV_CALLBACK = 'casanova_google_callback';

  // Transients.
  const TRANSIENT_STATE_PREFIX = 'casanova_google_state_';
  const FLOW_TTL = 10 * MINUTE_IN_SECONDS;

  public static function init(): void {
    // Las reglas se vuelcan con el flush que hace el portal al cambiar de versión
    // (en init, prioridad 10): hay que añadirlas antes.
    add_action('init', [__CLASS__, 'add_rewrite'], 5);
    add_filter('query_vars', [__CLASS__, 'add_query_vars']);

    add_action('template_redirect', [__CLASS__, 'handle_start']);
    add_action('template_redirect', [__CLASS__, 'handle_callback']);

    add_shortcode('casanova_google_login', [__CLASS__, 'shortcode_button']);

    add_action('admin_notices', [__CLASS__, 'admin_notice_missing_config']);
    add_action('admin_notices', [__CLASS__, 'admin_notice_redirect_uri_mismatch']);
  }

  public static function add_rewrite(): void {
    // Inicio del flujo OAuth.
    add_rewrite_rule('^google-login/?$', 'index.php?' . self::QV_START . '=1', 'top');

    // Callback de Google.
    add_rewrite_rule('^google-callback/?$', 'index.php?' . self::QV_CALLBACK . '=1', 'top');
  }

  public static function add_query_vars($vars) {
    $vars[] = self::QV_START;
    $vars[] = self::QV_CALLBACK;
    return $vars;
  }

  private static function has_config(): bool {
    return defined('GOOGLE_CLIENT_ID') && defined('GOOGLE_CLIENT_SECRET') && defined('GOOGLE_REDIRECT_URI')
      && GOOGLE_CLIENT_ID && GOOGLE_CLIENT_SECRET && GOOGLE_REDIRECT_URI;
  }

  private static function get_redirect_uri(): string {
    return esc_url_raw((string) GOOGLE_REDIRECT_URI);
  }

  private static function get_expected_redirect_uri(): string {
    return trailingslashit(home_url('/google-callback/'));
  }

  /**
   * Use OAuth2 profile/email scopes without OpenID Connect to avoid
   * intermediaries blocking the `iss=https://accounts.google.com` callback param.
   */
  private static function get_auth_scope(): string {
    return implode(' ', [
      'https://www.googleapis.com/auth/userinfo.email',
      'https://www.googleapis.com/auth/userinfo.profile',
    ]);
  }

  /**
   * Read OAuth callback params from either GET or POST.
   * Google supports both query and form_post response modes.
   */
  private static function request_param(string $key): string {
    $value = $_POST[$key] ?? $_GET[$key] ?? '';
    return is_scalar($value) ? sanitize_text_field((string) $value) : '';
  }

  public static function admin_notice_missing_config(): void {
    if (!current_user_can('manage_options')) return;
    if (self::has_config()) return;

    echo '<div class="notice notice-warning"><p><strong>Casanova Google Login:</strong> Faltan constantes en wp-config.php: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI.</p></div>';
  }

  public static function admin_notice_redirect_uri_mismatch(): void {
    if (!current_user_can('manage_options')) return;
    if (!self::has_config()) return;

    $configured = trailingslashit(self::get_redirect_uri());
    $expected = self::get_expected_redirect_uri();
    if ($configured === $expected) return;

    echo '<div class="notice notice-warning"><p><strong>Casanova Google Login:</strong> ';
    echo 'La redirect URI configurada no coincide con la URL canónica del sitio. ';
    echo 'Configurada: <code>' . esc_html($configured) . '</code>. ';
    echo 'Canónica: <code>' . esc_html($expected) . '</code>. ';
    echo 'Asegura que <code>GOOGLE_REDIRECT_URI</code> y Google Cloud Console usen exactamente la misma URL.';
    echo '</p></div>';
  }

  /**
   * Shortcode: pinta botón apuntando a /google-login.
   * Uso: [casanova_google_login]
   * Con redirect: [casanova_google_login redirect_after="https://www.casanova.golf/mi-cuenta"]
   */
  public static function shortcode_button($atts): string {
    $atts = shortcode_atts([
      'text' => 'Acceder con Google',
      'redirect_after' => home_url('/area-usuario'),
    ], $atts);

    if (!self::has_config()) {
      return '<p style="color:#b00;">Login con Google no configurado (faltan constantes en wp-config.php).</p>';
    }

    $start_url = add_query_arg([
      'redirect_after' => rawurlencode(esc_url_raw($atts['redirect_after'])),
    ], home_url('/google-login'));

    $html  = '<a class="casanova-google-login" href="' . esc_url($start_url) . '">';
    $html .= esc_html($atts['text']);
    $html .= '</a>';

    return $html;
  }

  /**
   * Guarda el contexto del login asociado al state.
   */
  private static function store_state_context(string $state, string $redirect_after): void {
    set_transient(self::TRANSIENT_STATE_PREFIX . $state, [
      'redirect_after' => $redirect_after,
    ], self::FLOW_TTL);
  }

  /**
   * Recupera el contexto asociado al state sin consumirlo.
   */
  private static function get_state_context(string $state): ?array {
    $context = get_transient(self::TRANSIENT_STATE_PREFIX . $state);

    if ($context === false || !is_array($context)) {
      return null;
    }

    return $context;
  }

  /**
   * Borra el contexto asociado al state después de un callback válido.
   */
  private static function delete_state_context(string $state): void {
    delete_transient(self::TRANSIENT_STATE_PREFIX . $state);
  }

  /**
   * Evita consumir el callback si llega como fetch/subrequest.
   * Si el navegador no envía los headers Sec-Fetch-*, procesamos por compatibilidad.
   */
  private static function is_navigation_request(): bool {
    $mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';

    if ($mode === '' && $dest === '') {
      return true;
    }

    if ($mode !== 'navigate') {
      return false;
    }

    return $dest === '' || $dest === 'document';
  }

  /**
   * Endpoint /google-login
   * Genera state, guarda el contexto en un transient propio y redirige a Google.
   */
  public static function process_start_request(): void {
    if (!self::has_config()) {
      wp_die('Login con Google no configurado (faltan constantes).');
    }

    $redirect_after = home_url('/mi-cuenta');
    if (!empty($_GET['redirect_after'])) {
      $maybe = rawurldecode(sanitize_text_field($_GET['redirect_after']));
      $maybe = esc_url_raw($maybe);
      if ($maybe) {
        $redirect_after = $maybe;
      }
    }

    $state = bin2hex(random_bytes(16));
    self::store_state_context($state, $redirect_after);

    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
      'client_id'     => GOOGLE_CLIENT_ID,
      'redirect_uri'  => self::get_redirect_uri(),
      'response_type' => 'code',
      'response_mode' => 'form_post',
      'scope'         => self::get_auth_scope(),
      'prompt'        => 'select_account',
      'state'         => $state,
    ]);

    wp_redirect($auth_url);
    exit;
  }

  public static function handle_start(): void {
    if ((int) get_query_var(self::QV_START) !== 1) return;

    self::process_start_request();
  }

  /**
   * Endpoint /google-callback
   * Valida state, hace token/userinfo y login/registro WP.
   */
  public static function process_callback_request(): void {
    if (!self::has_config()) {
      wp_die('Login con Google no configurado (faltan constantes).');
    }

    $error = self::request_param('error');
    if ($error !== '') {
      wp_die('Google OAuth error: ' . esc_html($error));
    }

    $code  = self::request_param('code');
    $state = self::request_param('state');

    if (!$code || !$state) {
      wp_die('Callback inválido: falta code o state.');
    }

    if (!self::is_navigation_request()) {
      status_header(204);
      exit;
    }

    $state_context = self::get_state_context($state);
    if (!$state_context) {
      wp_die('Estado OAuth expirado o inválido. Vuelve a iniciar sesión con Google.');
    }

    $redirect_after = !empty($state_context['redirect_after'])
      ? esc_url_raw($state_context['redirect_after'])
      : home_url('/mi-cuenta');

    if (!$redirect_after) {
      $redirect_after = home_url('/mi-cuenta');
    }

    $token_res = wp_remote_post('https://oauth2.googleapis.com/token', [
      'timeout' => 15,
      'body' => [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => self::get_redirect_uri(),
        'grant_type'    => 'authorization_code',
      ],
    ]);

    if (is_wp_error($token_res)) {
      wp_die('Error pidiendo token: ' . esc_html($token_res->get_error_message()));
    }

    $token_status = wp_remote_retrieve_response_code($token_res);
    $token_body = wp_remote_retrieve_body($token_res);
    $token_data = json_decode($token_body, true);

    if ($token_status < 200 || $token_status >= 300 || empty($token_data['access_token'])) {
      wp_die('No se obtuvo access_token. HTTP ' . esc_html((string) $token_status) . '. Respuesta: ' . esc_html($token_body));
    }

    $user_res = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
      'timeout' => 15,
      'headers' => [
        'Authorization' => 'Bearer ' . $token_data['access_token'],
      ],
    ]);

    if (is_wp_error($user_res)) {
      wp_die('Error pidiendo userinfo: ' . esc_html($user_res->get_error_message()));
    }

    $user_status = wp_remote_retrieve_response_code($user_res);
    $user_body = wp_remote_retrieve_body($user_res);
    $guser = json_decode($user_body, true);

    if ($user_status < 200 || $user_status >= 300 || empty($guser['email'])) {
      wp_die('Userinfo incompleto. HTTP ' . esc_html((string) $user_status) . '. Respuesta: ' . esc_html($user_body));
    }

    $email_verified = $guser['email_verified'] ?? $guser['verified_email'] ?? true;
    if (!$email_verified) {
      wp_die('El email de Google no está verificado.');
    }

    $user_id = self::login_or_register($guser);

    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    self::delete_state_context($state);

    wp_safe_redirect($redirect_after);
    exit;
  }

  public static function handle_callback(): void {
    if ((int) get_query_var(self::QV_CALLBACK) !== 1) return;

    self::process_callback_request();
  }

  private static function login_or_register(array $guser): int {
    $email = sanitize_email($guser['email']);
    $name  = sanitize_text_field($guser['name'] ?? '');
    $sub   = sanitize_text_field((string) ($guser['sub'] ?? $guser['id'] ?? ''));

    $wp_user = get_user_by('email', $email);

    if (!$wp_user) {
      $email_parts = explode('@', $email);
      $base = sanitize_user($email_parts[0] ?? '');
      $username = $base ?: 'googleuser';

      if (username_exists($username)) {
        $username .= '_' . wp_generate_password(4, false, false);
      }

      $user_id = wp_create_user($username, wp_generate_password(32), $email);

      if (is_wp_error($user_id)) {
        wp_die('No se pudo crear usuario: ' . esc_html($user_id->get_error_message()));
      }

      wp_update_user([
        'ID'           => $user_id,
        'display_name' => $name ?: $email,
      ]);

      if ($sub !== '') {
        update_user_meta($user_id, 'google_id', $sub);
      }
      return (int) $user_id;
    }

    $user_id = (int) $wp_user->ID;

    if ($sub !== '' && !get_user_meta($user_id, 'google_id', true)) {
      update_user_meta($user_id, 'google_id', $sub);
    }

    return $user_id;
  }
}

Casanova_Google_Login::init();
