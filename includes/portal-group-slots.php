<?php
if (!defined('ABSPATH')) exit;

function casanova_group_slots_distribution(float $total, int $num): array {
  $num = max(0, (int)$num);
  if ($num <= 0) return [];

  $total = round($total, 2);
  $base = floor(($total / $num) * 100) / 100;
  $sum = $base * $num;
  $remaining = (int) round(($total - $sum) * 100);
  if ($remaining < 0) $remaining = 0;

  $out = [];
  for ($i = 0; $i < $num; $i++) {
    $extra = ($remaining > 0) ? 0.01 : 0.00;
    if ($remaining > 0) $remaining--;
    $out[] = round($base + $extra, 2);
  }
  return $out;
}

function casanova_group_slots_get(int $idExpediente, int $idReservaPQ = 0): array {
  global $wpdb;
  $table = casanova_group_slots_table();
  $idExpediente = (int)$idExpediente;
  $idReservaPQ = (int)$idReservaPQ;
  if ($idExpediente <= 0) return [];

  if ($idReservaPQ > 0) {
    return $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id_expediente=%d AND id_reserva_pq=%d ORDER BY slot_index ASC", $idExpediente, $idReservaPQ)
    ) ?: [];
  }
  return $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id_expediente=%d ORDER BY slot_index ASC", $idExpediente)
  ) ?: [];
}


function casanova_group_slots_get_by_id(int $id) {
  global $wpdb;
  $table = casanova_group_slots_table();
  $id = (int)$id;
  if ($id <= 0) return null;
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id));
}

function casanova_group_slots_open(int $idExpediente, int $idReservaPQ = 0): array {
  $slots = casanova_group_slots_get($idExpediente, $idReservaPQ);
  $open = [];
  $now = current_time('mysql');
  foreach ($slots as $s) {
    // Excluir slots reservados (lock temporal) para evitar overbooking en link público.
    $ru = (string)($s->reserved_until ?? '');
    if ($ru !== '' && strtotime($ru) !== false && strtotime($ru) > strtotime($now)) {
      continue;
    }

    $due = (float)($s->base_due ?? 0);
    $paid = (float)($s->base_paid ?? 0);
    if ($due - $paid > 0.01) $open[] = $s;
  }
  return $open;
}

function casanova_group_slots_update(int $id, array $fields): bool {
  global $wpdb;
  $table = casanova_group_slots_table();
  $id = (int)$id;
  if ($id <= 0) return false;

  $allowed = ['base_due','base_paid','status','reserved_until','reserved_token','updated_at'];
  $clean = [];
  foreach ($fields as $k => $v) {
    if (!in_array($k, $allowed, true)) continue;
    if ($k === 'base_due' || $k === 'base_paid') {
      $clean[$k] = (string) number_format((float)$v, 2, '.', '');
      continue;
    }
    $clean[$k] = $v;
  }
  $clean['updated_at'] = current_time('mysql');

  $formats = [];
  foreach ($clean as $k => $v) {
    if ($k === 'base_due' || $k === 'base_paid') { $formats[] = '%s'; continue; }
    $formats[] = '%s';
  }
  $ok = $wpdb->update($table, $clean, ['id' => $id], $formats, ['%d']);
  return $ok !== false;
}

/**
 * ensure_group_slots($id_expediente, $id_reserva_pq, $num_pax, $base_total_pending)
 */
