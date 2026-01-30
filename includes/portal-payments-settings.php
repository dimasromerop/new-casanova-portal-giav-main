<?php
if (!defined('ABSPATH')) exit;

/**
 * Ajustes del plugin (Pagos + Portal).
 *
 * - Pagos: % depósito, mínimo, overrides.
 * - Portal: templates legacy por vista (compatibilidad) + menú dinámico (recomendado).
 */

function casanova_payments_get_deposit_percent(int $idExpediente = 0): float {
  // Override por expediente (si existe)
  $overrides_raw = get_option('casanova_deposit_overrides', '');
  if ($idExpediente > 0 && is_string($overrides_raw) && $overrides_raw !== '') {
    $lines = preg_split('/\r\n|\r|\n/', $overrides_raw);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '=') === false) continue;
      [$k,$v] = array_map('trim', explode('=', $line, 2));
      if ((int)$k === $idExpediente) {
        $p = (float) str_replace(',', '.', $v);
        if ($p > 0 && $p <= 100) return $p;
      }
    }
  }

  $p = (float) get_option('casanova_deposit_percent', 10);
  if ($p <= 0) $p = 10;
  if ($p > 100) $p = 100;
  return $p;
}

function casanova_payments_get_deposit_min_amount(): float {
  $m = (float) get_option('casanova_deposit_min_amount', 50);
  if ($m < 0) $m = 0;
  return $m;
}

add_action('admin_menu', function () {
  add_options_page(
    'Casanova Portal',
    'Casanova Portal',
    'manage_options',
    'casanova-payments',
    'casanova_payments_render_settings_page'
  );
});

