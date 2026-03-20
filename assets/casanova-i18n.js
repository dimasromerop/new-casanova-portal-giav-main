(function (windowObject) {
  "use strict";

  var dictionary = windowObject.CASANOVA_I18N || {};
  var meta = windowObject.CASANOVA_I18N_META || {};

  function interpolate(template, vars) {
    return String(template || "").replace(/\{(\w+)\}/g, function (_, name) {
      return Object.prototype.hasOwnProperty.call(vars || {}, name) ? vars[name] : "";
    });
  }

  function hashKey(value) {
    var hash = 5381;
    var source = String(value == null ? "" : value);

    for (var index = 0; index < source.length; index += 1) {
      hash = ((hash << 5) + hash) + source.charCodeAt(index);
      hash >>>= 0;
    }

    return "s_" + hash.toString(16);
  }

  function normalizeLocale(locale) {
    var source = String(locale || "").trim().replace(/_/g, "-");
    if (!source) return "es-ES";

    var parts = source.split("-").filter(Boolean);
    if (!parts.length) return "es-ES";

    var language = parts[0].toLowerCase();
    var region = parts[1] ? parts[1].toUpperCase() : "";

    return region ? language + "-" + region : language;
  }

  function t(key, fallback) {
    var value = dictionary[key];
    return (typeof value === "string" && value.length) ? value : String(fallback || "");
  }

  function tf(key, fallback, vars) {
    return interpolate(t(key, fallback), vars || {});
  }

  function tt(literal, fallback) {
    var source = String(literal == null ? "" : literal);
    var resolvedFallback = fallback == null ? source : String(fallback);
    return t(hashKey(source), resolvedFallback);
  }

  function ttf(literal, vars, fallback) {
    return interpolate(tt(literal, fallback), vars || {});
  }

  function getLocale() {
    return normalizeLocale(meta.locale || meta.localeRaw || "");
  }

  function getLanguages() {
    return Array.isArray(meta.languages) ? meta.languages : [];
  }

  function formatNumber(value, options) {
    if (typeof value !== "number" || !isFinite(value)) return "";

    try {
      return new Intl.NumberFormat(getLocale(), options || {}).format(value);
    } catch (_) {
      return String(value);
    }
  }

  function formatCurrency(value, currency, options) {
    if (typeof value !== "number" || !isFinite(value)) return "";

    var resolvedCurrency = String(currency || "EUR");
    var formatterOptions = Object.assign({ style: "currency", currency: resolvedCurrency }, options || {});

    try {
      return new Intl.NumberFormat(getLocale(), formatterOptions).format(value);
    } catch (_) {
      return String(Math.round(value)) + " " + resolvedCurrency;
    }
  }

  function formatDate(value, options) {
    if (value == null || value === "") return "";

    var date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return String(value);

    try {
      return new Intl.DateTimeFormat(getLocale(), options || {}).format(date);
    } catch (_) {
      return date.toISOString();
    }
  }

  function formatDateTime(value, options) {
    return formatDate(value, options);
  }

  windowObject.CasanovaPortalI18n = {
    dictionary: dictionary,
    meta: meta,
    interpolate: interpolate,
    t: t,
    tf: tf,
    tt: tt,
    ttf: ttf,
    hashKey: hashKey,
    getLocale: getLocale,
    getLanguages: getLanguages,
    formatNumber: formatNumber,
    formatCurrency: formatCurrency,
    formatDate: formatDate,
    formatDateTime: formatDateTime
  };
})(window);
