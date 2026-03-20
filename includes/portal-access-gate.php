<?php
add_action('template_redirect', function () {

  $legacy_onboarding_slug = 'area-usuario';
  $legacy_portal_slug = 'portal-app';
  $portal_page_id = function_exists('casanova_portal_shortcode_page_id')
    ? (int) casanova_portal_shortcode_page_id('casanova_portal_app')
    : 0;
  $onboarding_page_id = function_exists('casanova_portal_shortcode_page_id')
    ? (int) casanova_portal_shortcode_page_id('casanova_link_account')
    : 0;

  $portal_url = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : site_url('/' . $legacy_portal_slug . '/');
  $onboarding_url = function_exists('casanova_portal_link_account_url')
    ? (string) casanova_portal_link_account_url()
    : site_url('/' . $legacy_onboarding_slug . '/');

  $is_portal_page = ($portal_page_id > 0 && is_page($portal_page_id)) || is_page($legacy_portal_slug);
  $is_onboarding_page = ($onboarding_page_id > 0 && is_page($onboarding_page_id)) || is_page($legacy_onboarding_slug);

  // --- 1. Usuario NO logueado intentando entrar al portal ---
  if (!is_user_logged_in() && $is_portal_page) {

    // Redirige al login y vuelve luego al portal
    wp_safe_redirect(wp_login_url($portal_url));
    exit;
  }

  // A partir de aquí, solo usuarios logueados
  if (!is_user_logged_in()) {
    return;
  }

  $user_id   = casanova_portal_get_effective_user_id();
  $idcliente = casanova_portal_get_effective_client_id($user_id);
  $is_linked = !empty($idcliente);

  // --- 2. Usuario vinculado entrando al onboarding ---
  if ($is_linked && $is_onboarding_page) {
    wp_safe_redirect($portal_url);
    exit;
  }

  // --- 3. Usuario NO vinculado entrando al portal ---
  if (!$is_linked && $is_portal_page) {
    wp_safe_redirect($onboarding_url);
    exit;
  }

});
