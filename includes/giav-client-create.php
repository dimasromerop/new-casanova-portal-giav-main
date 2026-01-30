<?php
if (!defined('ABSPATH')) exit;

/**
 * Crea un Cliente en GIAV a partir de datos de facturación mínimos.
 * Devuelve idCliente o null si falla.
 *
 * OJO: Cliente_POST en GIAV tiene MUCHOS campos obligatorios. Aquí mandamos un set mínimo
 * con defaults seguros, siguiendo el WSDL api_2_05.
 */
function casanova_giav_cliente_create_from_billing(array $billing): ?int {
  $dni = isset($billing['dni']) ? strtoupper(preg_replace('/\s+/', '', (string)$billing['dni'])) : '';
  if ($dni === '') return null;

  $email = isset($billing['email']) ? trim((string)$billing['email']) : '';
  $nombre_raw = isset($billing['nombre']) ? trim((string)$billing['nombre']) : '';
  $apellidos = isset($billing['apellidos']) ? trim((string)$billing['apellidos']) : '';

  // Si 'nombre' viene como "Nombre Apellidos", intentamos separar.
  $nombre = $nombre_raw;
  if ($apellidos !== '' && $nombre_raw !== '') {
    $maybe = trim(str_replace($apellidos, '', $nombre_raw));
    if ($maybe !== '') $nombre = $maybe;
  }

  // Payload mínimo compatible con WSDL (campos obligatorios con defaults).
  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;

  $p->tipoCliente = 'Particular';
  $p->documento = $dni;
  $p->email = ($email !== '' ? $email : null);
  $p->apellidos = ($apellidos !== '' ? $apellidos : null);
  $p->nombre = ($nombre !== '' ? $nombre : null);

  // Obligatorios numéricos / booleanos
  $p->creditoImporte = 0.0;
  $p->traspasaDepartamentos = false;
  $p->factTotalizadora = false;
  $p->deshabilitado = false;
  $p->empresa_Facturar_Reg_General = false;

  // Nillables obligatorios
  $p->idTaxDistrict = null;
  $p->comisionesIVAIncluido = true;
  $p->comisionesComisionDefecto = null;
  $p->excluir_347_349 = false;
  $p->idAgenteComercial = null;
  $p->validaAeat = false;
  $p->idEntityStage = null;
  $p->idPaymentTerm = null;

  // ROI / SEPA / RGPD
  $p->validaROI = false;
  $p->inscritoROI = false;
  $p->idSepatipo = 'Puntual';
  $p->idSepaEstado = 'Pendiente';
  $p->sepaFecha = null;
  $p->mailingConsent = 'Pending';
  $p->rgpdSigned = false;

  // Customer portal flags (obligatorios)
  $p->customerPortal_Enabled = false;
  $p->customerPortal_DefaultVendorId = null;
  $p->customerPortal_Zone_TravelFiles = false;
  $p->customerPortal_Zone_Invoicing = false;
  $p->customerPortal_Zone_Payments = false;
  $p->customerPortal_Zone_Contact = false;

  // Facturae (obligatorios)
  $p->facturaECodPais = 'ESP';
  $p->facturaEAcepta = false;

  try {
    $res = casanova_giav_call('Cliente_POST', $p);
    if (is_wp_error($res)) {
      error_log('[CASANOVA][GIAV] Cliente_POST WP_Error: ' . $res->get_error_message());
      return null;
    }

    // Estructura típica: Cliente_POSTResult->Id
    if (is_object($res) && isset($res->Cliente_POSTResult) && is_object($res->Cliente_POSTResult) && isset($res->Cliente_POSTResult->Id)) {
      return (int)$res->Cliente_POSTResult->Id;
    }
    // Alternativa: Cliente_POSTResult directamente es WsCliente
    if (is_object($res) && isset($res->Cliente_POSTResult) && is_object($res->Cliente_POSTResult) && isset($res->Cliente_POSTResult->IdCliente)) {
      return (int)$res->Cliente_POSTResult->IdCliente;
    }

    error_log('[CASANOVA][GIAV] Cliente_POST unexpected response: ' . print_r($res, true));
    return null;
  } catch (Throwable $e) {
    error_log('[CASANOVA][GIAV] Cliente_POST exception: ' . $e->getMessage());
    return null;
  }
}
