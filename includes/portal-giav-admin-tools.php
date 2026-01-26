<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-only REST endpoints to inspect GIAV catalogs.
 *
 * Why: humans need IDs (formas de pago, custom fields) and GIAV hides them behind SOAP.
 * We expose them safely for admins only.
 */

if (!function_exists('casanova_giav_forma_pago_search_all')) {
  /**
   * Returns all GIAV payment methods (FormasPago) as raw WsFormaPago items.
   */
  function casanova_giav_forma_pago_search_all(bool $include_disabled = true, int $pageSize = 100, int $pageIndex = 0) {
    // GIAV constraint: pageSize max 100.
    $pageSize = max(1, min(100, (int) $pageSize));
    // Many parameters are "required" in SOAP even if nillable. Send a full, permissive payload.
    $params = [
      'idOficina' => null,
      'categoria' => null,
      'ambito' => null,
      'admiteCobros' => 'NoAplicar',
      'admiteReembolsos' => 'NoAplicar',
      'arqueaCaja' => 'NoAplicar',
      'idcliente' => null,
      'clientePertenece' => false,
      'idBankAccount' => null,
      'incluirDeshabilitados' => (bool) $include_disabled,
      'pageSize' => (int) $pageSize,
      'pageIndex' => (int) $pageIndex,
    ];

    $result = casanova_giav_call('FormaPago_SEARCH', $params);
    if (is_wp_error($result)) return $result;

    // Typical response: { FormaPago_SEARCHResult: { WsFormaPago: [...] } }
    $container = $result->FormaPago_SEARCHResult ?? $result;
    return casanova_giav_normalize_list($container, 'WsFormaPago');
  }
}

if (!function_exists('casanova_giav_forma_pago_search_all_pages')) {
  /**
   * Convenience: fetch all pages of FormaPago_SEARCH (GIAV pageSize max 100).
   */
  function casanova_giav_forma_pago_search_all_pages(bool $include_disabled = true, int $maxPages = 10) {
    $maxPages = max(1, min(50, (int) $maxPages));
    $all = [];
    for ($i = 0; $i < $maxPages; $i++) {
      $items = casanova_giav_forma_pago_search_all($include_disabled, 100, $i);
      if (is_wp_error($items)) return $items;
      foreach ($items as $it) $all[] = $it;
      if (count($items) < 100) break;
    }
    return $all;
  }
}

if (!function_exists('casanova_giav_customdata_search_by_target')) {
  /**
   * Returns GIAV CustomData definitions for a given target class.
   */
  function casanova_giav_customdata_search_by_target(string $targetClass, bool $include_hidden = true, int $pageSize = 100, int $pageIndex = 0) {
    // GIAV constraint: pageSize max 100.
    $pageSize = max(1, min(100, (int) $pageSize));

    // WSDL expects ArrayOfCrmTargetClass, not a string.
    // SoapClient typically represents arrays as: ['CrmTargetClass' => ['Expediente']]
    $targetClassArr = [ 'CrmTargetClass' => [ (string) $targetClass ] ];

    $params = [
      'idsCustomData' => null,
      'types' => null,
      'name' => null,
      'targetClass' => $targetClassArr,
      'hidden' => $include_hidden ? 'NoAplicar' : 'No',
      'required' => 'NoAplicar',
      'modificationDateHourFrom' => null,
      'modificationDateHourTo' => null,
      'pageSize' => $pageSize,
      'pageIndex' => (int) $pageIndex,
    ];

    $result = casanova_giav_call('CustomData_SEARCH', $params);
    if (is_wp_error($result)) return $result;

    $container = $result->CustomData_SEARCHResult ?? $result;
    // IMPORTANT: In this WSDL the item name is <CustomData>, not <WsCustomData>.
    // Using the wrong list key makes SoapClient return the container object instead of the items,
    // which then looks like "Id = 0" placeholders in admin tools.
    return casanova_giav_normalize_list($container, 'CustomData');
  }
}

if (!function_exists('casanova_giav_customdata_search_all_pages')) {
  /**
   * Convenience: fetch up to N pages of CustomData definitions (GIAV pageSize max 100).
   */
  function casanova_giav_customdata_search_all_pages(string $targetClass, bool $include_hidden = true, int $maxPages = 10): array|
    WP_Error {
    $maxPages = max(1, (int) $maxPages);
    $all = [];
    for ($i = 0; $i < $maxPages; $i++) {
      $items = casanova_giav_customdata_search_by_target($targetClass, $include_hidden, 100, $i);
      if (is_wp_error($items)) return $items;
      foreach ($items as $it) $all[] = $it;
      if (count($items) < 100) break;
    }
    return $all;
  }
}

