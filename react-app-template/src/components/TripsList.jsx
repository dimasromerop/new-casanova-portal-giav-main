import React, { useEffect, useMemo, useState } from "react";

import BadgeLabel from "./BadgeLabel.jsx";
import { Notice, ProgressBar, TableSkeleton } from "./ui.jsx";
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

function CalendarIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  );
}

function PeopleIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
    </svg>
  );
}

function CardsViewIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
    </svg>
  );
}

function TableViewIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <path d="M3 6h18M3 12h18M3 18h18" />
    </svg>
  );
}

function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <circle cx="11" cy="11" r="8" />
      <path d="M21 21l-4.35-4.35" />
    </svg>
  );
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

function TripsCardsSkeleton({ count = 4 }) {
  return (
    <div className="cp-trips-skeleton-grid" aria-hidden="true">
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="cp-trips-skeleton-card">
          <span className="cp-trips-skeleton-hero" />
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
  const heroImageUrl = getTripHeroImage(trip);
  const [heroReady, setHeroReady] = useState(!heroImageUrl);
  const [heroError, setHeroError] = useState(false);
  const nights = getTripNights(trip);
  const isGroup = Boolean(trip?.is_group || trip?.group);
  const nightsLabel = nights
    ? (isGroup ? `${nights} ${tt("noches")} · ${tt("Grupo")}` : `${nights} ${tt("noches")}`)
    : null;
  const fillClass = financials.progressPct >= 100
    ? "is-green"
    : financials.progressPct > 0
      ? "is-green"
      : "is-empty";

  useEffect(() => {
    setHeroReady(!heroImageUrl);
    setHeroError(false);
  }, [heroImageUrl]);

  const showHeroImage = Boolean(heroImageUrl) && !heroError;

  return (
    <article className="cp-trip-card">
      <div className={`cp-trip-card__hero ${showHeroImage ? "has-image" : "is-fallback"} ${showHeroImage && !heroReady ? "is-loading" : ""}`.trim()}>
        {showHeroImage ? (
          <img
            className={`cp-trip-card__hero-img ${heroReady ? "is-ready" : ""}`.trim()}
            src={heroImageUrl}
            alt=""
            loading="lazy"
            onLoad={() => setHeroReady(true)}
            onError={() => setHeroError(true)}
          />
        ) : null}
        <div className="cp-trip-card__hero-overlay" aria-hidden="true" />
        <div className="cp-trip-card__hero-top">
          <div className="cp-trip-card__reference">{reference}</div>
          <BadgeLabel label={statusLabel} variant={statusVariant} className="cp-trip-card__status" />
        </div>
      </div>

      <div className="cp-trip-card__body">
        <div className="cp-trip-card__title">{title}</div>
        <div className="cp-trip-card__sub">
          {trip?.code
            ? ttf("Referencia interna #{id}", { id: trip.id })
            : ttf("Expediente #{id}", { id: trip.id })}
        </div>

        <div className="cp-trip-card__chips">
          <div className="cp-trip-card__chip">
            <CalendarIcon />
            {startLabel} — {endLabel}
          </div>
          {nightsLabel ? (
            <div className="cp-trip-card__chip">
              <PeopleIcon />
              {nightsLabel}
            </div>
          ) : null}
        </div>

        <div className="cp-trip-card__payment">
          <div className="cp-trip-card__payment-head">
            <span className="cp-trip-card__payment-copy">{financials.summaryLabel}</span>
            <span className="cp-trip-card__payment-total">{financials.totalLabel}</span>
          </div>

          <div className="cp-trip-card__progress">
            <div
              className={`cp-trips-table__minibar-fill ${fillClass}`}
              style={{ width: `${financials.progressPct}%`, height: "100%", borderRadius: "3px" }}
            />
          </div>

          <div className="cp-trip-card__payment-meta">
            <span>{ttf("Pagado {amount}", { amount: financials.paidLabel })}</span>
            <span>{financials.pendingText}</span>
          </div>
        </div>
      </div>

      <div className="cp-trip-card__actions">
        <button type="button" className="cp-trip-card__cta" onClick={() => onOpen(trip.id)}>
          {tt("Ver detalle")}
        </button>
        {canPay ? (
          <button
            type="button"
            className="cp-trip-card__secondary"
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
    <div className="cp-content">
      {/* ─── Stats row ─── */}
      {!loading && trips.length > 0 ? (
        <div className="cp-trips-stats">
          <div className="cp-trips-stat">
            <div className="cp-trips-stat__label">{tt("Total viajes")}</div>
            <div className="cp-trips-stat__val">{stats.total}</div>
            <div className="cp-trips-stat__sub">{ttf("en {year}", { year })}</div>
          </div>
          <div className="cp-trips-stat">
            <div className="cp-trips-stat__label">{tt("Confirmados")}</div>
            <div className="cp-trips-stat__val" style={{ color: "var(--accent)" }}>{stats.confirmed}</div>
            <div className="cp-trips-stat__sub">{tt("listo para viajar")}</div>
          </div>
          <div className="cp-trips-stat">
            <div className="cp-trips-stat__label">{tt("Pendientes")}</div>
            <div className="cp-trips-stat__val" style={{ color: "var(--gold)" }}>{stats.pending}</div>
            <div className="cp-trips-stat__sub">{tt("sin estado")}</div>
          </div>
          <div className="cp-trips-stat">
            <div className="cp-trips-stat__label">{tt("Total facturado")}</div>
            <div className="cp-trips-stat__val">{euro(stats.totalBilled)}</div>
            <div className="cp-trips-stat__sub">{ttf("{paid} pagados", { paid: euro(stats.totalPaid) })}</div>
          </div>
        </div>
      ) : null}

      {/* ─── Controls row ─── */}
      <div className="cp-trips-list__controls">
        <div className="cp-trips-list__controls-left">
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

          <div className="cp-trips-search">
            <SearchIcon />
            <input
              type="text"
              placeholder={tt("Buscar viaje...")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>

        <div className="cp-trips-list__controls-right">
          <select
            className="cp-trips-list__select"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="all">{tt("Estado: Todos")}</option>
            <option value="confirmed">{tt("Confirmado")}</option>
            <option value="pending">{tt("Sin estado")}</option>
          </select>

          <select
            value={year}
            onChange={(event) => setYear(event.target.value)}
            className="cp-trips-list__select"
          >
            {years.map((optionYear) => (
              <option key={optionYear} value={optionYear}>{optionYear}</option>
            ))}
          </select>
        </div>
      </div>

      {/* ─── Content ─── */}
      <div className="cp-trips-list">
        {error ? (
          <Notice variant="warn" title={tt("Error al cargar los viajes")}>
            {sanitizeText(error)}
          </Notice>
        ) : null}

        {loading ? (
          showCards ? <TripsCardsSkeleton /> : <TableSkeleton rows={6} cols={6} />
        ) : !hasTrips ? (
          <div className="cp-trips-empty">
            <div className="cp-trips-empty__title">{tt("No hay viajes disponibles")}</div>
            <div className="cp-trips-empty__copy">{tt("No hay viajes disponibles para el año seleccionado.")}</div>
          </div>
        ) : showCards ? (
          <div className="cp-trips-grid">
            {filteredTrips.map((trip) => (
              <TripCard key={trip.id} trip={trip} onOpen={onOpen} />
            ))}
          </div>
        ) : (
          <div className="cp-trips-table-wrap">
            <table className="cp-trips-table">
              <thead>
                <tr>
                  <th>{tt("Viaje")}</th>
                  <th>{tt("Fechas")}</th>
                  <th>{tt("Estado")}</th>
                  <th className="is-right">{tt("Total")}</th>
                  <th>{tt("Pagado")}</th>
                  <th></th>
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
                  const fillClass = financials.progressPct >= 100
                    ? "is-green"
                    : financials.progressPct > 0
                      ? "is-green"
                      : "is-empty";

                  return (
                    <tr key={trip.id}>
                      <td>
                        <div className="cp-trips-table__title">{title}</div>
                        <div className="cp-trips-table__sub">
                          {tt("Ref.")} {reference}
                          {trip?.id ? ` · #${trip.id}` : ""}
                        </div>
                      </td>
                      <td>{formatTripDate(range.start)} — {formatTripDate(range.end)}</td>
                      <td>
                        <BadgeLabel label={statusLabel} variant={getStatusVariant(statusLabel)} />
                      </td>
                      <td className="is-right cp-trips-table__amount">{financials.totalLabel}</td>
                      <td>
                        <div className="cp-trips-table__paid-cell">
                          {financials.paidLabel}
                          <div className="cp-trips-table__minibar">
                            <div
                              className={`cp-trips-table__minibar-fill ${fillClass}`}
                              style={{ width: `${financials.progressPct}%` }}
                            />
                          </div>
                        </div>
                      </td>
                      <td>
                        <div className="cp-trips-table__actions">
                          <button type="button" className="cp-btn primary" onClick={() => onOpen(trip.id)}>
                            {tt("Detalle")}
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
