import React, { useEffect, useState } from "react";

import { t, tt } from "../../i18n/t.js";
import { EmptyState } from "../ui.jsx";
import { euro, formatDateES, formatTierLabel, formatTimestamp, normalizeTripDates } from "../../lib/formatters.js";
import { setParam } from "../../lib/params.js";
import { compactList, countNightsBetween, flightSummary, serviceSemanticType, transferSummary, uniqueStrings } from "../../lib/tripServices.js";

function firstNameFromProfile(profile) {
  const giavName = String(profile?.giav?.nombre || "").trim();
  if (giavName) return giavName.split(/\s+/)[0] || "";

  const displayName = String(profile?.user?.displayName || "").trim();
  if (displayName) return displayName.split(/\s+/)[0] || "";

  return "";
}

function DashboardGlanceItem({ icon: Icon, label, value, note, loading = false }) {
  return (
    <article className={`cp-trip-glance__item ${loading ? "is-loading" : ""}`}>
      <div className="cp-trip-glance__top">
        <span className="cp-trip-glance__icon" aria-hidden="true"><Icon /></span>
        <div className="cp-trip-glance__label">{label}</div>
      </div>
      {loading ? (
        <div className="cp-trip-glance__skeleton" aria-hidden="true">
          <span className="cp-trip-skeleton__line is-title" />
          <span className="cp-trip-skeleton__line is-copy" />
        </div>
      ) : (
        <>
          <div className="cp-trip-glance__value">{value}</div>
          <div className="cp-trip-glance__note">{note}</div>
        </>
      )}
    </article>
  );
}

function DashboardIncludeItem({ icon: Icon, label, value, detail = "", emphasis = false }) {
  return (
    <article className="cp-trip-includes__item">
      <span className="cp-trip-includes__icon" aria-hidden="true"><Icon /></span>
      <div className="cp-trip-includes__content">
        <div className="cp-trip-includes__label">{label}</div>
        <div className={`cp-trip-includes__value ${emphasis ? "is-emphasis" : ""}`.trim()}>{value}</div>
        {detail ? <div className="cp-trip-includes__detail">{detail}</div> : null}
      </div>
    </article>
  );
}

const LOYALTY_TIER_MIN_SPEND = {
  birdie: 0,
  eagle: 5000,
  eagle_plus: 15000,
  albatross: 30000,
};

function normalizeTierSlug(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_")
    .replace(/\+/g, "_plus")
    .replace(/\-/g, "_");
}

function MulligansTierIcon({ tone = "birdie" }) {
  if (tone === "albatross") {
    return (
      <svg viewBox="0 0 48 48" aria-hidden="true">
        <path d="M12 18l6-7 6 5 6-5 6 7v4a3 3 0 0 1-3 3H15a3 3 0 0 1-3-3z" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M18 25v5a6 6 0 0 0 12 0v-5" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" />
        <circle cx="24" cy="21" r="1.8" fill="currentColor" stroke="none" />
      </svg>
    );
  }

  if (tone === "eagle") {
    return (
      <svg viewBox="0 0 48 48" aria-hidden="true">
        <path d="M24 12l3.5 7.1 7.8 1.1-5.6 5.4 1.3 7.6-7-3.7-7 3.7 1.3-7.6-5.6-5.4 7.8-1.1z" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinejoin="round" />
        <path d="M12 21c2.6-2.3 5.5-3.5 8.6-3.7M36 21c-2.6-2.3-5.5-3.5-8.6-3.7" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
      </svg>
    );
  }

  return (
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M29 12c-8.1 1.5-13 7.9-13 15 0 6 3.7 9 8.1 9 7.5 0 10.9-8.4 10.9-16 0-4.5-2.3-8.2-6-8z" fill="none" stroke="currentColor" strokeWidth="2.3" strokeLinejoin="round" />
      <path d="M18.8 33.2c3.9-3.4 7-7.6 9-12.6" fill="none" stroke="currentColor" strokeWidth="2.3" strokeLinecap="round" />
      <path d="M20 18c1.6 1.1 2.8 2.4 3.8 4" fill="none" stroke="currentColor" strokeWidth="2.3" strokeLinecap="round" />
    </svg>
  );
}

