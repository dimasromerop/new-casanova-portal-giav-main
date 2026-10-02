import React, { useEffect, useMemo, useState } from "react";

import BadgeLabel from "../BadgeLabel.jsx";
import ChatThread from "../ChatThread.jsx";
import Icon from "../Icon.jsx";
import { EmptyState, Notice, Skeleton } from "../ui.jsx";
import { tt, ttf } from "../../i18n/t.js";
import { api } from "../../lib/api.js";
import { euro, formatDateES, formatNumberUi } from "../../lib/formatters.js";
import { readParams, setParams } from "../../lib/params.js";
import { getHistoryBadge, getInvoiceVariant } from "../../lib/statusBadges.js";
import { pickTripHeroImage, tripPackages } from "../../lib/tripServices.js";
import PaymentActions from "./PaymentActions.jsx";
import ServiceList from "./ServiceList.jsx";
import Tabs from "./TripTabs.jsx";
import TripHeader from "./TripHeader.jsx";

// Cabecera del chat del viaje: el equipo y el contacto directo (datos de la agencia, no fijos).
function TeamHeader() {
  const agency = window.CasanovaPortal?.agency || {};
  const tel = String(agency.tel || "").trim();
  const email = String(agency.email || "").trim();
  return (
    <div className="cp-thread-head">
      <span className="cp-msg__avatar" aria-hidden="true">CG</span>
      <div className="cp-thread-head__copy">
        <h2 className="cp-thread-head__title">{String(agency.nombre || "Casanova Golf")}</h2>
        <p className="cp-thread-head__meta">{tt("Equipo de soporte · Responde en menos de 24h")}</p>
      </div>
      {tel ? (
        <a className="cp-iconbtn" href={`tel:${tel.replace(/\s+/g, "")}`} aria-label={tt("Llamar")} title={tt("Llamar")}>
          <Icon name="phone" size={18} />
        </a>
      ) : null}
      {email ? (
        <a className="cp-iconbtn" href={`mailto:${email}`} aria-label={tt("Email")} title={tt("Email")}>
          <Icon name="mail" size={18} />
        </a>
      ) : null}
    </div>
  );
}

