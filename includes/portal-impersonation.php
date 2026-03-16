<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_portal_impersonation_session_key')) {
  function casanova_portal_impersonation_session_key(?int $admin_id = null): string {
    $admin_id = $admin_id ?: (int) get_current_user_id();
    if ($admin_id <= 0 || !function_exists('wp_get_session_token')) {
      return '';
    }

    $token = (string) wp_get_session_token();
    if ($token === '') {
      return '';
    }

    return 'casanova_impersonation_' . $admin_id . '_' . hash('sha256', $token);
  }
}

if (!function_exists('casanova_portal_impersonation_request_ip')) {
  function casanova_portal_impersonation_request_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
      if (empty($_SERVER[$key])) {
        continue;
      }

      $raw = (string) $_SERVER[$key];
      $parts = array_map('trim', explode(',', $raw));
      foreach ($parts as $part) {
        if ($part !== '') {
          return sanitize_text_field($part);
        }
      }
    }

    return '';
  }
}

if (!function_exists('casanova_portal_impersonation_find_linked_user')) {
  function casanova_portal_impersonation_find_linked_user(int $client_id): ?WP_User {
    if ($client_id <= 0) {
      return null;
    }

    $users = get_users([
      'number' => 1,
      'fields' => 'all',
      'meta_key' => 'casanova_idcliente',
      'meta_value' => (string) $client_id,
    ]);

    $user = $users[0] ?? null;
    return ($user instanceof WP_User) ? $user : null;
  }
}

if (!function_exists('casanova_portal_impersonation_get_giav_client')) {
  function casanova_portal_impersonation_get_giav_client(int $client_id): ?object {
    if ($client_id <= 0 || !function_exists('casanova_giav_cliente_get_by_id')) {
      return null;
    }

    $client = casanova_giav_cliente_get_by_id($client_id);
    if (is_wp_error($client) || !is_object($client)) {
      return null;
    }

    return $client;
  }
}

if (!function_exists('casanova_portal_impersonation_giav_client_name')) {
  function casanova_portal_impersonation_giav_client_name(?object $client): string {
    if (!$client) {
      return '';
    }

    $name = trim((string) (($client->Nombre ?? '') . ' ' . ($client->Apellidos ?? '')));
    if ($name !== '') {
      return preg_replace('/\s+/', ' ', $name);
    }

    $email = trim((string) ($client->Email ?? ''));
    if ($email !== '') {
      return $email;
    }

    $client_id = (int) ($client->Id ?? $client->idCliente ?? 0);
    return $client_id > 0 ? sprintf(__('Cliente GIAV #%d', 'casanova-portal'), $client_id) : '';
  }
}

if (!function_exists('casanova_portal_get_impersonation_state')) {
  function casanova_portal_get_impersonation_state(): array {
    if (!is_user_logged_in()) {
      return [];
    }

    $admin_id = (int) get_current_user_id();
    $key = casanova_portal_impersonation_session_key($admin_id);
    if ($key === '') {
      return [];
    }

    $state = get_transient($key);
    if (!is_array($state)) {
      return [];
    }

    $stored_admin_id = (int) ($state['admin_id'] ?? 0);
    $impersonated_user_id = (int) ($state['impersonating_user_id'] ?? 0);
    $impersonated_client_id = (int) ($state['impersonated_client_id'] ?? 0);
    if ($stored_admin_id !== $admin_id || $impersonated_user_id === $admin_id || ($impersonated_user_id <= 0 && $impersonated_client_id <= 0)) {
      delete_transient($key);
      return [];
    }

    $user = null;
    if ($impersonated_user_id > 0) {
      $candidate = get_userdata($impersonated_user_id);
      if ($candidate instanceof WP_User) {
        $user_client_id = (int) get_user_meta($impersonated_user_id, 'casanova_idcliente', true);
        if ($user_client_id > 0) {
          $impersonated_client_id = $user_client_id;
          $user = $candidate;
        } else {
          $impersonated_user_id = 0;
        }
      } else {
        $impersonated_user_id = 0;
      }
    }

    if (!($user instanceof WP_User) && $impersonated_client_id > 0) {
      $linked_user = casanova_portal_impersonation_find_linked_user($impersonated_client_id);
      if ($linked_user instanceof WP_User) {
        $user = $linked_user;
        $impersonated_user_id = (int) $linked_user->ID;
      }
    }

    $preview_only = !($user instanceof WP_User);
    $giav_client = null;
    if ($preview_only) {
      $giav_client = casanova_portal_impersonation_get_giav_client($impersonated_client_id);
      if (!$giav_client) {
        delete_transient($key);
        return [];
      }
    }

    if ($impersonated_client_id <= 0) {
      delete_transient($key);
      return [];
    }

    $client_name = trim((string) ($state['client_name'] ?? ''));
    if ($client_name === '' && $user instanceof WP_User) {
      $client_name = trim((string) $user->display_name);
      if ($client_name === '') {
        $client_name = trim((string) $user->user_login);
      }
    }
    if ($client_name === '') {
      if (!$giav_client) {
        $giav_client = casanova_portal_impersonation_get_giav_client($impersonated_client_id);
      }
      $client_name = casanova_portal_impersonation_giav_client_name($giav_client);
    }

    $normalized = [
      'admin_id' => $admin_id,
      'impersonating_user_id' => $impersonated_user_id,
      'impersonated_client_id' => $impersonated_client_id,
      'client_name' => $client_name,
      'preview_only' => $preview_only,
      'started_at' => (string) ($state['started_at'] ?? ''),
      'ip' => (string) ($state['ip'] ?? ''),
      'return_url' => (string) ($state['return_url'] ?? ''),
    ];

    if ($normalized !== $state) {
      set_transient($key, $normalized, DAY_IN_SECONDS);
    }

    return $normalized;
  }
}