export default function DashboardView({
  data,
  heroImageUrl,
  heroMap,
  tripDetail = null,
  tripDetailLoading = false,
  mulligansEnabled = true,
  profile = null,
  icons = {},
}) {
  const MapPinIcon = icons.IconMapPin ?? (() => null);
  const CalendarIcon = icons.IconCalendar ?? (() => null);
  const BedIcon = icons.IconBed ?? (() => null);
  const GolfFlagIcon = icons.IconGolfFlag ?? (() => null);
  const CarIcon = icons.IconCar ?? (() => null);
  const ClockArrowIcon = icons.IconClockArrow ?? (() => null);
  const ChatBubbleIcon = icons.IconChatBubble ?? (() => null);

  const [heroImageReady, setHeroImageReady] = useState(false);
  const [heroImageError, setHeroImageError] = useState(false);
  const nextTrip = data?.next_trip || null;
  const nextTripId = Number(nextTrip?.id || 0);
  const hasActiveTrip = Boolean(data?.active_trip_exists && nextTripId > 0);
  const payments = data?.payments || null;
  const mull = data?.mulligans || null;
  const action = data?.next_action || null;
  const preferredFirstName = firstNameFromProfile(profile);
  const detail = tripDetail && typeof tripDetail === "object" ? tripDetail : null;
  const showTripDetailSkeleton = Boolean(tripDetailLoading && hasActiveTrip);
  const detailTrip = detail?.trip && typeof detail.trip === "object" ? detail.trip : null;
  const packageServices = Array.isArray(detail?.package?.services) ? detail.package.services : [];
  const extraServices = Array.isArray(detail?.extras) ? detail.extras : [];
  const allServices = [...packageServices, ...extraServices];
  const hotelServices = allServices.filter((service) => serviceSemanticType(service) === "hotel");
  const golfServices = allServices.filter((service) => serviceSemanticType(service) === "golf");
  const flightServices = allServices.filter((service) => serviceSemanticType(service) === "flight");
  const transferServices = allServices.filter((service) => serviceSemanticType(service) === "transfer");
  const otherServices = allServices.filter((service) => {
    const semantic = serviceSemanticType(service);
    return semantic !== "hotel" && semantic !== "golf" && semantic !== "flight" && semantic !== "transfer";
  });

  const hasPaymentsData = payments && !Array.isArray(payments);
  const totalAmount = hasPaymentsData ? Number(payments?.total) : Number.NaN;
  const paidAmount = hasPaymentsData ? Number(payments?.paid) : Number.NaN;
  const pendingCandidate = hasPaymentsData ? Number(payments?.pending) : Number.NaN;
  const pendingAmount = hasPaymentsData
    ? (Number.isFinite(pendingCandidate)
        ? pendingCandidate
        : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
            ? Math.max(0, totalAmount - paidAmount)
            : null))
    : null;
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount) : "—";
  const pendingLabel = pendingAmount !== null ? euro(pendingAmount) : "—";
  const paymentProgress =
    hasPaymentsData && Number.isFinite(totalAmount) && totalAmount > 0 && Number.isFinite(paidAmount)
      ? Math.max(0, Math.min(100, Math.round((paidAmount / totalAmount) * 100)))
      : 0;

  const points = typeof mull?.points === "number" ? mull.points : 0;
  const levelLabel = formatTierLabel(mull?.tier);
  const lastSyncLabel = formatTimestamp(mull?.last_sync);

  const tierRaw = String(mull?.tier || "");
  const tierSlug = normalizeTierSlug(tierRaw);
  const tierClass = tierSlug ? "is-" + tierSlug : "";
  const multLabel = typeof mull?.mult === "number" ? ("x" + mull.mult) : null;
  const progressRaw = typeof mull?.progress_pct === "number"
    ? mull.progress_pct
    : (typeof mull?.progress === "number" ? (mull.progress <= 1 ? mull.progress * 100 : mull.progress) : 0);
  const progressPct = Math.max(0, Math.min(100, Math.round(progressRaw || 0)));
  const remaining = typeof mull?.remaining_to_next === "number" ? mull.remaining_to_next : null;
  const nextTier = mull?.next_tier_label ? String(mull.next_tier_label) : null;
  const hintText = (remaining !== null && nextTier) ? ("Te faltan " + euro(remaining) + " para subir a " + nextTier + ".") : null;
  const nextTierSlug = normalizeTierSlug(nextTier);
  const tierMinSpend = LOYALTY_TIER_MIN_SPEND[tierSlug] ?? 0;
  const nextTierMinSpend = LOYALTY_TIER_MIN_SPEND[nextTierSlug];
  const spendValue = typeof mull?.spend === "number" ? mull.spend : Number.NaN;
  const fallbackProgressPct = Number.isFinite(spendValue) && Number.isFinite(nextTierMinSpend)
    ? Math.max(0, Math.min(100, Math.round(((spendValue - tierMinSpend) / Math.max(1, nextTierMinSpend - tierMinSpend)) * 100)))
    : 0;
  const tierTone = tierSlug.includes("albatross") ? "albatross" : (tierSlug.includes("eagle") ? "eagle" : "birdie");
  const progressTargetLabel = nextTier || tt("Nivel m\u00e1ximo");
  const loyaltyProgressPct = nextTier ? Math.max(progressPct, fallbackProgressPct) : 100;
  const loyaltyCopy = nextTier
    ? tt("Tu nivel actual, tus puntos y el progreso hacia el siguiente nivel.")
    : tt("Tu nivel actual y los puntos que ya has acumulado.");
  const loyaltyHint = (remaining !== null && nextTier)
    ? ("Te faltan " + euro(remaining) + " para " + nextTier + ".")
    : tt("Ya est\u00e1s en el nivel m\u00e1s alto del programa.");

  const heroTrip = hasActiveTrip ? (detailTrip || nextTrip || null) : null;
  const tripLabel = heroTrip?.title ? String(heroTrip.title) : tt("Viaje");
  const tripCode = heroTrip?.code ? String(heroTrip.code) : "";
  const tripContext = tripCode ? `${tripLabel} (${tripCode})` : tripLabel;
  const tripDates = normalizeTripDates(heroTrip || nextTrip);
  const tripDateRange = [formatDateES(tripDates.start), formatDateES(tripDates.end)].filter((value) => value && value !== "—").join(" — ");
  const tripReferenceLabel = tripCode ? `${tt("Referencia")} ${tripCode}` : "";
  const daysLeftRaw = Number(nextTrip?.days_left);
  const daysLeft = Number.isFinite(daysLeftRaw) ? Math.max(0, Math.round(daysLeftRaw)) : null;
  let daysLeftLabel = null;
  if (daysLeft !== null) {
    daysLeftLabel = daysLeft === 0 ? tt("Tu viaje empieza hoy") : `Tu viaje empieza en ${daysLeft} días`;
  }

  const mapUrl = heroMap?.url ? String(heroMap.url) : "";
  const hasTrip = hasActiveTrip;
  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;

  const actionStatus = action?.status || (hasPaymentsData ? (isPaid ? "ok" : "pending") : "info");
  const actionBadge = action?.badge || (hasPaymentsData ? (isPaid ? tt("Todo listo") : tt("Pendiente")) : tt("Info"));
  const actionText = !hasActiveTrip
    ? tt("Todavía no tienes un viaje activo. Cuando lo tengas, aquí verás el siguiente paso con claridad.")
    : actionStatus === "pending" && pendingAmount !== null
      ? `Tienes un pago pendiente de ${euro(pendingAmount)} para este viaje.`
      : actionStatus === "invoices"
        ? tt("Ya tienes documentación disponible para este viaje.")
        : actionStatus === "ok"
          ? tt("Todo está en orden. Ahora solo queda preparar el viaje con calma.")
          : !hasPaymentsData
            ? tt("Estamos preparando la información de este viaje. En cuanto esté lista, la verás aquí.")
            : (action?.description || tt("Aquí verás el siguiente paso importante de tu viaje."));
  const actionTripLabel = hasActiveTrip ? (action?.trip_label || tripContext) : "";
  const actionNote = action?.note || null;
  const noteExpedienteId = actionNote?.expediente_id ? String(actionNote.expediente_id) : "";
  const actionNoteUrl = noteExpedienteId
    ? (() => {
        const params = new URLSearchParams(window.location.search);
        params.set("view", "trip");
        params.set("expediente", noteExpedienteId);
        return `${window.location.pathname}?${params.toString()}`;
      })()
    : (actionNote?.url ? String(actionNote.url) : "");
  const actionPillClass = actionStatus === "pending" ? "is-warn" : (actionStatus === "ok" ? "is-ok" : "is-info");
  const actionPillLabel = actionStatus === "invoices" && typeof action?.invoice_count === "number"
    ? `${actionBadge} · ${action.invoice_count}`
    : actionBadge;
  const actionCtaLabel = actionStatus === "pending" ? tt("Ver pagos") : (actionStatus === "invoices" ? tt("Ver facturas") : tt("Ver viaje"));

  const hotelTitles = uniqueStrings(hotelServices.map((service) => service?.title));
  const golfTitles = uniqueStrings(golfServices.map((service) => service?.title));
  const flightTitles = uniqueStrings(flightServices.map((service) => flightSummary(service)));
  const transferTitles = uniqueStrings(transferServices.map((service) => transferSummary(service)));
  const otherTitles = uniqueStrings(otherServices.map((service) => service?.title));
  const primaryHotel = hotelTitles[0] || "";
  const hotelCount = hotelTitles.length;
  const golfCount = golfServices.length;
  const hasFlightSummary = flightTitles.length > 0;
  const hasTransferSummary = transferTitles.length > 0;
  const flightCount = hasFlightSummary ? flightServices.length : 0;
  const transferCount = hasTransferSummary ? transferServices.length : 0;
  const extrasCount = otherServices.length;
  const nights = countNightsBetween(tripDates.start, tripDates.end);
  const nightsLabel = nights ? `${nights} ${nights === 1 ? tt("noche") : tt("noches")}` : "";
  const golfLabel = golfCount ? `${golfCount} ${golfCount === 1 ? tt("ronda de golf") : tt("rondas de golf")}` : "";
  const flightLabel = hasFlightSummary
    ? (flightServices.every((service) => service?.included !== false) ? tt("vuelos incluidos") : tt("vuelos previstos"))
    : "";
  const transferLabel = hasTransferSummary
    ? (transferServices.every((service) => service?.included !== false) ? tt("traslados incluidos") : tt("traslados previstos"))
    : "";
  const mobilityLabel = hasFlightSummary && hasTransferSummary
    ? (
        flightServices.every((service) => service?.included !== false) &&
        transferServices.every((service) => service?.included !== false)
          ? tt("vuelos y traslados incluidos")
          : tt("vuelos y traslados previstos")
      )
    : (flightLabel || transferLabel);
  const supportLabel = tt("asistencia Casanova Golf");
  const destinationLine = [primaryHotel, compactList(golfTitles, 2)].filter(Boolean).join(" · ") || primaryHotel || compactList(golfTitles, 2) || compactList(otherTitles, 1);
  const heroLead = hasActiveTrip
    ? tt("Todo lo importante de tu viaje, en un vistazo claro, cuidado y agradable.")
    : tt("Este espacio está listo para mostrarte tu próximo viaje de forma clara y tranquila.");
  const heroSummaryLine = [nightsLabel, golfLabel, mobilityLabel, supportLabel].filter(Boolean).slice(0, 4).join(" · ");
  const heroVisualLabel = destinationLine || tripLabel || tt("Tu próximo viaje");
  const heroVisualCopy = compactList(golfTitles, 1) || tripDateRange || heroSummaryLine || heroLead;
  const hotelNamesLabel = hotelTitles.join(" · ");
  const golfNamesLabel = golfTitles.join(" · ");
  const hotelCountLabel = hotelCount ? `${hotelCount} ${hotelCount === 1 ? tt("hotel") : tt("hoteles")}` : "";
  const hotelGlanceValue = nightsLabel || hotelCountLabel || tt("Hotel por confirmar");
  const hotelGlanceNote = hotelNamesLabel || tt("Estancia por confirmar");
  const golfGlanceValue = golfLabel || tt("Rondas de golf por confirmar");
  const golfGlanceNote = golfNamesLabel || tt("Campos por confirmar");
  const logisticsValue = hasFlightSummary
    ? compactList(flightTitles, 1)
    : (hasTransferSummary
      ? compactList(transferTitles, 1)
      : (extrasCount ? compactList(otherTitles, 1) : tt("Sin extras destacados")));
  const logisticsNote = hasFlightSummary && hasTransferSummary
    ? mobilityLabel
    : (hasFlightSummary
      ? flightLabel
      : (hasTransferSummary
        ? transferLabel
        : (extrasCount ? tt("Servicios del viaje") : tt("Se completará cuando quede confirmado"))));
  const milestoneHeadline = action?.title || (actionStatus === "pending"
    ? tt("Pago pendiente")
    : (actionStatus === "invoices"
      ? tt("Documentación disponible")
      : (isPaid ? tt("Todo en marcha") : tt("Tu viaje en marcha"))));
  const includeStay = primaryHotel
    ? `${nightsLabel ? `${nightsLabel} · ` : ""}${primaryHotel}`
    : (nightsLabel || tt("Estancia por confirmar"));
  const includeGolf = golfCount
    ? `${golfLabel}${golfTitles.length ? ` · ${compactList(golfTitles, 2)}` : ""}`
    : tt("Rondas de golf por confirmar");
  const includeMobility = [hasFlightSummary ? compactList(flightTitles, 1) : "", hasTransferSummary ? compactList(transferTitles, 1) : ""]
    .filter(Boolean)
    .join(" · ")
    || (extrasCount ? compactList(otherTitles, 1) : tt("Los vuelos y traslados aparecerán aquí cuando estén definidos"));
  const includeExtras = extrasCount
    ? compactList(otherTitles, 2)
    : tt("Coordinación y asistencia de Casanova Golf durante tu viaje");

  const includeStayValue = nightsLabel || tt("Estancia por confirmar");
  const includeStayDetail = compactList(hotelTitles, 3) || tt("Hoteles por confirmar");
  const includeGolfValue = golfLabel || tt("Rondas de golf por confirmar");
  const includeGolfDetail = compactList(golfTitles, 3) || tt("Campos por confirmar");

  const agency = window.CasanovaPortal?.agency || {};
  const agencyName = String(agency.nombre || "Casanova Golf").trim();
  const agencyTel = String(agency.tel || "").trim();
  const agencyEmail = String(agency.email || "").trim();
  const messageSnippet = String(data?.messages?.snippet || "").trim();
  const messageWhen = String(data?.messages?.when || "").trim();
  const unreadMessages = typeof data?.messages?.unread === "number" ? data.messages.unread : 0;
  const messageMeta = [
    messageWhen,
    unreadMessages > 0 ? `${unreadMessages} ${unreadMessages === 1 ? tt("mensaje sin leer") : tt("mensajes sin leer")}` : "",
  ].filter(Boolean).join(" · ");

  const emptyStateTitle = tt("Aún no tienes un viaje confirmado.");
  const emptyStateBody = tt("Cuando tu reserva esté lista, aquí verás todos los detalles de tu viaje: hotel, campos de golf, pagos y documentación.");
  const messageCopy = hasActiveTrip
    ? (messageSnippet || tt("AquÃ­ verÃ¡s los Ãºltimos mensajes sobre tu viaje: horarios de salida, pagos o cualquier actualizaciÃ³n."))
    : tt("Cuando tu reserva esté confirmada, aquí verás la conversación y las actualizaciones del equipo de Casanova Golf.");

  const dashboardMessageCopy = hasActiveTrip
    ? (messageSnippet || tt("Aquí verás los últimos mensajes sobre tu viaje: horarios de salida, pagos o cualquier actualización."))
    : tt("Cuando tu reserva esté confirmada, aquí verás la conversación y las actualizaciones del equipo de Casanova Golf.");

  useEffect(() => {
    setHeroImageReady(false);
    setHeroImageError(false);
  }, [heroImageUrl]);

  const showHeroImage = Boolean(heroImageUrl) && !heroImageError;
  const showHeroMediaSkeleton = showTripDetailSkeleton || (showHeroImage && !heroImageReady);

  const viewTrip = () => {
    if (!hasActiveTrip) return;
    setParam("view", "trip");
    setParam("expediente", String(nextTripId));
  };

  const viewPayments = () => {
    if (!hasActiveTrip) return;
    setParam("view", "trip");
    setParam("expediente", String(nextTripId));
    setParam("tab", "payments");
  };

  const viewActionTrip = () => {
    const targetId = action?.expediente_id || nextTripId;
    if (!targetId) return;
    setParam("view", "trip");
    setParam("expediente", String(targetId));
    if (actionStatus === "pending") setParam("tab", "payments");
    else if (actionStatus === "invoices") setParam("tab", "invoices");
  };

  const viewMessages = () => {
    setParam("view", "inbox");
  };

  const viewNoteTrip = (event) => {
    if (!noteExpedienteId) return;
    if (event && typeof event.preventDefault === "function") event.preventDefault();
    setParam("view", "trip");
    setParam("expediente", noteExpedienteId);
  };

  return (
    <div className="cp-content">
      {preferredFirstName ? (
        <div className="cp-dashboard-welcome">
          <div className="cp-dashboard-welcome__eyebrow">{tt("Bienvenido de nuevo")}</div>
          <div className="cp-dashboard-welcome__title">{tt("Hola")}, {preferredFirstName}</div>
        </div>
      ) : null}

      <div className="cp-grid cp-dash-grid cp-dash-premium cp-dashboard-grid cp-trip-home">
        {hasActiveTrip ? (
          <>
        <section className="cp-trip-hero-card cp-dash-span-12">
          <div className="cp-trip-hero-card__copy">
            <div className="cp-trip-hero-card__eyebrow">{tt("Tu próximo viaje")}</div>
            <div className="cp-trip-hero-card__title">{tripLabel}</div>

            {destinationLine ? (
              <div className="cp-trip-hero-card__destination">
                <span className="cp-trip-hero-card__destination-icon" aria-hidden="true"><MapPinIcon /></span>
                <span>{destinationLine}</span>
              </div>
            ) : null}

            <div className="cp-trip-hero-card__meta">
              <span className="cp-trip-hero-card__meta-item">
                <span className="cp-trip-hero-card__meta-icon" aria-hidden="true"><CalendarIcon /></span>
                <span>{tripDateRange || tt("Fechas por confirmar")}</span>
              </span>
              {tripReferenceLabel ? <span className="cp-trip-hero-card__meta-item is-reference">{tripReferenceLabel}</span> : null}
              {heroTrip?.status ? <span className="cp-pill cp-trip-hero-card__status">{heroTrip.status}</span> : null}
            </div>

            <div className="cp-trip-hero-card__summary">{heroSummaryLine || heroLead}</div>
            {!heroSummaryLine ? <div className="cp-trip-hero-card__lead">{heroLead}</div> : null}

            <div className="cp-trip-hero-card__actions">
              <button className="cp-btn primary" onClick={viewTrip} disabled={!hasTrip}>
                {t("view_details", "Ver detalles")}
              </button>
              <button className="cp-btn cp-btn--ghost" onClick={viewPayments} disabled={!hasPaymentsData}>
                {tt("Pagos")}
              </button>
              {mapUrl ? (
                <a className="cp-btn cp-btn--ghost" href={mapUrl} target="_blank" rel="noreferrer">
                  {heroMap?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
                </a>
              ) : null}
            </div>
          </div>

          <div className="cp-trip-hero-card__visual">
            <div className={`cp-trip-hero-card__media ${showHeroImage ? "has-image" : "is-fallback"} ${showHeroMediaSkeleton ? "is-loading" : ""}`}>
              {showHeroImage ? (
                <img
                  className={`cp-trip-hero-card__media-img ${heroImageReady ? "is-ready" : ""}`}
                  src={heroImageUrl}
                  alt=""
                  loading="lazy"
                  onLoad={() => setHeroImageReady(true)}
                  onError={() => setHeroImageError(true)}
                />
              ) : null}
              <div className="cp-trip-hero-card__media-overlay" aria-hidden="true" />
              {daysLeftLabel ? <span className="cp-trip-hero-card__countdown">{daysLeftLabel}</span> : null}
              <div className="cp-trip-hero-card__media-panel">
                {showHeroMediaSkeleton ? (
                  <div className="cp-trip-hero-card__media-skeleton" aria-hidden="true">
                    <span className="cp-trip-skeleton__line is-kicker" />
                    <span className="cp-trip-skeleton__line is-heading" />
                    <span className="cp-trip-skeleton__line is-heading short" />
                    <span className="cp-trip-skeleton__line is-copy" />
                  </div>
                ) : (
                  <>
                    <div className="cp-trip-hero-card__media-kicker">{tt("Viaje destacado")}</div>
                    <div className="cp-trip-hero-card__media-title">{heroVisualLabel || tt("Tu próximo viaje")}</div>
                    <div className="cp-trip-hero-card__media-copy">{heroVisualCopy || tt("Una vista rápida de lo esencial de tu viaje.")}</div>
                  </>
                )}
              </div>
            </div>
          </div>
        </section>

        <section className="cp-trip-glance cp-dash-span-12">
          <div className="cp-trip-glance__head">
            <div>
              <div className="cp-trip-module__eyebrow">{tt("Tu viaje de un vistazo")}</div>
              <div className="cp-trip-module__title">{tt("Los puntos clave de tu viaje")}</div>
            </div>
            {actionTripLabel ? <span className={`cp-pill cp-trip-glance__badge ${actionPillClass}`}>{actionPillLabel}</span> : null}
          </div>

          <div className="cp-trip-glance__grid">
            <DashboardGlanceItem
              icon={BedIcon}
              label={tt("Hotel")}
              value={hotelGlanceValue}
              note={hotelGlanceNote}
              loading={showTripDetailSkeleton}
            />

            <DashboardGlanceItem
              icon={GolfFlagIcon}
              label={tt("Golf")}
              value={golfGlanceValue}
              note={golfGlanceNote}
              loading={showTripDetailSkeleton}
            />

            <DashboardGlanceItem
              icon={CarIcon}
              label={tt("Vuelos y traslados")}
              value={logisticsValue}
              note={logisticsNote}
              loading={showTripDetailSkeleton}
            />

            <DashboardGlanceItem
              icon={ClockArrowIcon}
              label={tt("Próximo paso")}
              value={milestoneHeadline}
              note={actionTripLabel || tt("Te acompañaremos desde aquí")}
            />
          </div>
        </section>

        <article className="cp-trip-module cp-trip-module--action cp-dash-span-4">
          <div className="cp-trip-module__eyebrow">{tt("Próximo paso")}</div>
          <div className="cp-trip-module__title">{milestoneHeadline}</div>
          <div className="cp-trip-module__copy">{actionText}</div>
          {actionNote?.label && actionNoteUrl ? (
            <div className="cp-trip-module__note">
              {tt("También tienes otro viaje a la vista:")} {" "}
              <a href={actionNoteUrl} onClick={noteExpedienteId ? viewNoteTrip : undefined}>
                {actionNote.label}
              </a>
              {actionNote.pending ? ` · ${tt("Pendiente")}: ${actionNote.pending}` : ""}
            </div>
          ) : null}
          <div className="cp-trip-module__footer">
            {actionTripLabel ? (
              <button className="cp-btn primary" onClick={viewActionTrip}>
                {actionCtaLabel}
              </button>
            ) : <span />}
            <span className={`cp-pill cp-trip-module__status ${actionPillClass}`}>{actionPillLabel}</span>
          </div>
        </article>

        <article className="cp-trip-module cp-trip-module--finance cp-dash-span-4">
          <div className="cp-trip-module__eyebrow">{tt("Pagos del viaje")}</div>
          <div className="cp-trip-module__title">{tt("Pagos")}</div>
          {hasPaymentsData ? (
            <>
              <div className="cp-trip-finance">
                <div className="cp-trip-finance__stat">
                  <span>{tt("Pagado")}</span>
                  <strong>{paidLabel}</strong>
                </div>
                <div className="cp-trip-finance__stat">
                  <span>{tt("Pendiente")}</span>
                  <strong className="is-warn">{pendingLabel}</strong>
                </div>
                <div className="cp-trip-finance__stat">
                  <span>{tt("Total")}</span>
                  <strong>{totalLabel}</strong>
                </div>
              </div>
              <div className="cp-trip-finance__meter">
                <span className="cp-trip-finance__meter-bar" style={{ width: `${paymentProgress}%` }} />
              </div>
              <div className="cp-trip-module__meta">
                {isPaid ? tt("Todo el viaje está liquidado.") : `Has pagado ${paidLabel} de ${totalLabel}.`}
              </div>
              <button className="cp-btn cp-btn--ghost" onClick={viewPayments}>
                {tt("Ver pagos")}
              </button>
            </>
          ) : (
            <div className="cp-trip-module__copy">
              {tt("Todavía no hay datos de pagos disponibles. En cuanto estén listos, aparecerán aquí resumidos.")}
            </div>
          )}
        </article>

        <article className="cp-trip-module cp-trip-module--includes cp-dash-span-4">
          <div className="cp-trip-module__eyebrow">{tt("Lo que incluye tu viaje")}</div>
          <div className="cp-trip-module__title">{tt("Tu viaje incluye")}</div>
          <div className="cp-trip-includes">
            <DashboardIncludeItem
              icon={BedIcon}
              label={tt("Estancia")}
              value={includeStayValue}
              detail={includeStayDetail}
              emphasis
            />
            <DashboardIncludeItem
              icon={GolfFlagIcon}
              label={tt("Golf")}
              value={includeGolfValue}
              detail={includeGolfDetail}
              emphasis
            />
            <DashboardIncludeItem
              icon={CarIcon}
              label={tt("Vuelos y traslados")}
              value={includeMobility}
            />
            <DashboardIncludeItem
              icon={ChatBubbleIcon}
              label={tt("Extras y asistencia")}
              value={includeExtras}
            />
          </div>
        </article>
          </>
        ) : (
          <section className="cp-dash-span-12">
            <div className="cp-card" style={{ background: "var(--surface)" }}>
              <EmptyState title={emptyStateTitle} icon="🧳">
                {emptyStateBody}
              </EmptyState>
            </div>
          </section>
        )}

        <article className="cp-trip-module cp-trip-module--contact cp-dash-span-12">
          <div className="cp-trip-contact">
            <div className="cp-trip-contact__main">
              <div className="cp-trip-module__eyebrow">{tt("Tu equipo Casanova")}</div>
              <div className="cp-trip-module__title">{agencyName}</div>
              <div className="cp-trip-module__copy">
                {tt("Si necesitas ajustar algo, resolver una duda o revisar el viaje con nosotros, aquí tienes acceso directo al equipo que te acompaña.")}
              </div>
              <div className="cp-trip-contact__links">
                {agencyTel ? <a className="cp-trip-contact__link" href={`tel:${agencyTel.replace(/\s+/g, "")}`}>{agencyTel}</a> : null}
                {agencyEmail ? <a className="cp-trip-contact__link" href={`mailto:${agencyEmail}`}>{agencyEmail}</a> : null}
              </div>
            </div>

            <div className="cp-trip-contact__message">
              <div className="cp-trip-contact__message-head">
                <span className="cp-trip-contact__message-icon" aria-hidden="true"><ChatBubbleIcon /></span>
                <span>{tt("Mensajes")}</span>
              </div>
              <div className="cp-trip-contact__message-copy">
                {dashboardMessageCopy}
              </div>
              {messageMeta ? <div className="cp-trip-contact__message-meta">{messageMeta}</div> : null}
              <button className="cp-btn cp-btn--ghost" onClick={viewMessages}>
                {tt("Abrir mensajes")}
              </button>
            </div>
          </div>
        </article>

        {mulligansEnabled ? (
          <section className="cp-dash-span-12">
            <div className={`cp-dashboard-loyalty ${tierClass}`}>
              <div className="cp-dashboard-loyalty__lead">
                <div className="cp-dashboard-loyalty__eyebrow">{tt("Programa de fidelización")}</div>
                <div className="cp-dashboard-loyalty__title">{tt("Tus Mulligans")}</div>
                <div className="cp-dashboard-loyalty__copy">
                  {hintText || tt("Aquí tienes tu avance y un acceso rápido a los movimientos, sin recargar la portada.")}
                </div>
              </div>
              <div className="cp-dashboard-loyalty__summary">
                <div className="cp-dashboard-loyalty__current">
                  <span className={`cp-dashboard-loyalty__medal is-${tierTone}`} aria-hidden="true">
                    <MulligansTierIcon tone={tierTone} />
                  </span>
                  <div className="cp-dashboard-loyalty__current-copy">
                    <div className="cp-dashboard-loyalty__kicker">{tt("Nivel actual")}</div>
                    <div className="cp-dashboard-loyalty__level">{levelLabel}</div>
                  </div>
                </div>
                <div className="cp-dashboard-loyalty__points-panel">
                  <div className="cp-dashboard-loyalty__kicker">{tt("Puntos")}</div>
                  <div className="cp-dashboard-loyalty__points">{points.toLocaleString("es-ES")}</div>
                </div>
              </div>
              <div className="cp-dashboard-loyalty__stats-old" aria-hidden="true">
                <div className="cp-dashboard-loyalty__points">{points.toLocaleString("es-ES")}</div>
                <div className="cp-dashboard-loyalty__meta">
                  {tt("Nivel")} {levelLabel} · {tt("Ratio actual")} {multLabel || "—"}
                </div>
                <div className="cp-dashboard-loyalty__sub">
                  {tt("Gasto acumulado")}: {typeof mull?.spend === "number" ? euro(mull.spend) : "—"}
                </div>
              </div>
              <div className="cp-dashboard-loyalty__progress-head">
                <div className="cp-dashboard-loyalty__progress-end">
                  <span>{tt("Nivel actual")}</span>
                  <strong>{levelLabel}</strong>
                </div>
                <div className="cp-dashboard-loyalty__progress-end is-next">
                  <span>{tt("PrÃ³ximo nivel")}</span>
                  <strong>{progressTargetLabel}</strong>
                </div>
              </div>
              <div className="cp-dashboard-loyalty__progress">
                <span className="cp-dashboard-loyalty__progress-bar" style={{ width: `${loyaltyProgressPct}%` }} />
              </div>
              <div className="cp-dashboard-loyalty__hint">{loyaltyHint}</div>
              <div className="cp-dashboard-loyalty__actions">
                {lastSyncLabel ? <span className="cp-dashboard-loyalty__updated">{tt("Actualizado")}: {lastSyncLabel}</span> : <span />}
                <button className="cp-btn cp-btn--ghost" onClick={() => setParam("view", "mulligans")}>
                  {tt("Ver movimientos")}
                </button>
              </div>
            </div>
          </section>
        ) : null}
      </div>
    </div>
  );
}
