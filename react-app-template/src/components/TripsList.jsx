import React, { useEffect, useState } from "react";

import BadgeLabel from "./BadgeLabel.jsx";
import { Notice, TableSkeleton } from "./ui.jsx";
import { tt, ttf } from "../i18n/t.js";
import { api } from "../lib/api.js";
import { euro, formatDateES, normalizeTripDates } from "../lib/formatters.js";
import { getBonusesVariant, getPaymentVariant, getStatusVariant } from "../lib/statusBadges.js";

function buildFallbackYears() {
  const currentYear = new Date().getFullYear();
  const minYear = Math.max(2015, currentYear - 5);
  const years = [];
  for (let year = currentYear + 1; year >= minYear; year -= 1) {
    years.push(String(year));
  }
  return years;
}

export default function TripsList({ mock, onOpen, dashboard }) {
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [years, setYears] = useState(() => buildFallbackYears());
  const [trips, setTrips] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (mock) return;
    let active = true;
    setLoading(true);
    setError("");
    (async () => {
      try {
        const params = new URLSearchParams();
        if (year) {
          params.set("year", year);
        }
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
    const mockTrips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
    setTrips(mockTrips);
    setError("");
    setLoading(false);
    const extractedYears = Array.from(
      new Set(
        mockTrips
          .map((trip) => (trip?.date_range || "").match(/(\d{4})/g)?.[0])
          .filter(Boolean)
      )
    ).sort((left, right) => right.localeCompare(left));
    if (extractedYears.length) {
      setYears(extractedYears);
      if (!extractedYears.includes(year)) {
        setYear(extractedYears[0]);
      }
    } else {
      setYears(buildFallbackYears());
    }
  }, [dashboard?.trips, mock, year]);

  const hasTrips = trips.length > 0;

  return (
    <div className="cp-content">
      <div className="cp-card" style={{ background: "var(--surface)" }}>
        <div className="cp-card-header" style={{ gap: 14, alignItems: "center" }}>
          <div>
            <div className="cp-card-title">{tt("Tus viajes")}</div>
            <div className="cp-card-sub">{tt("Consulta fechas, pagos y servicios de cada expediente.")}</div>
          </div>
          <div style={{ marginLeft: "auto" }}>
            <select
              value={year}
              onChange={(event) => setYear(event.target.value)}
              className="cp-select"
              style={{ padding: "8px 10px", borderRadius: 10, border: "1px solid var(--border)", background: "var(--surface)", color: "var(--text)" }}
            >
              {years.map((optionYear) => (
                <option key={optionYear} value={optionYear}>{optionYear}</option>
              ))}
            </select>
          </div>
        </div>
        {error ? (
          <Notice variant="warn" title={tt("Error al cargar los viajes")}>
            {error}
          </Notice>
        ) : null}
        <div className="cp-table-wrap" style={{ marginTop: 14 }}>
          {loading ? (
            <TableSkeleton rows={6} cols={9} />
          ) : (
            <table width="100%" cellPadding="10" style={{ borderCollapse: "collapse" }}>
              <thead>
                <tr style={{ textAlign: "left", borderBottom: "1px solid var(--border)" }}>
                  <th style={{ width: 120 }}>{tt("Expediente")}</th>
                  <th>{tt("Viaje")}</th>
                  <th style={{ width: 140 }}>{tt("Inicio")}</th>
                  <th style={{ width: 140 }}>{tt("Fin")}</th>
                  <th style={{ width: 120 }}>{tt("Estado")}</th>
                  <th style={{ width: 110, textAlign: "right" }}>{tt("Total")}</th>
                  <th style={{ width: 160 }}>{tt("Pagos")}</th>
                  <th style={{ width: 120 }}>{tt("Bonos")}</th>
                  <th style={{ width: 180 }}></th>
                </tr>
              </thead>
              <tbody>
                {hasTrips ? (
                  trips.map((trip) => {
                    const range = normalizeTripDates(trip);
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
                    const currencyForTrip = payments?.currency || "EUR";
                    const totalLabel = hasPayments ? euro(totalAmount, currencyForTrip) : "-";
                    const paymentsLabelText = hasPayments
                      ? (pendingAmount <= 0.01
                          ? tt("Pagado")
                          : ttf("Pendiente: {amount}", { amount: euro(pendingAmount, currencyForTrip) }))
                      : tt("Sin datos");
                    const paymentsVariant = getPaymentVariant(
                      Number.isFinite(pendingAmount) ? pendingAmount : Number.NaN,
                      hasPayments
                    );
                    const bonusesAvailable = typeof trip?.bonuses?.available === "boolean" ? trip.bonuses.available : null;
                    const bonusesLabel = bonusesAvailable === null
                      ? tt("Sin datos")
                      : (bonusesAvailable ? tt("Disponibles") : tt("No disponibles"));
                    const bonusesVariant = getBonusesVariant(bonusesAvailable);
                    const statusLabel = trip.status || "";
                    const statusVariant = getStatusVariant(statusLabel);
                    return (
                      <tr key={trip.id} style={{ borderBottom: "1px solid var(--border)" }}>
                        <td>{trip.code || `#${trip.id}`}</td>
                        <td>
                          <div style={{ fontWeight: 600 }}>{trip.title}</div>
                          <div style={{ fontSize: 12, opacity: 0.75 }}>{trip.code ? ttf("ID {id}", { id: trip.id }) : ttf("Expediente {id}", { id: trip.id })}</div>
                        </td>
                        <td>{formatDateES(range.start)}</td>
                        <td>{formatDateES(range.end)}</td>
                        <td>
                          <BadgeLabel label={statusLabel} variant={statusVariant} />
                        </td>
                        <td style={{ textAlign: "right" }}>{totalLabel}</td>
                        <td>
                          <div className="cp-trip-payments-info">
                            <BadgeLabel label={paymentsLabelText} variant={paymentsVariant} />
                            {hasPayments && Number.isFinite(paidAmount) ? (
                              <div className="cp-trip-paid-amount">{tt("Pagado:")} {euro(paidAmount, currencyForTrip)}</div>
                            ) : null}
                          </div>
                        </td>
                        <td>
                          <BadgeLabel label={bonusesLabel} variant={bonusesVariant} />
                        </td>
                        <td style={{ display: "flex", gap: 10, justifyContent: "flex-end" }}>
                          <button className="cp-btn primary" style={{ whiteSpace: "nowrap" }} onClick={() => onOpen(trip.id)}>
                            {tt("Ver detalle")}
                          </button>
                          <button
                            className="cp-btn"
                            style={{ whiteSpace: "nowrap" }}
                            onClick={() => onOpen(trip.id, "payments")}
                          >
                            {tt("Pagar")}
                          </button>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={9} style={{ padding: 18, opacity: 0.8 }}>
                      {tt("No hay viajes disponibles para el año seleccionado.")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
