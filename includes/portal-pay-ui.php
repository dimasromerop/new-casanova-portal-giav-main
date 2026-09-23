<?php
if (!defined('ABSPATH')) exit;

/**
 * Presentación compartida de las páginas públicas de pago:
 * - pago individual por enlace (portal-payment-links.php)
 * - pago de grupo por token (portal-group-pay.php)
 *
 * Solo formato y maquetación: aquí no se calcula ningún importe ni se consulta GIAV.
 */

/*
 * TODO(Casanova): email y teléfono de contacto del pie de las páginas de pago.
 * Si en wp-config.php se definen CASANOVA_AGENCY_EMAIL / CASANOVA_AGENCY_PHONE se
 * usan esos; si no, se usan estas dos constantes. Vacías = no se muestra el enlace.
 */
if (!defined('CASANOVA_PAY_CONTACT_EMAIL')) define('CASANOVA_PAY_CONTACT_EMAIL', '');
if (!defined('CASANOVA_PAY_CONTACT_PHONE')) define('CASANOVA_PAY_CONTACT_PHONE', '');

function casanova_pay_ui_is_en(): bool {
  $locale = function_exists('casanova_portal_get_public_requested_locale')
    ? casanova_portal_get_public_requested_locale()
    : (string) get_locale();
  return stripos($locale, 'en') === 0;
}

/**
 * Importe formateado según el idioma de la página (solo presentación).
 * EN: €1,092.00 / $1,092.00 — ES: 1.092,00 € / 1.092,00 US$
 */
function casanova_pay_ui_money(float $amount, string $currency = 'EUR'): string {
  $en = casanova_pay_ui_is_en();
  $sign = $amount < -0.004 ? '-' : '';
  $number = number_format(abs($amount), 2, $en ? '.' : ',', $en ? ',' : '.');
  $usd = strtoupper($currency) === 'USD';
  if ($en) {
    return $sign . ($usd ? '$' : "\u{20AC}") . $number;
  }
  return $sign . $number . "\u{00A0}" . ($usd ? 'US$' : "\u{20AC}");
}

/**
 * Misma regla de formato que casanova_pay_ui_money(), en JS. Define cgpMoney(n, currency).
 */
function casanova_pay_ui_js_money(): string {
  return 'var cgpMoneyEn = ' . wp_json_encode(casanova_pay_ui_is_en()) . ';'
    . 'function cgpMoney(n, currency){'
    . 'n = Number(n); if (!isFinite(n)) n = 0;'
    . 'var sign = n < -0.004 ? "-" : "";'
    . 'var fixed = (Math.round(Math.abs(n) * 100) / 100).toFixed(2);'
    . 'var parts = fixed.split(".");'
    . 'var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cgpMoneyEn ? "," : ".");'
    . 'var num = intPart + (cgpMoneyEn ? "." : ",") + parts[1];'
    . 'var usd = String(currency || "EUR").toUpperCase() === "USD";'
    . 'if (cgpMoneyEn) return sign + (usd ? "$" : "€") + num;'
    . 'return sign + num + " " + (usd ? "US$" : "€");'
    . '}';
}

function casanova_pay_ui_parse_date($value): ?DateTimeImmutable {
  if ($value instanceof DateTimeImmutable) return $value;
  if ($value instanceof DateTime) return DateTimeImmutable::createFromMutable($value);
  $raw = substr(trim((string) $value), 0, 10);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
  try {
    return new DateTimeImmutable($raw, wp_timezone());
  } catch (Throwable $e) {
    return null;
  }
}

function casanova_pay_ui_date($value): string {
  $dt = casanova_pay_ui_parse_date($value);
  if (!$dt) return '';
  return wp_date('j M Y', $dt->getTimestamp(), wp_timezone());
}

/**
 * "13 – 20 Sep 2026", "28 Sep – 3 Oct 2026" o "28 Dec 2026 – 3 Jan 2027".
 */