if (!function_exists('casanova_portal_is_impersonating')) {
  function casanova_portal_is_impersonating(): bool {
    return !empty(casanova_portal_get_impersonation_state());
  }
}

if (!function_exists('casanova_portal_get_admin_user_id')) {
  function casanova_portal_get_admin_user_id(): int {
    $state = casanova_portal_get_impersonation_state();
    if (!empty($state['admin_id'])) {
      return (int) $state['admin_id'];
    }

    return (int) get_current_user_id();
  }
}

if (!function_exists('casanova_portal_get_effective_user_id')) {
  function casanova_portal_get_effective_user_id(): int {
    $state = casanova_portal_get_impersonation_state();
    if (array_key_exists('impersonating_user_id', $state)) {
      return (int) $state['impersonating_user_id'];
    }

    return (int) get_current_user_id();
  }
}

if (!function_exists('casanova_portal_is_preview_only')) {
  function casanova_portal_is_preview_only(): bool {
    $state = casanova_portal_get_impersonation_state();
    return !empty($state['preview_only']);
  }
}

if (!function_exists('casanova_portal_resolve_user_id')) {
  function casanova_portal_resolve_user_id(int $user_id = 0): int {
    $effective_user_id = casanova_portal_get_effective_user_id();
    if ($user_id <= 0) {
      return $effective_user_id;
    }

    if (!casanova_portal_is_impersonating()) {
      return $user_id;
    }

    $admin_id = casanova_portal_get_admin_user_id();
    return ($user_id === $admin_id) ? $effective_user_id : $user_id;
  }
}

if (!function_exists('casanova_portal_get_effective_client_id')) {
  function casanova_portal_get_effective_client_id(int $user_id = 0): int {
    $state = casanova_portal_get_impersonation_state();
    if (!empty($state)) {
      $admin_id = (int) ($state['admin_id'] ?? 0);
      $impersonated_user_id = (int) ($state['impersonating_user_id'] ?? 0);
      $resolved_user_id = casanova_portal_resolve_user_id($user_id);
      if (
        $user_id <= 0
        || ($admin_id > 0 && $user_id === $admin_id)
        || ($impersonated_user_id > 0 && ($user_id === $impersonated_user_id || $resolved_user_id === $impersonated_user_id))
      ) {
        return (int) ($state['impersonated_client_id'] ?? 0);
      }
    }

    $resolved_user_id = casanova_portal_resolve_user_id($user_id);
    if ($resolved_user_id <= 0) {
      return 0;
    }

    return (int) get_user_meta($resolved_user_id, 'casanova_idcliente', true);
  }
}

if (!function_exists('casanova_portal_get_effective_user')) {
  function casanova_portal_get_effective_user(): ?WP_User {
    $effective_user_id = casanova_portal_get_effective_user_id();
    if ($effective_user_id <= 0) {
      return null;
    }

    $user = get_userdata($effective_user_id);
    return ($user instanceof WP_User) ? $user : null;
  }
}