function casanova_ensure_group_slots(int $idExpediente, int $idReservaPQ, int $numPax, float $baseTotalPending): array {
  global $wpdb;
  $table = casanova_group_slots_table();
  $idExpediente = (int)$idExpediente;
  $idReservaPQ = (int)$idReservaPQ;
  $numPax = (int)$numPax;
  $baseTotalPending = (float)$baseTotalPending;

  if ($idExpediente <= 0 || $numPax <= 0) return [];

  $slots = casanova_group_slots_get($idExpediente, $idReservaPQ);
  $byIndex = [];
  $has_paid = false;
  foreach ($slots as $s) {
    $idx = (int)($s->slot_index ?? 0);
    if ($idx > 0) $byIndex[$idx] = $s;
    if ((float)($s->base_paid ?? 0) > 0.01) $has_paid = true;
  }

    $total_paid = 0.0;
  $has_reserved = false;
  $sum_due = 0.0;
  foreach ($slots as $s) {
    $total_paid += (float)($s->base_paid ?? 0);
    $sum_due += (float)($s->base_due ?? 0);
    if (!empty($s->reserved_until)) {
      $ts = strtotime((string)$s->reserved_until);
      if ($ts && $ts > time()) $has_reserved = true;
    }
  }
  $total_paid = round($total_paid, 2);
  $sum_due = round($sum_due, 2);

$distribution = casanova_group_slots_distribution($baseTotalPending, $numPax);

  for ($i = 1; $i <= $numPax; $i++) {
    $due = (float)($distribution[$i - 1] ?? 0);
    if (isset($byIndex[$i])) {
      $s = $byIndex[$i];

      // IMPORTANTE: el precio por plaza debe ser fijo y no depender de lo que paguen otros.
      // Solo reajustamos base_due si:
      // - no hay pagos aplicados a ningún slot
      // - no hay slots reservados (locks)
      // - y la suma de base_due no cuadra con el total base (p.ej. slots creados con un valor erróneo)
      $can_reseed = ($total_paid <= 0.01) && (!$has_reserved) && ($sum_due > 0.01) && (abs($sum_due - $baseTotalPending) > 0.05);

      if ($can_reseed && (float)($s->base_paid ?? 0) <= 0.01) {
        casanova_group_slots_update((int)$s->id, ['base_due' => $due]);
      }
      continue;
    }

    $row = [
      'id_expediente' => $idExpediente,
      'id_reserva_pq' => ($idReservaPQ > 0 ? $idReservaPQ : null),
      'slot_index' => $i,
      'base_due' => (string) number_format($due, 2, '.', ''),
      'base_paid' => (string) number_format(0, 2, '.', ''),
      'status' => 'open',
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ];

    $wpdb->insert(
      $table,
      $row,
      ['%d','%d','%d','%s','%s','%s','%s','%s']
    );
  }

  return casanova_group_slots_get($idExpediente, $idReservaPQ);
}

function casanova_group_context_from_reservas(int $idExpediente, int $idCliente, ?int $idReservaPQ = null): array|WP_Error {
  if (!function_exists('casanova_giav_reservas_por_expediente')) {
    return new WP_Error('reservas_missing', 'Reservas no disponibles.');
  }
  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || empty($reservas)) {
    return new WP_Error('reservas_empty', 'No se pudieron cargar reservas.');
  }

  if (!function_exists('casanova_calc_pago_expediente')) {
    return new WP_Error('calc_missing', 'No se pudo calcular el pago.');
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) return $calc;

  $pq = null;
  foreach ($reservas as $r) {
    if (!is_object($r)) continue;
    if ((string)($r->TipoReserva ?? '') !== 'PQ') continue;
    $rid = (int)($r->Id ?? 0);
    if ($idReservaPQ && $rid === (int)$idReservaPQ) { $pq = $r; break; }
    if (!$idReservaPQ) { $pq = $r; break; }
  }

  $numPax = 0;
  $idReservaPQResolved = $idReservaPQ ?: 0;
  if ($pq) {
    $idReservaPQResolved = (int)($pq->Id ?? $idReservaPQResolved);
    $numPax = (int)($pq->NumPax ?? 0);
  }

  $baseTotal = 0.0;
  if ($pq && isset($pq->Venta) && is_numeric($pq->Venta)) {
    $baseTotal = (float)$pq->Venta;
  } elseif ($pq && isset($pq->ImporteTotal) && is_numeric($pq->ImporteTotal)) {
    $baseTotal = (float)$pq->ImporteTotal;
  }

  $basePending = 0.0;
  // Prioridad: pendiente total del paquete (no "importe a pagar" por pax)
  if ($pq && isset($pq->Pendiente) && is_numeric($pq->Pendiente)) {
    $basePending = (float)$pq->Pendiente;
  }
  if ($basePending <= 0 && $pq && isset($pq->DatosExternos) && is_object($pq->DatosExternos)) {
    $v = $pq->DatosExternos->TotalPendienteCobrosPasajeros ?? null;
    if (is_numeric($v)) $basePending = (float)$v;
  }
  if ($basePending <= 0) {
    $basePending = (float)($calc['pendiente_real'] ?? 0);
  }
  // Último recurso (legacy): algunas instalaciones solo exponen ImporteAPagar
  if ($basePending <= 0 && $pq && isset($pq->ImporteAPagar) && is_numeric($pq->ImporteAPagar)) {
    $basePending = (float)$pq->ImporteAPagar;
  }
  if ($basePending <= 0 && $pq && isset($pq->DatosExternos) && is_object($pq->DatosExternos)) {
    $v = $pq->DatosExternos->ImporteAPagar ?? null;
    if (is_numeric($v)) $basePending = (float)$v;
  }