function casanova_pay_ui_date_range($from, $to): string {
  $a = casanova_pay_ui_parse_date($from);
  $b = casanova_pay_ui_parse_date($to);
  if (!$a && !$b) return '';
  if (!$a || !$b || $a->format('Y-m-d') === $b->format('Y-m-d')) {
    return casanova_pay_ui_date($a ?: $b);
  }
  $tz = wp_timezone();
  $end = wp_date('j M Y', $b->getTimestamp(), $tz);
  if ($a->format('Y-m') === $b->format('Y-m')) {
    $start = wp_date('j', $a->getTimestamp(), $tz);
  } elseif ($a->format('Y') === $b->format('Y')) {
    $start = wp_date('j M', $a->getTimestamp(), $tz);
  } else {
    $start = wp_date('j M Y', $a->getTimestamp(), $tz);
  }
  return $start . " \u{2013} " . $end;
}

function casanova_pay_ui_icon(string $name): string {
  $attrs = 'width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
  switch ($name) {
    case 'lock':
      return '<svg class="cgp-icon" ' . $attrs . '><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
    case 'minus':
      return '<svg class="cgp-icon" ' . $attrs . '><path d="M5 12h14"/></svg>';
    case 'plus':
      return '<svg class="cgp-icon" ' . $attrs . '><path d="M12 5v14M5 12h14"/></svg>';
  }
  return '';
}

function casanova_pay_ui_document_start(string $title): void {
  casanova_portal_render_public_document_start($title, ['assets/portal-pay.css']);
}

/**
 * Selector de idioma compacto (EN / ES). Mismo formulario GET y mismo parámetro
 * "locale" que casanova_portal_public_language_selector_html().
 */
function casanova_pay_ui_language_selector(string $actionUrl, array $queryArgs = []): string {
  if (!function_exists('casanova_portal_get_available_languages')) return '';
  $languages = casanova_portal_get_available_languages();
  if (count($languages) < 2) return '';

  $currentLocale = casanova_portal_get_public_requested_locale();
  $html = '<form class="cgp-lang casanova-public-locale-form" method="get" action="' . esc_url($actionUrl) . '">';
  foreach ($queryArgs as $key => $value) {
    $key = sanitize_key((string) $key);
    if ($key === '' || $key === 'locale') continue;
    if ($value === null || $value === '') continue;
    $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '" />';
  }
  $html .= '<select class="cgp-lang__select" name="locale" aria-label="' . esc_attr__('Idioma', 'casanova-portal') . '" onchange="this.form.submit()">';
  foreach ($languages as $item) {
    $locale = casanova_portal_normalize_locale_code((string) ($item['locale'] ?? ''));
    if ($locale === '') continue;
    $short = trim((string) ($item['label'] ?? ''));
    if ($short === '') $short = strtoupper(substr($locale, 0, 2));
    $name = trim((string) ($item['name'] ?? ''));
    if ($name === '' && function_exists('casanova_portal_locale_display_name')) {
      $name = casanova_portal_locale_display_name($locale);
    }
    $html .= '<option value="' . esc_attr($locale) . '" title="' . esc_attr($name) . '" ' . selected($locale, $currentLocale, false) . '>' . esc_html($short) . '</option>';
  }
  $html .= '</select>';
  $html .= '<noscript><button class="cgp-lang__submit" type="submit">' . esc_html__('Actualizar', 'casanova-portal') . '</button></noscript>';
  $html .= '</form>';
  return $html;
}

function casanova_pay_ui_header(string $language_selector_html = ''): string {
  $name = defined('CASANOVA_AGENCY_NAME') && CASANOVA_AGENCY_NAME ? (string) CASANOVA_AGENCY_NAME : 'Casanova Golf';
  $logo = '';
  if (defined('CASANOVA_AGENCY_LOGO_URL') && CASANOVA_AGENCY_LOGO_URL) {
    $logo = '<img class="cgp-brand__logo" src="' . esc_url(CASANOVA_AGENCY_LOGO_URL) . '" alt="" width="40" height="40" />';
  }
  return '<header class="cgp-header"><div class="cgp-header__inner">'
    . '<div class="cgp-brand">' . $logo . '<span class="cgp-brand__name">' . esc_html($name) . '</span></div>'
    . $language_selector_html
    . '</div></header>';
}

