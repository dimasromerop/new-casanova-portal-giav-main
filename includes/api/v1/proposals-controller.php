<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Propuestas
 * GET  /wp-json/casanova/v1/proposals        Propuestas y solicitudes en curso del cliente.
 * POST /wp-json/casanova/v1/proposals/seen   Marca propuestas como vistas (deja de salir «Nueva»).
 *
 * Los datos los da el plugin de propuestas (WP Travel Proposals & GIAV) por el filtro
 * `casanova_portal_proposals_feed`; si no está activo, `available` = false y la app oculta la
 * sección. Nunca se cachea: el estado «Nueva» cambia al abrir una propuesta.
 */
class Casanova_Proposals_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/proposals', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'mock' => [
          'description' => 'Devuelve datos de prueba (solo admins).',
          'type'        => 'integer',
          'required'    => false,
        ],
      ],
    ]);

    register_rest_route('casanova/v1', '/proposals/seen', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle_seen'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'ids' => [
          'description' => 'IDs de las propuestas vistas.',
          'type'        => 'array',
          'items'       => ['type' => 'integer'],
          'required'    => true,
        ],
      ],
    ]);

    // Actualizar una propuesta caducada (proposal_id) o pedir una nueva (sin proposal_id):
    // se envía el mismo formulario de solicitud de la web.
    register_rest_route('casanova/v1', '/proposals/request-form', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle_request_form'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'proposal_id' => ['type' => 'integer', 'required' => false],
        'lang'        => ['type' => 'string', 'required' => false],
        'mock'        => ['type' => 'integer', 'required' => false],
      ],
    ]);

    // Confirmar el email de la cuenta (sin esto no se empareja por email; ver portal-email-verification.php).
    register_rest_route('casanova/v1', '/proposals/verify-email', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle_verify_email'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'code' => ['type' => 'string', 'required' => false],
      ],
    ]);

    register_rest_route('casanova/v1', '/proposals/request', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle_request_submit'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'proposal_id' => ['type' => 'integer', 'required' => false],
        'lang'        => ['type' => 'string', 'required' => false],
        'mock'        => ['type' => 'integer', 'required' => false],
      ],
    ]);
  }

  /** Sin `code` envía el código al email de la cuenta; con `code`, lo comprueba. */
  public static function handle_verify_email(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    nocache_headers();

    if (function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only()) {
      return new WP_Error('read_only', __('Modo de vista cliente activo. Solo lectura.', 'casanova-portal'), ['status' => 403]);
    }
    if (!function_exists('casanova_portal_email_verification_send')) {
      return new WP_Error('unavailable', __('No disponible.', 'casanova-portal'), ['status' => 404]);
    }
    if (!self::is_linked()) return self::not_linked_error();

    $user_id = (int) get_current_user_id();
    $code = trim((string) $request->get_param('code'));
    $result = $code === ''
      ? casanova_portal_email_verification_send($user_id)
      : casanova_portal_email_verification_confirm($user_id, $code);
    if (is_wp_error($result)) return $result;

    return rest_ensure_response(['ok' => true] + $result);
  }

  private static function request_lang(WP_REST_Request $request): string {
    $lang = strtolower(substr((string) $request->get_param('lang'), 0, 2));
    if ($lang === '' && defined('ICL_LANGUAGE_CODE')) $lang = (string) ICL_LANGUAGE_CODE;
    return $lang === 'en' ? 'en' : 'es';
  }

  public static function handle_request_form(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    nocache_headers();

    $proposal_id = absint($request->get_param('proposal_id'));
    $lang = self::request_lang($request);

    if ((int) $request->get_param('mock') === 1 && current_user_can('manage_options')) {
      return rest_ensure_response(self::mock_request_form($proposal_id, $lang));
    }
    if (!self::is_available()) {
      return new WP_Error('unavailable', __('No disponible.', 'casanova-portal'), ['status' => 404]);
    }
    if (!self::is_linked()) return self::not_linked_error();

    $form = apply_filters('casanova_portal_proposals_request_form', null, self::effective_user_id(), $proposal_id, $lang);
    if (is_wp_error($form)) return $form;
    if (!is_array($form)) {
      return new WP_Error('unavailable', __('No disponible.', 'casanova-portal'), ['status' => 404]);
    }

    return rest_ensure_response($form);
  }

  public static function handle_request_submit(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    nocache_headers();

    // Un agente viendo el portal como el cliente no envía solicitudes en su nombre.
    if (function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only()) {
      return new WP_Error('read_only', __('Modo de vista cliente activo. Solo lectura.', 'casanova-portal'), ['status' => 403]);
    }

    $proposal_id = absint($request->get_param('proposal_id'));
    $data = [];
    foreach (['arrival', 'departure', 'green_fees', 'players', 'non_players', 'rooms', 'beds', 'phone', 'destination', 'comments'] as $key) {
      $data[$key] = (string) $request->get_param($key);
    }
    $data['flexible'] = rest_sanitize_boolean($request->get_param('flexible'));

    if ((int) $request->get_param('mock') === 1 && current_user_can('manage_options')) {
      return rest_ensure_response(['ok' => true, 'request_id' => 0, 'mock' => true]);
    }
    if (!self::is_available()) {
      return new WP_Error('unavailable', __('No disponible.', 'casanova-portal'), ['status' => 404]);
    }
    if (!self::is_linked()) return self::not_linked_error();

    $result = apply_filters('casanova_portal_proposals_submit_request', null, self::effective_user_id(), $proposal_id, $data, self::request_lang($request));
    if (is_wp_error($result)) return $result;
    if (!is_array($result)) {
      return new WP_Error('unavailable', __('No disponible.', 'casanova-portal'), ['status' => 503]);
    }

    return rest_ensure_response(['ok' => true, 'request_id' => (int) ($result['request_id'] ?? 0)]);
  }

  /** Datos de prueba del diálogo (mismos textos de habitaciones que el formulario ES). */
  private static function mock_request_form(int $proposal_id, string $lang): array {
    $en = $lang === 'en';
    return [
      'lang'       => $lang,
      'options'    => [
        'green_fees'  => array_map('strval', range(1, 8)),
        'players'     => array_map('strval', range(1, 12)),
        'non_players' => array_map('strval', range(0, 8)),
      ],
      'rooms_text' => $en
        ? ['double' => ['%d double', '%d doubles'], 'single' => ['%d single', '%d singles'], 'bed1' => ['Double bed', 'Twin beds'], 'bedN' => ['%d with double bed', '%d twin'], 'choose' => 'Choose an option']
        : ['double' => ['%d doble', '%d dobles'], 'single' => ['%d individual', '%d individuales'], 'bed1' => ['Cama de matrimonio', 'Dos camas (twin)'], 'bedN' => ['%d de matrimonio', '%d twin'], 'choose' => 'Elige una opción'],
      'has_beds'   => true,
      'prefill'    => [
        'arrival' => '', 'departure' => '', 'green_fees' => '2', 'players' => '2', 'non_players' => '0',
        'rooms' => '', 'beds' => '', 'phone' => '',
      ],
      'proposal'   => $proposal_id ? [
        'id' => $proposal_id, 'title' => 'Valencia · golf y spa', 'image' => '/casanova/wp-content/uploads/2015/03/sella-golf05.jpg',
        'start_date' => '2026-11-08', 'end_date' => '2026-11-11', 'players' => 2, 'non_players' => 0,
        'agent' => ['name' => 'Dimas', 'email' => 'dr@golfcasanova.com'],
      ] : null,
    ];
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  /**
   * ¿Se muestra la sección? Según el interruptor de Casanova Portal → Resumen y si hay plugin
   * que dé los datos. En modo «solo administradores» decide el usuario real (también cuando ve
   * el portal como un cliente).
   */
  public static function is_available(): bool {
    $mode = function_exists('casanova_portal_proposals_mode') ? casanova_portal_proposals_mode() : 'admins';
    $enabled = $mode === 'all' || ($mode === 'admins' && current_user_can('manage_options'));
    return (bool) apply_filters('casanova_portal_proposals_enabled', $enabled && has_filter('casanova_portal_proposals_feed'));
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    nocache_headers();

    $perf_start = function_exists('casanova_perf_now') ? casanova_perf_now() : microtime(true);
    $perf_error = null;
    $response = null;
    $user_id = self::effective_user_id();
    $perf_context = [
      'user_id' => $user_id,
      'mock' => (int) $request->get_param('mock') === 1 ? 1 : 0,
    ];

    try {
      if ((int) $request->get_param('mock') === 1 && current_user_can('manage_options')) {
        $response = rest_ensure_response(self::mock_feed());
        return $response;
      }

      $data = self::feed_for($user_id);
      $perf_context['groups'] = count($data['groups']);
      $perf_context['requests'] = count($data['requests']);
      $response = rest_ensure_response($data);
      return $response;
    } catch (Throwable $e) {
      $perf_error = $e;
      if (function_exists('casanova_log')) {
        casanova_log('proposals', 'proposals endpoint failed', [
          'user_id' => $user_id,
          'exception' => $e->getMessage(),
        ], 'error');
      }
      $response = new WP_REST_Response(self::empty_feed(true) + ['error' => 'unavailable'], 503);
      return $response;
    } finally {
      if (function_exists('casanova_perf_observe_rest')) {
        casanova_perf_observe_rest('proposals', $perf_start, $response, $perf_context, $perf_error);
      }
    }
  }

  public static function handle_seen(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    nocache_headers();

    $user_id = self::effective_user_id();
    $ids = array_values(array_filter(array_map('absint', (array) $request->get_param('ids'))));

    // Un agente viendo el portal como el cliente no debe quitarle las marcas de «Nueva».
    $read_only = function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only();
    if (!$read_only && $user_id > 0 && $ids && self::is_available()) {
      // Solo se marcan propuestas que el cliente ve de verdad.
      $visible = [];
      foreach (self::feed_for($user_id)['groups'] as $group) {
        foreach ((array) ($group['proposals'] ?? []) as $proposal) {
          $visible[(int) $proposal['id']] = true;
        }
      }
      $ids = array_values(array_filter($ids, static fn($id) => isset($visible[$id])));
      if ($ids) {
        do_action('casanova_portal_proposals_seen', $user_id, $ids);
      }
    } else {
      $ids = [];
    }

    return rest_ensure_response(['ok' => true, 'marked' => $ids]);
  }

  /**
   * Igual que la página del portal (portal-access-gate.php): sin cuenta vinculada a GIAV no hay
   * acceso. La API también lo exige; si no, un usuario recién registrado podría pedir sus
   * «propuestas» directamente y emparejarse por un email que nadie ha comprobado.
   */
  private static function is_linked(): bool {
    $user_id = self::effective_user_id();
    $client_id = function_exists('casanova_portal_get_effective_client_id')
      ? (int) casanova_portal_get_effective_client_id($user_id)
      : (int) get_user_meta($user_id, 'casanova_idcliente', true);
    return $client_id > 0;
  }

  private static function not_linked_error(): WP_Error {
    return new WP_Error('not_linked', __('Vincula tu cuenta para acceder a tu área de cliente.', 'casanova-portal'), ['status' => 403]);
  }

  private static function effective_user_id(): int {
    return function_exists('casanova_portal_get_effective_user_id')
      ? (int) casanova_portal_get_effective_user_id()
      : (int) get_current_user_id();
  }

  private static function empty_feed(bool $available): array {
    $user_id = self::effective_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : null;
    $read_only = function_exists('casanova_portal_is_read_only') && casanova_portal_is_read_only();
    // Aviso «confirma tu email»: solo al propio cliente (no en la vista como cliente de un agente).
    // Solo tiene sentido para clientes vinculados cuyo email de la cuenta no es el de GIAV.
    $needs_email = $user && !$read_only && self::is_linked()
      && function_exists('casanova_portal_email_is_verified') && !casanova_portal_email_is_verified($user_id);

    return [
      'available' => $available,
      'read_only' => $read_only,
      'email_verification' => [
        'needed'      => (bool) $needs_email,
        'emailMasked' => ($needs_email && function_exists('casanova_portal_mask_email')) ? casanova_portal_mask_email($user->user_email) : '',
      ],
      'counts'    => ['new' => 0, 'open' => 0],
      'groups'    => [],
      'requests'  => [],
    ];
  }

  /** Datos del cliente, limitados a lo que pinta la app. */
  private static function feed_for(int $user_id): array {
    if (!self::is_available()) {
      return self::empty_feed(false);
    }
    if (!self::is_linked()) {
      return self::empty_feed(true);
    }

    $feed = apply_filters('casanova_portal_proposals_feed', null, $user_id);
    if (!is_array($feed)) {
      return self::empty_feed(false);
    }

    $out = self::empty_feed(true);
    $out['counts'] = [
      'new'  => (int) ($feed['counts']['new'] ?? 0),
      'open' => (int) ($feed['counts']['open'] ?? 0),
    ];
    foreach ((array) ($feed['groups'] ?? []) as $group) {
      $out['groups'][] = [
        'key'       => (string) ($group['key'] ?? ''),
        'type'      => (string) ($group['type'] ?? 'single'),
        'title'     => (string) ($group['title'] ?? ''),
        'request'   => is_array($group['request'] ?? null) ? self::public_request($group['request']) : null,
        'proposals' => array_map([self::class, 'public_proposal'], (array) ($group['proposals'] ?? [])),
      ];
    }
    $out['requests'] = array_map([self::class, 'public_request'], (array) ($feed['requests'] ?? []));

    return $out;
  }

  private static function public_proposal(array $p): array {
    $price = is_array($p['price'] ?? null) ? $p['price'] : [];
    $line = static fn($l) => [
      'kind'  => (string) ($l['kind'] ?? ''),
      'room'  => (string) ($l['room'] ?? ''),
      'value' => (float) ($l['value'] ?? 0),
    ];

    return [
      'id'            => (int) ($p['id'] ?? 0),
      'url'           => esc_url_raw((string) ($p['url'] ?? '')),
      'title'         => (string) ($p['title'] ?? ''),
      'image'         => esc_url_raw((string) ($p['image'] ?? '')),
      'lang'          => (string) ($p['lang'] ?? ''),
      'start_date'    => $p['start_date'] ?? null,
      'end_date'      => $p['end_date'] ?? null,
      'nights'        => (int) ($p['nights'] ?? 0),
      'players'       => (int) ($p['players'] ?? 0),
      'non_players'   => (int) ($p['non_players'] ?? 0),
      'state'         => (string) ($p['state'] ?? ''),
      'is_new'        => !empty($p['is_new']),
      'sent_on'       => $p['sent_on'] ?? null,
      'expires_on'    => $p['expires_on'] ?? null,
      'accepted_on'   => $p['accepted_on'] ?? null,
      'expediente_id' => !empty($p['expediente_id']) ? (int) $p['expediente_id'] : null,
      'renewal_requested' => !empty($p['renewal_requested']),
      'agent'         => [
        'name'  => (string) ($p['agent']['name'] ?? ''),
        'email' => sanitize_email((string) ($p['agent']['email'] ?? '')),
      ],
      'price'         => [
        'currency' => (string) ($price['currency'] ?? 'EUR'),
        'basis'    => ($price['basis'] ?? '') === 'single' ? 'single' : 'double',
        'main'     => array_map($line, (array) ($price['main'] ?? [])),
        'extras'   => array_map($line, (array) ($price['extras'] ?? [])),
        'total'    => (float) ($price['total'] ?? 0),
      ],
    ];
  }

  private static function public_request(array $r): array {
    return [
      'id'          => (int) ($r['id'] ?? 0),
      'title'       => (string) ($r['title'] ?? ''),
      'start_date'  => $r['start_date'] ?? null,
      'end_date'    => $r['end_date'] ?? null,
      'players'     => (int) ($r['players'] ?? 0),
      'non_players' => (int) ($r['non_players'] ?? 0),
      'rooms'       => (string) ($r['rooms'] ?? ''),
      'beds'        => (string) ($r['beds'] ?? ''),
      'created_on'  => $r['created_on'] ?? null,
      'step'        => (string) ($r['step'] ?? 'received'),
    ];
  }

  private static function mock_feed(): array {
    $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/proposals.json';
    $data = file_exists($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) {
      return self::empty_feed(true);
    }

    $out = self::empty_feed(true);
    $out['counts'] = ['new' => (int) ($data['counts']['new'] ?? 0), 'open' => (int) ($data['counts']['open'] ?? 0)];
    $out['groups'] = array_map(static fn($g) => [
      'key'       => (string) ($g['key'] ?? ''),
      'type'      => (string) ($g['type'] ?? 'single'),
      'title'     => (string) ($g['title'] ?? ''),
      'request'   => is_array($g['request'] ?? null) ? self::public_request($g['request']) : null,
      'proposals' => array_map([self::class, 'public_proposal'], (array) ($g['proposals'] ?? [])),
    ], (array) ($data['groups'] ?? []));
    $out['requests'] = array_map([self::class, 'public_request'], (array) ($data['requests'] ?? []));

    return $out;
  }
}

/**
 * El plugin de propuestas empareja por cliente GIAV: le pasamos el que resuelve el portal
 * (incluida la vista como cliente de un agente, donde puede no haber usuario de WordPress).
 */
add_filter('wp_travel_giav_portal_email_verified', function ($verified, $user_id) {
  return function_exists('casanova_portal_email_is_verified') ? casanova_portal_email_is_verified((int) $user_id) : $verified;
}, 10, 2);

add_filter('wp_travel_giav_portal_user_identity', function ($identity, $user_id) {
  // El registro no confirma el email: solo se empareja por email si el cliente lo ha confirmado.
  if (!function_exists('casanova_portal_email_is_verified') || !casanova_portal_email_is_verified((int) $user_id)) {
    $identity['email'] = '';
  }
  if (function_exists('casanova_portal_get_effective_client_id')) {
    $client_id = (int) casanova_portal_get_effective_client_id((int) $user_id);
    if ($client_id > 0) {
      $identity['giav_client_id'] = $client_id;
    }
  }
  return $identity;
}, 10, 2);
