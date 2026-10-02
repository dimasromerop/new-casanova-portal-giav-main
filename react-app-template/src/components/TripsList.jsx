import React, { useEffect, useMemo, useState } from "react";

import BadgeLabel from "./BadgeLabel.jsx";
import Icon from "./Icon.jsx";
import { EmptyState, Notice, TableSkeleton } from "./ui.jsx";
import { tt, ttf } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { euro, formatDateES, normalizeTripDates } from "../lib/formatters.js";
import { getPaymentVariant, getStatusVariant } from "../lib/statusBadges.js";
import { pickTripHeroImage } from "../lib/tripServices.js";

const EMPTY_VALUE = "—";
const VIEW_STORAGE_KEY = "casanova-trips-list-view";
const MOJIBAKE_REPLACEMENTS = [
  ["Ã¡", "á"],
  ["Ã©", "é"],
  ["Ã­", "í"],
  ["Ã³", "ó"],
  ["Ãº", "ú"],
  ["Ã", "Á"],
  ["Ã‰", "É"],
  ["Ã", "Í"],
  ["Ã“", "Ó"],
  ["Ãš", "Ú"],
  ["Ã±", "ñ"],
  ["Ã‘", "Ñ"],
  ["â€”", "—"],
  ["â€“", "–"],
  ["â€˜", "‘"],
  ["â€™", "’"],
  ["â€œ", "“"],
  ["â€", "”"],
];

function buildFallbackYears() {
  const currentYear = new Date().getFullYear();
  const minYear = Math.max(2015, currentYear - 5);
  const years = [];
  for (let year = currentYear + 1; year >= minYear; year -= 1) {
    years.push(String(year));
  }
  return years;
}

function sanitizeText(value, fallback = EMPTY_VALUE) {
  if (value === null || value === undefined || value === "") return fallback;
  let text = String(value);
  MOJIBAKE_REPLACEMENTS.forEach(([source, target]) => {
    text = text.split(source).join(target);
  });
  return text;
}

function getTripYear(trip) {
  const candidate = trip?.date_start || trip?.date_end || trip?.date_range || "";
  const match = String(candidate).match(/(\d{4})/);
  return match ? match[1] : "";
}

function formatTripDate(value) {
  return sanitizeText(formatDateES(value));
}

function formatTripAmount(value, currency = "EUR") {
  if (typeof value !== "number" || Number.isNaN(value)) return EMPTY_VALUE;
  return sanitizeText(euro(value, currency));
}

function pickHeroImageFromSummary(summary) {
  return pickTripHeroImage(summary);
}

function getTripHeroImage(trip) {
  const directImage = trip?.hero_image_url || trip?.media?.image_url || trip?.trip?.hero_image_url || "";
  return typeof directImage === "string" ? directImage.trim() : "";
}

function getTripFinancials(trip) {
  const payments = trip?.payments || null;
  const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
  const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
  const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
  const pendingAmount = Number.isFinite(pendingCandidate)
    ? pendingCandidate
    : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
        ? Math.max(0, totalAmount - paidAmount)
        : Number.NaN);
  const hasPayments = Number.isFinite(totalAmount);
  const currency = payments?.currency || "EUR";
  const totalLabel = formatTripAmount(totalAmount, currency);
  const paidLabel = formatTripAmount(paidAmount, currency);
  const pendingLabel = formatTripAmount(pendingAmount, currency);
  const progressPct = hasPayments
    ? (totalAmount > 0
        ? Math.max(0, Math.min(100, (Number.isFinite(paidAmount) ? paidAmount : 0) / totalAmount * 100))
        : (pendingAmount <= 0.01 ? 100 : 0))
    : 0;
  const pendingText = hasPayments
    ? (pendingAmount <= 0.01
        ? tt("Sin importe pendiente")
        : ttf("Pendiente {amount}", { amount: pendingLabel }))
    : tt("Sin datos de pago");
  const summaryLabel = hasPayments
    ? (pendingAmount <= 0.01
        ? tt("Viaje pagado")
        : ttf("{paid} pagados", { paid: paidLabel }))
    : tt("Sin datos de pago");

  return {
    hasPayments,
    totalAmount,
    paidAmount,
    pendingAmount,
    totalLabel,
    paidLabel,
    pendingLabel,
    progressPct,
    pendingText,
    summaryLabel,
    badgeLabel: hasPayments
      ? (pendingAmount <= 0.01
          ? tt("Pagado")
          : ttf("Pendiente: {amount}", { amount: pendingLabel }))
      : tt("Sin datos"),
    badgeVariant: getPaymentVariant(
      Number.isFinite(pendingAmount) ? pendingAmount : Number.NaN,
      hasPayments
    ),
  };
}

