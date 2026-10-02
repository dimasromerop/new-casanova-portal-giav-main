import React, { useState } from "react";

import { t, tt, ttf } from "../../i18n/t.js";
import Icon from "../Icon.jsx";
import { EmptyState, ProgressBar } from "../ui.jsx";
import { euro, formatDateES, formatNumberUi, formatTierLabel, formatTimestamp, normalizeTripDates } from "../../lib/formatters.js";
import { setParams } from "../../lib/params.js";
import { firstOpenGroup, formatDateRange, formatDayMonth, formatPrice, fromPrice, nightsLabel as proposalNights, travellersLabel } from "../../lib/proposals.js";
import { compactList, countNightsBetween, flightSummary, serviceSemanticType, transferSummary, tripAllServices, uniqueStrings } from "../../lib/tripServices.js";

function firstNameFromProfile(profile) {
  const giavName = String(profile?.giav?.nombre || "").trim();
  if (giavName) return giavName.split(/\s+/)[0] || "";

  const displayName = String(profile?.user?.displayName || "").trim();
  if (displayName) return displayName.split(/\s+/)[0] || "";

  return "";
}

// GIAV guarda los nombres en mayúsculas: «ROMERO» → «Romero».
function properCase(value) {
  const text = String(value || "");
  if (text !== text.toUpperCase()) return text;
  return text.toLowerCase().replace(/(^|[\s-])(\p{L})/gu, (match, sep, letter) => sep + letter.toUpperCase());
}

function normalizeTierSlug(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_")
    .replace(/\+/g, "_plus")
    .replace(/-/g, "_");
}

const LOYALTY_TIER_MIN_SPEND = {
  birdie: 0,
  eagle: 5000,
  eagle_plus: 15000,
  albatross: 30000,
};

function HomeCard({ icon, title, aside = null, className = "", children }) {
  return (
    <article className={`cp-home-card ${className}`.trim()}>
      <header className="cp-home-card__head">
        <span className="cp-home-card__icon"><Icon name={icon} size={20} /></span>
        <h2 className="cp-home-card__title">{title}</h2>
        {aside}
      </header>
      {children}
    </article>
  );
}

// Aviso de propuestas abiertas cuando no hay viaje activo: la del primer grupo con algo abierto.
function ProposalTeaser({ group, open }) {
  const first = open[0];
  const title = (group.type === "request" && group.request?.title) || group.title || first.title || tt("Tu propuesta de viaje");
  const dates = formatDateRange(first.start_date, first.end_date);
  const nights = proposalNights(first.nights);
  const travellers = travellersLabel(first.players, first.non_players);
  const from = fromPrice(open);
  const expires = open.map((proposal) => proposal.expires_on).filter(Boolean).sort()[0] || "";
  const badge = open.length > 1 ? ttf("{count} opciones para tu viaje", { count: open.length }) : tt("Nueva propuesta");
  const fromLabel = !from
    ? ""
    : from.kind === "player"
      ? (from.basis === "single" ? tt("por jugador en habitación individual") : tt("por jugador en habitación doble"))
      : (from.basis === "single" ? tt("por persona en habitación individual") : tt("por persona en habitación doble"));

  return (
    <section className="cp-prop-teaser" aria-labelledby="cp-home-proposal-title">
      <div className="cp-prop-teaser__media">
        {first.image ? <img src={first.image} alt="" decoding="async" /> : null}
      </div>
      <div className="cp-prop-teaser__body">
        <span className="cp-badge cp-badge--new">{badge}</span>
        <h2 className="cp-prop-teaser__title" id="cp-home-proposal-title">{title}</h2>
        <p className="cp-prop-card__meta">
          {dates ? <span><Icon name="calendar" size={16} />{[dates, nights].filter(Boolean).join(" · ")}</span> : null}
          {travellers ? <span><Icon name="user" size={16} />{travellers}</span> : null}
        </p>
        {from ? (
          <p className="cp-prop-teaser__price">
            {open.length > 1 ? `${tt("Desde")} ` : ""}<strong>{formatPrice(from.value, from.currency)}</strong> {fromLabel}
          </p>
        ) : null}
        {expires ? (
          <p className="cp-prop-card__note is-warn">
            <Icon name="clock" size={16} />
            {open.length > 1
              ? ttf("Válidas hasta el {date}", { date: formatDayMonth(expires) })
              : ttf("Válida hasta el {date}", { date: formatDayMonth(expires) })}
          </p>
        ) : null}
        <div className="cp-prop-teaser__actions">
          <button type="button" className="cp-btn cp-btn--primary" onClick={() => setParams({ view: "proposals", expediente: null, tab: null })}>
            {open.length > 1 ? tt("Ver las propuestas") : tt("Ver la propuesta")}
            <Icon name="arrow-right" size={18} />
          </button>
        </div>
      </div>
    </section>
  );
}

