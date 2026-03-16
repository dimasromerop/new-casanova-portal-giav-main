export function formatDateES(iso) {
  if (!iso || typeof iso !== "string") return "—";
  const match = iso.match(/(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return iso;
  const [, year, month, day] = match;
  return `${day}/${month}/${year}`;
}

export function splitRange(range) {
  if (!range || typeof range !== "string") return { start: "—", end: "—" };
  const parts = range.split("–").map((value) => value.trim());
  if (parts.length === 2) return { start: parts[0], end: parts[1] };
  const fallback = range.split("-").map((value) => value.trim());
  if (fallback.length >= 2) return { start: fallback[0], end: fallback[1] };
  return { start: range, end: "—" };
}

export function normalizeTripDates(trip) {
  if (!trip) return { start: "—", end: "—" };
  if (trip.date_start || trip.date_end) {
    return { start: trip.date_start || "—", end: trip.date_end || "—" };
  }
  const range = splitRange(trip.date_range);
  return { start: range.start, end: range.end };
}

export function euro(value, currency = "EUR") {
  if (typeof value !== "number" || Number.isNaN(value)) return "—";
  try {
    return new Intl.NumberFormat("es-ES", { style: "currency", currency }).format(value);
  } catch {
    return `${Math.round(value)} ${currency}`;
  }
}

export function formatTierLabel(tier) {
  if (!tier) return "—";
  const normalized = String(tier).toLowerCase();
  const labels = {
    birdie: "Birdie",
    eagle: "Eagle",
    eagle_plus: "Eagle+",
    albatross: "Albatross",
  };
  if (labels[normalized]) return labels[normalized];
  return normalized.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
}

export function formatTimestamp(value) {
  const timestamp = Number(value || 0);
  if (!Number.isFinite(timestamp) || timestamp <= 0) return null;
  const date = new Date(timestamp * 1000);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleString("es-ES", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function formatMsgDate(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit", year: "numeric" });
}
