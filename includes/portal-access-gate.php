<?php
if (!function_exists('casanova_user_is_cliente')) {
  function casanova_user_is_cliente($user = null): bool {
    if (!$user) $user = wp_get_current_user();
    if (!($user instanceof WP_User)) return false;
    return in_array('cliente', (array) $user->roles, true);
  }
}

add_filter('login_redirect', function ($redirect_to, $request, $user) {
  if (!($user instanceof WP_User)) return $redirect_to;

  $portal_url = function_exists('casanova_portal_base_url')
    ? casanova_portal_base_url()
    : home_url('/portal-app/');

  if (casanova_user_is_cliente($user)) {
    return $portal_url;
  }

  // Non-cliente: avoid forcing portal/onboarding redirects.
  $target = (string) $redirect_to;
  $portal_base = untrailingslashit($portal_url);
  $area_base = untrailingslashit(home_url('/area-usuario/'));
  $target_base = untrailingslashit($target);

  if ($target_base !== '' && (
    ($portal_base && strpos($target_base, $portal_base) === 0) ||
    ($area_base && strpos($target_base, $area_base) === 0)
  )) {
    if (user_can($user, 'edit_posts') || user_can($user, 'manage_options')) {
      return admin_url();
    }
    return home_url('/');
  }

  return $redirect_to;
}, 20, 3);

add_action('template_redirect', function () {

  $area_usuario_slug   = 'area-usuario';
  $portal_cliente_slug = 'portal-app'; // o portal-app si es el slug real

  // --- 1. Usuario NO logueado intentando entrar al portal ---
  if (!is_user_logged_in() && is_page($portal_cliente_slug)) {

    // Redirige al login y vuelve luego al portal
    wp_safe_redirect(
      wp_login_url(site_url('/' . $portal_cliente_slug . '/'))
    );
    exit;
  }

  // A partir de aquí, solo usuarios logueados
  if (!is_user_logged_in()) {
    return;
  }

  $user_id   = get_current_user_id();
  $idcliente = get_user_meta($user_id, 'casanova_idcliente', true);
  $is_linked = !empty($idcliente);

  // --- 2. Usuario vinculado entrando al onboarding ---
  if ($is_linked && is_page($area_usuario_slug)) {
    wp_safe_redirect(site_url('/' . $portal_cliente_slug . '/'));
    exit;
  }

  // --- 3. Usuario NO vinculado entrando al portal ---
  if (!$is_linked && is_page($portal_cliente_slug)) {
    wp_safe_redirect(site_url('/' . $area_usuario_slug . '/'));
    exit;
  }

});
