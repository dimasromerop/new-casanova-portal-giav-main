<?php
if (!defined('ABSPATH')) exit;

function casanova_portal_messages_admin_recent_threads(int $limit = 30): array {
  global $wpdb;

  $limit = max(1, min(100, (int) $limit));
  $table = casanova_portal_message_threads_table();
  $sql = $wpdb->prepare(
    "SELECT * FROM {$table} ORDER BY COALESCE(last_message_at, created_at) DESC, id DESC LIMIT %d",
    $limit
  );

  $rows = $wpdb->get_results($sql);
  return is_array($rows) ? $rows : [];
}

function casanova_portal_messages_admin_current_url(array $args = []): string {
  return casanova_portal_messages_admin_url($args);
}

function casanova_portal_messages_admin_render_timeline_item(array $item): string {
  $direction = (string) ($item['direction'] ?? 'agency');
  $author = trim((string) ($item['author'] ?? ''));
  $content = trim((string) ($item['content'] ?? ''));
  $date = trim((string) ($item['date'] ?? ''));
  $origin = trim((string) ($item['origin'] ?? ''));
  $attachments = is_array($item['attachments'] ?? null) ? $item['attachments'] : [];

  $origin_label = '';
  if ($origin === 'giav') $origin_label = 'GIAV';
  if ($origin === 'portal') $origin_label = 'Portal';
  if ($origin === 'admin') $origin_label = 'Admin';

  $card_class = $direction === 'client' ? 'notice notice-warning' : 'notice notice-info';

  $html  = '<div class="' . esc_attr($card_class) . '" style="margin:0 0 12px 0;padding:12px 14px;">';
  $html .= '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">';
  $html .= '<div><strong>' . esc_html($author !== '' ? $author : ($direction === 'client' ? __('Cliente', 'casanova-portal') : 'Casanova Golf')) . '</strong>';
  if ($origin_label !== '') {
    $html .= ' <span class="tag">' . esc_html($origin_label) . '</span>';
  }
  $html .= '</div>';
  $html .= '<div style="color:#646970;">' . esc_html($date !== '' ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($date)) : '') . '</div>';
  $html .= '</div>';
  if ($content !== '') {
    $html .= '<div style="margin-top:8px;white-space:pre-wrap;line-height:1.55;">' . esc_html($content) . '</div>';
  }
  if (!empty($attachments)) {
    $html .= '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">';
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) continue;
      $label = trim((string) ($attachment['name'] ?? __('Adjunto', 'casanova-portal')));
      $size = trim((string) ($attachment['sizeLabel'] ?? ''));
      $download_url = trim((string) ($attachment['downloadUrl'] ?? ''));
      if ($download_url === '') continue;
      $html .= '<a class="button button-secondary" href="' . esc_url($download_url) . '" target="_blank" rel="noopener noreferrer">';
      $html .= esc_html($label);
      if ($size !== '') {
        $html .= ' · ' . esc_html($size);
      }
      $html .= '</a>';
    }
    $html .= '</div>';
  }
  $html .= '</div>';

  return $html;
}

add_action('admin_menu', function (): void {
  add_submenu_page(
    'casanova-payments',
    __('Mensajes', 'casanova-portal'),
    __('Mensajes', 'casanova-portal'),
    'manage_options',
    'casanova-payments-messages',
    'casanova_portal_messages_admin_render_page'
  );
}, 25);

