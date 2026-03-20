import { formatCurrency, formatDate, formatDateTime, formatNumber } from "../i18n/t.js";

const EMPTY_VALUE = "—";

function parseDateOnly(value) {
  const match = String(value || "").match(/(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return null;

  const [, year, month, day] = match;
  return new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
}

export function formatDateES(value) {
  if (!value || typeof value !== "string") return EMPTY_VALUE;

  const parsed = parseDateOnly(value);
  if (!parsed) return value;

  const formatted = formatDate(parsed, {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    timeZone: "UTC",
  });

  return formatted || value;
}

export function splitRange(range) {
  if (!range || typeof range !== "string") return { start: EMPTY_VALUE, end: EMPTY_VALUE };

  const parts = range.split("–").map((value) => value.trim());
  if (parts.length === 2) return { start: parts[0], end: parts[1] };

  const fallback = range.split("-").map((value) => value.trim());
  if (fallback.length >= 2) return { start: fallback[0], end: fallback[1] };

  return { start: range, end: EMPTY_VALUE };
}

export function normalizeTripDates(trip) {
  if (!trip) return { start: EMPTY_VALUE, end: EMPTY_VALUE };

  if (trip.date_start || trip.date_end) {
    return { start: trip.date_start || EMPTY_VALUE, end: trip.date_end || EMPTY_VALUE };
  }

  const range = splitRange(trip.date_range);
  return { start: range.start, end: range.end };
}

export function euro(value, currency = "EUR") {
  if (typeof value !== "number" || Number.isNaN(value)) return EMPTY_VALUE;

  const formatted = formatCurrency(value, currency);
  return formatted || `${Math.round(value)} ${currency}`;
}

export function formatTierLabel(tier) {
  if (!tier) return EMPTY_VALUE;

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

  return formatDateTime(date, {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function formatMsgDate(value) {
  if (!value) return EMPTY_VALUE;

  const date = parseDateOnly(value) || new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return formatDate(date, {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    ...(parseDateOnly(value) ? { timeZone: "UTC" } : {}),
  });
}

export function formatNumberUi(value, options = {}) {
  if (typeof value !== "number" || Number.isNaN(value)) return EMPTY_VALUE;

  const formatted = formatNumber(value, options);
  return formatted || String(value);
}

export function formatWeekdayShort(value) {
  const date = parseDateOnly(value) || new Date(String(value || ""));
  if (Number.isNaN(date.getTime())) return "";

  return formatDate(date, {
    weekday: "short",
    ...(parseDateOnly(value) ? { timeZone: "UTC" } : {}),
  });
}
