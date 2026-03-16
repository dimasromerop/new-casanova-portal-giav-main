import React, { useEffect, useMemo, useState } from "react";

import BadgeLabel from "../BadgeLabel.jsx";
import MessagesTimeline from "../MessagesTimeline.jsx";
import { Notice, Skeleton } from "../ui.jsx";
import { tt, ttf } from "../../i18n/t.js";
import { api } from "../../lib/api.js";
import { euro, formatDateES } from "../../lib/formatters.js";
import { readParams, setParam } from "../../lib/params.js";
import { getHistoryBadge, getInvoiceVariant } from "../../lib/statusBadges.js";
import PaymentActions from "./PaymentActions.jsx";
import ServiceList, { ServiceItem } from "./ServiceList.jsx";
import Tabs from "./TripTabs.jsx";
import TripHeader from "./TripHeader.jsx";

export default function TripDetailView({
  mock,
  expediente,
  dashboard,
  readOnly = false,
  readOnlyMessage = "",
  onLatestTs,
  onSeen,
  mulligansEnabled = true,
  KpiCard,
  paymentIcons = {},
}) {
  const BriefcaseIcon = paymentIcons.IconBriefcase ?? (() => null);
  const ShieldCheckIcon = paymentIcons.IconShieldCheck ?? (() => null);
  const ClockArrowIcon = paymentIcons.IconClockArrow ?? (() => null);
  const SparkleIcon = paymentIcons.IconSparkle ?? (() => null);

  const trips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
  const fallbackTrip = trips.find((trip) => String(trip.id) === String(expediente)) || { id: expediente };

  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        setLoading(true);
        setErr(null);
        const params = new URLSearchParams();
        if (mock) params.set("mock", "1");
        const refreshFlag = (() => {
          const urlParams = new URLSearchParams(window.location.search);
          return (
            urlParams.get("pay_status") === "checking" ||
            urlParams.get("payment") === "success" ||
            urlParams.get("refresh") === "1"
          );
        })();
        if (refreshFlag) params.set("refresh", "1");
        const qs = params.toString() ? `?${params.toString()}` : "";
        const data = await api(`/trip/${encodeURIComponent(String(expediente))}${qs}`);
        if (!alive) return;
        setDetail(data);
      } catch (error) {
        if (!alive) return;
        setErr(error);
        setDetail(null);
      } finally {
        if (!alive) return;
        setLoading(false);
      }
    })();

    return () => {
      alive = false;
    };
  }, [expediente, mock]);

  const trip = detail?.trip || fallbackTrip;
  const payments = detail?.payments || null;
  const pkg = detail?.package || null;
  const extras = Array.isArray(detail?.extras) ? detail.extras : [];
  const packageServices = Array.isArray(pkg?.services) ? pkg.services : [];
  const hasServices = Boolean(pkg) || extras.length > 0;
  const invoices = Array.isArray(detail?.invoices) ? detail.invoices : [];
  const bonuses = detail?.bonuses ?? { available: false, items: [] };
  const voucherItems = Array.isArray(bonuses.items) ? bonuses.items : [];
  const chargeHistory = payments?.history ?? [];
  const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
  const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
  const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
  const pendingAmount = Number.isFinite(pendingCandidate)
    ? pendingCandidate
    : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
        ? Math.max(0, totalAmount - paidAmount)
        : null);
  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const currency = payments?.currency || "EUR";
  const mulligansUsed = payments?.mulligans_used ?? 0;
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount, currency) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount, currency) : "—";
  const pendingLabel = pendingAmount !== null && Number.isFinite(pendingAmount) ? euro(pendingAmount, currency) : "—";

  const paymentKpiItems = payments
    ? [
        { key: "total", label: "Total", value: totalLabel, icon: <BriefcaseIcon />, colorClass: "is-salmon" },
        { key: "paid", label: tt("Pagado"), value: paidLabel, icon: <ShieldCheckIcon />, colorClass: "is-blue" },
        { key: "pending", label: tt("Pendiente"), value: pendingLabel, icon: <ClockArrowIcon />, colorClass: "is-green" },
        ...(mulligansEnabled
          ? [{
              key: "mulligans",
              label: "Mulligans usados",
              value: mulligansUsed.toLocaleString("es-ES"),
              icon: <SparkleIcon />,
              colorClass: "is-lilac",
            }]
          : []),
      ]
    : [];

  const bonusDisabledReason = (type) => {
    if (!isPaid) return "El viaje debe estar pagado para descargar los bonos.";
    return type === "view"
      ? "No hay una vista previa disponible para este bono."
      : "No hay un PDF disponible para este bono.";
  };

  const renderBonusButton = (label, url, type) => {
    if (url) {
      return (
        <a
          className="cp-btn cp-btn--ghost cp-bonus-btn"
          href={url}
          target="_blank"
          rel="noreferrer noopener"
        >
          {label}
        </a>
      );
    }

    return (
      <button
        type="button"
        className="cp-btn cp-btn--ghost cp-bonus-btn"
        disabled
        title={bonusDisabledReason(type)}
      >
        {label}
      </button>
    );
  };

  const title = trip?.title || ttf("Expediente #{id}", { id: expediente });
  const tab = readParams().tab;

  const resolvedTrip = useMemo(() => {
    if (!detail?.trip) return fallbackTrip;
    const detailTrip = detail.trip;
    const fallbackStatus = fallbackTrip.status;
    const status = detailTrip.status && String(detailTrip.status).trim() !== "" ? detailTrip.status : fallbackStatus;
    return {
      ...fallbackTrip,
      ...detailTrip,
      status,
    };
  }, [detail?.trip, fallbackTrip]);

  return (
    <div className="cp-content" style={{ maxWidth: 1200, width: "100%", margin: "0 auto" }}>
      <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
        <button className="cp-btn" onClick={() => setParam("view", "trips")}>
          {tt("← Viajes")}
        </button>
        <div className="cp-meta" style={{ opacity: 0.85 }}>
          {tt("Viajes &gt;")} <span className="cp-strong">{title}</span>
        </div>
      </div>

      <TripHeader
        trip={detail?.trip ? resolvedTrip : trip}
        payments={payments}
        map={detail?.map}
        weather={detail?.weather}
        itineraryUrl={detail?.itinerary_pdf_url}
      />

      <Tabs
        tab={tab}
        onTab={(value) => {
          setParam("tab", value);
        }}
      />

      <div style={{ marginTop: 14 }}>
        {loading ? (
          <div className="cp-card" style={{ background: "var(--surface)" }}>
            <div className="cp-card-title">{tt("Cargando expediente")}</div>
            <Skeleton lines={8} />
          </div>
        ) : err ? (
          <div className="cp-notice is-warn">{tt("No se puede cargar el expediente ahora mismo.")}</div>
        ) : null}

        {tab === "summary" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Resumen")}</div>
            <div className="cp-card-sub">{tt("Servicios y planificación del viaje")}</div>

            {!hasServices ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                {tt("No hay servicios disponibles ahora mismo.")}
              </div>
            ) : (
              <div className="cp-summary-services">
                {pkg ? (
                  <div className="cp-service-section">
                    <div className="cp-service-section__heading">{tt("Paquete")}</div>
                    <ServiceItem service={pkg} />
                    {packageServices.length > 0 ? (
                      <div className="cp-service-section">
                        <div className="cp-service-section__heading">{tt("Servicios incluidos")}</div>
                        <ServiceList services={packageServices} indent />
                      </div>
                    ) : null}
                  </div>
                ) : null}
                {extras.length > 0 ? (
                  <div className="cp-service-section">
                    <div className="cp-service-section__heading">{pkg ? "Extras" : "Servicios"}</div>
                    <ServiceList services={extras} />
                  </div>
                ) : null}
              </div>
            )}
          </div>
        ) : null}

        {tab === "payments" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Pagos")}</div>
            <div className="cp-card-sub">{tt("Estado de pagos del viaje")}</div>

            {!payments ? (
              <div style={{ marginTop: 10 }} className="cp-meta">{tt("Aún no hay pagos asociados a este viaje.")}</div>
            ) : (
              <>
                <div className="cp-kpi-card-grid">
                  {paymentKpiItems.map((item) => (
                    <KpiCard
                      key={item.key}
                      icon={item.icon}
                      label={item.label}
                      value={item.value}
                      colorClass={item.colorClass}
                    />
                  ))}
                </div>

                <PaymentActions
                  expediente={expediente}
                  payments={payments}
                  mock={mock}
                  readOnly={readOnly}
                  readOnlyMessage={readOnlyMessage}
                />
                {isPaid ? (
                  <div style={{ marginTop: 12 }}>
                    <div className="cp-pill cp-pill--success">{tt("Pagado")}</div>
                  </div>
                ) : null}

                {chargeHistory.length > 0 ? (
                  <div className="cp-payments-history">
                    <div className="cp-payments-history__title">{tt("Histórico de cobros")}</div>
                    <div className="cp-table-wrap">
                      <table className="cp-payments-history__table">
                        <thead>
                          <tr>
                            <th>{tt("Fecha")}</th>
                            <th>{tt("Tipo")}</th>
                            <th>{tt("Concepto")}</th>
                            <th>{tt("Pagador")}</th>
                            <th className="is-right">{tt("Importe")}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {chargeHistory.map((row) => {
                            const historyBadge = getHistoryBadge(row);
                            return (
                              <tr key={row.id}>
                                <td>{formatDateES(row.date)}</td>
                                <td>
                                  <BadgeLabel
                                    label={historyBadge.label}
                                    variant={historyBadge.variant}
                                    className="cp-history-badge"
                                  />
                                </td>
                                <td>{row.concept}</td>
                                <td>{row.payer || row.document || "—"}</td>
                                <td className="is-right">
                                  {euro(row.is_refund ? -row.amount : row.amount, currency)}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ) : (
                  <div style={{ marginTop: 14 }} className="cp-meta">
                    {tt("Aún no hay cobros registrados en este viaje.")}
                  </div>
                )}
              </>
            )}
          </div>
        ) : null}

        {tab === "invoices" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Facturas")}</div>
            <div className="cp-card-sub">{tt("Descargas asociadas a este viaje")}</div>
            {invoices.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">{tt("No hay facturas disponibles.")}</div>
            ) : (
              <div className="casanova-tablewrap" style={{ marginTop: 14 }}>
                <table className="casanova-table">
                  <thead>
                    <tr>
                      <th>{tt("Factura")}</th>
                      <th>{tt("Fecha")}</th>
                      <th className="num">{tt("Importe")}</th>
                      <th>{tt("Estado")}</th>
                      <th>{tt("Acciones")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((inv) => {
                      const statusRaw = String(inv.status || "").trim();
                      return (
                        <tr key={inv.id}>
                          <td>{inv.title || `Factura #${inv.id}`}</td>
                          <td>{formatDateES(inv.date)}</td>
                          <td className="num">
                            {typeof inv.amount === "number" ? euro(inv.amount, inv.currency || "EUR") : "—"}
                          </td>
                          <td>
                            <BadgeLabel label={statusRaw || "—"} variant={getInvoiceVariant(statusRaw)} />
                          </td>
                          <td>
                            {inv.download_url ? (
                              <a className="casanova-btn casanova-btn--sm casanova-btn--ghost" href={inv.download_url}>
                                {tt("Descargar PDF")}
                              </a>
                            ) : (
                              <span className="casanova-btn casanova-btn--sm casanova-btn--disabled">{tt("Descargar PDF")}</span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ) : null}

        {tab === "vouchers" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Bonos")}</div>
            <div className="cp-card-sub">{tt("Vouchers y documentación")}</div>
            {bonuses.available && voucherItems.length > 0 ? (
              <Notice variant="info" title={tt("Bonos disponibles")}>
                {tt("En cada reserva podrás ver el bono y descargar el PDF.")}
              </Notice>
            ) : null}

            {voucherItems.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                {isPaid
                  ? "No hay bonos disponibles para este viaje."
                  : "Los bonos aparecerán cuando el viaje esté pagado."}
              </div>
            ) : (
              <div className="cp-bonus-list">
                {voucherItems.map((item) => (
                  <div key={item.id} className="cp-bonus-card">
                    <div>
                      <div className="cp-bonus-title">{item.label}</div>
                      <div className="cp-bonus-meta">{item.date_range || "Sin fechas"}</div>
                    </div>
                    <div className="cp-bonus-actions">
                      {renderBonusButton(tt("Ver bono"), item.view_url, "view")}
                      {renderBonusButton("PDF", item.pdf_url, "pdf")}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : null}

        {tab === "messages" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Mensajes")}</div>
            <div className="cp-card-sub">{tt("Conversación sobre este viaje")}</div>
            <MessagesTimeline expediente={expediente} mock={mock} onLatestTs={onLatestTs} onSeen={onSeen} />
          </div>
        ) : null}
      </div>
    </div>
  );
}
