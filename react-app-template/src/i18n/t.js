const I18N = (typeof window !== "undefined" && window.CASANOVA_I18N) ? window.CASANOVA_I18N : {};

export function t(key, fallback = "") {
  const value = I18N?.[key];
  return (typeof value === "string" && value.length) ? value : fallback;
}

export function tf(key, fallback = "", vars = {}) {
  const template = t(key, fallback);
  return template.replace(/\{(\w+)\}/g, (_, name) => (vars[name] ?? ""));
}

function hashKey(str) {
  let hash = 5381;
  for (let index = 0; index < str.length; index += 1) {
    hash = ((hash << 5) + hash) + str.charCodeAt(index);
    hash >>>= 0;
  }
  return "s_" + hash.toString(16);
}

export function tt(literal, fallback = null) {
  const key = hashKey(String(literal ?? ""));
  const resolvedFallback = fallback === null ? String(literal ?? "") : String(fallback ?? "");
  return t(key, resolvedFallback);
}

export function ttf(literal, vars = {}, fallback = null) {
  const template = tt(literal, fallback);
  return template.replace(/\{(\w+)\}/g, (_, name) => (vars[name] ?? ""));
}
