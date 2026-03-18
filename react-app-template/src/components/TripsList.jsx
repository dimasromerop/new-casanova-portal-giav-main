import React, { useEffect, useState } from "react";

import BadgeLabel from "./BadgeLabel.jsx";
import { Notice, ProgressBar, TableSkeleton } from "./ui.jsx";
import { tt, ttf } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { euro, formatDateES, normalizeTripDates } from "../lib/formatters.js";
import { getPaymentVariant, getStatusVariant } from "../lib/statusBadges.js";

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

function CalendarIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M7 2v4M17 2v4M3 9h18" />
      <rect x="3" y="4.5" width="18" height="16.5" rx="3.5" />
      <path d="M8 13h3M13 13h3M8 17h3M13 17h3" />
    </svg>
  );
}

function CardsViewIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <rect x="3" y="4" width="8" height="7" rx="2" />
      <rect x="13" y="4" width="8" height="7" rx="2" />
      <rect x="3" y="13" width="8" height="7" rx="2" />
      <rect x="13" y="13" width="8" height="7" rx="2" />
    </svg>
  );
}

function TableViewIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <rect x="3" y="4" width="18" height="16" rx="2.5" />
      <path d="M3 10h18M9 4v16M16 4v16" />
    </svg>
  );
}

function TripsCardsSkeleton({ count = 4 }) {
  return (
    <div className="cp-trips-skeleton-grid" aria-hidden="true">
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="cp-trips-skeleton-card">
          <span className="cp-trips-skeleton-line is-eyebrow" />
          <span className="cp-trips-skeleton-line is-title" />
          <span className="cp-trips-skeleton-line is-copy" />
          <span className="cp-trips-skeleton-line is-copy short" />
          <div className="cp-trips-skeleton-block">
            <span className="cp-trips-skeleton-line is-kicker" />
            <span className="cp-trips-skeleton-line is-copy" />
            <span className="cp-trips-skeleton-progress" />
          </div>
          <div className="cp-trips-skeleton-actions">
            <span className="cp-trips-skeleton-button is-primary" />
            <span className="cp-trips-skeleton-button" />
          </div>
        </div>
      ))}
    </div>
  );
}

