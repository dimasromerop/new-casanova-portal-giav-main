<?php
/**
 * Cliente_SEARCH por documento usando enums correctos:
 * documentoModo: Solo_NIF | Solo_Pasaporte | Ambos
 * rgpdSigned: NoAplicar | Si | No
 * modoFecha: Creacion | RelevantDates | CreacionModificacion
 */
function casanova_giav_cliente_search_por_dni(string $dni) {
  $dni = preg_replace('/\s+/', '', strtoupper(trim($dni)));
  if ($dni === '') return [];

  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;

  // Filtro principal
  $p->documento = $dni;
  $p->documentoModo = 'Solo_NIF';
  $p->documentoExacto = true;

  // Para no filtrar por RGPD, usa NoAplicar
  $p->rgpdSigned = 'NoAplicar';

  // Si no quieres filtrar por fechas, mejor NO mandar modoFecha,
  // pero para evitar "Encoding missing property", lo declaramos con null.
  $p->modoFecha = 'Creacion';
  $p->fechaHoraDesde = null;
  $p->fechaHoraHasta = null;

  // Otros
  $p->incluirDeshabilitados = false;
  $p->pageSize = 50;
  $p->pageIndex = 0;

  $resp = casanova_giav_call('Cliente_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  // Log temporal mientras ajustamos el parseo
  error_log('[CASANOVA SOAP] Cliente_SEARCH raw response: ' . print_r($resp, true));

  return $resp;
}

/**
 * Extraer idCliente de la respuesta (tolerante).
 * Ajustaremos si tu estructura concreta es distinta, pero esto cubre lo tipico.
 */
function casanova_giav_extraer_idcliente($resp): ?string {
  if (!is_object($resp)) return null;

  // Caso real de tu API: Cliente_SEARCHResult->WsCliente->Id
  if (
    isset($resp->Cliente_SEARCHResult)
    && is_object($resp->Cliente_SEARCHResult)
    && isset($resp->Cliente_SEARCHResult->WsCliente)
    && is_object($resp->Cliente_SEARCHResult->WsCliente)
    && isset($resp->Cliente_SEARCHResult->WsCliente->Id)
  ) {
    return (string) $resp->Cliente_SEARCHResult->WsCliente->Id;
  }

  // Si en algun caso viniera un array/lista de WsCliente
  if (
    isset($resp->Cliente_SEARCHResult)
    && is_object($resp->Cliente_SEARCHResult)
    && isset($resp->Cliente_SEARCHResult->WsClientes)
  ) {
    $node = $resp->Cliente_SEARCHResult->WsClientes;
    if (is_array($node)) {
      $c0 = $node[0] ?? null;
      if (is_object($c0) && isset($c0->Id)) return (string) $c0->Id;
    }
    if (is_object($node) && isset($node->WsCliente)) {
      $c = $node->WsCliente;
      if (is_array($c)) {
        $c0 = $c[0] ?? null;
        if (is_object($c0) && isset($c0->Id)) return (string) $c0->Id;
      } elseif (is_object($c) && isset($c->Id)) {
        return (string) $c->Id;
      }
    }
  }

  // Fallbacks antiguos (por si el proveedor devuelve otra estructura)
  foreach (['Cliente_SEARCHResult', 'Result'] as $rk) {
    if (isset($resp->$rk) && is_object($resp->$rk)) {
      $r = $resp->$rk;

      if (isset($r->Clientes) && is_object($r->Clientes) && isset($r->Clientes->Cliente)) {
        $c = $r->Clientes->Cliente;
        if (is_array($c) && isset($c[0]->idCliente)) return (string) $c[0]->idCliente;
        if (is_object($c) && isset($c->idCliente)) return (string) $c->idCliente;
      }

      if (isset($r->Cliente) && is_object($r->Cliente) && isset($r->Cliente->idCliente)) {
        return (string) $r->Cliente->idCliente;
      }
    }
  }

  return null;
}

/**
 * BRICKS: Vincular cuenta (formId wkwkgw, campo principal form-field-a1a1f7)
 */
add_action('bricks/form/custom_action', function($form) {
  $fields = $form->get_fields();

  // Solo tu formulario
  if (($fields['formId'] ?? '') !== 'wkwkgw') return;

  if (!is_user_logged_in()) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'Debes iniciar sesión para vincular tu cuenta.',
    ]);
    return;
  }

  $identifier_type = 'dni';
  foreach ($fields as $k => $v) {
    if (!is_scalar($v)) continue;

    $raw_value = sanitize_key((string) $v);
    $candidate_type = function_exists('casanova_portal_linking_normalize_identifier_type')
      ? casanova_portal_linking_normalize_identifier_type((string) $v)
      : 'dni';

    if ($candidate_type === 'giav_id' || $raw_value === 'dni') {
      $identifier_type = $candidate_type;
      break;
    }
  }

  $identifier_raw = (string) ($fields['form-field-a1a1f7'] ?? '');
  $identifier = function_exists('casanova_portal_linking_normalize_identifier')
    ? casanova_portal_linking_normalize_identifier($identifier_raw, $identifier_type)
    : preg_replace('/\s+/', '', strtoupper(sanitize_text_field($identifier_raw)));

  // Optional OTP field (add it in Bricks to enable secure linking)
  $otp_raw = '';
  foreach ($fields as $k => $v) {
    if (is_string($k) && preg_match('/otp/i', $k)) {
      $otp_raw = (string) $v;
      break;
    }
  }
  $otp = preg_replace('/\s+/', '', sanitize_text_field($otp_raw));

  if ($identifier === '') {
    $message = function_exists('casanova_portal_linking_missing_identifier_message')
      ? casanova_portal_linking_missing_identifier_message($identifier_type)
      : 'Introduce tu DNI.';

    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => $message,
    ]);
    return;
  }

  $user_id = get_current_user_id();
  if ($identifier_type === 'dni') {
    update_user_meta($user_id, 'casanova_dni', $identifier);
  }

  // Step 2: verify OTP (if provided)
  if ($otp !== '') {
    if (!function_exists('casanova_portal_verify_linking_otp')) {
      $form->set_result([
        'action'  => 'casanova_vincular',
        'type'    => 'error',
        'message' => 'No se ha podido validar el código. Contacta con nosotros.',
      ]);
      return;
    }

    $vr = casanova_portal_verify_linking_otp($user_id, $identifier, $otp, $identifier_type);
    if (is_wp_error($vr)) {
      $form->set_result([
        'action'  => 'casanova_vincular',
        'type'    => 'error',
        'message' => $vr->get_error_message(),
      ]);
      return;
    }

    $form->set_result([
      'action'          => 'casanova_vincular',
      'type'            => 'redirect',
      'redirectTo'      => (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/portal-app/')),
      'redirectTimeout' => 0,
    ]);
    return;
  }

  if (!function_exists('casanova_portal_linking_resolve_customer_id')) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'No hemos podido consultar el sistema. Inténtalo más tarde.',
    ]);
    return;
  }

  $idCliente = casanova_portal_linking_resolve_customer_id($identifier_type, $identifier);
  if (is_wp_error($idCliente)) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => $idCliente->get_error_message(),
    ]);
    return;
  }

  // Step 1: request OTP to the email stored in GIAV (proof of ownership)
  if (!function_exists('casanova_portal_send_linking_otp')) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'No se ha podido iniciar la verificación. Contacta con nosotros.',
    ]);
    return;
  }

  $sr = casanova_portal_send_linking_otp($user_id, $identifier, (int) $idCliente, $identifier_type);
  if (is_wp_error($sr)) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => $sr->get_error_message(),
    ]);
    return;
  }

  $masked = is_array($sr) ? ($sr['emailMasked'] ?? '') : '';
  $msg = 'Te hemos enviado un código de verificación a tu email';
  if ($masked) $msg .= ' (' . $masked . ')';
  $msg .= '. Introdúcelo para completar la vinculación.';

  $form->set_result([
    'action'  => 'casanova_vincular',
    'type'    => 'success',
    'message' => $msg,
  ]);

}, 10, 1);
