import React, { useEffect, useMemo, useState } from "react";

import BadgeLabel from "../BadgeLabel.jsx";
import MessagesTimeline from "../MessagesTimeline.jsx";
import { Notice, Skeleton } from "../ui.jsx";
import { tt, ttf } from "../../i18n/t.js";
import { api } from "../../lib/api.js";
import { euro, formatDateES, formatNumberUi } from "../../lib/formatters.js";
import { readParams, setParam } from "../../lib/params.js";
import { getHistoryBadge, getInvoiceVariant } from "../../lib/statusBadges.js";
import { tripPackages } from "../../lib/tripServices.js";
import PaymentActions from "./PaymentActions.jsx";
import ServiceList from "./ServiceList.jsx";
import Tabs from "./TripTabs.jsx";
import TripHeader from "./TripHeader.jsx";

export default function TripDetailView({
  mock,
  expediente,
  dashboard,
  readOnly = false,
  readOnlyMessage = "",
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
  const tab = readParams().tab;

  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState(null);
  const [historyPage, setHistoryPage] = useState(1);

  useEffect(() => {
    let alive = true;

    if (tab === "messages") {
      setDetail(null);
      setLoading(false);
      setErr(null);
      return () => {
        alive = false;
      };
    }

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
  }, [expediente, mock, tab]);

  const trip = detail?.trip || fallbackTrip;
  const payments = detail?.payments || null;
  const packages = tripPackages(detail);
  const extras = Array.isArray(detail?.extras) ? detail.extras : [];
  const hasServices = packages.length > 0 || extras.length > 0;
  const invoices = Array.isArray(detail?.invoices) ? detail.invoices : [];
  const bonuses = detail?.bonuses ?? { available: false, items: [] };
  const voucherItems = Array.isArray(bonuses.items) ? bonuses.items : [];
  const chargeHistory = Array.isArray(payments?.history) ? payments.history : [];
  const payerTotals = Array.isArray(payments?.payer_totals) ? payments.payer_totals : [];
  const showGroupContributionSummary = payments?.economic_scope === "expediente" && payerTotals.length > 0;
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
  const mulligansUsed = Math.max(0, Number(payments?.mulligans_used ?? 0));
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount, currency) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount, currency) : "—";
  const pendingLabel = pendingAmount !== null && Number.isFinite(pendingAmount) ? euro(pendingAmount, currency) : "—";

  const paidPct = Number.isFinite(totalAmount) && totalAmount > 0 && Number.isFinite(paidAmount)
    ? Math.round((paidAmount / totalAmount) * 100)
    : 0;
  const pendingPct = Number.isFinite(totalAmount) && totalAmount > 0 && pendingAmount !== null
    ? Math.round((pendingAmount / totalAmount) * 100)
    : 0;
  const mulligansAvailable = Math.max(0, Number(payments?.mulligans_available ?? 0));
  const historyPageSize = 12;
  const historyTotalPages = Math.max(1, Math.ceil(chargeHistory.length / historyPageSize));
  const historyCurrentPage = Math.min(historyPage, historyTotalPages);
  const pagedChargeHistory = chargeHistory.slice(
    (historyCurrentPage - 1) * historyPageSize,
    historyCurrentPage * historyPageSize,
  );

  useEffect(() => {
    setHistoryPage(1);
  }, [expediente, chargeHistory.length]);

  const paymentKpiItems = payments
    ? [
        { key: "total", label: tt("Total"), value: totalLabel, icon: <BriefcaseIcon />, colorClass: "is-salmon" },
        { key: "paid", label: tt("Pagado"), value: paidLabel, icon: <ShieldCheckIcon />, colorClass: "is-blue", sub: `${paidPct}% ${tt("completado")}` },
        { key: "pending", label: tt("Pendiente"), value: pendingLabel, icon: <ClockArrowIcon />, colorClass: "is-green", cardClass: "is-pending", sub: `${pendingPct}% ${tt("pendiente")}` },
        ...(mulligansEnabled
          ? [{
              key: "mulligans",
              label: tt("Mulligans usados"),
              value: formatNumberUi(mulligansUsed),
              icon: <SparkleIcon />,
              colorClass: "is-lilac",
              sub: `${formatNumberUi(mulligansAvailable)} ${tt("disponibles")}`,
            }]
          : []),
      ]
    : [];

  const bonusDisabledReason = (type) => {
    if (!isPaid) return tt("El viaje debe estar pagado para descargar los bonos.");
    return type === "view"
      ? tt("No hay una vista previa disponible para este bono.")
      : tt("No hay un PDF disponible para este bono.");
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
  const showDetailState = tab !== "messages";

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

  const renderPackageSummary = (packageItem, index) => {
    if (!packageItem || typeof packageItem !== "object") return null;

    const packageServices = Array.isArray(packageItem?.services) ? packageItem.services : [];
    const key = packageItem.id || packageItem.code || `package-${index}`;
    const metaParts = [packageItem.date_range || "", packageItem.detail?.type || ""].filter(Boolean);

    return (
      <React.Fragment key={key}>
        <div className="cp-pkg-card">
          <div className="cp-pkg-card__info">
            <h3 className="cp-pkg-card__title">{packageItem.title || tt("Paquete")}</h3>
            <p className="cp-pkg-card__meta">{metaParts.join(" | ")}</p>
          </div>
          <div className="cp-pkg-card__right">
            {typeof packageItem.price === "number" ? (
              <span className="cp-pkg-card__price">{euro(packageItem.price)}</span>
            ) : null}
            <span className="cp-chip">{(packageItem.type || "PQ").toUpperCase()}</span>
            <div className="cp-pkg-card__actions">
              <button type="button" className="cp-btn cp-btn--ghost" onClick={() => {}} disabled={!packageItem.actions?.detail}>{tt("Detalle")}</button>
              {packageItem.voucher_urls?.view ? (
                <a className="cp-btn cp-btn--ghost" href={packageItem.voucher_urls.view} target="_blank" rel="noreferrer">{tt("Bono")}</a>
              ) : (
                <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("Bono")}</span>
              )}
              {packageItem.voucher_urls?.pdf ? (
                <a className="cp-btn cp-btn--ghost" href={packageItem.voucher_urls.pdf} target="_blank" rel="noreferrer">{tt("PDF")}</a>
              ) : (
                <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("PDF")}</span>
              )}
            </div>
          </div>
        </div>
        {packageServices.length > 0 ? (
          <div className="cp-service-section">
            <div className="cp-service-section__heading">
              {tt("Servicios incluidos")}
              <span className="cp-service-section__count">{packageServices.length}</span>
            </div>
            <ServiceList services={packageServices} indent sortMode="chronological" />
          </div>
        ) : null}
      </React.Fragment>
    );
  };

  return (
    <div className="cp-content cp-trip-detail">
      <div className="cp-trip-detail__nav">
        <button type="button" className="cp-btn cp-trip-detail__back" onClick={() => setParam("view", "trips")}>
          {tt("← Viajes")}
        </button>
        <div className="cp-meta cp-trip-detail__breadcrumb">
          {tt("Viajes >")} <span className="cp-strong">{title}</span>
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

      <div className="cp-trip-detail__body">
        {showDetailState && loading ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Cargando expediente")}</div>
            <Skeleton lines={8} />
          </div>
        ) : showDetailState && err ? (
          <div className="cp-notice is-warn">{tt("No se puede cargar el expediente ahora mismo.")}</div>
        ) : null}

        {tab === "summary" ? (
          <>
            {!hasServices ? (
              <div className="cp-card">
                <div className="cp-meta cp-mt-10">
                  {tt("No hay servicios disponibles ahora mismo.")}
                </div>
              </div>
            ) : (
              <div className="cp-summary-services">
                {packages.length > 1 ? (
                  <div className="cp-service-section__heading">
                    {tt("Paquetes")}
                    <span className="cp-service-section__count">{packages.length}</span>
                  </div>
                ) : null}
                {packages.map((packageItem, index) => renderPackageSummary(packageItem, index))}
                {extras.length > 0 ? (
                  <div className="cp-service-section cp-service-section--extras">
                    <div className="cp-service-section__heading">{tt("Extras")}</div>
                    <ServiceList services={extras} />
                  </div>
                ) : null}
              </div>
            )}
          </>
        ) : null}

        {tab === "payments" ? (
          <div className="cp-pay-tab">
            {!payments ? (
              <div className="cp-card">
                <div className="cp-card-title">{tt("Pagos")}</div>
                <div className="cp-meta cp-mt-10">{tt("Aún no hay pagos asociados a este viaje.")}</div>
              </div>
            ) : (
              <>
                {/* KPI cards */}
                <div className="cp-kpi-card-grid">
                  {paymentKpiItems.map((item) => (
                    <KpiCard
                      key={item.key}
                      icon={item.icon}
                      label={item.label}
                      value={item.value}
                      sub={item.sub}
                      colorClass={item.colorClass}
                      cardClass={item.cardClass}
                    />
                  ))}
                </div>

                {/* Progress bar */}
                <div className="cp-pay-progress">
                  <div className="cp-pay-progress__track">
                    <div
                      className="cp-pay-progress__fill"
                      style={{ width: `${paidPct}%` }}
                    />
                  </div>
                  <div className="cp-pay-progress__labels">
                    <span>{tt("Pagado")}: <strong>{paidLabel}</strong></span>
                    <span>{tt("Pendiente")}: <strong>{pendingLabel}</strong></span>
                  </div>
                </div>

                {isPaid ? (
                  <div className="cp-mt-12">
                    <div className="cp-pill cp-pill--success">{tt("Pagado")}</div>
                  </div>
                ) : (
                  <PaymentActions
                    expediente={expediente}
                    payments={payments}
                    mock={mock}
                    readOnly={readOnly}
                    readOnlyMessage={readOnlyMessage}
                  />
                )}

                {/* Payment history card */}
                {showGroupContributionSummary ? (
                  <div className="cp-pay-history">
                    <div className="cp-pay-history__title">
                      {tt("Aportaciones del grupo")}
                      <span className="cp-pay-history__count">
                        {payerTotals.length} {tt("pagadores")}
                      </span>
                    </div>
                    <div className="cp-table-wrap">
                      <table className="cp-payments-history__table">
                        <thead>
                          <tr>
                            <th>{tt("Pagador")}</th>
                            <th>{tt("Movimientos")}</th>
                            <th>{tt("Último movimiento")}</th>
                            <th className="is-right">{tt("Total neto")}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {payerTotals.map((row) => (
                            <tr key={row.id}>
                              <td>{row.payer || "—"}</td>
                              <td>{row.count ?? 0}</td>
                              <td>{row.last_date ? formatDateES(row.last_date) : "—"}</td>
                              <td className="is-right">{euro(Number(row.amount ?? 0), currency)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ) : null}

                <div className="cp-pay-history">
                  <div className="cp-pay-history__title">
                    {tt("Historial de pagos")}
                    <span className="cp-pay-history__count">
                      {chargeHistory.length} {tt("cobros")}
                    </span>
                  </div>
                  {chargeHistory.length > 0 ? (
                    <>
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
                            {pagedChargeHistory.map((row) => {
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
                      {historyTotalPages > 1 ? (
                        <div className="cp-pay-history__pagination">
                          <button
                            type="button"
                            className="cp-btn cp-btn--ghost"
                            onClick={() => setHistoryPage((page) => Math.max(1, page - 1))}
                            disabled={historyCurrentPage <= 1}
                          >
                            {tt("Anterior")}
                          </button>
                          <span className="cp-pay-history__page">
                            {ttf("Página {current} de {total}", {
                              current: historyCurrentPage,
                              total: historyTotalPages,
                            })}
                          </span>
                          <button
                            type="button"
                            className="cp-btn cp-btn--ghost"
                            onClick={() => setHistoryPage((page) => Math.min(historyTotalPages, page + 1))}
                            disabled={historyCurrentPage >= historyTotalPages}
                          >
                            {tt("Siguiente")}
                          </button>
                        </div>
                      ) : null}
                    </>
                  ) : (
                    <div className="cp-pay-history__empty">
                      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" strokeWidth="1.3">
                        <circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/>
                      </svg>
                      <p>{tt("No hay cobros registrados todavía.")}<br />{tt("Tu primer pago aparecerá aquí.")}</p>
                    </div>
                  )}
                </div>

                {/* Security footer */}
                <div className="cp-pay-security">
                  <div className="cp-pay-security__item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    {tt("Pago seguro SSL")}
                  </div>
                  <div className="cp-pay-security__item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    {tt("Datos encriptados")}
                  </div>
                  <div className="cp-pay-security__item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
                    {tt("PCI DSS certificado")}
                  </div>
                </div>
              </>
            )}
          </div>
        ) : null}

        {tab === "invoices" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Facturas")}</div>
            <div className="cp-card-sub">{tt("Descargas asociadas a este viaje")}</div>
            {invoices.length === 0 ? (
              <div className="cp-meta cp-mt-10">{tt("No hay facturas disponibles.")}</div>
            ) : (
              <div className="casanova-tablewrap cp-mt-14">
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
                          <td>{inv.title || ttf("Factura #{id}", { id: inv.id })}</td>
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
              <div className="cp-meta cp-mt-10">
                {isPaid
                  ? tt("No hay bonos disponibles para este viaje.")
                  : tt("Los bonos aparecerán cuando el viaje esté pagado.")}
              </div>
            ) : (
              <div className="cp-bonus-list">
                {voucherItems.map((item) => (
                  <div key={item.id} className="cp-bonus-card">
                    <div>
                      <div className="cp-bonus-title">{item.label}</div>
                      <div className="cp-bonus-meta">{item.date_range || tt("Sin fechas")}</div>
                    </div>
                    <div className="cp-bonus-actions">
                      {renderBonusButton(tt("Ver bono"), item.view_url, "view")}
                      {renderBonusButton(tt("PDF"), item.pdf_url, "pdf")}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : null}

        {tab === "messages" ? (
          <MessagesTimeline
            expediente={expediente}
            mock={mock}
            onSeen={onSeen}
            readOnly={readOnly}
            readOnlyMessage={readOnlyMessage}
          />
        ) : null}
      </div>
    </div>
  );
}