function IncludeRow({ icon, label, value, detail = "" }) {
  return (
    <li className="cp-home-includes__row">
      <span className="cp-home-includes__icon"><Icon name={icon} size={18} /></span>
      <div className="cp-home-includes__copy">
        <div className="cp-home-includes__label">{label}</div>
        <div className="cp-home-includes__value">{value}</div>
        {detail ? <div className="cp-home-includes__detail">{detail}</div> : null}
      </div>
    </li>
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
  proposals = null,
}) {
  // Se guarda qué URL ha cargado (o fallado) en lugar de un booleano: así una foto
  // servida desde caché, que dispara onLoad antes que cualquier efecto, no se pierde.
  const [loadedImageUrl, setLoadedImageUrl] = useState("");
  const [failedImageUrl, setFailedImageUrl] = useState("");
  const heroImageReady = Boolean(heroImageUrl) && loadedImageUrl === heroImageUrl;
  const heroImageError = Boolean(heroImageUrl) && failedImageUrl === heroImageUrl;

  const nextTrip = data?.next_trip || null;
  const nextTripId = Number(nextTrip?.id || 0);
  const hasActiveTrip = Boolean(data?.active_trip_exists && nextTripId > 0);
  const payments = data?.payments || null;
  const mull = data?.mulligans || null;
  const action = data?.next_action || null;
  const firstName = properCase(firstNameFromProfile(profile));
  const detail = tripDetail && typeof tripDetail === "object" ? tripDetail : null;
  const detailLoading = Boolean(tripDetailLoading && hasActiveTrip);
  const detailTrip = detail?.trip && typeof detail.trip === "object" ? detail.trip : null;

  /* ----- Servicios del viaje ----- */
  const allServices = tripAllServices(detail);
  const byType = (type) => allServices.filter((service) => serviceSemanticType(service) === type);
  const hotelServices = byType("hotel");
  const golfServices = byType("golf");
  const flightServices = byType("flight");
  const transferServices = byType("transfer");
  const otherServices = allServices.filter((service) => !["hotel", "golf", "flight", "transfer"].includes(serviceSemanticType(service)));

  const hotelTitles = uniqueStrings(hotelServices.map((service) => service?.title));
  const golfTitles = uniqueStrings(golfServices.map((service) => service?.title));
  const flightTitles = uniqueStrings(flightServices.map((service) => flightSummary(service)));
  const transferTitles = uniqueStrings(transferServices.map((service) => transferSummary(service)));
  const otherTitles = uniqueStrings(otherServices.map((service) => service?.title));

  /* ----- Viaje ----- */
  const heroTrip = hasActiveTrip ? (detailTrip || nextTrip || null) : null;
  const tripLabel = heroTrip?.title ? String(heroTrip.title) : tt("Viaje");
  const tripCode = heroTrip?.code ? String(heroTrip.code) : "";
  const tripDates = normalizeTripDates(heroTrip || nextTrip);
  const tripDateRange = [formatDateES(tripDates.start), formatDateES(tripDates.end)].filter((value) => value && value !== "—").join(" — ");
  const tripDatesLabel = tripDateRange || tt("Fechas por confirmar");
  const daysLeftRaw = Number(nextTrip?.days_left);
  const daysLeft = Number.isFinite(daysLeftRaw) ? Math.max(0, Math.round(daysLeftRaw)) : null;
  const daysLeftLabel = daysLeft === null
    ? ""
    : (daysLeft === 0 ? tt("Tu viaje empieza hoy") : ttf("Tu viaje empieza en {days} días", { days: daysLeft }));

  const nights = countNightsBetween(tripDates.start, tripDates.end);
  const nightsLabel = nights ? (nights === 1 ? ttf("{count} noche", { count: nights }) : ttf("{count} noches", { count: nights })) : "";
  const golfCount = golfServices.length;
  const golfLabel = golfCount
    ? (golfCount === 1 ? ttf("{count} ronda de golf", { count: golfCount }) : ttf("{count} rondas de golf", { count: golfCount }))
    : "";
  const allIncluded = (list) => list.every((service) => service?.included !== false);
  const hasFlights = flightTitles.length > 0;
  const hasTransfers = transferTitles.length > 0;
  const mobilityLabel = hasFlights && hasTransfers
    ? (allIncluded(flightServices) && allIncluded(transferServices) ? tt("vuelos y traslados incluidos") : tt("vuelos y traslados previstos"))
    : hasFlights
      ? (allIncluded(flightServices) ? tt("vuelos incluidos") : tt("vuelos previstos"))
      : hasTransfers
        ? (allIncluded(transferServices) ? tt("traslados incluidos") : tt("traslados previstos"))
        : "";
  const summaryLine = [hotelServices.length ? nightsLabel : "", golfLabel, mobilityLabel].filter(Boolean).join(" · ");
  const destinationLine = [hotelTitles[0] || "", compactList(golfTitles, 2)].filter(Boolean).join(" · ");

  const mapUrl = heroMap?.url ? String(heroMap.url) : "";
  const showHeroImage = Boolean(heroImageUrl) && !heroImageError;

  /* ----- Pagos y próximo paso ----- */
  const hasPaymentsData = Boolean(payments && !Array.isArray(payments));
  const totalAmount = hasPaymentsData ? Number(payments?.total) : Number.NaN;
  const paidAmount = hasPaymentsData ? Number(payments?.paid) : Number.NaN;
  const pendingCandidate = hasPaymentsData ? Number(payments?.pending) : Number.NaN;
  const pendingAmount = hasPaymentsData
    ? (Number.isFinite(pendingCandidate)
        ? pendingCandidate
        : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount) ? Math.max(0, totalAmount - paidAmount) : null))
    : null;
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount) : "—";
  const paymentProgress = hasPaymentsData && Number.isFinite(totalAmount) && totalAmount > 0 && Number.isFinite(paidAmount)
    ? Math.max(0, Math.min(100, Math.round((paidAmount / totalAmount) * 100)))
    : 0;
  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;

  const actionStatus = action?.status || (hasPaymentsData ? (isPaid ? "ok" : "pending") : "info");
  const actionBadge = action?.badge || (hasPaymentsData ? (isPaid ? tt("Todo listo") : tt("Pendiente")) : tt("Info"));
  const actionBadgeLabel = actionStatus === "invoices" && typeof action?.invoice_count === "number"
    ? ttf("{badge} · {count}", { badge: actionBadge, count: action.invoice_count })
    : actionBadge;
  const actionBadgeVariant = actionStatus === "pending" ? "warning" : (actionStatus === "ok" ? "success" : "info");
  const actionHeadline = action?.title || (actionStatus === "pending"
    ? tt("Pago pendiente")
    : (actionStatus === "invoices" ? tt("Documentación disponible") : (isPaid ? tt("Todo en marcha") : tt("Tu viaje en marcha"))));
  const actionText = actionStatus === "pending" && pendingAmount !== null
    ? ttf("Tienes un pago pendiente de {amount} para este viaje.", { amount: euro(pendingAmount) })
    : actionStatus === "invoices"
      ? tt("Ya tienes documentación disponible para este viaje.")
      : actionStatus === "ok"
        ? tt("Todo está en orden. Ahora solo queda preparar el viaje con calma.")
        : !hasPaymentsData
          ? tt("Estamos preparando la información de este viaje. En cuanto esté lista, la verás aquí.")
          : (action?.description || tt("Aquí verás el siguiente paso importante de tu viaje."));
  const actionIcon = actionStatus === "pending" ? "credit-card" : (actionStatus === "invoices" ? "file" : "success");
  const actionCtaLabel = actionStatus === "pending" ? tt("Ver pagos") : (actionStatus === "invoices" ? tt("Ver facturas") : tt("Ver viaje"));
  const actionNote = action?.note || null;
  const noteExpedienteId = actionNote?.expediente_id ? String(actionNote.expediente_id) : "";

  /* ----- Mensajes ----- */
  const messageSnippet = String(data?.messages?.snippet || "").trim();
  const messageWhen = String(data?.messages?.when || "").trim();
  const unreadMessages = typeof data?.messages?.unread === "number" ? data.messages.unread : 0;
  const messageCopy = hasActiveTrip
    ? (messageSnippet || tt("Aquí verás los últimos mensajes sobre tu viaje: horarios de salida, pagos o cualquier actualización."))
    : tt("Cuando tu reserva esté confirmada, aquí verás la conversación y las actualizaciones del equipo de Casanova Golf.");
  const unreadLabel = unreadMessages > 0
    ? (unreadMessages === 1 ? ttf("{count} mensaje sin leer", { count: unreadMessages }) : ttf("{count} mensajes sin leer", { count: unreadMessages }))
    : "";

  /* ----- Mulligans ----- */
  const points = typeof mull?.points === "number" ? mull.points : 0;
  const levelLabel = formatTierLabel(mull?.tier);
  const tierSlug = normalizeTierSlug(mull?.tier);
  const nextTier = mull?.next_tier_label ? String(mull.next_tier_label) : null;
  const remaining = typeof mull?.remaining_to_next === "number" ? mull.remaining_to_next : null;
  const progressRaw = typeof mull?.progress_pct === "number"
    ? mull.progress_pct
    : (typeof mull?.progress === "number" ? (mull.progress <= 1 ? mull.progress * 100 : mull.progress) : 0);
  const spendValue = typeof mull?.spend === "number" ? mull.spend : Number.NaN;
  const tierMinSpend = LOYALTY_TIER_MIN_SPEND[tierSlug] ?? 0;
  const nextTierMinSpend = LOYALTY_TIER_MIN_SPEND[normalizeTierSlug(nextTier)];
  const fallbackProgress = Number.isFinite(spendValue) && Number.isFinite(nextTierMinSpend)
    ? ((spendValue - tierMinSpend) / Math.max(1, nextTierMinSpend - tierMinSpend)) * 100
    : 0;
  const loyaltyProgress = nextTier ? Math.max(0, Math.min(100, Math.round(Math.max(progressRaw || 0, fallbackProgress)))) : 100;
  const loyaltyHint = remaining !== null && nextTier
    ? ttf("Te faltan {amount} para {tier}.", { amount: euro(remaining), tier: nextTier })
    : tt("Ya estás en el nivel más alto del programa.");
  const lastSyncLabel = formatTimestamp(mull?.last_sync);

  /* ----- Propuestas ----- */
  const openProposals = firstOpenGroup(proposals);
  const newProposals = Number(proposals?.counts?.new || 0);
  const openProposalCount = Number(proposals?.counts?.open || 0);
  const proposalsLead = newProposals > 0
    ? (newProposals === 1 ? tt("Tienes una propuesta nueva") : ttf("Tienes {count} propuestas nuevas", { count: newProposals }))
    : "";

  /* ----- Navegación (una sola entrada en el historial) ----- */
  const openTrip = (id, tab = null) => {
    if (!id) return;
    setParams({ view: "trip", expediente: String(id), tab });
  };
  const viewAction = () => {
    const tab = actionStatus === "pending" ? "payments" : (actionStatus === "invoices" ? "invoices" : null);
    openTrip(action?.expediente_id || nextTripId, tab);
  };
  const agencyWeb = String(window.CasanovaPortal?.agency?.web || "").trim();

  return (
    <div className="cp-content cp-home">
      <header className="cp-home__head">
        <h1 className="cp-home__title">{firstName ? `${tt("Hola")}, ${firstName}` : tt("Hola")}</h1>
        {hasActiveTrip && daysLeftLabel ? <p className="cp-home__lead">{daysLeftLabel}</p> : null}
        {!hasActiveTrip && proposalsLead ? <p className="cp-home__lead">{proposalsLead}</p> : null}
      </header>

      {hasActiveTrip ? (
        <section className="cp-home-hero" aria-labelledby="cp-home-trip-title">
          <div className={`cp-home-hero__media ${showHeroImage ? "has-image" : ""} ${showHeroImage && !heroImageReady ? "is-loading" : ""}`.trim()}>
            {showHeroImage ? (
              <img
                className={`cp-home-hero__img ${heroImageReady ? "is-ready" : ""}`.trim()}
                src={heroImageUrl}
                alt=""
                decoding="async"
                fetchpriority="high"
                ref={(img) => { if (img?.complete && img.naturalWidth > 0) setLoadedImageUrl(heroImageUrl); }}
                onLoad={() => setLoadedImageUrl(heroImageUrl)}
                onError={() => setFailedImageUrl(heroImageUrl)}
              />
            ) : null}
            <div className="cp-home-hero__shade" aria-hidden="true" />
            {heroTrip?.status ? <span className="cp-home-hero__status">{heroTrip.status}</span> : null}
            <div className="cp-home-hero__body">
              <p className="cp-home-hero__kicker">{tt("Tu próximo viaje")}</p>
              <h2 className="cp-home-hero__title" id="cp-home-trip-title">{tripLabel}</h2>
              <p className="cp-home-hero__meta">
                <span><Icon name="calendar" size={18} />{tripDatesLabel}</span>
                {destinationLine ? <span><Icon name="map-pin" size={18} />{destinationLine}</span> : null}
              </p>
            </div>
          </div>

          <div className="cp-home-hero__bar">
            <div className="cp-home-hero__facts">
              {detailLoading ? (
                <span className="cp-home-hero__skeleton" aria-hidden="true" />
              ) : (
                <>
                  {summaryLine ? <span className="cp-home-hero__summary">{summaryLine}</span> : null}
                  {tripCode ? <span className="cp-home-hero__ref">{ttf("Referencia {code}", { code: tripCode })}</span> : null}
                </>
              )}
            </div>
            <div className="cp-home-hero__actions">
              {mapUrl ? (
                <a className="cp-btn cp-btn--ghost" href={mapUrl} target="_blank" rel="noreferrer">
                  <Icon name="map-pin" size={18} />
                  {heroMap?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
                </a>
              ) : null}
              <button type="button" className="cp-btn cp-btn--primary" onClick={() => openTrip(nextTripId)}>
                {t("view_details", "Ver detalles")}
                <Icon name="arrow-right" size={18} />
              </button>
            </div>
          </div>
        </section>
      ) : openProposals ? (
        <ProposalTeaser group={openProposals.group} open={openProposals.open} />
      ) : (
        <EmptyState
          title={tt("Aún no tienes un viaje confirmado.")}
          icon="luggage"
          action={agencyWeb ? (
            <a className="cp-btn cp-btn--primary" href={agencyWeb} target="_blank" rel="noreferrer">
              {tt("Diseña tu viaje")}
            </a>
          ) : null}
        >
          {tt("Cuando tu reserva esté lista, aquí verás todos los detalles de tu viaje: hotel, campos de golf, pagos y documentación.")}
        </EmptyState>
      )}

      <div className="cp-home-grid">
        {hasActiveTrip ? (
          <HomeCard
            icon={actionIcon}
            title={tt("Próximo paso")}
            className={`cp-home-card--action is-${actionStatus}`}
            aside={<span className={`cp-badge cp-badge--${actionBadgeVariant}`}>{actionBadgeLabel}</span>}
          >
            <p className="cp-home-card__headline">{actionHeadline}</p>
            <p className="cp-home-card__text">{actionText}</p>

            {hasPaymentsData ? (
              <div className="cp-home-pay">
                <ProgressBar value={paymentProgress} variant="finance" label={tt("Progreso de pago")} />
                <div className="cp-home-pay__figures">
                  <span>{tt("Pagado")} <strong>{paidLabel}</strong></span>
                  <span>{tt("Total")} <strong>{totalLabel}</strong></span>
                </div>
              </div>
            ) : null}

            {actionNote?.label && noteExpedienteId ? (
              <p className="cp-home-card__note">
                {tt("También tienes otro viaje a la vista:")}{" "}
                <button type="button" className="cp-linkbtn" onClick={() => openTrip(noteExpedienteId)}>{actionNote.label}</button>
                {actionNote.pending ? ` ${ttf("· {label}: {value}", { label: tt("Pendiente"), value: actionNote.pending })}` : ""}
              </p>
            ) : null}

            <div className="cp-home-card__foot">
              <button type="button" className={`cp-btn ${actionStatus === "pending" ? "cp-btn--primary" : ""}`.trim()} onClick={viewAction}>
                {actionCtaLabel}
              </button>
            </div>
          </HomeCard>
        ) : null}

        {hasActiveTrip ? (
          <HomeCard icon="luggage" title={tt("Tu viaje incluye")}>
            {detailLoading ? (
              <div className="cp-skeleton" aria-hidden="true">
                <div className="cp-skeleton__line" />
                <div className="cp-skeleton__line" />
                <div className="cp-skeleton__line" />
              </div>
            ) : (
              <ul className="cp-home-includes">
                {hotelServices.length ? (
                  <IncludeRow icon="bed" label={tt("Estancia")} value={nightsLabel || tt("Estancia por confirmar")} detail={compactList(hotelTitles, 3)} />
                ) : null}
                <IncludeRow
                  icon="golf"
                  label={tt("Golf")}
                  value={golfLabel || tt("Rondas de golf por confirmar")}
                  detail={compactList(golfTitles, 3) || tt("Campos por confirmar")}
                />
                {hasFlights || hasTransfers ? (
                  <IncludeRow
                    icon="plane"
                    label={tt("Vuelos y traslados")}
                    value={mobilityLabel}
                    detail={[compactList(flightTitles, 1), compactList(transferTitles, 1)].filter(Boolean).join(" · ")}
                  />
                ) : null}
                <IncludeRow
                  icon="sparkles"
                  label={tt("Extras y asistencia")}
                  value={otherTitles.length ? compactList(otherTitles, 2) : tt("Coordinación y asistencia de Casanova Golf durante tu viaje")}
                />
              </ul>
            )}
          </HomeCard>
        ) : null}

        {hasActiveTrip && openProposalCount > 0 ? (
          <HomeCard
            icon="file"
            title={tt("Propuestas")}
            className="cp-home-card--proposals"
            aside={newProposals > 0 ? <span className="cp-badge cp-badge--new">{tt("Nueva")}</span> : null}
          >
            <p className="cp-home-card__text">
              {openProposalCount === 1
                ? tt("Tienes una propuesta de viaje pendiente de revisar.")
                : ttf("Tienes {count} propuestas de viaje pendientes de revisar.", { count: openProposalCount })}
            </p>
            <div className="cp-home-card__foot">
              <button type="button" className="cp-btn cp-btn--ghost" onClick={() => setParams({ view: "proposals", expediente: null, tab: null })}>
                {tt("Ver propuestas")}
              </button>
            </div>
          </HomeCard>
        ) : null}

        <HomeCard
          icon="message"
          title={tt("Mensajes")}
          className="cp-home-card--messages"
          aside={unreadMessages > 0 ? <span className="cp-count" aria-label={unreadLabel}>{unreadMessages}</span> : null}
        >
          <p className={`cp-home-card__text ${messageSnippet && hasActiveTrip ? "is-quote" : ""}`.trim()}>{messageCopy}</p>
          {messageWhen || unreadLabel ? (
            <p className="cp-home-card__meta">{[messageWhen, unreadLabel].filter(Boolean).join(" · ")}</p>
          ) : null}
          <div className="cp-home-card__foot">
            <button type="button" className="cp-btn cp-btn--ghost" onClick={() => setParams({ view: "inbox", expediente: null, tab: null })}>
              {tt("Abrir mensajes")}
            </button>
          </div>
        </HomeCard>

        {mulligansEnabled ? (
          <HomeCard
            icon="sparkles"
            title={tt("Tus Mulligans")}
            className="cp-home-card--loyalty"
            aside={levelLabel ? <span className={`cp-pill is-${tierSlug}`}>{levelLabel}</span> : null}
          >
            <p className="cp-home-loyalty__points">
              <strong>{formatNumberUi(points)}</strong>
              <span>{tt("puntos Mulligans acumulados")}</span>
            </p>
            <ProgressBar value={loyaltyProgress} variant="loyalty" label={tt("Progreso de Mulligans")} />
            <p className="cp-home-card__meta">{loyaltyHint}</p>
            <div className="cp-home-card__foot">
              {lastSyncLabel ? <span className="cp-home-card__meta">{tt("Actualizado")}: {lastSyncLabel}</span> : <span />}
              <button type="button" className="cp-btn cp-btn--ghost" onClick={() => setParams({ view: "mulligans", expediente: null, tab: null })}>
                {tt("Ver movimientos")}
              </button>
            </div>
          </HomeCard>
        ) : null}
      </div>
    </div>
  );
}