function casanova_pay_ui_title_block(string $eyebrow, string $title, string $meta_line): string {
  $html = '<div class="cgp-title">';
  $html .= '<p class="cgp-title__eyebrow">' . casanova_pay_ui_icon('lock') . '<span>' . esc_html($eyebrow) . '</span></p>';
  $html .= '<h1 class="cgp-title__trip">' . esc_html($title) . '</h1>';
  if ($meta_line !== '') {
    $html .= '<p class="cgp-title__meta">' . esc_html($meta_line) . '</p>';
  }
  $html .= '</div>';
  return $html;
}

/**
 * Nombre del viaje y código por separado. casanova_portal_expediente_meta() ya
 * devuelve label = "Titulo (Codigo)", por eso aquí se usa 'titulo' y el código
 * se muestra una sola vez en la línea "Ref.".
 */
function casanova_pay_ui_trip_identity(int $idCliente, int $idExpediente, $exp = null): array {
  $title = '';
  $code = '';
  if (function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, $idExpediente);
    $title = trim((string) ($meta['titulo'] ?? ''));
    $code = trim((string) ($meta['codigo'] ?? ''));
  }
  if (is_object($exp)) {
    if ($title === '') $title = trim((string) ($exp->Titulo ?? ''));
    if ($code === '') $code = trim((string) ($exp->Codigo ?? ''));
  }
  if ($title === '') {
    $title = sprintf(__('Expediente %s', 'casanova-portal'), $code !== '' ? $code : (string) $idExpediente);
  }
  return ['title' => $title, 'code' => $code];
}

function casanova_pay_ui_contact(): array {
  $email = defined('CASANOVA_AGENCY_EMAIL') && CASANOVA_AGENCY_EMAIL ? (string) CASANOVA_AGENCY_EMAIL : (string) CASANOVA_PAY_CONTACT_EMAIL;
  $phone = defined('CASANOVA_AGENCY_PHONE') && CASANOVA_AGENCY_PHONE ? (string) CASANOVA_AGENCY_PHONE : (string) CASANOVA_PAY_CONTACT_PHONE;
  return ['email' => trim($email), 'phone' => trim($phone)];
}

function casanova_pay_ui_footer(): string {
  $contact = casanova_pay_ui_contact();
  $links = [];
  if ($contact['email'] !== '' && is_email($contact['email'])) {
    $links[] = '<a href="mailto:' . esc_attr($contact['email']) . '">' . esc_html($contact['email']) . '</a>';
  }
  if ($contact['phone'] !== '') {
    $tel = preg_replace('/[^0-9+]/', '', $contact['phone']);
    $links[] = '<a href="tel:' . esc_attr($tel) . '">' . esc_html($contact['phone']) . '</a>';
  }
  $html = '<footer class="cgp-footer">';
  if (!empty($links)) {
    $html .= '<p>' . esc_html__('¿Dudas antes de pagar?', 'casanova-portal') . ' ' . implode(' &middot; ', $links) . '</p>';
  }
  $html .= '<p>' . esc_html__('Casanova Golf, S.L. · Madrid · CICMA 2969', 'casanova-portal') . '</p>';
  $html .= '</footer>';
  return $html;
}

/**
 * Campo de documento con la ayuda legal. name/required/autocomplete los decide
 * cada página para no alterar lo que se envía.
 */
function casanova_pay_ui_dni_field(string $id, string $value, string $autocomplete = ''): string {
  $help_id = $id . '-help';
  $html = '<div class="cgp-field">';
  $html .= '<label class="cgp-field__label" for="' . esc_attr($id) . '">' . esc_html__('Documento de identidad o pasaporte', 'casanova-portal') . '</label>';
  $html .= '<input class="cgp-input casanova-public-field__control" id="' . esc_attr($id) . '" type="text" name="billing_dni"'
    . ($autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '')
    . ' required value="' . esc_attr($value) . '" placeholder="' . esc_attr__('DNI/NIE, pasaporte o documento nacional', 'casanova-portal') . '" aria-describedby="' . esc_attr($help_id) . '" />';
  $html .= '<p class="cgp-field__help" id="' . esc_attr($help_id) . '">' . esc_html__('Lo exige la normativa española para emitir tu factura y para el registro oficial de viajeros. Solo lo usamos para estos fines.', 'casanova-portal') . '</p>';
  $html .= '</div>';
  return $html;
}

