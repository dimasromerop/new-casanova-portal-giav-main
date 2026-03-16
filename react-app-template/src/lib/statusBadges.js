export function getStatusVariant(status) {
  const value = String(status || "").toLowerCase();
  if (!value) return "muted";
  if (/(pag|ok|listo)/.test(value)) return "success";
  if (/pend/.test(value)) return "warning";
  if (/(cancel|rech|fail|error)/.test(value)) return "danger";
  return "info";
}

export function getPaymentVariant(pendingAmount, hasPayments) {
  if (!hasPayments) return "muted";
  if (pendingAmount <= 0.01) return "success";
  if (pendingAmount > 0) return "warning";
  return "info";
}

export function getBonusesVariant(available) {
  if (available === null) return "muted";
  return available ? "success" : "warning";
}

export function getInvoiceVariant(status) {
  const value = String(status || "").toLowerCase();
  if (value.includes("pend")) return "warning";
  if (/(pag|ok)/.test(value)) return "success";
  if (/(cancel|fail|rech)/.test(value)) return "danger";
  return "info";
}

export function getHistoryBadge(row) {
  const label = row.is_refund ? "Reembolso" : row.type ? row.type : "Cobro";
  const variant = row.is_refund ? "danger" : "success";
  return { label, variant };
}
