import React, { useState } from "react";

import { tt } from "../../i18n/t.js";
import { formatDateES, formatWeekdayShort, normalizeTripDates } from "../../lib/formatters.js";
import Icon from "../Icon.jsx";

// Códigos WMO de Open-Meteo → icono Tabler.
function weatherIconName(code) {
  const c = Number(code);
  if (!Number.isFinite(c)) return "cloud";
  if (c === 0) return "sun";
  if (c === 45 || c === 48) return "fog";
  if ((c >= 51 && c <= 67) || (c >= 80 && c <= 82)) return "rain";
  if (c >= 71 && c <= 77) return "snow";
  if (c >= 95) return "storm";
  return "cloud";
}

function TripWeather({ weather }) {
  const days = Array.isArray(weather?.daily) ? weather.daily.slice(0, 5) : [];
  const provider = String(weather?.provider || "");
  if (!days.length) return null;

  return (
    <div className="cp-trip-weather" aria-label={tt("Previsión en destino")}>
      {days.map((day, idx) => {
        const tmin = Number(day?.t_min);
        const tmax = Number(day?.t_max);
        const base = day?.icon_base_uri || day?.iconBaseUri || "";
        return (
          <div key={idx} className="cp-trip-weather__day">
            <span className="cp-trip-weather__dow">{formatWeekdayShort(day?.date)}</span>
            {base && provider === "google-weather" ? (
              <img className="cp-trip-weather__img" src={String(base).endsWith(".svg") ? String(base) : `${base}.svg`} alt="" loading="lazy" />
            ) : (
              <Icon name={weatherIconName(day?.code)} size={20} />
            )}
            <span className="cp-trip-weather__temp">
              {Number.isFinite(tmax) ? Math.round(tmax) : "–"}°
              <span>{Number.isFinite(tmin) ? Math.round(tmin) : "–"}°</span>
            </span>
          </div>
        );
      })}
    </div>
  );
}

export default function TripHeader({ trip, map, weather, itineraryUrl, imageUrl = "", onBack, onPayments, showPayments = true }) {
  const range = normalizeTripDates(trip);
  const dates = [formatDateES(range.start), formatDateES(range.end)].filter((value) => value && value !== "—").join(" — ");
  // Igual que en el Inicio: se guarda la URL cargada para no perder fotos servidas desde caché.
  const [loadedUrl, setLoadedUrl] = useState("");
  const [failedUrl, setFailedUrl] = useState("");
  const showImage = Boolean(imageUrl) && failedUrl !== imageUrl;
  const ready = loadedUrl === imageUrl;
  const mapUrl = map?.url ? String(map.url) : "";
  const hasWeather = Array.isArray(weather?.daily) && weather.daily.length > 0;
  const hasBar = hasWeather || Boolean(mapUrl) || Boolean(itineraryUrl) || showPayments;

  return (
    <section className="cp-trip-hero" aria-labelledby="cp-trip-title">
      <div className={`cp-trip-hero__media ${showImage ? "has-image" : ""}`.trim()}>
        {showImage ? (
          <img
            className={`cp-trip-hero__img ${ready ? "is-ready" : ""}`.trim()}
            src={imageUrl}
            alt=""
            decoding="async"
            ref={(img) => { if (img?.complete && img.naturalWidth > 0) setLoadedUrl(imageUrl); }}
            onLoad={() => setLoadedUrl(imageUrl)}
            onError={() => setFailedUrl(imageUrl)}
          />
        ) : null}
        <div className="cp-trip-hero__shade" aria-hidden="true" />

        <a className="cp-trip-hero__back" href="?view=trips" onClick={onBack}>
          <Icon name="arrow-left" size={18} />
          {tt("Tus viajes")}
        </a>

        <div className="cp-trip-hero__body">
          <div className="cp-trip-hero__badges">
            {trip?.status ? <span className="cp-trip-hero__badge">{trip.status}</span> : null}
            {trip?.code ? <span className="cp-trip-hero__badge is-quiet">{trip.code}</span> : null}
          </div>
          <h1 className="cp-trip-hero__title" id="cp-trip-title">{trip?.title || tt("Viaje")}</h1>
          <p className="cp-trip-hero__meta">
            <Icon name="calendar" size={18} />
            {dates || tt("Fechas por confirmar")}
          </p>
        </div>
      </div>

      {hasBar ? (
        <div className="cp-trip-hero__bar">
          <TripWeather weather={weather} />
          <div className="cp-trip-hero__actions">
            {mapUrl ? (
              <a
                className="cp-btn cp-btn--ghost"
                href={mapUrl}
                target="_blank"
                rel="noreferrer"
                title={map?.type === "route" ? tt("Ver ruta en Google Maps") : tt("Ver mapa en Google Maps")}
              >
                <Icon name="map-pin" size={18} />
                {map?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
              </a>
            ) : null}
            {itineraryUrl ? (
              <a className="cp-btn cp-btn--ghost" href={itineraryUrl} target="_blank" rel="noreferrer noopener">
                <Icon name="file" size={18} />
                {tt("Programa PDF")}
              </a>
            ) : null}
            {showPayments ? (
              <button type="button" className="cp-btn cp-btn--primary" onClick={onPayments}>
                <Icon name="credit-card" size={18} />
                {tt("Ver pagos")}
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
    </section>
  );
}