if (!function_exists('casanova_portal_impersonation_client_name')) {
  function casanova_portal_impersonation_client_name(): string {
    $state = casanova_portal_get_impersonation_state();
    if (!empty($state['client_name'])) {
      return (string) $state['client_name'];
    }

    $user = casanova_portal_get_effective_user();
    if ($user instanceof WP_User) {
      $name = trim((string) $user->display_name);
      if ($name !== '') {
        return $name;
      }
      return trim((string) $user->user_login);
    }

    $client_id = casanova_portal_get_effective_client_id();
    if ($client_id > 0) {
      $client = casanova_portal_impersonation_get_giav_client($client_id);
      return casanova_portal_impersonation_giav_client_name($client);
    }

    return '';
  }
}

if (!function_exists('casanova_portal_is_read_only')) {
  function casanova_portal_is_read_only(): bool {
    return casanova_portal_is_impersonating();
  }
}

if (!function_exists('casanova_portal_impersonation_message')) {
  function casanova_portal_impersonation_message(): string {
    if (casanova_portal_is_preview_only()) {
      return __('Previsualizacion de cliente activa. Solo lectura.', 'casanova-portal');
    }

    return __('Modo de vista cliente activo. Solo lectura.', 'casanova-portal');
  }
}

if (!function_exists('casanova_portal_current_url')) {
  function casanova_portal_current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($host === '' || $uri === '') {
      return home_url('/');
    }

    return esc_url_raw($scheme . $host . $uri);
  }
}

if (!function_exists('casanova_portal_start_impersonation_url')) {
  function casanova_portal_start_impersonation_url(int $user_id): string {
    return add_query_arg([
      'action' => 'casanova_start_impersonation',
      'user_id' => (int) $user_id,
      '_wpnonce' => wp_create_nonce('casanova_start_impersonation_' . (int) $user_id),
    ], admin_url('admin-post.php'));
  }
}

if (!function_exists('casanova_portal_start_impersonation_client_preview_url')) {
  function casanova_portal_start_impersonation_client_preview_url(int $client_id): string {
    return add_query_arg([
      'action' => 'casanova_start_impersonation_preview',
      'client_id' => (int) $client_id,
      '_wpnonce' => wp_create_nonce('casanova_start_impersonation_preview_' . (int) $client_id),
    ], admin_url('admin-post.php'));
  }
}

if (!function_exists('casanova_portal_stop_impersonation_url')) {
  function casanova_portal_stop_impersonation_url(string $redirect_to = ''): string {
    $args = [
      'action' => 'casanova_stop_impersonation',
      '_wpnonce' => wp_create_nonce('casanova_stop_impersonation'),
    ];

    if ($redirect_to !== '') {
      $args['redirect_to'] = $redirect_to;
    }

    return add_query_arg($args, admin_url('admin-post.php'));
  }
}

if (!function_exists('casanova_portal_impersonation_admin_page_url')) {
  function casanova_portal_impersonation_admin_page_url(array $args = []): string {
    $url = add_query_arg([
      'page' => 'casanova-portal-impersonation',
    ], admin_url('users.php'));

    return !empty($args) ? add_query_arg($args, $url) : $url;
  }
}

if (!function_exists('casanova_portal_impersonation_search_clients')) {
  function casanova_portal_impersonation_search_clients(string $search = '', int $limit = 20): array {
    $search = trim(sanitize_text_field($search));
    $limit = max(1, min(50, $limit));
    $users = [];

    $append_user = static function ($candidate) use (&$users): void {
      if (!$candidate instanceof WP_User) {
        return;
      }

      $client_id = (int) get_user_meta((int) $candidate->ID, 'casanova_idcliente', true);
      if ($client_id <= 0) {
        return;
      }

      $users[(int) $candidate->ID] = $candidate;
    };

    $base_args = [
      'number' => $limit,
      'orderby' => 'ID',
      'order' => 'DESC',
      'fields' => 'all_with_meta',
      'meta_query' => [
        [
          'key' => 'casanova_idcliente',
          'compare' => 'EXISTS',
        ],
      ],
    ];

    if ($search !== '' && ctype_digit($search)) {
      $wp_user = get_user_by('id', (int) $search);
      $append_user($wp_user);

      $giav_matches = get_users([
        'number' => $limit,
        'fields' => 'all_with_meta',
        'meta_key' => 'casanova_idcliente',
        'meta_value' => (string) (int) $search,
      ]);
      foreach ($giav_matches as $candidate) {
        $append_user($candidate);
      }
    }

    if ($search !== '') {
      $search_args = $base_args;
      $search_args['search'] = '*' . $search . '*';
      $search_args['search_columns'] = ['display_name', 'user_email', 'user_login'];

      $matches = get_users($search_args);
      foreach ($matches as $candidate) {
        $append_user($candidate);
      }
    } else {
      $recent_users = get_users($base_args);
      foreach ($recent_users as $candidate) {
        $append_user($candidate);
      }
    }

    return array_values($users);
  }
}