function casanova_pay_ui_text_field(string $id, string $name, string $label, string $value, string $type = 'text', string $autocomplete = ''): string {
  return '<div class="cgp-field">'
    . '<label class="cgp-field__label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
    . '<input class="cgp-input casanova-public-field__control" id="' . esc_attr($id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"'
    . ($autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '')
    . ' required value="' . esc_attr($value) . '" />'
    . '</div>';
}

function casanova_pay_ui_transfer_note(): string {
  return __('Te llevaremos a una página donde podrás elegir tu banco y autorizar la transferencia desde tu banca online. Al terminar, volverás automáticamente aquí. Compatible con la mayoría de bancos españoles y portugueses.', 'casanova-portal');
}

/**
 * Bloque "Método de pago" común: moneda (si aplica), tarjetas de método y tipo
 * de tarjeta como control segmentado dentro de la tarjeta "Tarjeta".
 * Emite exactamente los mismos name/value que el marcado anterior.
 *
 * $o: prefix (ids), currency_mode ('choice'|'fixed_usd'|'eur'), currency_checked,
 *     inespay, method_checked, stripe_only, card_brand_checked, heading_html.
 */
function casanova_pay_ui_method_block(array $o): string {
  $p = (string) ($o['prefix'] ?? 'casanova');
  $currency_mode = (string) ($o['currency_mode'] ?? 'eur');
  $currency_checked = strtoupper((string) ($o['currency_checked'] ?? 'EUR')) === 'USD' ? 'USD' : 'EUR';
  $inespay = !empty($o['inespay']);
  $bank_checked = $inespay && (string) ($o['method_checked'] ?? 'card') === 'bank_transfer';
  $stripe_only = !empty($o['stripe_only']);
  $amex_checked = (string) ($o['card_brand_checked'] ?? 'other') === 'amex';

  $html = '';

  if ($currency_mode === 'fixed_usd') {
    $html .= '<input type="hidden" name="currency" value="USD" />';
  } elseif ($currency_mode === 'choice') {
    $html .= '<fieldset class="cgp-fieldset" id="' . esc_attr($p) . '-currency-wrap">';
    $html .= '<legend class="cgp-fieldset__legend">' . esc_html__('Moneda de pago', 'casanova-portal') . '</legend>';
    $html .= '<div class="cgp-seg">';
    $html .= '<label class="cgp-seg__opt"><input class="cgp-seg__input" type="radio" name="currency" value="EUR" ' . ($currency_checked === 'USD' ? '' : 'checked') . ' /><span>EUR (&euro;)</span></label>';
    $html .= '<label class="cgp-seg__opt"><input class="cgp-seg__input" type="radio" name="currency" value="USD" ' . ($currency_checked === 'USD' ? 'checked' : '') . ' /><span>USD ($)</span></label>';
    $html .= '</div>';
    $html .= '<p class="cgp-field__help">' . esc_html__('En USD solo se puede pagar con tarjeta.', 'casanova-portal') . '</p>';
    $html .= '</fieldset>';
  } else {
    $html .= '<input type="hidden" name="currency" value="EUR" />';
  }

  $card_brand_html = '';
  if ($stripe_only) {
    $card_brand_html = '<input type="hidden" name="card_brand" value="other" />';
  } else {
    $card_brand_html .= '<fieldset class="cgp-fieldset cgp-fieldset--nested" id="' . esc_attr($p) . '-card-brand-wrap">';
    $card_brand_html .= '<legend class="cgp-fieldset__legend cgp-fieldset__legend--sub">' . esc_html__('¿Con qué tarjeta vas a pagar?', 'casanova-portal') . '</legend>';
    $card_brand_html .= '<div class="cgp-seg">';
    $card_brand_html .= '<label class="cgp-seg__opt"><input class="cgp-seg__input" type="radio" name="card_brand" value="other" ' . ($amex_checked ? '' : 'checked') . ' /><span>' . esc_html__('Visa, Mastercard y otras', 'casanova-portal') . '</span></label>';
    $card_brand_html .= '<label class="cgp-seg__opt"><input class="cgp-seg__input" type="radio" name="card_brand" value="amex" ' . ($amex_checked ? 'checked' : '') . ' /><span>American Express</span></label>';
    $card_brand_html .= '</div>';
    $card_brand_html .= '<p class="cgp-field__help">' . esc_html__('American Express se procesa en una pasarela segura aparte.', 'casanova-portal') . '</p>';
    $card_brand_html .= '</fieldset>';
  }

  $card_title = '<span class="cgp-option__title">' . esc_html__('Tarjeta', 'casanova-portal') . '</span>'
    . '<span class="cgp-option__hint">' . esc_html__('Visa, Mastercard o American Express', 'casanova-portal') . '</span>';

  $html .= '<div class="cgp-options" id="' . esc_attr($p) . '-method-wrap" role="radiogroup" aria-labelledby="' . esc_attr($p) . '-method-label">';
  if ($inespay) {
    $html .= '<div class="cgp-option' . ($bank_checked ? '' : ' is-checked') . '" data-cgp-option>';
    $html .= '<label class="cgp-option__head"><input class="cgp-radio" type="radio" name="method" value="card" ' . ($bank_checked ? '' : 'checked') . ' /><span class="cgp-option__text">' . $card_title . '</span></label>';
    $html .= $stripe_only ? $card_brand_html : '<div class="cgp-option__body" data-cgp-when-checked>' . $card_brand_html . '</div>';
    $html .= '</div>';
    $html .= '<div class="cgp-option' . ($bank_checked ? ' is-checked' : '') . '" data-cgp-option data-cgp-bank-option>';
    $html .= '<label class="cgp-option__head"><input class="cgp-radio" type="radio" name="method" value="bank_transfer" ' . ($bank_checked ? 'checked' : '') . ' /><span class="cgp-option__text">'
      . '<span class="cgp-option__title">' . esc_html__('Transferencia bancaria online', 'casanova-portal') . '</span>'
      . '<span class="cgp-option__hint">' . esc_html__('Paga directamente desde tu banco', 'casanova-portal') . '</span>'
      . '</span></label>';
    $html .= '<div class="cgp-option__body" data-cgp-when-checked><p id="' . esc_attr($p) . '-method-note" class="cgp-option__note">' . esc_html(casanova_pay_ui_transfer_note()) . '</p></div>';
    $html .= '</div>';
  } else {
    // Solo tarjeta: mismo input oculto que antes; la tarjeta es informativa.
    $html .= '<input type="hidden" name="method" value="card" />';
    $html .= '<div class="cgp-option is-checked cgp-option--static">';
    $html .= '<div class="cgp-option__head"><span class="cgp-option__text">' . $card_title . '</span></div>';
    $html .= $stripe_only ? $card_brand_html : '<div class="cgp-option__body">' . $card_brand_html . '</div>';
    $html .= '</div>';
  }
  $html .= '</div>';

  return $html;
}

/**
 * JS de presentación del bloque de método/opciones. Define cgpSyncOptions(root, currency):
 * marca la tarjeta seleccionada, muestra el cuerpo de la seleccionada y, en USD,
 * fuerza tarjeta y oculta transferencia y tipo de tarjeta (igual que antes).
 */
function casanova_pay_ui_js_options(): string {
  return 'function cgpSyncOptions(root, currency){'
    . 'if (!root) return;'
    . 'var usd = currency === "USD";'
    . 'if (usd) { Array.prototype.forEach.call(root.querySelectorAll("input[name=method]"), function(i){ if (i.value === "card") i.checked = true; }); }'
    . 'Array.prototype.forEach.call(root.querySelectorAll("[data-cgp-option]"), function(opt){'
    . 'var input = opt.querySelector(".cgp-radio");'
    . 'var on = !!(input && input.checked);'
    . 'opt.classList.toggle("is-checked", on);'
    . 'var body = opt.querySelector("[data-cgp-when-checked]");'
    . 'if (body) body.hidden = !on;'
    . 'if (opt.hasAttribute("data-cgp-bank-option")) opt.classList.toggle("casanova-hidden", usd);'
    . '});'
    . 'Array.prototype.forEach.call(root.querySelectorAll("[id$=-card-brand-wrap]"), function(w){ w.classList.toggle("casanova-hidden", usd); });'
    . '}';
}
