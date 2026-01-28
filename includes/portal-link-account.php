<?php

/**
 * Shortcode: [casanova_link_account]
 *
 * Renders a 2-step linking flow (DNI -> Email OTP) that doesn't depend on
 * Bricks conditional logic.
 */

if (!defined('ABSPATH')) exit;

function casanova_portal_enqueue_link_account_assets(): void {
  $handle_js = 'casanova-link-account';
  $handle_css = 'casanova-link-account';

  wp_register_script(
    $handle_js,
    CASANOVA_GIAV_PLUGIN_URL . 'assets/link-account.js',
    [],
    casanova_portal_giav_current_version(),
    true
  );

  wp_register_style(
    $handle_css,
    CASANOVA_GIAV_PLUGIN_URL . 'assets/link-account.css',
    [],
    casanova_portal_giav_current_version()
  );

  wp_enqueue_style($handle_css);
  wp_enqueue_script($handle_js);

  wp_localize_script($handle_js, 'CASANOVA_LINKING', [
    'restUrl'   => esc_url_raw(rest_url('casanova/v1')),
    'nonce'     => wp_create_nonce('wp_rest'),
    'redirectTo'=> function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/'),
  ]);
}

function casanova_portal_shortcode_link_account($atts = []): string {
  if (!is_user_logged_in()) {
    return '<div class="casanova-link-account casanova-link-account--guest">' .
      esc_html__('Inicia sesión para vincular tu cuenta.', 'casanova-portal') .
    '</div>';
  }

  $user_id = get_current_user_id();
  $already = (string) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($already !== '') {
    $url = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/');
    return '<div class="casanova-link-account casanova-link-account--linked">' .
      '<p>' . esc_html__('Tu cuenta ya está vinculada.', 'casanova-portal') . '</p>' .
      '<p><a class="casanova-link-account__btn" href="' . esc_url($url) . '">' . esc_html__('Ir al portal', 'casanova-portal') . '</a></p>' .
    '</div>';
  }

  casanova_portal_enqueue_link_account_assets();

  $dni_prefill = (string) get_user_meta($user_id, 'casanova_dni', true);
  $dni_prefill = esc_attr($dni_prefill);

  // NOTE: We intentionally keep the markup simple. UI polish lives in CSS.
  ob_start();
  ?>
  <div class="casanova-link-account" data-casanova-link-account>
    <div class="casanova-link-account__card">
      <h3 class="casanova-link-account__title"><?php echo esc_html__('Vincula tu cuenta', 'casanova-portal'); ?></h3>
      <p class="casanova-link-account__intro"><?php echo esc_html__('Introduce tu DNI. Te enviaremos un código al email asociado a tu reserva para verificar que eres el titular.', 'casanova-portal'); ?></p>

      <div class="casanova-link-account__alert" data-casanova-linking-alert style="display:none"></div>

      <!-- Step 1 -->
      <form class="casanova-link-account__form" data-casanova-linking-step="1">
        <label class="casanova-link-account__label">
          <span><?php echo esc_html__('DNI', 'casanova-portal'); ?></span>
          <input class="casanova-link-account__input" type="text" name="dni" autocomplete="off" inputmode="text" value="<?php echo $dni_prefill; ?>" placeholder="<?php echo esc_attr__('Ej.: 12345678Z', 'casanova-portal'); ?>" required>
        </label>
        <button class="casanova-link-account__btn" type="submit" data-casanova-linking-submit>
          <?php echo esc_html__('Enviar código', 'casanova-portal'); ?>
        </button>
        <p class="casanova-link-account__hint"><?php echo esc_html__('El código caduca en 10 minutos.', 'casanova-portal'); ?></p>
      </form>

      <!-- Step 2 -->
      <form class="casanova-link-account__form" data-casanova-linking-step="2" style="display:none">
        <label class="casanova-link-account__label">
          <span><?php echo esc_html__('Código de verificación', 'casanova-portal'); ?></span>
          <input class="casanova-link-account__input" type="text" name="otp" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" placeholder="<?php echo esc_attr__('6 dígitos', 'casanova-portal'); ?>" required>
        </label>

        <div class="casanova-link-account__row">
          <button class="casanova-link-account__btn" type="submit" data-casanova-linking-verify>
            <?php echo esc_html__('Verificar y continuar', 'casanova-portal'); ?>
          </button>
          <button class="casanova-link-account__btn casanova-link-account__btn--ghost" type="button" data-casanova-linking-resend>
            <?php echo esc_html__('Reenviar código', 'casanova-portal'); ?>
          </button>
        </div>
        <p class="casanova-link-account__hint" data-casanova-linking-sent-hint></p>
      </form>

      <p class="casanova-link-account__support">
        <?php echo esc_html__('¿No tienes acceso a ese email? Escríbenos y lo solucionamos.', 'casanova-portal'); ?>
      </p>
    </div>
  </div>
  <?php
  return (string) ob_get_clean();
}

add_shortcode('casanova_link_account', 'casanova_portal_shortcode_link_account');