add_action('admin_post_casanova_admin_send_portal_message', function (): void {
  if (!current_user_can('manage_options')) {
    wp_die(__('No autorizado.', 'casanova-portal'), 403);
  }

  $idExpediente = isset($_POST['id_expediente']) ? absint($_POST['id_expediente']) : 0;
  check_admin_referer('casanova_admin_send_portal_message_' . $idExpediente);

  $idCliente = isset($_POST['id_cliente']) ? absint($_POST['id_cliente']) : 0;
  if ($idCliente <= 0 && $idExpediente > 0) {
    $idCliente = casanova_portal_messages_resolve_client_id_for_expediente($idExpediente);
  }

  $body = casanova_portal_messages_sanitize_body((string) ($_POST['body'] ?? ''));
  $attachments_files = casanova_portal_messages_normalize_uploaded_files($_FILES['attachments'] ?? []);
  $redirect = casanova_portal_messages_admin_current_url(['expediente' => $idExpediente]);

  if ($idExpediente <= 0 || $idCliente <= 0) {
    wp_safe_redirect(add_query_arg(['message_error' => 'expediente'], $redirect));
    exit;
  }

  if ($body === '' && empty($attachments_files)) {
    wp_safe_redirect(add_query_arg(['message_error' => 'empty'], $redirect));
    exit;
  }

  $admin = wp_get_current_user();
  $author_name = trim((string) ($admin->display_name ?: $admin->user_login));
  if ($author_name === '') {
    $author_name = 'Casanova Golf';
  }

  $created = casanova_portal_messages_create_message([
    'id_cliente' => $idCliente,
    'id_expediente' => $idExpediente,
    'user_id' => (int) get_current_user_id(),
    'direction' => 'agency',
    'origin' => 'admin',
    'author_name' => $author_name,
    'body' => $body,
    'attachments_files' => $attachments_files,
    'metadata' => [
      'channel' => 'wp-admin',
    ],
  ]);

  if (is_wp_error($created)) {
    wp_safe_redirect(add_query_arg([
      'message_error' => rawurlencode((string) $created->get_error_code()),
    ], $redirect));
    exit;
  }

  wp_safe_redirect(add_query_arg(['message_sent' => '1'], $redirect));
  exit;
});

