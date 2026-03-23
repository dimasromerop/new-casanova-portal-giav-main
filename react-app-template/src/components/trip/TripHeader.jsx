import React from "react";

import { tt, ttf } from "../../i18n/t.js";
import { formatDateES, formatWeekdayShort, normalizeTripDates } from "../../lib/formatters.js";
import { setParam } from "../../lib/params.js";

function weatherIconFor(code) {
  const c = Number(code);
  if (!Number.isFinite(c)) return "";
  if (c === 0) return "☀️";
  if (c >= 1 && c <= 3) return "⛅";
  if (c === 45 || c === 48) return "🌫️";
  if (c >= 51 && c <= 57) return "🌦️";
  if (c >= 61 && c <= 67) return "🌧️";
  if (c >= 71 && c <= 77) return "🌨️";
  if (c >= 80 && c <= 82) return "🌧️";
  if (c >= 95) return "⛈️";
  return "🌤️";
}

function TripWeather({ weather }) {
  const days = Array.isArray(weather?.daily) ? weather.daily : [];
  const slice = days.slice(0, 5);
  const provider = String(weather?.provider || "");
  if (!slice.length) return null;

  function iconNode(day) {
    const base = day?.icon_base_uri || day?.iconBaseUri || "";
    if (base && provider === "google-weather") {
      const src = String(base).endsWith(".svg") ? String(base) : `${String(base)}.svg`;
      return <img className="cp-weather__icon-img" src={src} alt="" loading="lazy" />;
    }
    return <span aria-hidden="true">{weatherIconFor(day?.code)}</span>;
  }

  return (
    <div className="cp-weather" title={tt("Previsión en destino")}>
      <div className="cp-weather__title">{tt("Tiempo")}</div>
      <div className="cp-weather__row">
        {slice.map((day, idx) => {
          const tmin = Number(day?.t_min);
          const tmax = Number(day?.t_max);
          return (
            <div key={idx} className="cp-weather__day">
              <div className="cp-weather__dow">{formatWeekdayShort(day?.date)}</div>
              <div className="cp-weather__icon">{iconNode(day)}</div>
              <div className="cp-weather__temp">
                {Number.isFinite(tmax) ? Math.round(tmax) : "–"}° /{" "}
                {Number.isFinite(tmin) ? Math.round(tmin) : "–"}°
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default function TripHeader({ trip, map, weather, itineraryUrl }) {
  const range = normalizeTripDates(trip);

  return (
    <div className="cp-card cp-trip-header cp-mt-14">
      <div className="cp-card-header cp-trip-header__card-header">
        <div className="cp-trip-header__summary">
          <div className="cp-card-title cp-trip-header__title">
            {trip?.title || tt("Viaje")}
          </div>
          <div className="cp-card-sub cp-trip-header__sub">
            <span className="cp-strong">{trip?.code || trip?.id || "—"}</span>
            {trip?.status ? (
              <span className="cp-trip-header__status">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="12" height="12"><path d="M20 6L9 17l-5-5"/></svg>
                {trip.status}
              </span>
            ) : null}
            <span>{formatDateES(range.start)} — {formatDateES(range.end)}</span>
          </div>
          <div className="cp-trip-head__cta-group">
            {map?.url ? (
              <a
                className="cp-btn"
                href={String(map.url)}
                target="_blank"
                rel="noreferrer"
                title={map?.type === "route" ? tt("Ver ruta en Google Maps") : tt("Ver mapa en Google Maps")}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                {map?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
              </a>
            ) : null}

            <button
              type="button"
              className="cp-btn"
              onClick={() => {
                setParam("tab", "payments");
              }}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>
              {tt("Ver pagos")}
            </button>

            {itineraryUrl ? (
              <a
                className="cp-btn cp-btn--ghost"
                href={itineraryUrl}
                target="_blank"
                rel="noreferrer noopener"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                {tt("Programa PDF")}
              </a>
            ) : null}
          </div>
        </div>

        <div className="cp-trip-head__actions">
          <TripWeather weather={weather} />
        </div>
      </div>
    </div>
  );
}
