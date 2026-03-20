function getRuntime() {
  if (typeof window === "undefined") return null;
  return window.CasanovaPortalI18n || null;
}

function getDictionary() {
  if (typeof window === "undefined") return {};
  return window.CASANOVA_I18N || {};
}

function getMeta() {
  if (typeof window === "undefined") return {};
  return window.CASANOVA_I18N_META || {};
}

function interpolate(template, vars = {}) {
  return String(template || "").replace(/\{(\w+)\}/g, (_, name) => (vars[name] ?? ""));
}

function hashKey(value) {
  let hash = 5381;
  const source = String(value ?? "");

  for (let index = 0; index < source.length; index += 1) {
    hash = ((hash << 5) + hash) + source.charCodeAt(index);
    hash >>>= 0;
  }

  return "s_" + hash.toString(16);
}

function normalizeLocale(locale) {
  const source = String(locale || "").trim().replace(/_/g, "-");
  if (!source) return "es-ES";

  const parts = source.split("-").filter(Boolean);
  if (!parts.length) return "es-ES";

  const language = parts[0].toLowerCase();
  const region = parts[1] ? parts[1].toUpperCase() : "";

  return region ? `${language}-${region}` : language;
}

export function getLocale() {
  const runtime = getRuntime();
  if (runtime?.getLocale) return runtime.getLocale();

  const meta = getMeta();
  return normalizeLocale(meta.locale || meta.localeRaw || "");
}

export function getLanguages() {
  const runtime = getRuntime();
  if (runtime?.getLanguages) return runtime.getLanguages();

  const meta = getMeta();
  return Array.isArray(meta.languages) ? meta.languages : [];
}

export function t(key, fallback = "") {
  const runtime = getRuntime();
  if (runtime?.t) return runtime.t(key, fallback);

  const value = getDictionary()?.[key];
  return (typeof value === "string" && value.length) ? value : String(fallback || "");
}

export function tf(key, fallback = "", vars = {}) {
  const runtime = getRuntime();
  if (runtime?.tf) return runtime.tf(key, fallback, vars);

  return interpolate(t(key, fallback), vars);
}

export function tt(literal, fallback = null) {
  const runtime = getRuntime();
  if (runtime?.tt) return runtime.tt(literal, fallback);

  const source = String(literal ?? "");
  const resolvedFallback = fallback === null ? source : String(fallback ?? "");
  return t(hashKey(source), resolvedFallback);
}

export function ttf(literal, vars = {}, fallback = null) {
  const runtime = getRuntime();
  if (runtime?.ttf) return runtime.ttf(literal, vars, fallback);

  return interpolate(tt(literal, fallback), vars);
}

export function formatNumber(value, options = {}) {
  const runtime = getRuntime();
  if (runtime?.formatNumber) return runtime.formatNumber(value, options);

  if (typeof value !== "number" || !Number.isFinite(value)) return "";
  try {
    return new Intl.NumberFormat(getLocale(), options).format(value);
  } catch {
    return String(value);
  }
}

export function formatCurrency(value, currency = "EUR", options = {}) {
  const runtime = getRuntime();
  if (runtime?.formatCurrency) return runtime.formatCurrency(value, currency, options);

  if (typeof value !== "number" || !Number.isFinite(value)) return "";
  try {
    return new Intl.NumberFormat(getLocale(), { style: "currency", currency, ...options }).format(value);
  } catch {
    return `${Math.round(value)} ${currency}`;
  }
}

export function formatDate(value, options = {}) {
  const runtime = getRuntime();
  if (runtime?.formatDate) return runtime.formatDate(value, options);

  if (value == null || value === "") return "";

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  try {
    return new Intl.DateTimeFormat(getLocale(), options).format(date);
  } catch {
    return date.toISOString();
  }
}

export function formatDateTime(value, options = {}) {
  const runtime = getRuntime();
  if (runtime?.formatDateTime) return runtime.formatDateTime(value, options);

  return formatDate(value, options);
}
