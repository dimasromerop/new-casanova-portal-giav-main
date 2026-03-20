<?php
/**
 * Frontend i18n dictionary and locale metadata.
 *
 * JS/React do not participate in WordPress gettext automatically. This file
 * exposes:
 * - a translated dictionary for literal hashing (`tt`, `ttf`)
 * - explicit stable keys (`t`, `tf`)
 * - locale / language metadata so formatting and selectors follow the
 *   currently active portal language.
 */

if (!defined('ABSPATH')) exit;

function casanova_portal_i18n_runtime_handle(): string {
  return 'casanova-portal-i18n-runtime';
}

function casanova_portal_i18n_hash_key(string $literal): string {
  $hash = 5381;
  $length = strlen($literal);

  for ($index = 0; $index < $length; $index++) {
    $hash = (($hash << 5) + $hash) + ord($literal[$index]);
    $hash = $hash & 0xFFFFFFFF;
  }

  if ($hash < 0) $hash += 0x100000000;
  return 's_' . dechex($hash);
}

function casanova_portal_normalize_locale_code(string $locale): string {
  $clean = trim(str_replace('-', '_', $locale));
  if ($clean === '') return '';

  $parts = array_values(array_filter(explode('_', $clean), 'strlen'));
  if (!$parts) return '';

  $lang = strtolower($parts[0]);
  $region = isset($parts[1]) ? strtoupper($parts[1]) : '';

  return $region !== '' ? "{$lang}_{$region}" : $lang;
}

function casanova_portal_normalize_locale_tag(string $locale): string {
  $normalized = casanova_portal_normalize_locale_code($locale);
  return $normalized !== '' ? str_replace('_', '-', $normalized) : '';
}

function casanova_portal_locale_display_name(string $locale): string {
  $normalized = casanova_portal_normalize_locale_code($locale);
  if ($normalized === '') return '';

  $map = [
    'ca_ES' => 'Català',
    'de_DE' => 'Deutsch',
    'en_GB' => 'English (UK)',
    'en_US' => 'English',
    'es_ES' => 'Español',
    'fr_FR' => 'Français',
    'it_IT' => 'Italiano',
    'nl_NL' => 'Nederlands',
    'pt_BR' => 'Português (Brasil)',
    'pt_PT' => 'Português',
  ];
  if (isset($map[$normalized])) return $map[$normalized];

  if (function_exists('locale_get_display_name')) {
    $display = @locale_get_display_name($normalized, $normalized);
    if (is_string($display) && trim($display) !== '') {
      $display = preg_replace('/\s*\(.+\)$/', '', trim($display));
      return preg_replace_callback('/(^|[\s\-_])([[:alpha:]])/u', static function ($matches) {
        if (function_exists('mb_strtoupper')) {
          return $matches[1] . mb_strtoupper($matches[2], 'UTF-8');
        }
        return $matches[1] . strtoupper($matches[2]);
      }, $display);
    }
  }

  return strtoupper(substr($normalized, 0, 2));
}

function casanova_portal_detect_plugin_locales(): array {
  $files = glob(CASANOVA_GIAV_PLUGIN_PATH . 'languages/casanova-portal-*.mo');
  if (!is_array($files)) return [];

  $locales = [];
  foreach ($files as $file) {
    if (!preg_match('/casanova-portal-([A-Za-z_]+)\.mo$/', str_replace('\\', '/', $file), $matches)) {
      continue;
    }
    $locale = casanova_portal_normalize_locale_code($matches[1]);
    if ($locale !== '') $locales[$locale] = $locale;
  }

  return array_values($locales);
}

function casanova_portal_get_available_languages(): array {
  $items = [];

  if (has_filter('wpml_active_languages') || defined('ICL_SITEPRESS_VERSION')) {
    $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'code']);
    if (is_array($active)) {
      foreach ($active as $code => $entry) {
        $locale = '';
        if (is_array($entry)) {
          $locale = (string) ($entry['default_locale'] ?? $entry['locale'] ?? '');
        }
        $normalizedLocale = casanova_portal_normalize_locale_code($locale !== '' ? $locale : (string) $code);
        if ($normalizedLocale === '') continue;

        $lang = strtolower((string) ($entry['language_code'] ?? $code));
        if ($lang === '') $lang = strtolower(substr($normalizedLocale, 0, 2));

        $name = '';
        if (is_array($entry)) {
          $name = trim((string) ($entry['translated_name'] ?? $entry['native_name'] ?? ''));
        }
        if ($name === '') $name = casanova_portal_locale_display_name($normalizedLocale);

        $items[$normalizedLocale] = [
          'value' => $normalizedLocale,
          'locale' => $normalizedLocale,
          'lang' => $lang,
          'label' => strtoupper(substr($lang, 0, 2)),
          'name' => $name,
        ];
      }
    }
  }

  if (!$items) {
    $locales = casanova_portal_detect_plugin_locales();
    $currentLocale = casanova_portal_normalize_locale_code((string) determine_locale());
    if ($currentLocale !== '' && !in_array($currentLocale, $locales, true)) {
      $locales[] = $currentLocale;
    }

    foreach ($locales as $locale) {
      $lang = strtolower(substr($locale, 0, 2));
      $items[$locale] = [
        'value' => $locale,
        'locale' => $locale,
        'lang' => $lang,
        'label' => strtoupper($lang),
        'name' => casanova_portal_locale_display_name($locale),
      ];
    }
  }

  if (!$items) {
    $items['es_ES'] = [
      'value' => 'es_ES',
      'locale' => 'es_ES',
      'lang' => 'es',
      'label' => 'ES',
      'name' => 'Español',
    ];
  }

  uasort($items, static function (array $left, array $right): int {
    return strcmp($left['locale'], $right['locale']);
  });

  return array_values($items);
}