if (!function_exists('casanova_giav_expediente_search_simple')) {
  /**
   * Simple Expediente search helper to help admins find GIAV IDs (Id) for deposit overrides.
   *
   * Search strategy:
   * - If $q looks like a code (digits), filter by codigoDesde/codigoHasta.
   * - Else filter by titulo.
   */
  function casanova_giav_expediente_search_simple(string $q, int $pageSize = 50, int $pageIndex = 0) {
    $q = trim((string) $q);
    $pageSize = max(1, min(100, (int) $pageSize));

    $is_code = ($q !== '' && preg_match('/^\d+$/', $q));

    $params = [
      'idsExpediente' => null,
      'codOficina' => null,
      'idsOficina' => null,
      'codigoDesde' => $is_code ? $q : null,
      'codigoHasta' => $is_code ? $q : null,
      'idsCliente' => null,
      'idsPasajeros' => null,
      'idsDepartamento' => null,
      'idsAgenteComercial' => null,
      'fechaCreacionDesde' => null,
      'fechaCreacionHasta' => null,
      'fechaHoraModificacionDesde' => null,
      'fechaHoraModificacionHasta' => null,
      'fechaCierreDesde' => null,
      'fechaCierreHasta' => null,
      // Required enum; leaving date filters null means "no filter" in practice.
      'modoMultiFiltroFecha' => 'Salida',
      'multiFiltroFechaDesde' => null,
      'multiFiltroFechaHasta' => null,
      'idsEntitiesStages' => null,
      'idsUsuarioCreacion' => null,
      'titulo' => (!$is_code && $q !== '') ? $q : null,
      'idsCategories' => null,
      'idsPresupuesto' => null,
      // Required filters.
      'facturacionPendiente' => 'NoAplicar',
      'cobroPendiente' => 'NoAplicar',
      'estadoCierre' => 'NoAplicar',
      'tipoExpediente' => 'NoAplicar',
      'recepcionCosteTotal' => 'NoAplicar',
      'customDataValues' => null,
      'pageSize' => $pageSize,
      'pageIndex' => (int) $pageIndex,
    ];

    $result = casanova_giav_call('Expediente_SEARCH', $params);
    if (is_wp_error($result)) return $result;
    $container = $result->Expediente_SEARCHResult ?? $result;
    return casanova_giav_normalize_list($container, 'WsExpediente');
  }
}

add_action('rest_api_init', function () {
  // List GIAV payment methods (Formas de pago)
  register_rest_route('casanova/v1', '/giav/payment-methods', [
    'methods' => 'GET',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
    'callback' => function (WP_REST_Request $request) {
      $include_disabled = $request->get_param('include_disabled');
      $include_disabled = ($include_disabled === null) ? true : (bool) $include_disabled;
      $q = trim((string) $request->get_param('q'));

      $items = casanova_giav_forma_pago_search_all_pages($include_disabled);
      if (is_wp_error($items)) {
        return new WP_REST_Response([
          'ok' => false,
          'code' => 'giav_error',
          'message' => $items->get_error_message(),
        ], 500);
      }

      $out = [];
      foreach ($items as $it) {
        $row = [
          'id' => isset($it->Id) ? (int) $it->Id : null,
          'nombre' => isset($it->Nombre) ? (string) $it->Nombre : '',
          'codigo' => isset($it->Codigo) ? (string) $it->Codigo : '',
          'categoria' => isset($it->Categoria) ? (string) $it->Categoria : '',
          'tipo' => isset($it->Tipo) ? (string) $it->Tipo : '',
          'deshabilitado' => isset($it->Deshabilitado) ? (bool) $it->Deshabilitado : false,
        ];

        if ($q !== '') {
          $hay = strtolower($row['nombre'] . ' ' . $row['codigo'] . ' ' . $row['categoria'] . ' ' . $row['tipo']);
          if (strpos($hay, strtolower($q)) === false) continue;
        }
        $out[] = $row;
      }

      return new WP_REST_Response([
        'ok' => true,
        'count' => count($out),
        'items' => $out,
      ], 200);
    },
  ]);

  // List GIAV CustomData definitions (custom fields)
  register_rest_route('casanova/v1', '/giav/custom-fields', [
    'methods' => 'GET',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
    'callback' => function (WP_REST_Request $request) {
      $target = strtolower(trim((string) $request->get_param('target')));
      // Friendly aliases
      $map = [
        'expediente' => 'Expediente',
        'reserva' => 'Reserva',
        'servicio' => 'Reserva',
        'servicios' => 'Reserva',
      ];
      $targetClass = $map[$target] ?? $request->get_param('targetClass');
      $targetClass = (string) $targetClass;
      if ($targetClass === '') {
        return new WP_REST_Response([
          'ok' => false,
          'code' => 'missing_target',
          'message' => 'Falta target (expediente|reserva) o targetClass.',
        ], 400);
      }

      $q = trim((string) $request->get_param('q'));
      $include_hidden = $request->get_param('include_hidden');
      $include_hidden = ($include_hidden === null) ? true : (bool) $include_hidden;

      // Fetch pages defensively (GIAV max pageSize=100).
      $items = casanova_giav_customdata_search_all_pages($targetClass, $include_hidden, 10);
      if (is_wp_error($items)) {
        return new WP_REST_Response([
          'ok' => false,
          'code' => 'giav_error',
          'message' => $items->get_error_message(),
        ], 500);
      }

      $out = [];
      foreach ($items as $it) {
        $row = [
          'id' => isset($it->Id) ? (int) $it->Id : null,
          'name' => isset($it->Name) ? (string) $it->Name : '',
          'targetClass' => isset($it->TargetClass) ? (string) $it->TargetClass : $targetClass,
          'type' => isset($it->Type) ? (string) $it->Type : '',
          'hidden' => isset($it->Hidden) ? (bool) $it->Hidden : false,
          'required' => isset($it->Required) ? (bool) $it->Required : false,
        ];
        if ($q !== '') {
          $hay = strtolower($row['name']);
          if (strpos($hay, strtolower($q)) === false) continue;
        }
        $out[] = $row;
      }

      return new WP_REST_Response([
        'ok' => true,
        'count' => count($out),
        'items' => $out,
      ], 200);
    },
  ]);
});
