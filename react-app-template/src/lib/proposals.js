import { formatCurrency, formatDate, getLocale, tt, ttf } from "../i18n/t.js";

// Utilidades de la sección Propuestas que también usa el Inicio (aviso de propuestas nuevas).

function toDate(value) {
  const match = String(value || "").match(/(\d{4})-(\d{2})-(\d{2})/);
  return match ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))) : null;
}

/** «12–16 may 2027» (o lo que dicte el idioma: «May 12–16, 2027»). */
export function formatDateRange(start, end) {
  const a = toDate(start);
  const b = toDate(end);
  if (!a) return "";
  const options = { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" };
  if (!b || b.getTime() === a.getTime()) return formatDate(a, options);
  try {
    const formatter = new Intl.DateTimeFormat(getLocale() || undefined, options);
    if (typeof formatter.formatRange === "function") return formatter.formatRange(a, b);
  } catch {
    // sigue con el formato simple
  }
  return `${formatDate(a, options)} – ${formatDate(b, options)}`;
}

/** «15 de octubre» (con el año solo si no es el actual). */
export function formatDayMonth(value) {
  const date = toDate(value);
  if (!date) return "";
  const sameYear = date.getUTCFullYear() === new Date().getFullYear();
  return formatDate(date, { day: "numeric", month: "long", ...(sameYear ? {} : { year: "numeric" }), timeZone: "UTC" });
}

/** «24 sep 2026». */
export function formatShortDate(value) {
  const date = toDate(value);
  return date ? formatDate(date, { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }) : "";
}

/** Mes y año para solicitudes sin fechas cerradas: «junio de 2027». */
export function formatMonthYear(value) {
  const date = toDate(value);
  return date ? formatDate(date, { month: "long", year: "numeric", timeZone: "UTC" }) : "";
}

/**
 * Importe sin decimales cuando es redondo (1.890 €), con dos si no (1.890,50 €). Siempre con
 * separador de miles, como la propuesta pública (en español Intl no lo pone a 4 cifras).
 */
export function formatPrice(value, currency = "EUR") {
  const amount = Number(value);
  if (!Number.isFinite(amount)) return "";
  const whole = Math.abs(amount - Math.round(amount)) < 0.005;
  const digits = whole ? { minimumFractionDigits: 0, maximumFractionDigits: 0 } : {};
  return formatCurrency(amount, currency || "EUR", { ...digits, useGrouping: "always" });
}

export function nightsLabel(nights) {
  const n = Number(nights) || 0;
  if (!n) return "";
  return n === 1 ? ttf("{count} noche", { count: n }) : ttf("{count} noches", { count: n });
}

/** «3 jugadores · 1 acompañante». */
export function travellersLabel(players, nonPlayers) {
  const p = Number(players) || 0;
  const n = Number(nonPlayers) || 0;
  const parts = [];
  if (p) parts.push(p === 1 ? tt("1 jugador") : ttf("{count} jugadores", { count: p }));
  if (n) parts.push(n === 1 ? tt("1 acompañante") : ttf("{count} acompañantes", { count: n }));
  return parts.join(" · ");
}

export function priceBasisLabel(basis) {
  return basis === "single" ? tt("Por persona en habitación individual") : tt("Por persona en habitación doble");
}

/** Etiqueta de una línea de precio (las mismas que la propuesta pública). */
export function priceLineLabel(line) {
  const room = String(line?.room || "").trim();
  switch (line?.kind) {
    case "player":
      return room ? ttf("Jugador ({room})", { room }) : tt("Jugador");
    case "non_player":
      return room ? ttf("Acompañante ({room})", { room }) : tt("Acompañante");
    case "single_supplement":
      return tt("Suplemento individual");
    case "informative_single":
      return tt("Opción individual (informativa)");
    default:
      return tt("Precio por persona");
  }
}

/** Todas las propuestas de la respuesta, en orden. */
export function allProposals(feed) {
  return (Array.isArray(feed?.groups) ? feed.groups : []).flatMap((group) => (Array.isArray(group?.proposals) ? group.proposals : []));
}

/** Primer grupo con propuestas abiertas (para el aviso del Inicio). */
export function firstOpenGroup(feed) {
  for (const group of Array.isArray(feed?.groups) ? feed.groups : []) {
    const open = (group?.proposals || []).filter((proposal) => proposal?.state === "open");
    if (open.length) return { group, open };
  }
  return null;
}

/**
 * Precio «desde» de varias propuestas: el menor por jugador; si ninguna distingue jugadores,
 * el menor por persona. Devuelve también a qué se refiere para poder decirlo.
 */
export function fromPrice(proposals) {
  const lines = [];
  (proposals || []).forEach((proposal) => {
    (proposal?.price?.main || []).forEach((line) => {
      lines.push({ ...line, currency: proposal.price.currency, basis: proposal.price.basis });
    });
  });
  const pick = (kind) => lines.filter((line) => line.kind === kind).sort((a, b) => a.value - b.value)[0] || null;

  return pick("player") || pick("per_person") || pick("non_player");
}
