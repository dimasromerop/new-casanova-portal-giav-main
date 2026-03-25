<?php
if (!defined('ABSPATH')) exit;

function casanova_render_voucher_html(array $data): string {
  $a = $data['agencia'] ?? [];
  $c = $data['cliente'] ?? [];
  $p = $data['proveedor'] ?? [];
  $r = $data['reserva'] ?? null;
  $pasajeros = $data['pasajeros'] ?? [];
  $pdf_url = $data['pdf_url'] ?? '';
  $logo = $data['logo'] ?? '';
  $idExp = (int)($data['expediente'] ?? 0);

  if (!$r || !is_object($r)) {
    return '<p>' . esc_html__('Voucher no disponible.', 'casanova-portal') . '</p>';
  }

  $dxr = $r->DatosExternos ?? null;

  $s = function($v): string {
    return trim((string)($v ?? ''));
  };

  $pick = function(...$vals) use ($s): string {
    foreach ($vals as $v) {
      $x = $s($v);
      if ($x !== '') {
        return $x;
      }
    }
    return '';
  };

  $join = function(array $parts) use ($s): string {
    $out = [];
    foreach ($parts as $part) {
      $text = $s($part);
      if ($text !== '') {
        $out[] = $text;
      }
    }
    return implode(' · ', $out);
  };

  $codExp = '';
  if (is_object($dxr) && !empty($dxr->CodigoExpediente)) {
    $codExp = trim((string)$dxr->CodigoExpediente);
  }

  $tipo = $pick($r->TipoReserva ?? null, is_object($dxr) ? ($dxr->TipoReserva ?? null) : null);
  $desc = $pick($r->Descripcion ?? null, is_object($dxr) ? ($dxr->Descripcion ?? null) : null, $r->Concepto ?? null);
  $dest = $pick($r->Destino ?? null, is_object($dxr) ? ($dxr->Destino ?? null) : null);

  $rango = casanova_fmt_date_range($r->FechaDesde ?? null, $r->FechaHasta ?? null);
  if ($rango === '' && is_object($dxr)) {
    $entrada = $pick($dxr->FechaEntrada ?? null);
    $salida  = $pick($dxr->FechaSalida ?? null);
    $rango = trim($entrada . ($entrada && $salida ? ' - ' : '') . $salida);
  }

  $loc = $pick($r->Localizador ?? null, is_object($dxr) ? ($dxr->Localizador ?? null) : null);
  $reg = $pick($r->Regimen ?? null, is_object($dxr) ? ($dxr->Regimen ?? null) : null);

  $habit = casanova_reserva_room_types_text($r);
  if ($habit === '') {
    $habit = $pick($r->Habitaciones ?? null, is_object($dxr) ? ($dxr->Habitaciones ?? null) : null);
  }

  if (current_user_can('manage_options')) {
    error_log('[CASANOVA] habit=' . $habit);
    if (is_object($dxr)) error_log('[CASANOVA] reserva DX keys: ' . implode(',', array_keys(get_object_vars($dxr))));
    if (!empty($pasajeros) && is_object($pasajeros[0] ?? null)) {
      $dxp = ($pasajeros[0]->DatosExternos ?? null);
      error_log('[CASANOVA] pasajero keys: ' . implode(',', array_keys(get_object_vars($pasajeros[0]))));
      if (is_object($dxp)) error_log('[CASANOVA] pasajero DX keys: ' . implode(',', array_keys(get_object_vars($dxp))));
    }
  }

  $rooming = $pick(
    $r->Rooming ?? null,
    is_object($dxr) ? ($dxr->Rooming ?? $dxr->TextoRooming ?? null) : null
  );

  $pax = (int)($r->NumPax ?? 0);
  $ad  = (int)($r->NumAdultos ?? 0);
  $ni  = (int)($r->NumNinos ?? 0);
  $paxTxt = $pax ? (string)$pax : trim(($ad ? $ad . ' Adultos/Adults' : '') . ($ni ? ' · ' . $ni . ' Niños/Kids' : ''));

  $textoExtra = trim((string)($r->TextoBono ?? ''));
  $show_importe = !empty($data['show_importe']);
  $venta = casanova_fmt_money($r->Venta ?? 0);

  $pasajeros_names = [];
  if (is_array($pasajeros)) {
    foreach ($pasajeros as $pr0) {
      if (!is_object($pr0)) continue;

      $dx = $pr0->DatosExternos ?? null;
      $nombre = '';
      if (is_object($dx)) {
        $nombre = trim((string)($dx->NombrePasajero ?? $dx->Nombre ?? ''));
      }
      if ($nombre === '') {
        $nombre = trim((string)($pr0->NombrePasajero ?? $pr0->Nombre ?? ''));
      }
      if ($nombre === '') {
        $idp = (int)($pr0->IdPasajero ?? $pr0->Id ?? 0);
        $nombre = $idp > 0 ? sprintf(__('Pasajero #%d', 'casanova-portal'), $idp) : '';
      }
      if ($nombre !== '') {
        $pasajeros_names[] = $nombre;
      }
    }
  }

  $pasajeros_names = array_values(array_unique(array_map(function($n) {
    $n = trim((string)$n);
    return preg_replace('/\s+/', ' ', $n);
  }, $pasajeros_names)));
  sort($pasajeros_names, SORT_FLAG_CASE | SORT_STRING);

  $ag_nombre = $pick($a['nombre'] ?? null);
  $ag_dir    = $pick($a['direccion'] ?? null);
  $ag_web    = $pick($a['web'] ?? null);
  $ag_email  = $pick($a['email'] ?? null);
  $ag_tel    = $pick($a['tel'] ?? null);

  $cl_nombre = $pick($c['nombre'] ?? null);

  $pr_nombre = $pick($p['nombre'] ?? null);
  $pr_tel    = $pick($p['tel'] ?? null);
  $pr_email  = $pick($p['email'] ?? null);
  $pr_dir    = $pick($p['direccion'] ?? null);

  $tipo_humano = casanova_human_service_type($tipo, $desc, $r);
  $is_golf = casanova_is_golf_service($tipo, $r);
  $label_pax = $is_golf ? esc_html__('Jugadores/Players', 'casanova-portal') : esc_html__('Pasajeros/Passengers', 'casanova-portal');
  $pax_label = $is_golf ? 'Nº Jugadores/Players' : 'Pax';

  $detail_items = [];
  if ($pr_nombre !== '') {
    $detail_items[] = [
      'label' => __('Proveedor / Supplier', 'casanova-portal'),
      'value' => $pr_nombre,
      'align' => 'left',
    ];
  }
  if ($paxTxt !== '') {
    $detail_items[] = [
      'label' => $pax_label,
      'value' => $paxTxt,
      'align' => 'right',
    ];
  }
  if ($habit !== '') {
    $detail_items[] = [
      'label' => __('Distribución / Room Distribution', 'casanova-portal'),
      'value' => $habit,
      'align' => 'left',
    ];
  }
  if ($reg !== '') {
    $detail_items[] = [
      'label' => __('Régimen / Board Basis', 'casanova-portal'),
      'value' => $reg,
      'align' => 'right',
    ];
  }
  if ($show_importe && $venta !== '') {
    $detail_items[] = [
      'label' => __('Importe / Amount', 'casanova-portal'),
      'value' => $venta,
      'align' => 'right',
    ];
  }
  $detail_rows = array_chunk($detail_items, 2);

  $voucher_ref = $pick($codExp, $idExp > 0 ? (string)$idExp : '');
  if ($voucher_ref !== '' && strpos($voucher_ref, '#') !== 0 && preg_match('/^\d+$/', $voucher_ref)) {
    $voucher_ref = '#' . $voucher_ref;
  }

  $voucher_type = $tipo_humano !== '' ? $tipo_humano : __('Servicio / Service', 'casanova-portal');
  $agency_contact_line = $join([$ag_email, $ag_tel, $ag_web]);
  $provider_contact_line = $join([$pr_dir, $pr_email, $pr_tel]);

  $voucher_css_path = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-voucher.css';
  $voucher_css_href = add_query_arg(
    'ver',
    rawurlencode(file_exists($voucher_css_path) ? (string) filemtime($voucher_css_path) : '1'),
    CASANOVA_GIAV_PLUGIN_URL . 'assets/portal-voucher.css'
  );

  ob_start();
  $issue_date = date_i18n('d/m/Y');
  ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html(trim(($desc !== '' ? $desc . ' · ' : '') . __('Bono/Voucher', 'casanova-portal'))); ?></title>
  <link rel="stylesheet" href="<?php echo esc_url($voucher_css_href); ?>">
</head>
<body class="casanova-voucher-body">
  <div class="casanova-voucher-page">
    <div class="casanova-voucher-card">

      <?php if ($pdf_url !== ''): ?>
        <div class="casanova-voucher__download">
          <a class="casanova-voucher__download-link" href="<?php echo esc_url($pdf_url); ?>">
            <span class="casanova-voucher__download-icon" aria-hidden="true">&#8595;</span>
            <?php echo esc_html__('Descargar PDF', 'casanova-portal'); ?>
          </a>
        </div>
      <?php endif; ?>

      <div class="casanova-voucher__section casanova-voucher__section--header no-break">
        <table class="casanova-voucher__header-table">
          <tr>
            <td class="casanova-voucher__brand-cell">
              <?php if (!empty($logo)): ?>
                <div class="casanova-voucher__logo-wrap">
                  <img class="casanova-voucher__logo" src="<?php echo esc_attr($logo); ?>" alt="">
                </div>
              <?php endif; ?>
              <div class="casanova-voucher__eyebrow"><?php echo esc_html__('Bono / Voucher', 'casanova-portal'); ?></div>
              <?php if ($ag_nombre !== ''): ?>
                <div class="casanova-voucher__company"><?php echo esc_html($ag_nombre); ?></div>
              <?php endif; ?>
              <div class="casanova-voucher__company-meta">
                <?php if ($ag_dir !== ''): ?>
                  <div><?php echo esc_html($ag_dir); ?></div>
                <?php endif; ?>
                <?php if ($agency_contact_line !== ''): ?>
                  <div><?php echo esc_html($agency_contact_line); ?></div>
                <?php endif; ?>
              </div>
            </td>
            <td class="casanova-voucher__summary-cell">
              <?php if ($voucher_ref !== ''): ?>
                <div class="casanova-voucher__pill"><?php echo esc_html($voucher_ref); ?></div>
              <?php endif; ?>
              <?php if ($voucher_type !== ''): ?>
                <div class="casanova-voucher__summary-type"><?php echo esc_html($voucher_type); ?></div>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </div>

      <div class="casanova-voucher__section no-break">
        <table class="casanova-voucher__meta-table">
          <tr>
            <td class="casanova-voucher__meta-cell">
              <div class="casanova-voucher__meta-label"><?php echo esc_html__('Fecha emisión / Issue date', 'casanova-portal'); ?></div>
              <div class="casanova-voucher__meta-value"><?php echo esc_html($issue_date); ?></div>
            </td>
            <td class="casanova-voucher__meta-cell casanova-voucher__meta-cell--right">
              <?php if ($cl_nombre !== ''): ?>
                <div class="casanova-voucher__meta-label"><?php echo esc_html__('Cliente / Guest', 'casanova-portal'); ?></div>
                <div class="casanova-voucher__meta-value"><?php echo esc_html($cl_nombre); ?></div>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </div>

      <div class="casanova-voucher__section no-break">
        <table class="casanova-voucher__service-table">
          <tr>
            <td class="casanova-voucher__service-ref-col">
              <div class="casanova-voucher__meta-label"><?php echo esc_html__('Localizador', 'casanova-portal'); ?></div>
              <div class="casanova-voucher__service-ref"><?php echo esc_html($loc !== '' ? $loc : '—'); ?></div>
              <div class="casanova-voucher__service-subref"><?php echo esc_html__('Reference', 'casanova-portal'); ?></div>
            </td>
            <td class="casanova-voucher__service-main-col">
              <div class="casanova-voucher__meta-label"><?php echo esc_html__('Servicio', 'casanova-portal'); ?></div>
              <div class="casanova-voucher__service-name"><?php echo esc_html($desc); ?></div>
              <div class="casanova-voucher__service-meta-line">
                <?php if ($rango !== ''): ?>
                  <span class="casanova-voucher__service-meta-item">
                    <?php echo esc_html__('Fechas:', 'casanova-portal'); ?>
                    <strong><?php echo esc_html($rango); ?></strong>
                  </span>
                <?php endif; ?>
                <?php if ($dest !== ''): ?>
                  <span class="casanova-voucher__service-meta-item">
                    <?php echo esc_html__('Destino:', 'casanova-portal'); ?>
                    <strong><?php echo esc_html($dest); ?></strong>
                  </span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        </table>
      </div>

      <?php if (!empty($detail_rows)): ?>
        <div class="casanova-voucher__section no-break">
          <div class="casanova-voucher__section-title"><?php echo esc_html__('Detalles del servicio / Service details', 'casanova-portal'); ?></div>
          <table class="casanova-voucher__detail-table">
            <?php foreach ($detail_rows as $row_index => $row): ?>
              <?php $row_class = ($row_index === count($detail_rows) - 1) ? ' casanova-voucher__detail-row--last' : ''; ?>
              <tr class="casanova-voucher__detail-row<?php echo esc_attr($row_class); ?>">
                <?php for ($col = 0; $col < 2; $col++): ?>
                  <?php $item = $row[$col] ?? null; ?>
                  <?php
                    $cell_classes = 'casanova-voucher__detail-cell';
                    if (is_array($item) && ($item['align'] ?? '') === 'right') {
                      $cell_classes .= ' casanova-voucher__detail-cell--right';
                    }
                    if (!$item) {
                      $cell_classes .= ' casanova-voucher__detail-cell--empty';
                    }
                  ?>
                  <td class="<?php echo esc_attr($cell_classes); ?>">
                    <?php if ($item): ?>
                      <div class="casanova-voucher__detail-label"><?php echo esc_html((string)$item['label']); ?></div>
                      <div class="casanova-voucher__detail-value"><?php echo esc_html((string)$item['value']); ?></div>
                    <?php else: ?>
                      &nbsp;
                    <?php endif; ?>
                  </td>
                <?php endfor; ?>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($rooming !== ''): ?>
        <div class="casanova-voucher__section no-break">
          <div class="casanova-voucher__section-title"><?php echo esc_html__('Rooming', 'casanova-portal'); ?></div>
          <div class="casanova-voucher__note-box">
            <div class="casanova-voucher__text-pre"><?php echo esc_html($rooming); ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($pasajeros_names)): ?>
        <div class="casanova-voucher__section no-break">
          <div class="casanova-voucher__section-title"><?php echo esc_html($label_pax); ?></div>
          <ul class="casanova-voucher__list">
            <?php foreach ($pasajeros_names as $nm): ?>
              <li class="casanova-voucher__list-item"><?php echo esc_html($nm); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($textoExtra !== ''): ?>
        <div class="casanova-voucher__section no-break">
          <div class="casanova-voucher__section-title"><?php echo esc_html__('Observaciones / Remarks', 'casanova-portal'); ?></div>
          <div class="casanova-voucher__note-box">
            <div class="casanova-voucher__text-pre"><?php echo esc_html($textoExtra); ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($provider_contact_line !== ''): ?>
        <div class="casanova-voucher__section no-break">
          <div class="casanova-voucher__section-title"><?php echo esc_html__('Contacto proveedor', 'casanova-portal'); ?></div>
          <div class="casanova-voucher__contact-line"><?php echo esc_html($provider_contact_line); ?></div>
        </div>
      <?php endif; ?>

      <div class="casanova-voucher__footer">
        <div class="casanova-voucher__footer-line"><?php echo esc_html__('Este bono es válido únicamente si el expediente no tiene pagos pendientes.', 'casanova-portal'); ?></div>
        <?php if ($agency_contact_line !== ''): ?>
          <div class="casanova-voucher__footer-line">
            <strong><?php echo esc_html__('Contacto agencia:', 'casanova-portal'); ?></strong>
            <?php echo esc_html($agency_contact_line); ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</body>
</html>
  <?php

  return ob_get_clean();
}
