<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode contenedor para la SPA React.
 *
 * Uso: [casanova_portal_app]
 *
 * Esta salida es mínima intencionadamente: React renderiza el resto.
 */
add_shortcode('casanova_portal_app', function($atts) {
  $atts = shortcode_atts([
    'class' => '',
  ], $atts, 'casanova_portal_app');

  $cls = 'casanova-portal-app';
  if (!empty($atts['class'])) {
    $cls .= ' ' . sanitize_html_class($atts['class']);
  }

  $html = '';
  if (function_exists('casanova_portal_render_impersonation_banner')) {
    $html .= casanova_portal_render_impersonation_banner();
  }

  // Root unico para React.
  $html .= '<div id="casanova-portal-root" class="' . esc_attr($cls) . '"></div>';

  return $html;
});