function getTripNights(trip) {
  const range = normalizeTripDates(trip);
  if (!range.start || !range.end) return null;
  const start = new Date(range.start);
  const end = new Date(range.end);
  if (isNaN(start.getTime()) || isNaN(end.getTime())) return null;
  const diff = Math.round((end - start) / (1000 * 60 * 60 * 24));
  return diff > 0 ? diff : null;
}

function TripsCardsSkeleton({ count = 3 }) {
  return (
    <div className="cp-trips-grid" aria-hidden="true">
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="cp-trip-card is-skeleton">
          <span className="cp-trip-card__media" />
          <div className="cp-trip-card__body">
            <div className="cp-skeleton">
              <div className="cp-skeleton__line" />
              <div className="cp-skeleton__line" />
              <div className="cp-skeleton__line" />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

function PaymentMeter({ financials }) {
  if (!financials.hasPayments) {
    return <p className="cp-trip-card__pay-empty">{tt("Sin datos de pago")}</p>;
  }
  const settled = financials.pendingAmount <= 0.01;
  return (
    <div className="cp-trip-card__pay">
      <div className="cp-trip-card__pay-head">
        <span>{settled ? tt("Viaje pagado") : ttf("Pagado {amount}", { amount: financials.paidLabel })}</span>
        <strong>{financials.totalLabel}</strong>
      </div>
      <div
        className={`cp-meter ${settled ? "is-done" : ""}`.trim()}
        role="progressbar"
        aria-label={tt("Progreso de pago")}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(financials.progressPct)}
      >
        <span style={{ width: `${financials.progressPct}%` }} />
      </div>
      {!settled ? <p className="cp-trip-card__pay-due">{financials.pendingText}</p> : null}
    </div>
  );
}

function TripCard({ trip, onOpen }) {
  const range = normalizeTripDates(trip);
  const financials = getTripFinancials(trip);
  const title = sanitizeText(trip?.title, ttf("Expediente #{id}", { id: trip?.id }));
  const reference = sanitizeText(trip?.code, "");
  const statusLabel = sanitizeText(trip?.status, tt("Sin estado"));
  const statusVariant = getStatusVariant(statusLabel);
  const canPay = financials.hasPayments && financials.pendingAmount > 0.01;
  const heroImageUrl = getTripHeroImage(trip);
  // Se guarda la URL cargada (no un booleano) para no perder fotos servidas desde caché.
  const [loadedUrl, setLoadedUrl] = useState("");
  const [failedUrl, setFailedUrl] = useState("");
  const showImage = Boolean(heroImageUrl) && failedUrl !== heroImageUrl;
  const nights = getTripNights(trip);
  const isGroup = Boolean(trip?.is_group || trip?.group);
  const nightsLabel = nights
    ? (isGroup ? `${nights} ${tt("noches")} · ${tt("Grupo")}` : `${nights} ${tt("noches")}`)
    : "";

  return (
    <article className="cp-trip-card">
      <div className={`cp-trip-card__media ${showImage ? "has-image" : ""}`.trim()}>
        {showImage ? (
          <img
            className={loadedUrl === heroImageUrl ? "is-ready" : ""}
            src={heroImageUrl}
            alt=""
            loading="lazy"
            decoding="async"
            ref={(img) => { if (img?.complete && img.naturalWidth > 0) setLoadedUrl(heroImageUrl); }}
            onLoad={() => setLoadedUrl(heroImageUrl)}
            onError={() => setFailedUrl(heroImageUrl)}
          />
        ) : null}
        <BadgeLabel label={statusLabel} variant={statusVariant} className="cp-trip-card__status" />
      </div>

      <div className="cp-trip-card__body">
        {reference ? <p className="cp-trip-card__ref">{reference}</p> : null}
        <h2 className="cp-trip-card__title">
          <button type="button" className="cp-trip-card__link" onClick={() => onOpen(trip.id)}>{title}</button>
        </h2>
        <p className="cp-trip-card__meta">
          <span><Icon name="calendar" size={16} />{formatTripDate(range.start)} — {formatTripDate(range.end)}</span>
          {nightsLabel ? <span><Icon name="bed" size={16} />{nightsLabel}</span> : null}
        </p>
        <PaymentMeter financials={financials} />
      </div>

      <div className="cp-trip-card__foot">
        {canPay ? (
          <button type="button" className="cp-btn cp-btn--sm" onClick={() => onOpen(trip.id, "payments")}>
            {tt("Pagar")}
          </button>
        ) : <span />}
        <button type="button" className="cp-btn cp-btn--primary cp-btn--sm" onClick={() => onOpen(trip.id)}>
          {tt("Ver detalle")}
          <Icon name="arrow-right" size={16} />
        </button>
      </div>
    </article>
  );
}

export default function TripsList({ mock, onOpen, dashboard }) {
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [years, setYears] = useState(() => buildFallbackYears());
  const [view, setView] = useState(() => {
    if (typeof window === "undefined") return "cards";
    const stored = window.localStorage.getItem(VIEW_STORAGE_KEY);
    return stored === "table" ? "table" : "cards";
  });
  const [trips, setTrips] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  useEffect(() => {
    if (typeof window === "undefined") return;
    window.localStorage.setItem(VIEW_STORAGE_KEY, view);
  }, [view]);

  useEffect(() => {
    if (mock) return;
    let active = true;
    setLoading(true);
    setError("");

    (async () => {
      try {
        const params = new URLSearchParams();
        if (year) params.set("year", year);
        const query = params.toString();
        const data = await api(`/expedientes${query ? `?${query}` : ""}`);
        if (!active) return;

        setTrips(Array.isArray(data.items) ? data.items : []);
        const serverYears = Array.isArray(data.years) && data.years.length
          ? data.years.map((value) => String(value))
          : buildFallbackYears();
        setYears(serverYears);
        if (serverYears.length && !serverYears.includes(year)) {
          setYear(serverYears[0]);
        }
      } catch (err) {
        if (!active) return;
        setError(err?.message || tt("No se han podido cargar los viajes."));
        setTrips([]);
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, [year, mock]);

  useEffect(() => {
    if (!mock) return;

    const allMockTrips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
    const dashboardHeroTripId = Number(dashboard?.next_trip?.id || 0);
    const dashboardHeroImage = pickHeroImageFromSummary(dashboard?.next_trip_summary);
    const extractedYears = Array.from(
      new Set(allMockTrips.map((trip) => getTripYear(trip)).filter(Boolean))
    ).sort((left, right) => right.localeCompare(left));
    const serverYears = extractedYears.length ? extractedYears : buildFallbackYears();
    const effectiveYear = serverYears.includes(year) ? year : serverYears[0];
    const filteredTrips = allMockTrips
      .filter((trip) => {
        const tripYear = getTripYear(trip);
        return !effectiveYear || !tripYear || tripYear === effectiveYear;
      })
      .map((trip) => {
        if (
          dashboardHeroImage &&
          dashboardHeroTripId > 0 &&
          Number(trip?.id || 0) === dashboardHeroTripId &&
          !getTripHeroImage(trip)
        ) {
          return {
            ...trip,
            hero_image_url: dashboardHeroImage,
          };
        }

        return trip;
      });

    setYears(serverYears);
    if (effectiveYear !== year) {
      setYear(effectiveYear);
    }
    setTrips(filteredTrips);
    setError("");
    setLoading(false);
  }, [dashboard?.trips, mock, year]);

  // Filtered trips by search and status
  const filteredTrips = useMemo(() => {
    let result = trips;

    if (search.trim()) {
      const q = search.trim().toLowerCase();
      result = result.filter((trip) => {
        const title = (trip?.title || "").toLowerCase();
        const code = (trip?.code || "").toLowerCase();
        const id = String(trip?.id || "").toLowerCase();
        return title.includes(q) || code.includes(q) || id.includes(q);
      });
    }

    if (statusFilter !== "all") {
      result = result.filter((trip) => {
        const status = (trip?.status || "").toLowerCase().trim();
        if (statusFilter === "confirmed") {
          return status === "confirmado" || status === "confirmed";
        }
        return status !== "confirmado" && status !== "confirmed";
      });
    }

    return result;
  }, [trips, search, statusFilter]);

  // Stats computed from all trips (not filtered)
  const stats = useMemo(() => {
    const total = trips.length;
    let confirmed = 0;
    let pending = 0;
    let totalBilled = 0;
    let totalPaid = 0;

    trips.forEach((trip) => {
      const status = (trip?.status || "").toLowerCase().trim();
      if (status === "confirmado" || status === "confirmed") {
        confirmed += 1;
      } else {
        pending += 1;
      }
      const fin = getTripFinancials(trip);
      if (Number.isFinite(fin.totalAmount)) totalBilled += fin.totalAmount;
      if (Number.isFinite(fin.paidAmount)) totalPaid += fin.paidAmount;
    });

    return { total, confirmed, pending, totalBilled, totalPaid };
  }, [trips]);

  const hasTrips = filteredTrips.length > 0;
  const showCards = view !== "table";

  return (
    <div className="cp-content cp-trips">
      {!loading && trips.length > 0 ? (
        <dl className="cp-trips-stats">
          <div className="cp-trips-stat">
            <dt>{tt("Total viajes")}</dt>
            <dd>{stats.total}</dd>
            <span>{ttf("en {year}", { year })}</span>
          </div>
          <div className="cp-trips-stat">
            <dt>{tt("Confirmados")}</dt>
            <dd>{stats.confirmed}</dd>
            <span>{tt("listo para viajar")}</span>
          </div>
          <div className="cp-trips-stat">
            <dt>{tt("Total facturado")}</dt>
            <dd>{euro(stats.totalBilled)}</dd>
            <span>{ttf("{paid} pagados", { paid: euro(stats.totalPaid) })}</span>
          </div>
        </dl>
      ) : null}

      <div className="cp-trips-toolbar">
        <label className="cp-trips-search">
          <span className="cp-sr-only">{tt("Buscar viaje...")}</span>
          <Icon name="search" size={18} />
          <input
            type="search"
            placeholder={tt("Buscar viaje...")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </label>

        <div className="cp-trips-toolbar__filters">
          <label className="cp-select">
            <span className="cp-sr-only">{tt("Estado")}</span>
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="all">{tt("Estado: Todos")}</option>
              <option value="confirmed">{tt("Confirmado")}</option>
              <option value="pending">{tt("Sin estado")}</option>
            </select>
            <Icon name="chevron" size={16} />
          </label>

          <label className="cp-select">
            <span className="cp-sr-only">{tt("Fechas")}</span>
            <select value={year} onChange={(event) => setYear(event.target.value)}>
              {years.map((optionYear) => (
                <option key={optionYear} value={optionYear}>{optionYear}</option>
              ))}
            </select>
            <Icon name="chevron" size={16} />
          </label>

          <div className="cp-segmented" role="group" aria-label={tt("Cambiar vista de viajes")}>
            <button
              type="button"
              className={showCards ? "is-active" : ""}
              aria-pressed={showCards}
              aria-label={tt("Tarjetas")}
              title={tt("Tarjetas")}
              onClick={() => setView("cards")}
            >
              <Icon name="grid" size={18} />
            </button>
            <button
              type="button"
              className={showCards ? "" : "is-active"}
              aria-pressed={!showCards}
              aria-label={tt("Tabla")}
              title={tt("Tabla")}
              onClick={() => setView("table")}
            >
              <Icon name="list" size={18} />
            </button>
          </div>
        </div>
      </div>

      <div className="cp-trips-list">
        {error ? (
          <Notice variant="warn" title={tt("Error al cargar los viajes")}>
            {sanitizeText(error)}
          </Notice>
        ) : null}

        {loading ? (
          showCards ? <TripsCardsSkeleton /> : <TableSkeleton rows={6} cols={5} />
        ) : !hasTrips ? (
          <EmptyState title={tt("No hay viajes disponibles")} icon="luggage">
            {tt("No hay viajes disponibles para el año seleccionado.")}
          </EmptyState>
        ) : showCards ? (
          <div className="cp-trips-grid">
            {filteredTrips.map((trip) => (
              <TripCard key={trip.id} trip={trip} onOpen={onOpen} />
            ))}
          </div>
        ) : (
          <div className="cp-table-wrap cp-trips-table-wrap">
            <table className="cp-table cp-trips-table">
              <thead>
                <tr>
                  <th>{tt("Viaje")}</th>
                  <th>{tt("Fechas")}</th>
                  <th>{tt("Estado")}</th>
                  <th className="num">{tt("Total")}</th>
                  <th>{tt("Pagado")}</th>
                  <th><span className="cp-sr-only">{tt("Acciones")}</span></th>
                </tr>
              </thead>
              <tbody>
                {filteredTrips.map((trip) => {
                  const range = normalizeTripDates(trip);
                  const financials = getTripFinancials(trip);
                  const reference = sanitizeText(trip?.code, ttf("Expediente #{id}", { id: trip?.id }));
                  const title = sanitizeText(trip?.title, reference);
                  const statusLabel = sanitizeText(trip?.status, tt("Sin estado"));
                  const canPay = financials.hasPayments && financials.pendingAmount > 0.01;

                  return (
                    <tr key={trip.id}>
                      <td>
                        <button type="button" className="cp-trips-table__title" onClick={() => onOpen(trip.id)}>{title}</button>
                        <div className="cp-trips-table__sub">{reference}</div>
                      </td>
                      <td className="cp-trips-table__dates">{formatTripDate(range.start)} — {formatTripDate(range.end)}</td>
                      <td>
                        <BadgeLabel label={statusLabel} variant={getStatusVariant(statusLabel)} />
                      </td>
                      <td className="num">{financials.totalLabel}</td>
                      <td>
                        <div className="cp-trips-table__paid">
                          <span>{financials.paidLabel}</span>
                          <div className={`cp-meter ${financials.progressPct >= 100 ? "is-done" : ""}`.trim()} aria-hidden="true">
                            <span style={{ width: `${financials.progressPct}%` }} />
                          </div>
                        </div>
                      </td>
                      <td>
                        <div className="cp-trips-table__actions">
                          {canPay ? (
                            <button type="button" className="cp-btn cp-btn--sm" onClick={() => onOpen(trip.id, "payments")}>
                              {tt("Pagar")}
                            </button>
                          ) : null}
                          <button type="button" className="cp-btn cp-btn--primary cp-btn--sm" onClick={() => onOpen(trip.id)}>
                            {tt("Detalle")}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