export default function TripDetailView({
  mock,
  expediente,
  dashboard,
  readOnly = false,
  readOnlyMessage = "",
  onSeen,
  mulligansEnabled = true,
}) {

  const trips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
  const fallbackTrip = trips.find((trip) => String(trip.id) === String(expediente)) || { id: expediente };
  const tab = readParams().tab;

  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState(null);
  const [historyPage, setHistoryPage] = useState(1);

  // El expediente se carga una vez por viaje; cambiar de pestaña no vuelve a pedirlo.
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
            <h2 className="cp-pkg-card__title">{packageItem.title || tt("Paquete")}</h2>
            {metaParts.length ? <p className="cp-pkg-card__meta">{metaParts.join(" · ")}</p> : null}
          </div>
          <div className="cp-pkg-card__right">
            {typeof packageItem.price === "number" ? (
              <span className="cp-pkg-card__price">{euro(packageItem.price)}</span>
            ) : null}
            {packageItem.voucher_urls?.view || packageItem.voucher_urls?.pdf ? (
              <div className="cp-pkg-card__actions">
                {packageItem.voucher_urls?.view ? (
                  <a className="cp-btn cp-btn--ghost cp-btn--sm" href={packageItem.voucher_urls.view} target="_blank" rel="noreferrer">
                    <Icon name="ticket" size={16} />
                    {tt("Bono")}
                  </a>
                ) : null}
                {packageItem.voucher_urls?.pdf ? (
                  <a className="cp-btn cp-btn--ghost cp-btn--sm" href={packageItem.voucher_urls.pdf} target="_blank" rel="noreferrer">
                    <Icon name="download" size={16} />
                    {tt("PDF")}
                  </a>
                ) : null}
              </div>
            ) : null}
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
      <TripHeader
        trip={detail?.trip ? resolvedTrip : trip}
        map={detail?.map}
        weather={detail?.weather}
        itineraryUrl={detail?.itinerary_pdf_url}
        imageUrl={pickTripHeroImage(detail) || fallbackTrip?.hero_image_url || ""}
        showPayments={tab !== "payments"}
        onBack={(event) => {
          if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
          event.preventDefault();
          setParams({ view: "trips", expediente: null, tab: null });
        }}
        onPayments={() => setParams({ tab: "payments" }, { scrollTop: false })}
      />

      <Tabs tab={tab} onTab={(value) => setParams({ tab: value }, { scrollTop: false })} />

      <div className="cp-trip-detail__body" role="tabpanel" id="cp-trip-panel" aria-labelledby={`cp-tab-${tab}`}>
        {showDetailState && loading ? (
          <div className="cp-card" aria-busy="true">
            <span className="cp-sr-only">{tt("Cargando expediente")}</span>
            <Skeleton lines={6} />
          </div>
        ) : showDetailState && err ? (
          <Notice variant="error" title={tt("No se puede cargar el expediente ahora mismo.")}>
            {tt("Inténtalo de nuevo más tarde.")}
          </Notice>
        ) : null}

        {tab === "summary" && !loading && !err ? (
          <>
            {!hasServices ? (
              <EmptyState title={tt("No hay servicios disponibles ahora mismo.")} icon="luggage">
                {tt("Estamos preparando la información de este viaje. En cuanto esté lista, la verás aquí.")}
              </EmptyState>
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

        {tab === "payments" && !loading && !err ? (
          <div className="cp-pay-tab">
            {!payments ? (
              <EmptyState title={tt("Aún no hay pagos asociados a este viaje.")} icon="credit-card" />
            ) : (
              <>
                <section className="cp-pay-summary" aria-label={tt("Pagos del viaje")}>
                  <dl className="cp-pay-summary__figures">
                    <div>
                      <dt>{tt("Total")}</dt>
                      <dd>{totalLabel}</dd>
                    </div>
                    <div>
                      <dt>{tt("Pagado")}</dt>
                      <dd>{paidLabel}</dd>
                      <span>{paidPct}% {tt("completado")}</span>
                    </div>
                    <div className={isPaid ? "" : "is-due"}>
                      <dt>{tt("Pendiente")}</dt>
                      <dd>{pendingLabel}</dd>
                      <span>{pendingPct}% {tt("pendiente")}</span>
                    </div>
                    {mulligansEnabled ? (
                      <div>
                        <dt>{tt("Mulligans usados")}</dt>
                        <dd>{formatNumberUi(mulligansUsed)}</dd>
                        <span>{formatNumberUi(mulligansAvailable)} {tt("disponibles")}</span>
                      </div>
                    ) : null}
                  </dl>
                  <div
                    className={`cp-meter cp-pay-summary__meter ${isPaid ? "is-done" : ""}`.trim()}
                    role="progressbar"
                    aria-label={tt("Progreso de pago")}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.max(0, Math.min(100, paidPct))}
                  >
                    <span style={{ width: `${Math.max(0, Math.min(100, paidPct))}%` }} />
                  </div>
                </section>

                {isPaid ? (
                  <Notice variant="success" title={tt("Viaje pagado")}>
                    {tt("Todo el viaje está liquidado.")}
                  </Notice>
                ) : (
                  <PaymentActions
                    expediente={expediente}
                    payments={payments}
                    mock={mock}
                    readOnly={readOnly}
                    readOnlyMessage={readOnlyMessage}
                  />
                )}

                {showGroupContributionSummary ? (
                  <section className="cp-pay-block">
                    <header className="cp-pay-block__head">
                      <h2 className="cp-pay-block__title">{tt("Aportaciones del grupo")}</h2>
                      <span className="cp-pay-block__count">{payerTotals.length} {tt("pagadores")}</span>
                    </header>
                    <div className="cp-table-wrap">
                      <table className="cp-table">
                        <thead>
                          <tr>
                            <th>{tt("Pagador")}</th>
                            <th>{tt("Movimientos")}</th>
                            <th>{tt("Último movimiento")}</th>
                            <th className="num">{tt("Total neto")}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {payerTotals.map((row) => (
                            <tr key={row.id}>
                              <td>{row.payer || "—"}</td>
                              <td>{row.count ?? 0}</td>
                              <td>{row.last_date ? formatDateES(row.last_date) : "—"}</td>
                              <td className="num">{euro(Number(row.amount ?? 0), currency)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </section>
                ) : null}

                <section className="cp-pay-block">
                  <header className="cp-pay-block__head">
                    <h2 className="cp-pay-block__title">{tt("Historial de pagos")}</h2>
                    <span className="cp-pay-block__count">{chargeHistory.length} {tt("cobros")}</span>
                  </header>
                  {chargeHistory.length > 0 ? (
                    <>
                      <div className="cp-table-wrap">
                        <table className="cp-table">
                          <thead>
                            <tr>
                              <th>{tt("Fecha")}</th>
                              <th>{tt("Tipo")}</th>
                              <th>{tt("Concepto")}</th>
                              <th>{tt("Pagador")}</th>
                              <th className="num">{tt("Importe")}</th>
                            </tr>
                          </thead>
                          <tbody>
                            {pagedChargeHistory.map((row) => {
                              const historyBadge = getHistoryBadge(row);
                              return (
                                <tr key={row.id}>
                                  <td className="cp-nowrap">{formatDateES(row.date)}</td>
                                  <td>
                                    <BadgeLabel label={historyBadge.label} variant={historyBadge.variant} />
                                  </td>
                                  <td>{row.concept}</td>
                                  <td>{row.payer || row.document || "—"}</td>
                                  <td className={`num ${row.is_refund ? "is-refund" : ""}`.trim()}>
                                    {euro(row.is_refund ? -row.amount : row.amount, currency)}
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                      {historyTotalPages > 1 ? (
                        <nav className="cp-pay-block__pagination" aria-label={tt("Historial de pagos")}>
                          <button
                            type="button"
                            className="cp-btn cp-btn--ghost cp-btn--sm"
                            onClick={() => setHistoryPage((page) => Math.max(1, page - 1))}
                            disabled={historyCurrentPage <= 1}
                          >
                            {tt("Anterior")}
                          </button>
                          <span>
                            {ttf("Página {current} de {total}", { current: historyCurrentPage, total: historyTotalPages })}
                          </span>
                          <button
                            type="button"
                            className="cp-btn cp-btn--ghost cp-btn--sm"
                            onClick={() => setHistoryPage((page) => Math.min(historyTotalPages, page + 1))}
                            disabled={historyCurrentPage >= historyTotalPages}
                          >
                            {tt("Siguiente")}
                          </button>
                        </nav>
                      ) : null}
                    </>
                  ) : (
                    <p className="cp-pay-block__empty">
                      <Icon name="receipt" size={20} />
                      <span>{tt("No hay cobros registrados todavía.")} {tt("Tu primer pago aparecerá aquí.")}</span>
                    </p>
                  )}
                </section>

                <ul className="cp-pay-trust">
                  <li><Icon name="shield-lock" size={18} />{tt("Pago seguro SSL")}</li>
                  <li><Icon name="lock" size={18} />{tt("Datos encriptados")}</li>
                  <li><Icon name="success" size={18} />{tt("PCI DSS certificado")}</li>
                </ul>
              </>
            )}
          </div>
        ) : null}

        {tab === "invoices" && !loading && !err ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Facturas")}</div>
            <div className="cp-card-sub">{tt("Descargas asociadas a este viaje")}</div>
            {invoices.length === 0 ? (
              <div className="cp-meta cp-mt-10">{tt("No hay facturas disponibles.")}</div>
            ) : (
              <div className="cp-table-wrap cp-mt-14">
                <table className="cp-table">
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
                              <a className="cp-btn cp-btn--sm" href={inv.download_url}>
                                {tt("Descargar PDF")}
                              </a>
                            ) : (
                              <span className="cp-btn cp-btn--sm is-disabled" aria-disabled="true">{tt("Descargar PDF")}</span>
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

        {tab === "vouchers" && !loading && !err ? (
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
          <ChatThread
            expediente={expediente}
            mock={mock}
            onSeen={onSeen}
            readOnly={readOnly}
            readOnlyMessage={readOnlyMessage}
            header={<TeamHeader />}
            emptyText={tt("Si necesitas algo sobre este viaje, escríbenos desde aquí y seguiremos la conversación en el portal.")}
            placeholder={tt("Escribe aquí tu mensaje sobre este viaje...")}
          />
        ) : null}
      </div>
    </div>
  );
}