add_action('admin_init', function () {
  // --- Pagos
  register_setting('casanova_payments', 'casanova_deposit_percent', [
    'type' => 'number',
    'sanitize_callback' => function($v){
      $v = (float) str_replace(',', '.', (string)$v);
      if ($v <= 0) $v = 10;
      if ($v > 100) $v = 100;
      return $v;
    },
    'default' => 10,
  ]);

  register_setting('casanova_payments', 'casanova_deposit_min_amount', [
    'type' => 'number',
    'sanitize_callback' => function($v){
      $v = (float) str_replace(',', '.', (string)$v);
      if ($v < 0) $v = 0;
      return $v;
    },
    'default' => 50,
  ]);

  register_setting('casanova_payments', 'casanova_deposit_overrides', [
    'type' => 'string',
    'sanitize_callback' => function($v){
      $v = trim((string)$v);
      if (strlen($v) > 20000) $v = substr($v, 0, 20000);
      return $v;
    },
    'default' => '',
  ]);

  // GIAV - Forma de pago (Inespay)
  register_setting('casanova_payments', 'casanova_giav_idformapago_inespay', [
    'type' => 'integer',
    'sanitize_callback' => 'absint',
    'default' => 0,
  ]);

  // --- Portal (legacy templates por vista)
  register_setting('casanova_portal', 'casanova_portal_tpl_dashboard', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_expedientes', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_mulligans', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_perfil', ['type' => 'integer', 'sanitize_callback' => 'absint']);

  // --- Menú dinámico (recomendado)
  register_setting('casanova_portal_menu', 'casanova_portal_menu_items', [
    'type' => 'array',
    'sanitize_callback' => 'casanova_portal_sanitize_menu_items',
    'default' => [],
  ]);
});

function casanova_portal_sanitize_menu_items($value): array {
  if (!is_array($value)) return [];

  $out = [];
  foreach ($value as $row) {
    if (!is_array($row)) continue;

    $key = isset($row['key']) ? sanitize_key((string)$row['key']) : '';
    $label = isset($row['label']) ? sanitize_text_field((string)$row['label']) : '';
    $icon = isset($row['icon']) ? sanitize_key((string)$row['icon']) : 'dot';
    $template_id = isset($row['template_id']) ? absint($row['template_id']) : 0;
    $order = isset($row['order']) ? (int)$row['order'] : 100;
    $enabled = isset($row['enabled']) ? (int)!!$row['enabled'] : 0;

    // preserve[]
    $preserve = [];
    if (isset($row['preserve']) && is_array($row['preserve'])) {
      foreach ($row['preserve'] as $qv) {
        $qv = sanitize_key((string)$qv);
        if ($qv) $preserve[] = $qv;
      }
    }

    // Row vacía: se ignora
    if (!$key && !$label) continue;

    if (!$key) continue; // clave obligatoria
    if (!$label) $label = $key;

    $out[] = [
      'key' => $key,
      'label' => $label,
      'icon' => $icon ?: 'dot',
      'template_id' => $template_id,
      'order' => $order,
      'enabled' => $enabled ? 1 : 0,
      'preserve' => array_values(array_unique($preserve)),
    ];
  }

  // Orden estable
  usort($out, function($a, $b){
    return ((int)($a['order'] ?? 100)) <=> ((int)($b['order'] ?? 100));
  });

  // Evita keys duplicadas: última gana
  $by = [];
  foreach ($out as $row) {
    $by[$row['key']] = $row;
  }
  return array_values($by);
}

function casanova_portal_get_bricks_templates(): array {
  $templates = [];
  $posts = get_posts([
    'post_type'      => 'bricks_template',
    'posts_per_page' => 300,
    'post_status'    => ['publish','draft','private'],
    'orderby'        => 'title',
    'order'          => 'ASC',
  ]);
  foreach ($posts as $pp) {
    $templates[(int)$pp->ID] = $pp->post_title ? $pp->post_title : ('Template #' . (int)$pp->ID);
  }
  return $templates;
}

function casanova_payments_render_settings_page(): void {
  if (!current_user_can('manage_options')) return;

  $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'payments';
  if (!in_array($tab, ['payments','portal','menu','links','slots','giav','help'], true)) $tab = 'payments';

  echo '<div class="wrap">';
  echo '<h1>Casanova Portal</h1>';

  $base = admin_url('options-general.php?page=casanova-payments');
  $t_pay = add_query_arg(['tab' => 'payments'], $base);
  $t_por = add_query_arg(['tab' => 'portal'], $base);
  $t_men = add_query_arg(['tab' => 'menu'], $base);
  $t_hlp = add_query_arg(['tab' => 'help'], $base);
  $t_lnk = add_query_arg(['tab' => 'links'], $base);
  $t_slots = add_query_arg(['tab' => 'slots'], $base);
  $t_giav = add_query_arg(['tab' => 'giav'], $base);

  echo '<nav class="nav-tab-wrapper" aria-label="Secciones">';
  echo '<a href="' . esc_url($t_pay) . '" class="nav-tab ' . ($tab==='payments'?'nav-tab-active':'') . '">Pagos</a>';
  echo '<a href="' . esc_url($t_por) . '" class="nav-tab ' . ($tab==='portal'?'nav-tab-active':'') . '">Portal</a>';
  echo '<a href="' . esc_url($t_men) . '" class="nav-tab ' . ($tab==='menu'?'nav-tab-active':'') . '">Menú</a>';
  echo '<a href="' . esc_url($t_lnk) . '" class="nav-tab ' . ($tab==='links'?'nav-tab-active':'') . '">Payment Links</a>';
  echo '<a href="' . esc_url($t_slots) . '" class="nav-tab ' . ($tab==='slots'?'nav-tab-active':'') . '">Group Slots</a>';
  echo '<a href="' . esc_url($t_giav) . '" class="nav-tab ' . ($tab==='giav'?'nav-tab-active':'') . '">GIAV</a>';
  echo '<a href="' . esc_url($t_hlp) . '" class="nav-tab ' . ($tab==='help'?'nav-tab-active':'') . '">Ayuda</a>';
  echo '</nav>';

  if ($tab === 'payments') {
    $p  = get_option('casanova_deposit_percent', 10);
    $m  = get_option('casanova_deposit_min_amount', 50);
    $ov = get_option('casanova_deposit_overrides', '');
    $idfp_inespay = (int) get_option('casanova_giav_idformapago_inespay', 0);
    if (!is_string($ov)) $ov = '';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_payments');

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="casanova_deposit_percent">Depósito (%)</label></th>';
    echo '<td><input name="casanova_deposit_percent" id="casanova_deposit_percent" type="number" step="0.01" min="0" max="100" value="' . esc_attr($p) . '" /> <p class="description">Por defecto 10%.</p></td></tr>';

    echo '<tr><th scope="row"><label for="casanova_deposit_min_amount">Depósito mínimo (€)</label></th>';
    echo '<td><input name="casanova_deposit_min_amount" id="casanova_deposit_min_amount" type="number" step="0.01" min="0" value="' . esc_attr($m) . '" /> <p class="description">Por defecto 50€.</p></td></tr>';

    echo '<tr><th scope="row"><label for="casanova_deposit_overrides">Overrides por expediente</label></th>';
    echo '<td><textarea name="casanova_deposit_overrides" id="casanova_deposit_overrides" rows="8" cols="60" class="large-text code">' . esc_textarea($ov) . '</textarea>';
    echo '<p class="description">Opcional. Una línea por expediente: <code>2553848=15</code> (porcentaje).</p></td></tr>';

    echo '<tr><th scope="row"><label for="casanova_giav_idformapago_inespay">GIAV: ID forma de pago (Inespay)</label></th>';
    echo '<td><input name="casanova_giav_idformapago_inespay" id="casanova_giav_idformapago_inespay" type="number" min="0" step="1" value="' . esc_attr($idfp_inespay) . '" />';
    echo '<p class="description">Obligatorio para que el webhook de Inespay registre el cobro en GIAV. Alternativa: define <code>CASANOVA_GIAV_IDFORMAPAGO_INESPAY</code> en <code>wp-config.php</code> (tiene prioridad).</p></td></tr>';

    echo '</table>';
    submit_button();
    echo '</form>';

    // Helper: find GIAV Expediente IDs for deposit overrides (GIAV uses Id, not Codigo).
    $qexp = isset($_GET['giav_expediente_q']) ? sanitize_text_field((string) $_GET['giav_expediente_q']) : '';

    echo '<hr />';
    echo '<h2>Buscar Expediente en GIAV (para overrides)</h2>';
    echo '<p class="description">GIAV distingue <strong>ID</strong> (numérico interno) y <strong>Código</strong>. El override de depósito usa el <strong>ID</strong> (ej.: <code>2553848=15</code>). Aquí puedes buscar por código (solo números) o por texto del título.</p>';

    // Search form (GET) to avoid mixing with settings POST.
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="casanova-payments" />';
    echo '<input type="hidden" name="tab" value="payments" />';
    echo '<p>';
    echo '<label for="giav_expediente_q" class="screen-reader-text">Buscar expediente</label>';
    echo '<input name="giav_expediente_q" id="giav_expediente_q" type="text" class="regular-text" placeholder="ID o código (números) o texto del título" value="' . esc_attr($qexp) . '" /> ';
    submit_button('Buscar', 'secondary', 'submit', false);
    echo '</p>';
    echo '</form>';

    if ($qexp !== '') {
      if (!function_exists('casanova_giav_expediente_search_simple')) {
        echo '<div class="notice notice-error"><p>No está disponible la herramienta GIAV (falta <code>casanova_giav_expediente_search_simple</code>).</p></div>';
      } else {
        $items = casanova_giav_expediente_search_simple($qexp, 50, 0);
        if (is_wp_error($items)) {
          echo '<div class="notice notice-error"><p>Error GIAV: ' . esc_html($items->get_error_message()) . '</p></div>';
        } else {
          if (empty($items)) {
            echo '<div class="notice notice-warning"><p>No se encontraron expedientes para <code>' . esc_html($qexp) . '</code>.</p></div>';
          } else {
            echo '<table class="widefat striped" style="max-width: 1100px;">';
            echo '<thead><tr>';
            echo '<th>ID (GIAV)</th><th>Código</th><th>Título</th><th>Fechas</th><th>Destino</th>';
            echo '</tr></thead><tbody>';
            foreach ($items as $it) {
              $id = isset($it->Id) ? (int) $it->Id : 0;
              $codigo = isset($it->Codigo) ? (string) $it->Codigo : '';
              $titulo = isset($it->Titulo) ? (string) $it->Titulo : '';
              $dest = isset($it->Destino) ? (string) $it->Destino : '';
              $fd = isset($it->FechaDesde) ? (string) $it->FechaDesde : '';
              $fh = isset($it->FechaHasta) ? (string) $it->FechaHasta : '';
              $fechas = '';
              if ($fd || $fh) {
                $fechas = esc_html(substr($fd, 0, 10) . ' → ' . substr($fh, 0, 10));
              }
              echo '<tr>';
              echo '<td><code>' . (int) $id . '</code></td>';
              echo '<td>' . esc_html($codigo) . '</td>';
              echo '<td>' . esc_html($titulo) . '</td>';
              echo '<td>' . $fechas . '</td>';
              echo '<td>' . esc_html($dest) . '</td>';
              echo '</tr>';
            }
            echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';
            echo '<p class="description">Copia el <strong>ID (GIAV)</strong> para el override: <code>ID=porcentaje</code>.</p>';
          }
        }
      }
    }

  } elseif ($tab === 'portal') {
    // Legacy templates por vista (compatibilidad)
    $tpl_dashboard   = (int) get_option('casanova_portal_tpl_dashboard', 0);
    $tpl_expedientes = (int) get_option('casanova_portal_tpl_expedientes', 0);
    $tpl_mulligans   = (int) get_option('casanova_portal_tpl_mulligans', 0);
    $tpl_perfil      = (int) get_option('casanova_portal_tpl_perfil', 0);

    $templates = casanova_portal_get_bricks_templates();

    $render_select = function(string $name, int $current) use ($templates) {
      echo '<select name="' . esc_attr($name) . '" class="regular-text">';
      echo '<option value="0">— No asignado —</option>';
      foreach ($templates as $id => $title) {
        $sel = selected($current, $id, false);
        echo '<option value="' . (int)$id . '" ' . $sel . '>' . esc_html($title) . ' (#' . (int)$id . ')</option>';
      }
      echo '</select>';
    };

    echo '<p class="description">Compatibilidad: asignación directa de templates por vista. Si usas el Menú dinámico, lo normal es asignar templates ahí y dejar esto en blanco.</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_portal');

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Dashboard</th><td>';
    $render_select('casanova_portal_tpl_dashboard', $tpl_dashboard);
    echo '<p class="description">Vista: <code>?view=dashboard</code></p></td></tr>';

    echo '<tr><th scope="row">Expedientes</th><td>';
    $render_select('casanova_portal_tpl_expedientes', $tpl_expedientes);
    echo '<p class="description">Vista: <code>?view=expedientes</code></p></td></tr>';

    echo '<tr><th scope="row">Mulligans</th><td>';
    $render_select('casanova_portal_tpl_mulligans', $tpl_mulligans);
    echo '<p class="description">Vista: <code>?view=mulligans</code></p></td></tr>';

    echo '<tr><th scope="row">Perfil</th><td>';
    $render_select('casanova_portal_tpl_perfil', $tpl_perfil);
    echo '<p class="description">Vista: <code>?view=perfil</code></p></td></tr>';

    echo '</table>';
    submit_button('Guardar');
    echo '</form>';

  } elseif ($tab === 'menu') {
    // Menú dinámico
    $templates = casanova_portal_get_bricks_templates();
    $items = get_option('casanova_portal_menu_items', []);
    if (!is_array($items)) $items = [];

    // Si no hay items, pre-rellena con los defaults (mismo orden que el router)
    if (empty($items)) {
      $items = [
        ['key'=>'dashboard','label'=>'Principal','icon'=>'home','template_id'=>(int)get_option('casanova_portal_tpl_dashboard',0),'order'=>10,'enabled'=>1,'preserve'=>[]],
        ['key'=>'expedientes','label'=>'Reservas','icon'=>'briefcase','template_id'=>(int)get_option('casanova_portal_tpl_expedientes',0),'order'=>20,'enabled'=>1,'preserve'=>['expediente']],
        ['key'=>'mulligans','label'=>'Mulligans','icon'=>'flag','template_id'=>(int)get_option('casanova_portal_tpl_mulligans',0),'order'=>30,'enabled'=>1,'preserve'=>[]],
        ['key'=>'mensajes','label'=>'Mensajes','icon'=>'message','template_id'=>(int)get_option('casanova_portal_tpl_mensajes',0),'order'=>35,'enabled'=>1,'preserve'=>[]],
        ['key'=>'perfil','label'=>'Mis datos','icon'=>'user','template_id'=>(int)get_option('casanova_portal_tpl_perfil',0),'order'=>40,'enabled'=>1,'preserve'=>[]],
      ];
    }

    $icon_choices = [
      'home' => 'Home',
      'briefcase' => 'Maletín',
      'flag' => 'Bandera',
      'user' => 'Usuario',
      'message' => 'Mensajes',
      'receipt' => 'Factura',
      'ticket' => 'Bono/Voucher',
      'help' => 'Soporte',
      'dot' => 'Punto',
    ];

    echo '<p class="description">Define los elementos del menú lateral. Cada elemento es una sección con su <code>?view=</code> y un template de Bricks asociado.</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_portal_menu');

    echo '<style>
      .casanova-menu-table th, .casanova-menu-table td { vertical-align: top; }
      .casanova-menu-table input[type=text] { width: 100%; }
      .casanova-menu-table .small { width: 90px; }
      .casanova-menu-actions { margin-top: 10px; display:flex; gap:10px; align-items:center; }
      .casanova-menu-hint { color:#666; }
    </style>';

    echo '<table class="widefat striped casanova-menu-table" id="casanova-menu-table">';
    echo '<thead><tr>';
    echo '<th style="width:110px">Clave (view)</th>';
    echo '<th style="width:180px">Etiqueta</th>';
    echo '<th style="width:140px">Icono</th>';
    echo '<th>Template Bricks</th>';
    echo '<th style="width:90px">Orden</th>';
    echo '<th style="width:140px">Preservar</th>';
    echo '<th style="width:90px">Activo</th>';
    echo '<th style="width:70px"></th>';
    echo '</tr></thead><tbody>';

    $row_idx = 0;
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $key = sanitize_key((string)($it['key'] ?? ''));
      $label = sanitize_text_field((string)($it['label'] ?? ''));
      $icon = sanitize_key((string)($it['icon'] ?? 'dot'));
      $template_id = absint($it['template_id'] ?? 0);
      $order = (int)($it['order'] ?? 100);
      $enabled = !empty($it['enabled']) ? 1 : 0;
      $preserve = $it['preserve'] ?? [];
      if (!is_array($preserve)) $preserve = [];

      echo '<tr>';
      echo '<td><input type="text" name="casanova_portal_menu_items['.$row_idx.'][key]" value="'.esc_attr($key).'" placeholder="dashboard" /></td>';
      echo '<td><input type="text" name="casanova_portal_menu_items['.$row_idx.'][label]" value="'.esc_attr($label).'" placeholder="Principal" /></td>';

      echo '<td><select name="casanova_portal_menu_items['.$row_idx.'][icon]">';
      foreach ($icon_choices as $k => $lbl) {
        echo '<option value="'.esc_attr($k).'" '.selected($icon, $k, false).'>'.esc_html($lbl).'</option>';
      }
      echo '</select></td>';

      echo '<td><select name="casanova_portal_menu_items['.$row_idx.'][template_id]" class="regular-text">';
      echo '<option value="0">— (fallback del plugin) —</option>';
      foreach ($templates as $tid => $title) {
        echo '<option value="'.(int)$tid.'" '.selected($template_id, (int)$tid, false).'>'.esc_html($title).' (#'.(int)$tid.')</option>';
      }
      echo '</select></td>';

      echo '<td><input class="small" type="number" name="casanova_portal_menu_items['.$row_idx.'][order]" value="'.esc_attr($order).'" /></td>';

      // preserve checkboxes
      $preserve_options = ['expediente' => 'expediente'];
      echo '<td>';
      foreach ($preserve_options as $pv => $pl) {
        $checked = in_array($pv, $preserve, true) ? 'checked' : '';
        echo '<label style="display:block"><input type="checkbox" name="casanova_portal_menu_items['.$row_idx.'][preserve][]" value="'.esc_attr($pv).'" '.$checked.' /> '.esc_html($pl).'</label>';
      }
      echo '</td>';

      echo '<td style="text-align:center"><input type="checkbox" name="casanova_portal_menu_items['.$row_idx.'][enabled]" value="1" '.checked($enabled, 1, false).' /></td>';
      echo '<td><button type="button" class="button link-delete casanova-row-del">Quitar</button></td>';
      echo '</tr>';

      $row_idx++;
    }

    
    
    echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';

    echo '<div class="casanova-menu-actions">';
    echo '<button type="button" class="button" id="casanova-menu-add">Añadir item</button>';
    echo '<span class="casanova-menu-hint">Tip: Clave = lo que irá en <code>?view=</code>. Ej: <code>facturas</code>, <code>vouchers</code>, <code>soporte</code>.</span>';
    echo '</div>';

    // Template de fila para JS
    echo '<template id="casanova-menu-row-template">';
    echo '<tr>';
    echo '<td><input type="text" name="__NAME__[key]" value="" placeholder="facturas" /></td>';
    echo '<td><input type="text" name="__NAME__[label]" value="" placeholder="Facturas" /></td>';
    echo '<td><select name="__NAME__[icon]">';
    foreach ($icon_choices as $k => $lbl) {
      echo '<option value="'.esc_attr($k).'">'.esc_html($lbl).'</option>';
    }
    echo '</select></td>';

    echo '<td><select name="__NAME__[template_id]" class="regular-text">';
    echo '<option value="0">— (fallback del plugin) —</option>';
    foreach ($templates as $tid => $title) {
      echo '<option value="'.(int)$tid.'">'.esc_html($title).' (#'.(int)$tid.')</option>';
    }
    echo '</select></td>';

    echo '<td><input class="small" type="number" name="__NAME__[order]" value="100" /></td>';
    echo '<td><label style="display:block"><input type="checkbox" name="__NAME__[preserve][]" value="expediente" /> expediente</label></td>';
    echo '<td style="text-align:center"><input type="checkbox" name="__NAME__[enabled]" value="1" checked /></td>';
    echo '<td><button type="button" class="button link-delete casanova-row-del">Quitar</button></td>';
    echo '</tr>';
    echo '</template>';

    echo '<script>
      (function(){
        const table = document.getElementById("casanova-menu-table");
        const addBtn = document.getElementById("casanova-menu-add");
        const tpl = document.getElementById("casanova-menu-row-template");
        if (!table || !addBtn || !tpl) return;

        function nextIndex(){
          const rows = table.querySelectorAll("tbody tr");
          return rows.length;
        }

        function wireDelete(btn){
          btn.addEventListener("click", function(){
            const tr = btn.closest("tr");
            if (tr) tr.remove();
          });
        }

        table.querySelectorAll(".casanova-row-del").forEach(wireDelete);

        addBtn.addEventListener("click", function(){
          const idx = nextIndex();
          const html = tpl.innerHTML.replaceAll("__NAME__", `casanova_portal_menu_items[${idx}]`);
          const tbody = table.querySelector("tbody");
          const tmp = document.createElement("tbody");
          tmp.innerHTML = html;
          const tr = tmp.querySelector("tr");
          if (tr) {
            tbody.appendChild(tr);
            const del = tr.querySelector(".casanova-row-del");
            if (del) wireDelete(del);
          }
        });
      })();
    </script>';

    submit_button('Guardar menú');
    echo '</form>';

  } elseif ($tab === 'links') {
    $token = isset($_GET['token']) ? sanitize_text_field((string) $_GET['token']) : '';
    $created = isset($_GET['link_created']) && $_GET['link_created'] === '1';
    $error = isset($_GET['link_error']) ? sanitize_key((string) $_GET['link_error']) : '';
    $group_created = isset($_GET['group_created']) && $_GET['group_created'] === '1';
    $group_token = isset($_GET['group_token']) ? sanitize_text_field((string) $_GET['group_token']) : '';
    $group_error = isset($_GET['group_error']) ? sanitize_key((string) $_GET['group_error']) : '';

    if ($created && $token !== '') {
      $url = function_exists('casanova_payment_link_url') ? casanova_payment_link_url($token) : '';
      echo '<div class="notice notice-success"><p><strong>Link creado.</strong> ' . esc_html($token) . '</p>';
      if ($url) {
        echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Abrir enlace</a> | <code>' . esc_html($url) . '</code></p>';
      }
      echo '</div>';
    } elseif ($error) {
      $msg = 'No se pudo crear el link.';
      if ($error === 'amount') $msg = 'Importe invalido.';
      if ($error === 'expediente') $msg = 'Expediente invalido.';
      if ($error === 'missing') $msg = 'Modulo de payment links no disponible.';
      if ($error === 'create') $msg = 'Error al crear el link.';
      echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    }

    if ($group_created && $group_token !== '') {
      $url = function_exists('casanova_group_pay_url') ? casanova_group_pay_url($group_token) : '';
      echo '<div class="notice notice-success"><p><strong>Token de grupo creado.</strong> ' . esc_html($group_token) . '</p>';
      if ($url) {
        echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Abrir enlace</a> | <code>' . esc_html($url) . '</code></p>';
      }
      echo '</div>';
    } elseif ($group_error) {
      $msg = 'No se pudo crear el token de grupo.';
      if ($group_error === 'expediente') $msg = 'Expediente invalido.';
      if ($group_error === 'missing') $msg = 'Modulo de grupos no disponible.';
      if ($group_error === 'create') $msg = 'Error al crear el token de grupo.';
      echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    }

    echo '<h2>Crear Payment Link</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('casanova_create_payment_link');
    echo '<input type="hidden" name="action" value="casanova_create_payment_link" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="id_expediente">ID Expediente (GIAV)</label></th>';
    echo '<td><input name="id_expediente" id="id_expediente" type="number" min="1" step="1" required class="regular-text" /></td></tr>';

    echo '<tr><th scope="row"><label for="scope">Scope</label></th>';
    echo '<td><select name="scope" id="scope">';
    echo '<option value="group_total">group_total (total pendiente)</option>';
    echo '<option value="group_partial">group_partial</option>';
    echo '<option value="passenger_share">passenger_share</option>';
    echo '<option value="custom_amount">custom_amount</option>';
    echo '<option value="slot_base">slot_base (plazas base)</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="amount_authorized">Importe autorizado (EUR)</label></th>';
    echo '<td><input name="amount_authorized" id="amount_authorized" type="text" class="regular-text" placeholder="Ej: 300.00" />';
    echo '<p class="description">Obligatorio si scope no es <code>group_total</code>.</p></td></tr>';

    echo '<tr><th scope="row"><label for="currency">Moneda</label></th>';
    echo '<td><input name="currency" id="currency" type="text" value="EUR" class="small-text" /></td></tr>';

    echo '<tr><th scope="row"><label for="expires_at">Caduca el (opcional)</label></th>';
    echo '<td><input name="expires_at" id="expires_at" type="date" class="regular-text" />';
    echo '<p class="description">Se aplica a fin de dia (23:59:59).</p></td></tr>';

    echo '<tr><th scope="row"><label for="id_reserva_pq">ID Reserva PQ (opcional)</label></th>';
    echo '<td><input name="id_reserva_pq" id="id_reserva_pq" type="number" min="0" step="1" class="regular-text" /></td></tr>';

    echo '<tr><th scope="row"><label for="id_pasajero">ID Pasajero (opcional)</label></th>';
    echo '<td><input name="id_pasajero" id="id_pasajero" type="number" min="0" step="1" class="regular-text" /></td></tr>';
    echo '</table>';

    submit_button('Crear link');
    echo '</form>';

    echo '<script>
      (function(){
        const scope = document.getElementById("scope");
        const amount = document.getElementById("amount_authorized");
        if (!scope || !amount) return;
        function toggle(){
          const v = scope.value;
          const needs = (v !== "group_total");
          amount.disabled = !needs;
          amount.required = needs;
          if (!needs) amount.value = "";
        }
        scope.addEventListener("change", toggle);
        toggle();
      })();
    </script>';

    echo '<hr />';
    echo '<h2>Crear token reusable de grupo</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('casanova_create_group_token');
    echo '<input type="hidden" name="action" value="casanova_create_group_token" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="group_id_expediente">ID Expediente (GIAV)</label></th>';
    echo '<td><input name="group_id_expediente" id="group_id_expediente" type="number" min="1" step="1" required class="regular-text" /></td></tr>';

    echo '<tr><th scope="row"><label for="group_id_reserva_pq">ID Reserva PQ (opcional)</label></th>';
    echo '<td><input name="group_id_reserva_pq" id="group_id_reserva_pq" type="number" min="0" step="1" class="regular-text" /></td></tr>';

    echo '<tr><th scope="row"><label for="group_expires_at">Caduca el (opcional)</label></th>';
    echo '<td><input name="group_expires_at" id="group_expires_at" type="date" class="regular-text" /></td></tr>';
    echo '</table>';
    submit_button('Crear token de grupo');
    echo '</form>';

    echo '<hr />';
    echo '<h2>Ultimos links</h2>';

    $deleted = isset($_GET['link_deleted']) ? absint($_GET['link_deleted']) : -1;
    if ($deleted >= 0) {
      if ($deleted > 0) {
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Links eliminados: %d', $deleted)) . '</p></div>';
      } else {
        echo '<div class="notice notice-info"><p>' . esc_html('No se eliminaron links.') . '</p></div>';
      }
    }


    if (function_exists('casanova_payment_links_table')) {
      global $wpdb;
      $table = casanova_payment_links_table();
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if ($exists === $table) {
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 20");
        if (!empty($rows)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field('casanova_delete_payment_links');
          echo '<input type="hidden" name="action" value="casanova_delete_payment_links" />';
          echo '<div style="margin:8px 0">';
          echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Eliminar links seleccionados?\')">Eliminar seleccionados</button>';
          echo '</div>';
          echo '<table class="widefat striped">';
          echo '<thead><tr><th style="width:26px"><input type="checkbox" id="casanova_links_checkall" /></th><th>ID</th><th>Expediente</th><th>Scope</th><th>Plazas</th><th>Importe</th><th>Status</th><th>Token</th><th>URL</th><th>Caduca</th><th>Creado</th><th>Acciones</th></tr></thead><tbody>';
          foreach ($rows as $r) {
            $token = (string)($r->token ?? '');
            $url = ($token && function_exists('casanova_payment_link_url')) ? casanova_payment_link_url($token) : '';
            $slots_count = '';
            if ((string)($r->scope ?? '') === 'slot_base') {
              $meta = [];
              $raw = (string)($r->metadata ?? '');
              if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $meta = $decoded;
              }
              $slots_count = isset($meta['slots_count']) ? (string)$meta['slots_count'] : '';
            }
            echo '<tr>';
            echo '<td><input type="checkbox" name="link_ids[]" value="' . esc_attr((string)$r->id) . '" /></td>';
            echo '<td>' . esc_html((string)$r->id) . '</td>';
            echo '<td>' . esc_html((string)$r->id_expediente) . '</td>';
            echo '<td>' . esc_html((string)$r->scope) . '</td>';
            echo '<td>' . esc_html($slots_count) . '</td>';
            echo '<td>' . esc_html(number_format((float)$r->amount_authorized, 2, ',', '.')) . ' ' . esc_html((string)$r->currency) . '</td>';
            echo '<td>' . esc_html((string)$r->status) . '</td>';
            echo '<td><code>' . esc_html($token) . '</code></td>';
            echo '<td>' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Abrir</a>' : '') . '</td>';
            echo '<td>' . esc_html((string)($r->expires_at ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($r->created_at ?? '')) . '</td>';
            $del_url = wp_nonce_url(add_query_arg(['action'=>'casanova_delete_payment_links','id'=>(int)$r->id], admin_url('admin-post.php')), 'casanova_delete_payment_links');
            echo '<td><a href="' . esc_url($del_url) . '" class="button-link-delete" onclick="return confirm(\'Eliminar este link?\')">Eliminar</a></td>';
            echo '</tr>';
          }
          echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';
        } else {
          echo '<p class="description">No hay links creados.</p>';
        }
      } else {
        echo '<p class="description">Tabla de payment links no disponible.</p>';
      }
    } else {
      echo '<p class="description">Modulo de payment links no disponible.</p>';
    }

    echo '<hr />';
    echo '<h2>Tokens de grupo recientes</h2>';
    if (function_exists('casanova_group_pay_tokens_table')) {
      global $wpdb;
      $gt = casanova_group_pay_tokens_table();
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $gt));
      if ($exists === $gt) {
        $rows = $wpdb->get_results("SELECT * FROM {$gt} ORDER BY id DESC LIMIT 20");
        if (!empty($rows)) {
          echo '<table class="widefat striped">';
          echo '<thead><tr><th style="width:26px"><input type="checkbox" id="casanova_links_checkall" /></th><th>ID</th><th>Expediente</th><th>PQ</th><th>Status</th><th>Token</th><th>URL</th><th>Caduca</th><th>Creado</th><th>Acciones</th></tr></thead><tbody>';
          foreach ($rows as $r) {
            $token = (string)($r->token ?? '');
            $url = ($token && function_exists('casanova_group_pay_url')) ? casanova_group_pay_url($token) : '';
            echo '<tr>';
            echo '<td><input type="checkbox" name="link_ids[]" value="' . esc_attr((string)$r->id) . '" /></td>';
            echo '<td>' . esc_html((string)$r->id) . '</td>';
            echo '<td>' . esc_html((string)$r->id_expediente) . '</td>';
            echo '<td>' . esc_html((string)($r->id_reserva_pq ?? '')) . '</td>';
            echo '<td>' . esc_html((string)$r->status) . '</td>';
            echo '<td><code>' . esc_html($token) . '</code></td>';
            echo '<td>' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Abrir</a>' : '') . '</td>';
            echo '<td>' . esc_html((string)($r->expires_at ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($r->created_at ?? '')) . '</td>';
            $del_url = wp_nonce_url(add_query_arg(['action'=>'casanova_delete_payment_links','id'=>(int)$r->id], admin_url('admin-post.php')), 'casanova_delete_payment_links');
            echo '<td><a href="' . esc_url($del_url) . '" class="button-link-delete" onclick="return confirm(\'Eliminar este link?\')">Eliminar</a></td>';
            echo '</tr>';
          }
          echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';
        } else {
          echo '<p class="description">No hay tokens de grupo.</p>';
        }
      } else {
        echo '<p class="description">Tabla de tokens de grupo no disponible.</p>';
      }
    } else {
      echo '<p class="description">Modulo de grupos no disponible.</p>';
    }

  } elseif ($tab === 'slots') {
    $idExpediente = isset($_GET['expediente_id']) ? absint($_GET['expediente_id']) : 0;
    $idReservaPQ = isset($_GET['reserva_pq']) ? absint($_GET['reserva_pq']) : 0;

    echo '<h2>Group Slots</h2>';
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="casanova-payments" />';
    echo '<input type="hidden" name="tab" value="slots" />';
    echo '<input type="number" name="expediente_id" value="' . esc_attr((string)$idExpediente) . '" placeholder="ID Expediente" style="min-width:160px;" /> ';
    echo '<input type="number" name="reserva_pq" value="' . esc_attr((string)$idReservaPQ) . '" placeholder="ID Reserva PQ (opcional)" style="min-width:180px;" /> ';
    submit_button('Ver', 'secondary', '', false);
    echo '</form>';

    if ($idExpediente > 0 && function_exists('casanova_group_slots_table')) {
      global $wpdb;
      $table = casanova_group_slots_table();
      $where = $idReservaPQ > 0
        ? $wpdb->prepare("WHERE id_expediente=%d AND id_reserva_pq=%d", $idExpediente, $idReservaPQ)
        : $wpdb->prepare("WHERE id_expediente=%d", $idExpediente);
      $slots = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY slot_index ASC");

      echo '<h3>Slots</h3>';
      if (!empty($slots)) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th style="width:26px"><input type="checkbox" id="casanova_links_checkall" /></th><th>ID</th><th>Slot</th><th>Base Due</th><th>Base Paid</th><th>Status</th><th>PQ</th></tr></thead><tbody>';
        foreach ($slots as $s) {
          echo '<tr>';
          echo '<td>' . esc_html((string)$s->id) . '</td>';
          echo '<td>' . esc_html((string)$s->slot_index) . '</td>';
          echo '<td>' . esc_html(number_format((float)$s->base_due, 2, ',', '.')) . '</td>';
          echo '<td>' . esc_html(number_format((float)$s->base_paid, 2, ',', '.')) . '</td>';
          echo '<td>' . esc_html((string)$s->status) . '</td>';
          echo '<td>' . esc_html((string)($s->id_reserva_pq ?? '')) . '</td>';
          echo '</tr>';
        }
        echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';
      } else {
        echo '<p class="description">No hay slots para este expediente.</p>';
      }

      if (function_exists('casanova_charges_table')) {
        $ct = casanova_charges_table();
        $charges = $wpdb->get_results("SELECT * FROM {$ct} {$where} ORDER BY id DESC");
        echo '<h3>Charges</h3>';
        if (!empty($charges)) {
          echo '<table class="widefat striped">';
          echo '<thead><tr><th style="width:26px"><input type="checkbox" id="casanova_links_checkall" /></th><th>ID</th><th>Titulo</th><th>Tipo</th><th>Due</th><th>Paid</th><th>Status</th><th>Slot</th><th>PQ</th></tr></thead><tbody>';
          foreach ($charges as $c) {
            echo '<tr>';
            echo '<td>' . esc_html((string)$c->id) . '</td>';
            echo '<td>' . esc_html((string)$c->title) . '</td>';
            echo '<td>' . esc_html((string)($c->type ?? '')) . '</td>';
            echo '<td>' . esc_html(number_format((float)$c->amount_due, 2, ',', '.')) . '</td>';
            echo '<td>' . esc_html(number_format((float)$c->amount_paid, 2, ',', '.')) . '</td>';
            echo '<td>' . esc_html((string)$c->status) . '</td>';
            echo '<td>' . esc_html((string)($c->slot_id ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($c->id_reserva_pq ?? '')) . '</td>';
            echo '</tr>';
          }
          echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';
        } else {
          echo '<p class="description">No hay charges para este expediente.</p>';
        }
      }
    } else {
      echo '<p class="description">Indica un ID de expediente para ver slots.</p>';
    }

  } elseif ($tab === 'giav') {
    // Admin-only: render catalogs directly (no REST auth/nonce needed).
    $section = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : 'payment-methods';
    $q = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
    $include_disabled = isset($_GET['include_disabled']) ? (bool) $_GET['include_disabled'] : true;
    $include_hidden = isset($_GET['include_hidden']) ? (bool) $_GET['include_hidden'] : true;

    echo '<div style="margin-top:14px;">';
    echo '<p class="description">Herramientas internas para sacar IDs desde GIAV (formas de pago y custom fields) sin pelearse con la autenticación del REST.</p>';

    $base_giav = add_query_arg(['tab' => 'giav'], $base);
    $link_pay = add_query_arg(['section' => 'payment-methods'], $base_giav);
    $link_exp = add_query_arg(['section' => 'custom-expediente'], $base_giav);
    $link_res = add_query_arg(['section' => 'custom-reserva'], $base_giav);

    echo '<p>';
    echo '<a class="button ' . ($section==='payment-methods'?'button-primary':'') . '" href="' . esc_url($link_pay) . '">Formas de pago</a> ';
    echo '<a class="button ' . ($section==='custom-expediente'?'button-primary':'') . '" href="' . esc_url($link_exp) . '">Custom fields (Expediente)</a> ';
    echo '<a class="button ' . ($section==='custom-reserva'?'button-primary':'') . '" href="' . esc_url($link_res) . '">Custom fields (Servicios/Reserva)</a>';
    echo '</p>';

    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="casanova-payments" />';
    echo '<input type="hidden" name="tab" value="giav" />';
    echo '<input type="hidden" name="section" value="' . esc_attr($section) . '" />';
    echo '<input type="search" name="q" value="' . esc_attr($q) . '" placeholder="Filtrar por nombre/código…" style="min-width:320px;" /> ';
    echo '<label style="margin-left:10px;"><input type="checkbox" name="include_disabled" value="1" ' . checked($include_disabled, true, false) . ' /> incluir deshabilitados</label> ';
    echo '<label style="margin-left:10px;"><input type="checkbox" name="include_hidden" value="1" ' . checked($include_hidden, true, false) . ' /> incluir ocultos</label> ';
    submit_button('Filtrar', 'secondary', '', false);
    echo '</form>';

    $err = null;
    $rows = [];

    if ($section === 'payment-methods') {
      // Fetch all pages (GIAV pageSize max 100) so admins don't miss items.
      if (function_exists('casanova_giav_forma_pago_search_all_pages')) {
        $items = casanova_giav_forma_pago_search_all_pages($include_disabled, 10);
      } elseif (function_exists('casanova_giav_forma_pago_search_all')) {
        $items = casanova_giav_forma_pago_search_all($include_disabled);
      } else {
        $items = new WP_Error('missing', 'No está disponible casanova_giav_forma_pago_search_all().');
      }
      if (is_wp_error($items)) {
        $err = $items->get_error_message();
      } else {
        foreach ($items as $it) {
          $row = [
            'Id' => isset($it->Id) ? (int) $it->Id : 0,
            'Nombre' => isset($it->Nombre) ? (string) $it->Nombre : '',
            'Codigo' => isset($it->Codigo) ? (string) $it->Codigo : '',
            'Categoria' => isset($it->Categoria) ? (string) $it->Categoria : '',
            'Tipo' => isset($it->Tipo) ? (string) $it->Tipo : '',
            'Deshabilitado' => isset($it->Deshabilitado) ? ((bool)$it->Deshabilitado ? 'Sí' : 'No') : 'No',
          ];
          if ($q !== '') {
            $hay = strtolower($row['Nombre'] . ' ' . $row['Codigo'] . ' ' . $row['Categoria'] . ' ' . $row['Tipo']);
            if (strpos($hay, strtolower($q)) === false) continue;
          }
          $rows[] = $row;
        }
      }
    } else {
      $target = ($section === 'custom-expediente') ? 'Expediente' : 'Reserva';
      // Fetch all pages (GIAV pageSize max 100) so admins don't miss definitions.
      if (function_exists('casanova_giav_customdata_search_all_pages')) {
        $items = casanova_giav_customdata_search_all_pages($target, $include_hidden, 10);
      } elseif (function_exists('casanova_giav_customdata_search_by_target')) {
        $items = casanova_giav_customdata_search_by_target($target, $include_hidden);
      } else {
        $items = new WP_Error('missing', 'No está disponible casanova_giav_customdata_search_by_target().');
      }
      if (is_wp_error($items)) {
        $err = $items->get_error_message();
      } else {
        foreach ($items as $it) {
          $row = [
            'Id' => isset($it->Id) ? (int) $it->Id : 0,
            'Name' => isset($it->Name) ? (string) $it->Name : '',
            'TargetClass' => isset($it->TargetClass) ? (string) $it->TargetClass : $target,
            'Type' => isset($it->Type) ? (string) $it->Type : '',
            'Hidden' => isset($it->Hidden) ? ((bool)$it->Hidden ? 'Sí' : 'No') : 'No',
            'Required' => isset($it->Required) ? ((bool)$it->Required ? 'Sí' : 'No') : 'No',
          ];
          if ($q !== '') {
            $hay = strtolower($row['Name']);
            if (strpos($hay, strtolower($q)) === false) continue;
          }
          $rows[] = $row;
        }
      }
    }

    if ($err) {
      echo '<div class="notice notice-error"><p><strong>GIAV:</strong> ' . esc_html($err) . '</p></div>';
    } else {
      echo '<p class="description">Resultados: <strong>' . esc_html((string) count($rows)) . '</strong></p>';
      echo '<table class="widefat striped">';
      if ($section === 'payment-methods') {
        echo '<thead><tr><th style="width:80px;">Id</th><th>Nombre</th><th>Código</th><th>Categoría</th><th>Tipo</th><th>Deshabilitado</th></tr></thead><tbody>';
        foreach ($rows as $r) {
          echo '<tr>';
          echo '<td><code>' . esc_html((string)$r['Id']) . '</code></td>';
          echo '<td>' . esc_html($r['Nombre']) . '</td>';
          echo '<td>' . esc_html($r['Codigo']) . '</td>';
          echo '<td>' . esc_html($r['Categoria']) . '</td>';
          echo '<td>' . esc_html($r['Tipo']) . '</td>';
          echo '<td>' . esc_html($r['Deshabilitado']) . '</td>';
          echo '</tr>';
        }
        echo '</tbody>';
      } else {
        echo '<thead><tr><th style="width:80px;">Id</th><th>Name</th><th>TargetClass</th><th>Type</th><th>Hidden</th><th>Required</th></tr></thead><tbody>';
        foreach ($rows as $r) {
          echo '<tr>';
          echo '<td><code>' . esc_html((string)$r['Id']) . '</code></td>';
          echo '<td>' . esc_html($r['Name']) . '</td>';
          echo '<td>' . esc_html($r['TargetClass']) . '</td>';
          echo '<td>' . esc_html($r['Type']) . '</td>';
          echo '<td>' . esc_html($r['Hidden']) . '</td>';
          echo '<td>' . esc_html($r['Required']) . '</td>';
          echo '</tr>';
        }
        echo '</tbody>';
      }
      echo '</table>';
    }

    echo '</div>';

  } elseif ($tab === 'help') {
    echo '<h2>Ayuda rápida</h2>';
    echo '<p class="description">Mini-documentación del portal: shortcodes disponibles, parámetros y notas de configuración. Para no depender de la memoria (mal negocio).</p>';

    echo '<h3>Shortcodes principales</h3>';
    echo '<table class="widefat striped" style="max-width:1100px">';
    echo '<thead><tr><th style="width:240px">Shortcode</th><th>Qué muestra</th><th style="width:420px">Opciones / Ejemplos</th></tr></thead><tbody>';

    echo '<tr><td><code>[casanova_portal]</code></td><td>Layout base del portal (menú + contenido según <code>?view=</code>).</td><td>Se usa en la página principal del portal. Ej.: <code>/area-usuario/?view=dashboard</code></td></tr>';
    echo '<tr><td><code>[casanova_mulligans]</code></td><td>Card de Mulligans (saldo, tier, progreso, movimientos).</td><td>Sin parámetros relevantes. Puedes limitar movimientos con <code>[casanova_mulligans_movimientos limit="10"]</code></td></tr>';

    echo '<tr><td><code>[casanova_proximo_viaje]</code></td><td>Próximo viaje del cliente (título, fechas, countdown, totales, enlace).</td><td><code>variant="hero"</code> (dashboard), <code>variant="compact"</code>, <code>variant="default"</code> (por defecto). Ej.: <code>[casanova_proximo_viaje variant="hero"]</code></td></tr>';

    echo '<tr><td><code>[casanova_card_pagos]</code></td><td>Card de estado de pagos (total, pagado, pendiente, barra).</td><td><code>source="auto|current|next"</code> y <code>cta="both|pagar|detalle|none"</code>. Ej.: <code>[casanova_card_pagos source="auto" cta="both"]</code></td></tr>';

    echo '<tr><td><code>[casanova_card_proxima_accion]</code></td><td>Card con la siguiente acción sugerida (pago/deposito/facturas/todo ok).</td><td><code>source="auto|current|next"</code>, <code>tab_pagos="pagos"</code>, <code>tab_facturas="facturas"</code>. Ej.: <code>[casanova_card_proxima_accion]</code></td></tr>';

    echo '<tr><td><code>[casanova_expedientes]</code></td><td>Listado de expedientes del cliente.</td><td>Navega con <code>?expediente=XXXX</code>. Se integra bien en layouts 2 columnas (lista+detalle).</td></tr>';
    echo '<tr><td><code>[casanova_expediente_resumen]</code></td><td>Resumen del expediente (totales, pagado, pendiente, mulligans usados).</td><td>Normalmente dentro del detalle (tab “Resumen”).</td></tr>';
    echo '<tr><td><code>[casanova_expediente_reservas]</code></td><td>Reservas/servicios del expediente (incluye botones de pago donde aplica).</td><td>Normalmente dentro de un tab “Reservas/Servicios”.</td></tr>';

    echo '<tr><td><code>[casanova_mensajes]</code></td><td>Centro de mensajes del viaje (notas/Comentarios en GIAV). Muestra solo elementos tipo <code>Comment</code>.</td><td>Usa el expediente activo (<code>?expediente=</code>) o el próximo viaje. Ej.: <code>[casanova_mensajes]</code></td></tr>';
    echo '<tr><td><code>[casanova_card_mensajes]</code></td><td>Card resumen de mensajes (dashboard): indica si hay mensajes nuevos y a qué viaje corresponde.</td><td>Sin parámetros. Ej.: <code>[casanova_card_mensajes]</code></td></tr>';
    echo '<tr><td><code>[casanova_bonos]</code></td><td>Listado de bonos disponibles (expedientes pagados), agrupado por viaje con enlaces a HTML y PDF.</td><td><code>days="3"</code>, <code>only_recent="1"</code></td></tr>';
    
    echo '</tbody></table>';
          echo '<script>(function(){var a=document.getElementById("casanova_links_checkall");if(!a)return; a.addEventListener("change",function(){document.querySelectorAll("input[name=\"link_ids[]\"]").forEach(function(c){c.checked=a.checked;});});})();</script>';
          echo '</form>';

    echo '<h3>Notas importantes</h3>';
    echo '<ul style="max-width:1100px; list-style:disc; margin-left:20px">';
    echo '<li><strong>CSS personalizado:</strong> guarda tus overrides en <code>wp-content/uploads/casanova-portal/portal-custom.css</code> (se carga después de <code>portal.css</code> y sobrevive a actualizaciones del plugin).</li>';
    echo '<li><strong>Menú:</strong> en la pestaña “Menú” cada item es un <code>?view=</code>. Si marcas “preservar <code>expediente</code>”, el portal mantiene el viaje activo al navegar (recomendado para secciones contextuales).</li>';
    echo '<li><strong>Depósito:</strong> solo se ofrece si estamos dentro de fecha límite y no se ha pagado nada anteriormente. Si no aplica, se ofrece pago pendiente normal.</li>';
    echo '</ul>';
  }

  echo '</div>';
}