function casanova_portal_messages_admin_render_page(): void {
  if (!current_user_can('manage_options')) return;

  $selected_expediente = isset($_GET['expediente']) ? absint($_GET['expediente']) : 0;
  $selected_client_id = $selected_expediente > 0
    ? casanova_portal_messages_resolve_client_id_for_expediente($selected_expediente)
    : 0;
  $threads = casanova_portal_messages_admin_recent_threads(30);

  echo '<div class="wrap casanova-admin-wrap">';
  echo '<h1>' . esc_html__('Mensajes', 'casanova-portal') . '</h1>';
  echo '<p class="description casanova-admin-lead">' . esc_html__('Conversaciones propias del portal. GIAV sigue entrando en el timeline, pero aquí gestionas la parte bidireccional y las respuestas del equipo.', 'casanova-portal') . '</p>';

  if (isset($_GET['message_sent'])) {
    echo '<div class="notice notice-success"><p>' . esc_html__('Mensaje enviado.', 'casanova-portal') . '</p></div>';
  }
  if (isset($_GET['message_error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html__('No se pudo enviar el mensaje. Revisa el expediente y el contenido.', 'casanova-portal') . '</p></div>';
  }

  echo '<div class="casanova-admin-grid">';
  echo '<section class="casanova-admin-card">';
  echo '<h2>' . esc_html__('Abrir expediente', 'casanova-portal') . '</h2>';
  echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
  echo '<input type="hidden" name="page" value="casanova-payments-messages" />';
  echo '<p><label for="casanova_messages_expediente"><strong>' . esc_html__('Expediente GIAV', 'casanova-portal') . '</strong></label></p>';
  echo '<input type="number" min="1" class="regular-text" id="casanova_messages_expediente" name="expediente" value="' . esc_attr($selected_expediente > 0 ? (string) $selected_expediente : '') . '" />';
  echo '<p class="description">' . esc_html__('Introduce un ID de expediente para ver la conversación completa y responder desde WordPress.', 'casanova-portal') . '</p>';
  submit_button(__('Abrir conversación', 'casanova-portal'), 'primary', '', false);
  echo '</form>';
  echo '</section>';

  echo '<section class="casanova-admin-card">';
  echo '<h2>' . esc_html__('Hilos recientes del portal', 'casanova-portal') . '</h2>';
  if (empty($threads)) {
    echo '<p>' . esc_html__('Todavía no hay mensajes propios del portal.', 'casanova-portal') . '</p>';
  } else {
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Expediente', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Cliente', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Último mensaje', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Sin leer', 'casanova-portal') . '</th>';
    echo '<th>' . esc_html__('Acción', 'casanova-portal') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($threads as $thread) {
      if (!is_object($thread)) continue;
      $expediente_id = (int) ($thread->id_expediente ?? 0);
      $client_id = (int) ($thread->id_cliente ?? 0);
      $unread_client = casanova_portal_messages_local_unread_for_agency($client_id, $expediente_id);
      $open_url = casanova_portal_messages_admin_current_url(['expediente' => $expediente_id]);

      echo '<tr>';
      echo '<td><strong>' . esc_html(casanova_portal_messages_expediente_label($expediente_id)) . '</strong><br /><span class="description">#' . esc_html((string) $expediente_id) . '</span></td>';
      echo '<td>' . esc_html((string) $client_id) . '</td>';
      echo '<td>' . esc_html((string) ($thread->last_message_preview ?? '')) . '</td>';
      echo '<td>' . ($unread_client > 0 ? '<span class="tag">' . esc_html((string) $unread_client) . '</span>' : '0') . '</td>';
      echo '<td><a class="button button-secondary" href="' . esc_url($open_url) . '">' . esc_html__('Abrir', 'casanova-portal') . '</a></td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
  }
  echo '</section>';
  echo '</div>';

  if ($selected_expediente > 0) {
    echo '<section class="casanova-admin-card" style="margin-top:20px;">';
    echo '<h2>' . esc_html(casanova_portal_messages_expediente_label($selected_expediente)) . '</h2>';

    if ($selected_client_id <= 0) {
      echo '<div class="notice notice-warning inline"><p>' . esc_html__('No se pudo resolver el cliente de este expediente. Si ya existe un hilo local, revisa los datos de GIAV.', 'casanova-portal') . '</p></div>';
    } else {
      casanova_portal_messages_mark_agency_read_for_expediente($selected_client_id, $selected_expediente);

      $items = casanova_portal_messages_timeline_items($selected_client_id, $selected_expediente, 100);
      if (empty($items)) {
        echo '<p>' . esc_html__('Aún no hay mensajes en esta conversación.', 'casanova-portal') . '</p>';
      } else {
        foreach ($items as $item) {
          if (!is_array($item)) continue;
          echo casanova_portal_messages_admin_render_timeline_item($item);
        }
      }

      echo '<hr style="margin:24px 0;" />';
      echo '<h3>' . esc_html__('Responder desde WordPress', 'casanova-portal') . '</h3>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
      echo '<input type="hidden" name="action" value="casanova_admin_send_portal_message" />';
      echo '<input type="hidden" name="id_expediente" value="' . esc_attr((string) $selected_expediente) . '" />';
      echo '<input type="hidden" name="id_cliente" value="' . esc_attr((string) $selected_client_id) . '" />';
      wp_nonce_field('casanova_admin_send_portal_message_' . $selected_expediente);
      echo '<textarea name="body" rows="5" class="large-text" placeholder="' . esc_attr__('Escribe aquí la respuesta para el cliente…', 'casanova-portal') . '"></textarea>';
      echo '<p style="margin-top:10px;"><input type="file" name="attachments[]" multiple accept=".pdf,image/jpeg,image/png,image/webp" /></p>';
      echo '<p class="description">' . esc_html__('Adjuntos básicos: hasta 3 archivos por mensaje, máximo 5 MB por archivo. Formatos: PDF, JPG, PNG y WEBP.', 'casanova-portal') . '</p>';
      echo '<p class="description">' . esc_html__('El cliente lo verá dentro del portal y además recibirá un email con enlace a la conversación.', 'casanova-portal') . '</p>';
      submit_button(__('Enviar mensaje', 'casanova-portal'));
      echo '</form>';
    }

    echo '</section>';
  }

  echo '</div>';
}