return [
    'reservas' => $reservas,
    'calc' => $calc,
    'pq' => $pq,
    'id_reserva_pq' => $idReservaPQResolved,
    'num_pax' => $numPax,
    'base_total' => $baseTotal,
    'base_pending' => $basePending,
  ];
}



/**
 * Reserva (lock temporal) los primeros N slots abiertos para evitar race conditions en /pay/group/{token}.
 * Devuelve los slots reservados (en orden de slot_index).
 *
 * Nota: no depende de transacciones. Usa UPDATE + SELECT por reserved_token.
 */
function casanova_group_slots_reserve(int $idExpediente, int $idReservaPQ, int $count, int $ttlMinutes = 15): array|WP_Error {
  global $wpdb;
  $table = casanova_group_slots_table();
  $idExpediente = (int)$idExpediente;
  $idReservaPQ = (int)$idReservaPQ;
  $count = max(0, (int)$count);
  $ttlMinutes = max(1, (int)$ttlMinutes);

  if ($idExpediente <= 0 || $count <= 0) return [];

  $token = bin2hex(random_bytes(16));
  $now = current_time('mysql');
  $expires = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
  // Convertimos a horario WP (current_time es local WP); para DB guardamos en el mismo "mysql" que usa el plugin.
  $expires = date('Y-m-d H:i:s', strtotime($now) + ($ttlMinutes * 60));

  // Subquery con límite necesita envoltorio por MySQL.
  if ($idReservaPQ > 0) {
    $sql = $wpdb->prepare(
      "UPDATE {$table} SET reserved_until=%s, reserved_token=%s, updated_at=%s
       WHERE id IN (
         SELECT id FROM (
           SELECT id FROM {$table}
           WHERE id_expediente=%d AND id_reserva_pq=%d
             AND (reserved_until IS NULL OR reserved_until < %s)
             AND (base_due - base_paid) > 0.01
           ORDER BY slot_index ASC
           LIMIT %d
         ) t
       )",
      $expires, $token, $now,
      $idExpediente, $idReservaPQ, $now,
      $count
    );
  } else {
    $sql = $wpdb->prepare(
      "UPDATE {$table} SET reserved_until=%s, reserved_token=%s, updated_at=%s
       WHERE id IN (
         SELECT id FROM (
           SELECT id FROM {$table}
           WHERE id_expediente=%d
             AND (reserved_until IS NULL OR reserved_until < %s)
             AND (base_due - base_paid) > 0.01
           ORDER BY slot_index ASC
           LIMIT %d
         ) t
       )",
      $expires, $token, $now,
      $idExpediente, $now,
      $count
    );
  }

  $updated = $wpdb->query($sql);
  if ($updated === false) {
    return new WP_Error('slot_reserve_failed', $wpdb->last_error ?: 'slot reserve failed');
  }
  if ((int)$updated < $count) {
    // No conseguimos reservar suficientes.
    return [];
  }

  // Cargamos los slots reservados por token.
  $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE reserved_token=%s ORDER BY slot_index ASC", $token)) ?: [];
  return $rows;
}

/**
 * Asigna un pago a una lista explícita de slots (IDs), respetando el orden recibido.
 * Útil cuando el link one-shot se generó reservando slots.
 */
function casanova_allocate_payment_to_slot_ids(array $slotIds, float $amountPaid): array {
  $amountPaid = round((float)$amountPaid, 2);
  $alloc = [];
  if ($amountPaid <= 0.0) return $alloc;

  $slotIds = array_values(array_filter(array_map('intval', $slotIds), fn($v) => $v > 0));
  if (empty($slotIds)) return $alloc;

  $remaining = $amountPaid;
  foreach ($slotIds as $slotId) {
    if ($remaining <= 0.0001) break;
    $s = casanova_group_slots_get_by_id($slotId);
    if (!$s) continue;

    $due = (float)($s->base_due ?? 0);
    $paid = (float)($s->base_paid ?? 0);
    $open = $due - $paid;
    if ($open <= 0.01) continue;

    $apply = min($open, $remaining);
    $new_paid = $paid + $apply;
    $status = ($new_paid + 0.01 >= $due) ? 'paid' : 'open';

    casanova_group_slots_update((int)$s->id, [
      'base_paid' => $new_paid,
      'status' => $status,
      'reserved_until' => null,
      'reserved_token' => null,
    ]);

    $alloc[] = [
      'slot_id' => (int)$s->id,
      'slot_index' => (int)($s->slot_index ?? 0),
      'amount_applied' => round($apply, 2),
    ];
    $remaining -= $apply;
  }

  return $alloc;
}

