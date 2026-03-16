import React from "react";

import { tt, ttf } from "../../i18n/t.js";
import { formatDateES, normalizeTripDates } from "../../lib/formatters.js";
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

function weekdayShortES(isoDate) {
  if (!isoDate) return "";
  try {
    const d = new Date(String(isoDate));
    return new Intl.DateTimeFormat("es-ES", { weekday: "short" }).format(d);
  } catch {
    return "";
  }
}

function TripWeather({ weather }) {
  const days = Array.isArray(weather?.daily) ? weather.daily : [];
  const slice = days.slice(0, 5);
  const provider = String(weather?.provider || "");
  if (!slice.length) return null;

  const title = "Previsión en destino";

  function iconNode(day) {
    const base = day?.icon_base_uri || day?.iconBaseUri || "";
    if (base && provider === "google-weather") {
      const src = String(base).endsWith(".svg") ? String(base) : `${String(base)}.svg`;
      return <img className="cp-weather__icon-img" src={src} alt="" loading="lazy" />;
    }
    return <span aria-hidden="true">{weatherIconFor(day?.code)}</span>;
  }

  return (
    <div className="cp-weather" title={title}>
      <div className="cp-weather__title">{tt("Tiempo")}</div>
      <div className="cp-weather__row">
        {slice.map((day, idx) => {
          const tmin = Number(day?.t_min);
          const tmax = Number(day?.t_max);
          return (
            <div key={idx} className="cp-weather__day">
              <div className="cp-weather__dow">{weekdayShortES(day?.date)}</div>
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
    <div className="cp-card" style={{ marginTop: 14 }}>
      <div className="cp-card-header">
        <div>
          <div className="cp-card-title" style={{ fontSize: 20 }}>
            {trip?.title || tt("Viaje")}
          </div>
          <div className="cp-card-sub">
            {trip?.code || ttf("Expediente #{id}", { id: trip?.id || "—" })} · {trip?.status || "—"}
          </div>
        </div>
        <div className="cp-trip-head__meta-actions">
          <div className="cp-trip-head__meta">
            <span className="cp-strong">{tt("Fechas:")}</span> {formatDateES(range.start)} – {formatDateES(range.end)}
          </div>
          <div className="cp-trip-head__actions">
            {map?.url ? (
              <a
                className="cp-btn"
                href={String(map.url)}
                target="_blank"
                rel="noreferrer"
                title={map?.type === "route" ? tt("Ver ruta en Google Maps") : tt("Ver mapa en Google Maps")}
              >
                {map?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
              </a>
            ) : null}
            <button
              className="cp-btn"
              onClick={() => {
                setParam("tab", "payments");
              }}
            >
              {tt("Ver pagos")}
            </button>
            {itineraryUrl ? (
              <a
                className="cp-btn cp-btn--ghost"
                href={itineraryUrl}
                target="_blank"
                rel="noreferrer noopener"
              >
                {tt("Programa del viaje (PDF)")}
              </a>
            ) : null}
            <TripWeather weather={weather} />
          </div>
        </div>
      </div>
    </div>
  );
}
