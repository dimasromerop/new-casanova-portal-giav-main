<?php
if (!defined('ABSPATH')) exit;

/**
 * Aplazame helper.
 *
 * El frontend nunca usa la private key: solo recibe checkout_id + public_key.
 */
class Casanova_Aplazame_Service {

  public static function config() {
    $public_key = defined('CASANOVA_APLAZAME_PUBLIC_KEY')
      ? (string) CASANOVA_APLAZAME_PUBLIC_KEY
      : (string) get_option('casanova_aplazame_public_key', '');

    $private_key = defined('CASANOVA_APLAZAME_PRIVATE_KEY')
      ? (string) CASANOVA_APLAZAME_PRIVATE_KEY
      : (string) get_option('casanova_aplazame_private_key', '');

    $sandbox = defined('CASANOVA_APLAZAME_SANDBOX')
      ? (bool) CASANOVA_APLAZAME_SANDBOX
      : (bool) get_option('casanova_aplazame_sandbox', 1);

    $base_url = defined('CASANOVA_APLAZAME_BASE_URL')
      ? (string) CASANOVA_APLAZAME_BASE_URL
      : 'https://api.aplazame.com';

    $public_key = trim($public_key);
    $private_key = trim($private_key);
    $base_url = rtrim(trim($base_url), '/');

    if ($public_key === '' || $private_key === '') {
      return new WP_Error(
        'aplazame_config_missing',
        'Falta configuracion de Aplazame: define CASANOVA_APLAZAME_PUBLIC_KEY y CASANOVA_APLAZAME_PRIVATE_KEY o guardalas en Ajustes > Casanova Portal > Pagos.'
      );
    }

    return [
      'public_key' => $public_key,
      'private_key' => $private_key,
      'sandbox' => (bool) $sandbox,
      'base_url' => $base_url !== '' ? $base_url : 'https://api.aplazame.com',
      'accept' => $sandbox ? 'application/vnd.aplazame.sandbox.v4+json' : 'application/vnd.aplazame.v4+json',
    ];
  }

  public static function is_enabled(): bool {
    return !is_wp_error(self::config());
  }

  public static function public_checkout_config() {
    $cfg = self::config();
    if (is_wp_error($cfg)) return $cfg;

    return [
      'public_key' => (string) $cfg['public_key'],
      'sandbox' => (bool) $cfg['sandbox'],
    ];
  }

  public static function decimal_from_float(float $amount): int {
    return (int) round($amount * 100);
  }

  public static function verify_notification_authorization(string $authorization): bool {
    $cfg = self::config();
    if (is_wp_error($cfg)) return false;

    $authorization = trim($authorization);
    $expected = 'Bearer ' . (string) $cfg['private_key'];

    return $authorization !== '' && hash_equals($expected, $authorization);
  }