function TripCard({ trip, onOpen }) {
  const range = normalizeTripDates(trip);
  const financials = getTripFinancials(trip);
  const title = sanitizeText(trip?.title, ttf("Expediente #{id}", { id: trip?.id }));
  const reference = sanitizeText(trip?.code, ttf("Expediente #{id}", { id: trip?.id }));
  const statusLabel = sanitizeText(trip?.status, tt("Sin estado"));
  const statusVariant = getStatusVariant(statusLabel);
  const startLabel = formatTripDate(range.start);
  const endLabel = formatTripDate(range.end);
  const canPay = financials.hasPayments && financials.pendingAmount > 0.01;

  return (
    <article className="cp-trip-card">
      <div className="cp-trip-card__head">
        <div className="cp-trip-card__reference">{reference}</div>
        <BadgeLabel label={statusLabel} variant={statusVariant} className="cp-trip-card__status" />
      </div>

      <div className="cp-trip-card__title">{title}</div>
      <div className="cp-trip-card__sub">
        {trip?.code
          ? ttf("Referencia interna #{id}", { id: trip.id })
          : ttf("Expediente #{id}", { id: trip.id })}
      </div>

      <div className="cp-trip-card__dates">
        <span className="cp-trip-card__dates-icon">
          <CalendarIcon />
        </span>
        <span className="cp-trip-card__dates-range">
          <span>{startLabel}</span>
          <span className="cp-trip-card__dates-separator">-</span>
          <span>{endLabel}</span>
        </span>
      </div>

      <div className="cp-trip-card__payment">
        <div className="cp-trip-card__payment-head">
          <div>
            <span className="cp-trip-card__payment-kicker">{tt("Resumen de pago")}</span>
            <strong className="cp-trip-card__payment-copy">{financials.summaryLabel}</strong>
          </div>
          <span className="cp-trip-card__payment-total">{financials.totalLabel}</span>
        </div>

        <div className="cp-trip-card__progress">
          <ProgressBar
            value={financials.progressPct}
            variant="trip-card"
            label={tt("Progreso de pago")}
          />
        </div>

        <div className="cp-trip-card__payment-meta">
          <span>{ttf("Pagado {amount}", { amount: financials.paidLabel })}</span>
          <span>{financials.pendingText}</span>
        </div>
      </div>

      <div className="cp-trip-card__actions">
        <button type="button" className="cp-btn primary cp-trip-card__cta" onClick={() => onOpen(trip.id)}>
          {tt("Ver detalle")}
        </button>
        {canPay ? (
          <button
            type="button"
            className="cp-btn cp-btn--ghost cp-trip-card__secondary"
            onClick={() => onOpen(trip.id, "payments")}
          >
            {tt("Pagar")}
          </button>
        ) : null}
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
        setError(err?.message || "No se han podido cargar los viajes.");
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
    const extractedYears = Array.from(
      new Set(allMockTrips.map((trip) => getTripYear(trip)).filter(Boolean))
    ).sort((left, right) => right.localeCompare(left));
    const serverYears = extractedYears.length ? extractedYears : buildFallbackYears();
    const effectiveYear = serverYears.includes(year) ? year : serverYears[0];
    const filteredTrips = allMockTrips.filter((trip) => {
      const tripYear = getTripYear(trip);
      return !effectiveYear || !tripYear || tripYear === effectiveYear;
    });

    setYears(serverYears);
    if (effectiveYear !== year) {
      setYear(effectiveYear);
    }
    setTrips(filteredTrips);
    setError("");
    setLoading(false);
  }, [dashboard?.trips, mock, year]);

  const hasTrips = trips.length > 0;
  const showCards = view !== "table";

  return (
    <div className="cp-content">
      <div className="cp-card cp-trips-list">
        <div className="cp-trips-list__header">
          <div>
            <div className="cp-card-title">{tt("Tus viajes")}</div>
            <div className="cp-card-sub">{tt("Consulta fechas, pagos y estado de cada expediente sin perder legibilidad en móvil.")}</div>
          </div>

          <div className="cp-trips-list__controls">
            <div className="cp-trips-view-toggle" role="group" aria-label={tt("Cambiar vista de viajes")}>
              <button
                type="button"
                className={`cp-trips-view-toggle__btn ${showCards ? "is-active" : ""}`.trim()}
                aria-pressed={showCards}
                onClick={() => setView("cards")}
              >
                <CardsViewIcon />
                <span>{tt("Tarjetas")}</span>
              </button>
              <button
                type="button"
                className={`cp-trips-view-toggle__btn ${showCards ? "" : "is-active"}`.trim()}
                aria-pressed={!showCards}
                onClick={() => setView("table")}
              >
                <TableViewIcon />
                <span>{tt("Tabla")}</span>
              </button>
            </div>

            <label className="cp-trips-list__filter">
              <span>{tt("Año")}</span>
              <select value={year} onChange={(event) => setYear(event.target.value)} className="cp-select cp-trips-list__select">
                {years.map((optionYear) => (
                  <option key={optionYear} value={optionYear}>{optionYear}</option>
                ))}
              </select>
            </label>
          </div>
        </div>

        {error ? (
          <Notice variant="warn" title={tt("Error al cargar los viajes")}>
            {sanitizeText(error)}
          </Notice>
        ) : null}

        {loading ? (
          showCards ? <TripsCardsSkeleton /> : <TableSkeleton rows={6} cols={8} />
        ) : !hasTrips ? (
          <div className="cp-trips-empty">
            <div className="cp-trips-empty__title">{tt("No hay viajes disponibles")}</div>
            <div className="cp-trips-empty__copy">{tt("No hay viajes disponibles para el año seleccionado.")}</div>
          </div>
        ) : showCards ? (
          <div className="cp-trips-grid">
            {trips.map((trip) => (
              <TripCard key={trip.id} trip={trip} onOpen={onOpen} />
            ))}
          </div>
        ) : (
          <div className="cp-table-wrap cp-trips-table-wrap">
            <table className="cp-trips-table">
              <thead>
                <tr>
                  <th>{tt("Expediente")}</th>
                  <th>{tt("Viaje")}</th>
                  <th>{tt("Inicio")}</th>
                  <th>{tt("Fin")}</th>
                  <th>{tt("Estado")}</th>
                  <th className="is-right">{tt("Total")}</th>
                  <th>{tt("Pagos")}</th>
                  <th>{tt("Acciones")}</th>
                </tr>
              </thead>
              <tbody>
                {trips.map((trip) => {
                  const range = normalizeTripDates(trip);
                  const financials = getTripFinancials(trip);
                  const reference = sanitizeText(trip?.code, ttf("Expediente #{id}", { id: trip?.id }));
                  const title = sanitizeText(trip?.title, reference);
                  const statusLabel = sanitizeText(trip?.status, tt("Sin estado"));
                  const canPay = financials.hasPayments && financials.pendingAmount > 0.01;

                  return (
                    <tr key={trip.id}>
                      <td>{reference}</td>
                      <td>
                        <div className="cp-trips-table__title">{title}</div>
                        <div className="cp-trips-table__sub">
                          {trip?.code
                            ? ttf("Expediente #{id}", { id: trip.id })
                            : tt("Sin referencia externa")}
                        </div>
                      </td>
                      <td>{formatTripDate(range.start)}</td>
                      <td>{formatTripDate(range.end)}</td>
                      <td>
                        <BadgeLabel label={statusLabel} variant={getStatusVariant(statusLabel)} />
                      </td>
                      <td className="is-right cp-trips-table__amount">{financials.totalLabel}</td>
                      <td>
                        <div className="cp-trip-payments-info">
                          <BadgeLabel label={financials.badgeLabel} variant={financials.badgeVariant} />
                          <div className="cp-trip-paid-amount">{ttf("Pagado: {amount}", { amount: financials.paidLabel })}</div>
                        </div>
                      </td>
                      <td>
                        <div className="cp-trips-table__actions">
                          <button type="button" className="cp-btn primary" onClick={() => onOpen(trip.id)}>
                            {tt("Ver detalle")}
                          </button>
                          {canPay ? (
                            <button type="button" className="cp-btn cp-btn--ghost" onClick={() => onOpen(trip.id, "payments")}>
                              {tt("Pagar")}
                            </button>
                          ) : null}
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