/**
 * allocate_payment_to_slots($id_expediente, $amount_paid)
 */
function casanova_allocate_payment_to_slots(int $idExpediente, float $amountPaid, int $idReservaPQ = 0): array {
  $amountPaid = round((float)$amountPaid, 2);
  $alloc = [];
  if ($idExpediente <= 0 || $amountPaid <= 0.0) return $alloc;

  $slots = casanova_group_slots_get($idExpediente, $idReservaPQ);
  if (empty($slots)) return $alloc;

  $remaining = $amountPaid;
  foreach ($slots as $s) {
    if ($remaining <= 0.0001) break;
    $due = (float)($s->base_due ?? 0);
    $paid = (float)($s->base_paid ?? 0);
    $open = $due - $paid;
    if ($open <= 0.01) continue;

    $apply = min($open, $remaining);
    $new_paid = $paid + $apply;
    $status = ($new_paid + 0.01 >= $due) ? 'paid' : 'open';

    casanova_group_slots_update((int)$s->id, [
      'base_paid' => $new_paid,
      'status' => $status,
    ]);

    $alloc[] = [
      'slot_id' => (int)$s->id,
      'slot_index' => (int)($s->slot_index ?? 0),
      'amount_applied' => round($apply, 2),
    ];
    $remaining -= $apply;
  }

  return $alloc;
}

function casanova_group_tokens_create(array $data) {
  global $wpdb;
  $table = casanova_group_pay_tokens_table();
  $token = $data['token'] ?? bin2hex(random_bytes(20));
  $now = current_time('mysql');

  $row = [
    'token' => $token,
    'id_expediente' => (int)($data['id_expediente'] ?? 0),
    'id_reserva_pq' => !empty($data['id_reserva_pq']) ? (int)$data['id_reserva_pq'] : null,
    'status' => (string)($data['status'] ?? 'active'),
    'expires_at' => !empty($data['expires_at']) ? (string)$data['expires_at'] : null,
    'created_at' => $now,
    'updated_at' => $now,
  ];

  $ok = $wpdb->insert(
    $table,
    $row,
    ['%s','%d','%d','%s','%s','%s','%s']
  );
  if (!$ok) return new WP_Error('group_token_insert_failed', $wpdb->last_error);

  $row['id'] = (int)$wpdb->insert_id;
  return (object)$row;
}

function casanova_group_token_get(string $token) {
  global $wpdb;
  $table = casanova_group_pay_tokens_table();
  $token = trim($token);
  if ($token === '') return null;
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token=%s LIMIT 1", $token));
}

function casanova_group_token_update(int $id, array $fields): bool {
  global $wpdb;
  $table = casanova_group_pay_tokens_table();
  $id = (int)$id;
  if ($id <= 0) return false;

  $allowed = ['status','expires_at','updated_at'];
  $clean = [];
  foreach ($fields as $k => $v) {
    if (!in_array($k, $allowed, true)) continue;
    $clean[$k] = $v;
  }
  $clean['updated_at'] = current_time('mysql');

  $formats = [];
  foreach ($clean as $k => $v) { $formats[] = '%s'; }

  $ok = $wpdb->update($table, $clean, ['id' => $id], $formats, ['%d']);
  return $ok !== false;
}

function casanova_group_token_is_expired($token_obj): bool {
  if (!$token_obj || !is_object($token_obj)) return true;
  if (empty($token_obj->expires_at)) return false;
  $ts = strtotime((string)$token_obj->expires_at);
  if (!$ts) return false;
  return $ts < time();
}

function casanova_group_pay_url(string $token): string {
  $token = trim($token);
  if ($token === '') return home_url('/');
  return home_url('/pay/group/' . rawurlencode($token) . '/');
}