  public static function post(string $path, array $body) {
    $cfg = self::config();
    if (is_wp_error($cfg)) return $cfg;

    $url = (string) $cfg['base_url'] . '/' . ltrim($path, '/');
    $args = [
      'timeout' => 20,
      'user-agent' => 'CasanovaPortalGIAV/0.30.12 (+WordPress)',
      'headers' => [
        'Accept' => (string) $cfg['accept'],
        'Authorization' => 'Bearer ' . (string) $cfg['private_key'],
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
    ];

    casanova_portal_log('aplazame.post.request', [
      'url' => $url,
      'sandbox' => !empty($cfg['sandbox']),
    ]);

    $res = wp_remote_post($url, $args);
    if (is_wp_error($res)) {
      casanova_portal_log('aplazame.post.wp_error', [
        'url' => $url,
        'message' => $res->get_error_message(),
        'data' => $res->get_error_data(),
      ]);
      return $res;
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $raw_body = (string) wp_remote_retrieve_body($res);
    $body_data = json_decode($raw_body, true);
    if (!is_array($body_data)) $body_data = [];

    $location = (string) wp_remote_retrieve_header($res, 'location');
    casanova_portal_log('aplazame.post.response', [
      'url' => $url,
      'code' => $code,
      'location' => $location,
      'body_prefix' => substr($raw_body, 0, 400),
    ]);

    if ($code < 200 || $code >= 300) {
      $message = trim((string) ($body_data['message'] ?? $body_data['error'] ?? ''));
      if ($message === '') {
        $message = 'Aplazame HTTP ' . $code;
      }

      return new WP_Error('aplazame_http_error', $message, [
        'code' => $code,
        'body' => $body_data,
        'raw_body' => $raw_body,
        'location' => $location,
      ]);
    }

    return [
      'status_code' => $code,
      'body' => $body_data,
      'raw_body' => $raw_body,
      'location' => $location,
    ];
  }

  public static function create_checkout(array $payload) {
    $res = self::post('/checkout', $payload);
    if (is_wp_error($res)) return $res;

    $checkout_id = '';
    if (!empty($res['body']['id']) && is_scalar($res['body']['id'])) {
      $checkout_id = (string) $res['body']['id'];
    }

    if ($checkout_id === '' && !empty($res['location']) && is_string($res['location'])) {
      $path = wp_parse_url($res['location'], PHP_URL_PATH);
      if (is_string($path) && $path !== '') {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $last = end($parts);
        if (is_string($last) && $last !== '') {
          $checkout_id = $last;
        }
      }
    }

    if ($checkout_id === '') {
      return new WP_Error(
        'aplazame_missing_checkout_id',
        'Aplazame no devolvio el checkout_id esperado.',
        $res
      );
    }

    return [
      'checkout_id' => $checkout_id,
      'location' => (string) ($res['location'] ?? ''),
      'response' => (array) ($res['body'] ?? []),
    ];
  }

  public static function build_checkout_payload(int $user_id, int $idCliente, object $intent, array $reservas, array $merchant_urls) {
    if (!function_exists('casanova_giav_cliente_get_by_id')) {
      return new WP_Error('aplazame_missing_profile', __('No se pueden cargar los datos del cliente para Aplazame.', 'casanova-portal'));
    }

    $cliente = casanova_giav_cliente_get_by_id($idCliente);
    if (is_wp_error($cliente)) {
      return $cliente;
    }

    $name_parts = self::resolve_customer_name($cliente, $user_id);
    $first_name = $name_parts['first_name'];
    $last_name = $name_parts['last_name'];
    $email = self::pick_first([
      self::read_customer_field($cliente, ['Email']),
      ($user_id > 0 && ($user = get_userdata($user_id)) instanceof WP_User) ? (string) $user->user_email : '',
    ]);

    if ($email === '') {
      return new WP_Error(
        'aplazame_missing_email',
        __('Aplazame requiere un email valido. Revísalo en tu perfil antes de continuar.', 'casanova-portal')
      );
    }

    $street = self::read_customer_field($cliente, ['Direccion']);
    $postcode = self::read_customer_field($cliente, ['CodPostal', 'CP']);
    $city = self::read_customer_field($cliente, ['Poblacion']);
    $state = self::read_customer_field($cliente, ['Provincia']);
    $country = self::normalize_country_code(self::read_customer_field($cliente, ['Pais']));
    $phone = self::normalize_phone(self::pick_first([
      self::read_customer_field($cliente, ['Movil']),
      self::read_customer_field($cliente, ['Telefono', 'Tel']),
    ]));

    if ($street === '' || $postcode === '' || $city === '' || $state === '') {
      return new WP_Error(
        'aplazame_missing_address',
        __('Aplazame requiere direccion completa. Actualiza calle, codigo postal, poblacion y provincia en tu perfil antes de continuar.', 'casanova-portal')
      );
    }

    $document = self::normalize_document(self::pick_first([
      self::read_customer_field($cliente, ['Documento']),
      self::read_customer_field($cliente, ['PasaporteNumero']),
    ]));

    $address = [
      'phone' => $phone,
      'street' => $street,
      'city' => $city,
      'state' => $state,
      'country' => $country !== '' ? $country : 'ES',
      'postcode' => $postcode,
    ];
    if ($address['phone'] === '') unset($address['phone']);

    $mode = 'full';
    $payload = json_decode((string) ($intent->payload ?? ''), true);
    if (is_array($payload)) {
      $mode_value = strtolower(trim((string) ($payload['mode'] ?? '')));
      if ($mode_value === 'deposit') {
        $mode = 'deposit';
      }
    }

    $exp_meta = function_exists('casanova_portal_expediente_meta')
      ? casanova_portal_expediente_meta($idCliente, (int) $intent->id_expediente)
      : ['label' => sprintf(__('Expediente %s', 'casanova-portal'), (int) $intent->id_expediente), 'titulo' => '', 'codigo' => ''];

    $trip_label = trim((string) ($exp_meta['label'] ?? ''));
    if ($trip_label === '') {
      $trip_label = sprintf(__('Expediente %s', 'casanova-portal'), (int) $intent->id_expediente);
    }

    $article_name = $mode === 'deposit'
      ? sprintf(__('Deposito viaje %s', 'casanova-portal'), $trip_label)
      : sprintf(__('Pago viaje %s', 'casanova-portal'), $trip_label);

    $portal_url = (string) ($merchant_urls['portal_url'] ?? home_url('/'));
    $amount_decimal = self::decimal_from_float((float) $intent->amount);

    $order = [
      'id' => (string) ($intent->provider_reference ?? ('APL-' . (int) $intent->id)),
      'currency' => 'EUR',
      'total_amount' => $amount_decimal,
      'tax_rate' => 0,
      'articles' => [
        [
          'id' => 'trip-' . (int) $intent->id_expediente . '-' . $mode,
          'name' => $article_name,
          'description' => $trip_label,
          'url' => $portal_url,
          'image_url' => self::resolve_image_url(),
          'quantity' => 1,
          'price' => $amount_decimal,
          'tax_rate' => 0,
          'discount' => 0,
          'category' => 'Viajes',
        ],
      ],
    ];

    $event_date = self::detect_event_date($reservas);
    if ($event_date !== null) {
      $order['options'] = ['event_date' => $event_date];
    }

    $customer = [
      'id' => (string) $idCliente,
      'email' => $email,
      'type' => 'e',
      'first_name' => $first_name,
      'last_name' => $last_name,
      'language' => self::resolve_language($user_id),
      'address' => $address,
    ];
    if ($phone !== '') {
      $customer['phone'] = $phone;
    }
    if ($document !== '') {
      $customer['document'] = ['number' => $document];
    }

    $billing = [
      'first_name' => $first_name,
      'last_name' => $last_name,
      'street' => $street,
      'city' => $city,
      'state' => $state,
      'country' => $country !== '' ? $country : 'ES',
      'postcode' => $postcode,
    ];
    if ($phone !== '') {
      $billing['phone'] = $phone;
    }

    $shipping = [
      'first_name' => $first_name,
      'last_name' => $last_name,
      'street' => $street,
      'city' => $city,
      'state' => $state,
      'country' => $country !== '' ? $country : 'ES',
      'postcode' => $postcode,
      'name' => __('Documentacion digital', 'casanova-portal'),
      'price' => 0,
      'tax_rate' => 0,
      'discount' => 0,
      'method' => 'postal',
    ];
    if ($phone !== '') {
      $shipping['phone'] = $phone;
    }

    return [
      'merchant' => [
        'notification_url' => (string) ($merchant_urls['notification_url'] ?? ''),
        'success_url' => self::relative_store_url((string) ($merchant_urls['success_url'] ?? $portal_url)),
        'pending_url' => self::relative_store_url((string) ($merchant_urls['pending_url'] ?? $portal_url)),
        'error_url' => self::relative_store_url((string) ($merchant_urls['error_url'] ?? $portal_url)),
        'dismiss_url' => self::relative_store_url((string) ($merchant_urls['dismiss_url'] ?? $portal_url)),
        'ko_url' => self::relative_store_url((string) ($merchant_urls['ko_url'] ?? $portal_url)),
        'close_on_success' => false,
      ],
      'product' => [
        'type' => 'instalments',
      ],
      'order' => $order,
      'customer' => $customer,
      'billing' => $billing,
      'shipping' => $shipping,
    ];
  }

  private static function resolve_customer_name($cliente, int $user_id): array {
    $first_name = self::read_customer_field($cliente, ['Nombre']);
    $last_name = self::read_customer_field($cliente, ['Apellidos']);
    if ($first_name !== '' && $last_name !== '') {
      return [
        'first_name' => $first_name,
        'last_name' => $last_name,
      ];
    }

    $display_name = '';
    if ($user_id > 0) {
      $user = get_userdata($user_id);
      if ($user instanceof WP_User) {
        $display_name = trim((string) $user->display_name);
      }
    }

    $full_name = trim($first_name . ' ' . $last_name);
    if ($full_name === '') {
      $full_name = $display_name;
    }
    if ($full_name === '') {
      $full_name = __('Cliente Casanova', 'casanova-portal');
    }

    $parts = preg_split('/\s+/', $full_name);
    $parts = is_array($parts) ? array_values(array_filter($parts, static function($value) {
      return $value !== '';
    })) : [];

    if (!empty($parts)) {
      $first_name = (string) array_shift($parts);
      $last_name = !empty($parts) ? implode(' ', $parts) : __('Cliente', 'casanova-portal');
    }

    if ($first_name === '') $first_name = __('Cliente', 'casanova-portal');
    if ($last_name === '') $last_name = __('Cliente', 'casanova-portal');

    return [
      'first_name' => $first_name,
      'last_name' => $last_name,
    ];
  }

  private static function read_customer_field($cliente, array $keys): string {
    if (!is_object($cliente)) return '';

    foreach ($keys as $key) {
      if (!isset($cliente->{$key})) continue;
      $value = trim((string) $cliente->{$key});
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  private static function pick_first(array $values): string {
    foreach ($values as $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  private static function normalize_phone(string $phone): string {
    $phone = trim($phone);
    if ($phone === '') return '';

    return preg_replace('/\s+/', '', $phone) ?: '';
  }

  private static function normalize_document(string $document): string {
    $document = strtoupper(trim($document));
    if ($document === '') return '';

    return preg_replace('/\s+/', '', $document) ?: '';
  }

  private static function resolve_language(int $user_id): string {
    $locale = '';
    if ($user_id > 0) {
      $locale = (string) get_user_meta($user_id, 'casanova_portal_locale', true);
      if ($locale === '') {
        $locale = (string) get_user_locale($user_id);
      }
    }
    if ($locale === '') {
      $locale = (string) get_locale();
    }

    $lang = strtolower(substr(preg_replace('/[^A-Za-z_]/', '', $locale), 0, 2));
    return $lang !== '' ? $lang : 'es';
  }

  private static function detect_event_date(array $reservas): ?string {
    $candidates = [];

    foreach ($reservas as $reserva) {
      if (!is_object($reserva)) continue;

      foreach (['FechaInicio', 'FechaEntrada', 'FechaSalida', 'FechaServicio', 'Fecha'] as $field) {
        if (empty($reserva->{$field})) continue;

        $value = trim((string) $reserva->{$field});
        if ($value === '') continue;

        try {
          if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            $candidates[] = substr($value, 0, 10);
            continue;
          }
          if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('d/m/Y', $value, wp_timezone());
            if ($dt instanceof DateTimeImmutable) {
              $candidates[] = $dt->format('Y-m-d');
            }
          }
        } catch (Throwable $e) {
          continue;
        }
      }
    }

    if (empty($candidates)) {
      return null;
    }

    sort($candidates, SORT_STRING);
    return (string) $candidates[0];
  }

  private static function normalize_country_code(string $country): string {
    $country = trim($country);
    if ($country === '') return 'ES';

    $upper = strtoupper($country);
    if (preg_match('/^[A-Z]{2}$/', $upper)) {
      return $upper;
    }

    $normalized = function_exists('remove_accents')
      ? remove_accents($country)
      : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $country);
    $normalized = strtolower(trim((string) $normalized));

    $map = [
      'espana' => 'ES',
      'españa' => 'ES',
      'spain' => 'ES',
      'portugal' => 'PT',
      'francia' => 'FR',
      'france' => 'FR',
      'italia' => 'IT',
      'italy' => 'IT',
      'alemania' => 'DE',
      'germany' => 'DE',
      'reino unido' => 'GB',
      'united kingdom' => 'GB',
      'gran bretana' => 'GB',
      'great britain' => 'GB',
      'irlanda' => 'IE',
      'ireland' => 'IE',
      'estados unidos' => 'US',
      'united states' => 'US',
      'usa' => 'US',
      'belgica' => 'BE',
      'belgium' => 'BE',
      'holanda' => 'NL',
      'netherlands' => 'NL',
    ];

    return $map[$normalized] ?? 'ES';
  }

  private static function resolve_image_url(): string {
    $candidates = [
      defined('CASANOVA_AGENCY_LOGO_URL') ? (string) CASANOVA_AGENCY_LOGO_URL : '',
      defined('WP_TRAVEL_GIAV_PUBLIC_LOGO_URL') ? (string) WP_TRAVEL_GIAV_PUBLIC_LOGO_URL : '',
      function_exists('get_site_icon_url') ? (string) get_site_icon_url(512) : '',
      home_url('/favicon.ico'),
    ];

    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '' && preg_match('~^https?://~i', $candidate)) {
        return $candidate;
      }
    }

    return home_url('/favicon.ico');
  }

  private static function relative_store_url(string $absolute_url): string {
    $absolute_url = trim($absolute_url);
    if ($absolute_url === '') {
      return '/';
    }

    $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
    $query = (string) wp_parse_url($absolute_url, PHP_URL_QUERY);
    $fragment = (string) wp_parse_url($absolute_url, PHP_URL_FRAGMENT);

    $relative = $path !== '' ? $path : '/';
    if ($query !== '') {
      $relative .= '?' . $query;
    }
    if ($fragment !== '') {
      $relative .= '#' . $fragment;
    }

    return $relative;
  }
}
