<?php
if (!defined('ABSPATH')) exit;

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

