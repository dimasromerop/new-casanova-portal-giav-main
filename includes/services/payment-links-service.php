<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('casanova_payment_links_resolve_expediente_reference')) {
  /**
   * Resuelve una referencia de expediente introducida en admin.
   * Admite:
   * - ID interno del expediente
   * - Código visible del expediente
   *
   * Si el valor coincide con un ID y con un código de expedientes distintos,
   * devuelve error para evitar crear enlaces sobre el expediente equivocado.
     *
   * @return array<string,mixed>|WP_Error
   */
  function casanova_payment_links_resolve_expediente_reference($reference) {
    $raw = trim((string) $reference);
    if ($raw === '') {
      return new WP_Error('payment_link_invalid_expediente', 'Expediente invalido.');
    }

    if (!preg_match('/^\d+$/', $raw)) {
      return new WP_Error('payment_link_invalid_expediente', 'El expediente debe ser numerico.');
    }

    if (!function_exists('casanova_giav_expediente_get')) {
      return new WP_Error('payment_link_missing_expediente_lookup', 'No se puede resolver el expediente.');
    }

    $resolved_by_id = null;
    $resolved_id = (int) $raw;
    if ($resolved_id > 0) {
      $resolved_by_id = casanova_giav_expediente_get($resolved_id);
      if (is_wp_error($resolved_by_id)) {
        return $resolved_by_id;
      }
      if (!is_object($resolved_by_id)) {
        $resolved_by_id = null;
      }
    }

    $code_matches = [];
    if (function_exists('casanova_giav_expediente_search_simple')) {
      $items = casanova_giav_expediente_search_simple($raw, 10, 0);
      if (is_wp_error($items)) {
        return $items;
      }

      if (is_array($items)) {
        foreach ($items as $item) {
          if (!is_object($item)) {
            continue;
          }

          $codigo = trim((string) ($item->Codigo ?? ''));
          if ($codigo !== $raw) {
            continue;
          }

          $item_id = (int) ($item->IdExpediente ?? $item->Id ?? 0);
          if ($item_id <= 0) {
            continue;
          }

          $code_matches[$item_id] = $item;
        }
      }
    }

    $resolved_by_code = null;
    if (!empty($code_matches)) {
      if (count($code_matches) > 1) {
        return new WP_Error('payment_link_ambiguous_expediente', 'El codigo coincide con varios expedientes.');
      }
      $resolved_by_code = reset($code_matches);
    }

    if ($resolved_by_id && $resolved_by_code) {
      $id_by_id = (int) ($resolved_by_id->IdExpediente ?? $resolved_by_id->Id ?? 0);
      $id_by_code = (int) ($resolved_by_code->IdExpediente ?? $resolved_by_code->Id ?? 0);

      if ($id_by_id > 0 && $id_by_code > 0 && $id_by_id !== $id_by_code) {
        return new WP_Error('payment_link_ambiguous_expediente', 'El valor coincide con un ID interno y con un codigo visible de expedientes distintos.');
      }
    }

    $expediente = $resolved_by_id ?: $resolved_by_code;
    if (!$expediente || !is_object($expediente)) {
      return new WP_Error('payment_link_expediente_not_found', 'No se encontro el expediente.');
    }

    $id = (int) ($expediente->IdExpediente ?? $expediente->Id ?? 0);
    if ($id <= 0) {
      return new WP_Error('payment_link_expediente_not_found', 'No se encontro el expediente.');
    }

    return [
      'id' => $id,
      'codigo' => trim((string) ($expediente->Codigo ?? '')),
      'titulo' => trim((string) ($expediente->Titulo ?? '')),
      'source' => $resolved_by_id ? 'id' : 'codigo',
      'expediente' => $expediente,
      'input' => $raw,
    ];
  }
}

class Casanova_Payment_Links_Service {

  public static function create(array $data) {
    $idExpediente = (int)($data['id_expediente'] ?? 0);
    if ($idExpediente <= 0) {
      return new WP_Error('payment_link_invalid_expediente', 'Expediente invalido.');
    }

    $amount = (float)($data['amount_authorized'] ?? 0);
    if ($amount < 0) {
      return new WP_Error('payment_link_invalid_amount', 'Importe invalido.');
    }

    if (!function_exists('casanova_payment_link_create')) {
      return new WP_Error('payment_link_missing', 'Payment links not available.');
    }

    return casanova_payment_link_create($data);
  }

  public static function validate_token(string $token) {
    if (!function_exists('casanova_payment_link_get_by_token')) {
      return new WP_Error('payment_link_missing', 'Payment links not available.');
    }

    $token = trim($token);
    if ($token === '') {
      return new WP_Error('payment_link_invalid_token', 'Token invalido.');
    }

    $link = casanova_payment_link_get_by_token($token);
    if (!$link) {
      return new WP_Error('payment_link_not_found', 'Enlace no encontrado.');
    }

    $status = strtolower(trim((string)($link->status ?? '')));
    if ($status !== 'active') {
      return new WP_Error('payment_link_not_active', 'Enlace no disponible.');
    }

    if (function_exists('casanova_payment_link_is_expired') && casanova_payment_link_is_expired($link)) {
      if (function_exists('casanova_payment_link_update')) {
        casanova_payment_link_update((int)$link->id, ['status' => 'expired']);
      }
      return new WP_Error('payment_link_expired', 'Enlace caducado.');
    }

    return $link;
  }
}