function casanova_portal_get_frontend_locale_code(): string {
  $locale = '';

  if (function_exists('determine_locale')) {
    $locale = (string) determine_locale();
  }
  if ($locale === '') {
    $locale = (string) get_locale();
  }
  if ($locale === '' && is_user_logged_in()) {
    $locale = (string) get_user_meta(casanova_portal_get_effective_user_id(), 'casanova_portal_locale', true);
  }
  if ($locale === '') {
    $locale = 'es_ES';
  }

  return casanova_portal_normalize_locale_code($locale);
}

function casanova_portal_get_frontend_i18n_meta(): array {
  static $meta = null;
  if (is_array($meta)) return $meta;

  $localeCode = casanova_portal_get_frontend_locale_code();
  $languages = casanova_portal_get_available_languages();
  $selected = null;

  foreach ($languages as $item) {
    if (($item['locale'] ?? '') === $localeCode) {
      $selected = $item;
      break;
    }
  }

  if (!$selected) {
    $lang = strtolower(substr($localeCode, 0, 2));
    $selected = [
      'value' => $localeCode,
      'locale' => $localeCode,
      'lang' => $lang,
      'label' => strtoupper($lang),
      'name' => casanova_portal_locale_display_name($localeCode),
    ];
    $languages[] = $selected;
  }

  $meta = [
    'locale' => casanova_portal_normalize_locale_tag($localeCode),
    'localeRaw' => $localeCode,
    'lang' => (string) ($selected['lang'] ?? strtolower(substr($localeCode, 0, 2))),
    'languages' => array_values($languages),
    'isRTL' => function_exists('is_rtl') ? (bool) is_rtl() : false,
  ];

  return $meta;
}

function casanova_portal_get_generated_js_literals(): array {
  static $entries = null;
  if (is_array($entries)) return $entries;

  $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/generated/js-i18n-literals.php';
  if (!file_exists($file)) {
    $entries = [];
    return $entries;
  }

  $loaded = require $file;
  $entries = is_array($loaded) ? $loaded : [];

  return $entries;
}

function casanova_portal_get_js_i18n(): array {
  $out = [
    'close' => __('Cerrar', 'casanova-portal'),
    'account_label' => __('Tu cuenta', 'casanova-portal'),
    'menu_profile' => __('Mi perfil', 'casanova-portal'),
    'menu_security' => __('Seguridad', 'casanova-portal'),
    'menu_logout' => __('Cerrar sesión', 'casanova-portal'),
    'portal_language' => __('Idioma del portal', 'casanova-portal'),
    'language_updated' => __('Idioma actualizado.', 'casanova-portal'),
    'language_update_failed' => __('No se pudo actualizar el idioma.', 'casanova-portal'),
    'nav_dashboard' => __('Dashboard', 'casanova-portal'),
    'nav_trips' => __('Viajes', 'casanova-portal'),
    'nav_trip_detail' => __('Detalle del viaje', 'casanova-portal'),
    'nav_messages' => __('Mensajes', 'casanova-portal'),
    'nav_mulligans' => __('Mulligans', 'casanova-portal'),
    'nav_portal' => __('Portal', 'casanova-portal'),
    'mock_mode' => __('Modo prueba', 'casanova-portal'),
    'view_details' => __('Ver detalles', 'casanova-portal'),
    'payment_registered_title' => __('Pago registrado', 'casanova-portal'),
  ];

  foreach (casanova_portal_get_generated_js_literals() as $literal => $translation) {
    $literal = (string) $literal;
    $out[casanova_portal_i18n_hash_key($literal)] = is_string($translation) && $translation !== ''
      ? $translation
      : __($literal, 'casanova-portal');
  }

  return $out;
}

function casanova_portal_register_i18n_runtime(): void {
  $handle = casanova_portal_i18n_runtime_handle();
  if (wp_script_is($handle, 'registered')) return;

  $path = CASANOVA_GIAV_PLUGIN_PATH . 'assets/casanova-i18n.js';
  $version = file_exists($path) ? (string) filemtime($path) : casanova_portal_giav_current_version();

  wp_register_script(
    $handle,
    CASANOVA_GIAV_PLUGIN_URL . 'assets/casanova-i18n.js',
    [],
    $version,
    true
  );
}

function casanova_portal_localize_i18n_runtime(): void {
  static $localized = false;
  if ($localized) return;

  casanova_portal_register_i18n_runtime();
  $handle = casanova_portal_i18n_runtime_handle();

  wp_localize_script($handle, 'CASANOVA_I18N', casanova_portal_get_js_i18n());
  wp_localize_script($handle, 'CASANOVA_I18N_META', casanova_portal_get_frontend_i18n_meta());

  $localized = true;
}