if (!function_exists('casanova_portal_impersonation_preview_target')) {
  function casanova_portal_impersonation_preview_target(string $search = ''): array {
    $search = trim(sanitize_text_field($search));
    if ($search === '') {
      return [];
    }

    $client_id = 0;
    $dni_candidate = strtoupper((string) preg_replace('/\s+/', '', $search));
    if (ctype_digit($search)) {
      $client_id = (int) $search;
    } elseif (
      preg_match('/^[A-Za-z0-9-]{5,20}$/', $dni_candidate)
      && function_exists('casanova_giav_cliente_search_por_dni')
      && function_exists('casanova_giav_extraer_idcliente')
    ) {
      $lookup = casanova_giav_cliente_search_por_dni($dni_candidate);
      if (!is_wp_error($lookup)) {
        $extracted = (string) casanova_giav_extraer_idcliente($lookup);
        if (ctype_digit($extracted)) {
          $client_id = (int) $extracted;
        }
      }
    }

    if ($client_id <= 0) {
      return [];
    }

    $client = casanova_portal_impersonation_get_giav_client($client_id);
    if (!$client) {
      return [];
    }

    $linked_user = casanova_portal_impersonation_find_linked_user($client_id);
    $user_id = $linked_user instanceof WP_User ? (int) $linked_user->ID : 0;
    $preview_only = $user_id <= 0;
    $client_name = casanova_portal_impersonation_giav_client_name($client);

    return [
      'client_id' => $client_id,
      'client_name' => $client_name,
      'email' => trim((string) ($client->Email ?? '')),
      'user_id' => $user_id,
      'user_login' => $linked_user instanceof WP_User ? (string) $linked_user->user_login : '',
      'preview_only' => $preview_only,
      'start_url' => $preview_only
        ? casanova_portal_start_impersonation_client_preview_url($client_id)
        : casanova_portal_start_impersonation_url($user_id),
    ];
  }
}

