export function uniqueStrings(values) {
  const seen = new Set();
  const out = [];
  for (const value of Array.isArray(values) ? values : []) {
    const clean = String(value || "").trim();
    if (!clean) continue;
    const key = clean.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(clean);
  }
  return out;
}

export function serviceDetailPayload(service) {
  if (service?.details && typeof service.details === "object") return service.details;
  if (service?.detail?.details && typeof service.detail.details === "object") return service.detail.details;
  return {};
}

function normalizeTransportToken(value) {
  const clean = String(value || "").trim();
  if (!clean) return "";
  return clean
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "");
}

function isGenericTransportLabel(value) {
  const token = normalizeTransportToken(value);
  return token === "nacional" || token === "unioneuropea" || token === "restomundo" || token === "restodelmundo";
}

export function serviceSemanticType(service) {
  const semantic = String(service?.semantic_type || "").trim().toLowerCase();
  if (semantic) return semantic;

  const detailPayload = serviceDetailPayload(service);
  const type = String(service?.type || service?.detail?.type || "").trim().toUpperCase();
  if (type === "HT") return "hotel";
  if (type === "GF") return "golf";
  if (type === "TR") return "transfer";
  if (type === "AV") return "flight";
  if (type === "OT") {
    const subtype = String(service?.subtype || service?.detail?.subtype || detailPayload?.subtype || "").trim().toLowerCase();
    if (subtype.includes("traslado")) return "transfer";
    if (detailPayload.players !== undefined && detailPayload.players !== null && detailPayload.players !== "") return "golf";
    if (detailPayload.route || detailPayload.flight_code || detailPayload.schedule) return "flight";
    if (Array.isArray(detailPayload.segments) && detailPayload.segments.length) return "flight";
  }
  return "other";
}

export function compactList(values, limit = 2) {
  const list = uniqueStrings(values);
  if (!list.length) return "";
  if (list.length <= limit) return list.join(" · ");
  return `${list.slice(0, limit).join(" · ")} +${list.length - limit}`;
}

export function dateToUtcMidnight(value) {
  const input = String(value || "").trim();
  if (!input) return null;

  let match = input.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (match) {
    const [, year, month, day] = match;
    return Date.UTC(Number(year), Number(month) - 1, Number(day));
  }

  match = input.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (match) {
    const [, day, month, year] = match;
    return Date.UTC(Number(year), Number(month) - 1, Number(day));
  }

  const parsed = new Date(input);
  if (Number.isNaN(parsed.getTime())) return null;
  return Date.UTC(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
}

export function countNightsBetween(start, end) {
  const startUtc = dateToUtcMidnight(start);
  const endUtc = dateToUtcMidnight(end);
  if (!Number.isFinite(startUtc) || !Number.isFinite(endUtc)) return 0;
  return Math.max(0, Math.round((endUtc - startUtc) / 86400000));
}

export function flightSummary(service) {
  const details = serviceDetailPayload(service);
  const segments = uniqueStrings(Array.isArray(details.segments) ? details.segments : []);
  if (segments.length) return compactList(segments, 1);

  const route = String(details.route || "").trim();
  const parts = [
    String(details.flight_code || "").trim(),
    !isGenericTransportLabel(route) ? route : "",
  ].filter(Boolean);
  if (parts.length) return parts.join(" · ");

  const schedule = String(details.schedule || "").trim();
  if (schedule) return schedule;

  return "";
}

export function transferSummary(service) {
  const details = serviceDetailPayload(service);
  const route = String(details.route || "").trim();
  if (route) return route;

  const provider = String(details.provider || "").trim();
  const title = String(service?.title || "").trim();
  return title || provider;
}