if (!function_exists('casanova_portal_render_impersonation_admin_page')) {
  function casanova_portal_render_impersonation_admin_page(): void {
    if (!current_user_can('manage_options')) {
      wp_die(__('No autorizado.', 'casanova-portal'), 403);
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $clients = casanova_portal_impersonation_search_clients($search, 25);
    $preview_target = casanova_portal_impersonation_preview_target($search);
    $state = casanova_portal_get_impersonation_state();
    $active_user_id = (int) ($state['impersonating_user_id'] ?? 0);
    $active_client_id = (int) ($state['impersonated_client_id'] ?? 0);
    $active_preview_only = !empty($state['preview_only']);
    $admin_page_url = casanova_portal_impersonation_admin_page_url();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</h1>';
    echo '<p>' . esc_html__('Busca por nombre, email, usuario, ID de WordPress o GIAV ID. Para clientes sin acceso web, usa el GIAV ID o el DNI exacto.', 'casanova-portal') . '</p>';

    if (!empty($state)) {
      $active_name = trim((string) ($state['client_name'] ?? ''));
      $stop_url = casanova_portal_stop_impersonation_url($admin_page_url);
      $active_label = $active_preview_only
        ? __('Previsualizacion GIAV activa: %s', 'casanova-portal')
        : __('Vista cliente activa: %s', 'casanova-portal');

      echo '<div class="notice notice-warning" style="padding:12px 16px;">';
      echo '<p style="margin:0 0 10px;"><strong>' . esc_html(sprintf($active_label, $active_name !== '' ? $active_name : __('Cliente', 'casanova-portal'))) . '</strong></p>';
      if ($active_preview_only) {
        echo '<p style="margin:0 0 10px;">' . esc_html__('Este cliente existe en GIAV, pero todavia no tiene usuario WordPress vinculado. La vista previa usa directamente su contexto de cliente.', 'casanova-portal') . '</p>';
      }
      echo '<p style="margin:0;"><a class="button button-secondary" href="' . esc_url($stop_url) . '">' . esc_html__('Salir de vista cliente', 'casanova-portal') . '</a></p>';
      echo '</div>';
    }

    echo '<form method="get" style="margin:18px 0 20px;">';
    echo '<input type="hidden" name="page" value="casanova-portal-impersonation">';
    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    echo '<label class="screen-reader-text" for="casanova-portal-impersonation-search">' . esc_html__('Buscar cliente', 'casanova-portal') . '</label>';
    echo '<input type="search" id="casanova-portal-impersonation-search" name="s" class="regular-text" style="min-width:320px;" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Nombre, email, usuario, WP ID, GIAV ID o DNI exacto', 'casanova-portal') . '">';
    submit_button(__('Buscar cliente', 'casanova-portal'), 'primary', '', false);
    echo '<a class="button button-secondary" href="' . esc_url($admin_page_url) . '">' . esc_html__('Limpiar', 'casanova-portal') . '</a>';
    echo '</div>';
    echo '</form>';

    if (!empty($preview_target)) {
      $preview_client_id = (int) ($preview_target['client_id'] ?? 0);
      $preview_name = trim((string) ($preview_target['client_name'] ?? ''));
      $preview_email = trim((string) ($preview_target['email'] ?? ''));
      $preview_user_id = (int) ($preview_target['user_id'] ?? 0);
      $preview_user_login = trim((string) ($preview_target['user_login'] ?? ''));
      $preview_only = !empty($preview_target['preview_only']);
      $preview_start_url = (string) ($preview_target['start_url'] ?? '');
      $preview_is_active = ($active_client_id > 0 && $active_client_id === $preview_client_id);
      $preview_notice_class = $preview_only ? 'notice notice-info' : 'notice notice-success';

      echo '<div class="' . esc_attr($preview_notice_class) . '" style="padding:12px 16px;margin-bottom:18px;">';
      echo '<p style="margin:0 0 10px;"><strong>' . esc_html($preview_name !== '' ? $preview_name : sprintf(__('Cliente GIAV #%d', 'casanova-portal'), $preview_client_id)) . '</strong></p>';
      if ($preview_only) {
        echo '<p style="margin:0 0 10px;">' . esc_html__('Cliente encontrado en GIAV sin usuario WordPress vinculado. Puedes abrir una previsualizacion de su portal en modo solo lectura antes de darle acceso.', 'casanova-portal') . '</p>';
      } else {
        echo '<p style="margin:0 0 10px;">' . esc_html__('Cliente encontrado en GIAV y ya vinculado al portal. Puedes abrir su portal directamente desde aqui.', 'casanova-portal') . '</p>';
      }
      echo '<p style="margin:0 0 10px;">';
      echo esc_html(sprintf(__('GIAV #%d', 'casanova-portal'), $preview_client_id));
      if ($preview_user_id > 0) {
        echo ' - ' . esc_html(sprintf(__('WP #%d', 'casanova-portal'), $preview_user_id));
      }
      if ($preview_email !== '') {
        echo ' - ' . esc_html($preview_email);
      }
      if ($preview_user_login !== '') {
        echo ' - ' . esc_html($preview_user_login);
      }
      echo '</p>';
      echo '<p style="margin:0;">';
      echo '<a class="button button-primary" href="' . esc_url($preview_start_url) . '">' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</a>';
      if ($preview_is_active) {
        echo ' <span class="description" style="margin-left:8px;">' . esc_html__('Cliente visto ahora mismo', 'casanova-portal') . '</span>';
      }
      echo '</p>';
      echo '</div>';
    }

    if (empty($clients) && empty($preview_target)) {
      $message = ($search !== '')
        ? __('No hemos encontrado clientes con portal para esa busqueda.', 'casanova-portal')
        : __('Todavia no hay clientes con portal listados.', 'casanova-portal');

      echo '<p>' . esc_html($message) . '</p>';
      echo '</div>';
      return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Cliente', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Email', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Usuario', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('IDs', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Accion', 'casanova-portal') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($clients as $client_user) {
      $user_id = (int) $client_user->ID;
      $client_id = (int) get_user_meta($user_id, 'casanova_idcliente', true);
      $display_name = trim((string) $client_user->display_name);
      if ($display_name === '') {
        $display_name = trim((string) $client_user->user_login);
      }

      $start_url = casanova_portal_start_impersonation_url($user_id);
      $is_active = (($active_user_id > 0 && $active_user_id === $user_id) || ($active_client_id > 0 && $active_client_id === $client_id));

      echo '<tr' . ($is_active ? ' class="active"' : '') . '>';
      echo '<td><strong>' . esc_html($display_name) . '</strong>';
      if ($is_active) {
        echo '<div class="description">' . esc_html__('Cliente visto ahora mismo', 'casanova-portal') . '</div>';
      }
      echo '</td>';
      echo '<td>' . esc_html((string) $client_user->user_email) . '</td>';
      echo '<td>' . esc_html((string) $client_user->user_login) . '</td>';
      echo '<td><div>WP #' . (int) $user_id . '</div><div>GIAV #' . (int) $client_id . '</div></td>';
      echo '<td><a class="button button-primary" href="' . esc_url($start_url) . '">' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</a></td>';
      echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    if ($search === '') {
      echo '<p class="description" style="margin-top:10px;">' . esc_html__('Mostrando clientes recientes con portal activo. Usa el buscador para afinar la seleccion o introduce un GIAV ID/DNI exacto para previsualizar clientes sin acceso web.', 'casanova-portal') . '</p>';
    }

    echo '</div>';
  }
}

if (!function_exists('casanova_portal_impersonation_payload')) {
  function casanova_portal_impersonation_payload(): array {
    $state = casanova_portal_get_impersonation_state();
    if (empty($state)) {
      return [
        'active' => false,
        'readOnly' => false,
      ];
    }

    return [
      'active' => true,
      'readOnly' => true,
      'adminId' => (int) $state['admin_id'],
      'userId' => (int) $state['impersonating_user_id'],
      'clientId' => (int) $state['impersonated_client_id'],
      'clientName' => (string) $state['client_name'],
      'previewOnly' => !empty($state['preview_only']),
      'exitUrl' => casanova_portal_stop_impersonation_url(
        !empty($state['return_url'])
          ? (string) $state['return_url']
          : casanova_portal_impersonation_admin_page_url()
      ),
      'message' => casanova_portal_impersonation_message(),
    ];
  }
}

if (!function_exists('casanova_portal_render_impersonation_banner')) {
  function casanova_portal_render_impersonation_banner(): string {
    $payload = casanova_portal_impersonation_payload();
    if (empty($payload['active'])) {
      return '';
    }

    $client_name = trim((string) ($payload['clientName'] ?? ''));
    $title = sprintf(
      __('Estás viendo el portal como: %s', 'casanova-portal'),
      $client_name !== '' ? $client_name : __('Cliente', 'casanova-portal')
    );

    $exit_url = (string) ($payload['exitUrl'] ?? '');
    $style = 'display:flex;gap:12px;align-items:center;justify-content:space-between;margin:0 0 20px;';

    $html  = '<div class="casanova-alert casanova-alert--warn casanova-impersonation-banner" style="' . esc_attr($style) . '">';
    $html .= '<strong>' . esc_html($title) . '</strong>';
    if ($exit_url !== '') {
      $html .= '<a class="casanova-btn casanova-btn--ghost" href="' . esc_url($exit_url) . '">' . esc_html__('Salir de vista cliente', 'casanova-portal') . '</a>';
    }
    $html .= '</div>';

    return $html;
  }
}

add_action('admin_post_casanova_start_impersonation', function (): void {
  if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  $target_user_id = isset($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0;
  check_admin_referer('casanova_start_impersonation_' . $target_user_id);

  $target_user = get_userdata($target_user_id);
  $target_client_id = $target_user ? (int) get_user_meta($target_user_id, 'casanova_idcliente', true) : 0;
  if (!$target_user instanceof WP_User || $target_client_id <= 0) {
    wp_die(__('El usuario seleccionado no tiene portal de cliente.', 'casanova-portal'), 400);
  }

  $admin_id = (int) get_current_user_id();
  $return_url = wp_get_referer();
  $return_url = $return_url ? wp_validate_redirect($return_url, admin_url('users.php')) : admin_url('users.php');

  $key = casanova_portal_impersonation_session_key($admin_id);
  if ($key === '') {
    wp_die(__('No se pudo iniciar la vista cliente.', 'casanova-portal'), 500);
  }

  $state = [
    'admin_id' => $admin_id,
    'impersonating_user_id' => $target_user_id,
    'impersonated_client_id' => $target_client_id,
    'client_name' => trim((string) ($target_user->display_name ?: $target_user->user_login)),
    'preview_only' => false,
    'started_at' => current_time('mysql'),
    'ip' => casanova_portal_impersonation_request_ip(),
    'return_url' => esc_url_raw($return_url),
  ];

  set_transient($key, $state, DAY_IN_SECONDS);

  if (function_exists('casanova_portal_log')) {
    casanova_portal_log('impersonation.start', [
      'admin_id' => $admin_id,
      'impersonated_user_id' => $target_user_id,
      'impersonated_client_id' => $target_client_id,
      'preview_only' => false,
      'timestamp' => current_time('mysql'),
      'ip' => $state['ip'],
    ]);
  }

  $portal_url = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/portal-app/');

  wp_safe_redirect($portal_url);
  exit;
});

add_action('admin_post_casanova_start_impersonation_preview', function (): void {
  if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  $target_client_id = isset($_REQUEST['client_id']) ? absint($_REQUEST['client_id']) : 0;
  check_admin_referer('casanova_start_impersonation_preview_' . $target_client_id);

  $client = casanova_portal_impersonation_get_giav_client($target_client_id);
  if (!$client) {
    wp_die(__('El cliente de GIAV no existe o no se pudo cargar.', 'casanova-portal'), 400);
  }

  $linked_user = casanova_portal_impersonation_find_linked_user($target_client_id);
  $linked_user_id = $linked_user instanceof WP_User ? (int) $linked_user->ID : 0;

  $admin_id = (int) get_current_user_id();
  $return_url = wp_get_referer();
  $return_url = $return_url ? wp_validate_redirect($return_url, admin_url('users.php')) : admin_url('users.php');

  $key = casanova_portal_impersonation_session_key($admin_id);
  if ($key === '') {
    wp_die(__('No se pudo iniciar la vista cliente.', 'casanova-portal'), 500);
  }

  $state = [
    'admin_id' => $admin_id,
    'impersonating_user_id' => $linked_user_id,
    'impersonated_client_id' => $target_client_id,
    'client_name' => $linked_user instanceof WP_User
      ? trim((string) ($linked_user->display_name ?: $linked_user->user_login))
      : casanova_portal_impersonation_giav_client_name($client),
    'preview_only' => ($linked_user_id <= 0),
    'started_at' => current_time('mysql'),
    'ip' => casanova_portal_impersonation_request_ip(),
    'return_url' => esc_url_raw($return_url),
  ];

  set_transient($key, $state, DAY_IN_SECONDS);

  if (function_exists('casanova_portal_log')) {
    casanova_portal_log('impersonation.start', [
      'admin_id' => $admin_id,
      'impersonated_user_id' => $linked_user_id,
      'impersonated_client_id' => $target_client_id,
      'preview_only' => !empty($state['preview_only']),
      'timestamp' => current_time('mysql'),
      'ip' => $state['ip'],
    ]);
  }

  $portal_url = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/portal-app/');

  wp_safe_redirect($portal_url);
  exit;
});

add_action('admin_post_casanova_stop_impersonation', function (): void {
  if (!is_user_logged_in()) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  check_admin_referer('casanova_stop_impersonation');

  $state = casanova_portal_get_impersonation_state();
  $admin_id = (int) get_current_user_id();
  $key = casanova_portal_impersonation_session_key($admin_id);

  $default_redirect = !empty($state['return_url'])
    ? (string) $state['return_url']
    : casanova_portal_impersonation_admin_page_url();

  $redirect_to = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';
  if ($redirect_to === '') {
    $redirect_to = $default_redirect;
  }
  $redirect_to = wp_validate_redirect($redirect_to, $default_redirect);
  if (!is_string($redirect_to) || $redirect_to === '') {
    $redirect_to = $default_redirect;
  }

  if ($key !== '') {
    delete_transient($key);
  }

  if (!empty($state) && function_exists('casanova_portal_log')) {
    casanova_portal_log('impersonation.stop', [
      'admin_id' => (int) ($state['admin_id'] ?? $admin_id),
      'impersonated_user_id' => (int) ($state['impersonating_user_id'] ?? 0),
      'impersonated_client_id' => (int) ($state['impersonated_client_id'] ?? 0),
      'preview_only' => !empty($state['preview_only']),
      'timestamp' => current_time('mysql'),
      'ip' => casanova_portal_impersonation_request_ip(),
    ]);
  }

  $redirected = wp_safe_redirect($redirect_to, 302, 'Casanova Portal');
  if (!$redirected) {
    wp_safe_redirect(casanova_portal_impersonation_admin_page_url(), 302, 'Casanova Portal');
  }
  exit;
});

add_filter('rest_pre_dispatch', function ($result, WP_REST_Server $server, WP_REST_Request $request) {
  if (!casanova_portal_is_read_only()) {
    return $result;
  }

  $route = (string) $request->get_route();
  if (strpos($route, '/casanova/v1/') !== 0) {
    return $result;
  }

  $method = strtoupper((string) $request->get_method());
  if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    return $result;
  }

  return new WP_Error(
    'casanova_impersonation_read_only',
    casanova_portal_impersonation_message(),
    ['status' => 403]
  );
}, 10, 3);

add_action('admin_menu', function (): void {
  add_users_page(
    __('Ver portal como cliente', 'casanova-portal'),
    __('Ver portal como cliente', 'casanova-portal'),
    'manage_options',
    'casanova-portal-impersonation',
    'casanova_portal_render_impersonation_admin_page'
  );
});

add_action('manage_users_extra_tablenav', function (string $which): void {
  if ($which !== 'top' || !current_user_can('manage_options')) {
    return;
  }

  echo '<div class="alignleft actions">';
  echo '<a class="button button-primary" href="' . esc_url(casanova_portal_impersonation_admin_page_url()) . '">' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</a>';
  echo '</div>';
}, 10, 1);

add_filter('manage_users_columns', function (array $columns): array {
  if (!current_user_can('manage_options')) {
    return $columns;
  }

  $columns['casanova_portal_impersonate'] = __('Portal cliente', 'casanova-portal');
  return $columns;
});

add_filter('manage_users_custom_column', function (string $output, string $column_name, int $user_id): string {
  if ($column_name !== 'casanova_portal_impersonate' || !current_user_can('manage_options')) {
    return $output;
  }

  $id_cliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($id_cliente <= 0) {
    return '<span class="description">' . esc_html__('Sin portal', 'casanova-portal') . '</span>';
  }

  $button = '<a class="button button-secondary button-small" href="' . esc_url(casanova_portal_start_impersonation_url($user_id)) . '">' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</a>';
  $meta = '<div class="description">GIAV #' . (int) $id_cliente . '</div>';

  return $button . $meta;
}, 10, 3);

add_action('edit_user_profile', function (WP_User $user): void {
  if (!current_user_can('manage_options')) {
    return;
  }

  $id_cliente = (int) get_user_meta((int) $user->ID, 'casanova_idcliente', true);
  if ($id_cliente <= 0) {
    return;
  }

  echo '<h2>' . esc_html__('Portal cliente', 'casanova-portal') . '</h2>';
  echo '<table class="form-table" role="presentation">';
  echo '<tr>';
  echo '<th>' . esc_html__('Vista cliente', 'casanova-portal') . '</th>';
  echo '<td>';
  echo '<p><a class="button button-secondary" href="' . esc_url(casanova_portal_start_impersonation_url((int) $user->ID)) . '">' . esc_html__('Ver portal como cliente', 'casanova-portal') . '</a></p>';
  echo '<p><a class="button button-link" href="' . esc_url(casanova_portal_impersonation_admin_page_url()) . '">' . esc_html__('Elegir otro cliente', 'casanova-portal') . '</a></p>';
  echo '<p class="description">' . esc_html__('Abre el portal usando el contexto real de este cliente, en modo solo lectura.', 'casanova-portal') . '</p>';
  echo '</td>';
  echo '</tr>';
  echo '</table>';
});
