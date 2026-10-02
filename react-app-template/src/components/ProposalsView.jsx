import React, { useState } from "react";

import Icon from "./Icon.jsx";
import EmailVerifyNotice from "./EmailVerifyNotice.jsx";
import RequestDialog from "./RequestDialog.jsx";
import { EmptyState, Notice, Skeleton } from "./ui.jsx";
import { tt, ttf } from "../i18n/t.js";
import { setParams } from "../lib/params.js";
import {
  formatDateRange,
  formatDayMonth,
  formatMonthYear,
  formatPrice,
  formatShortDate,
  nightsLabel,
  priceBasisLabel,
  priceLineLabel,
  travellersLabel,
} from "../lib/proposals.js";

function mailto(email, subject) {
  return `mailto:${email}?subject=${encodeURIComponent(subject)}`;
}

function agentLabel(agent) {
  return agent?.name ? ttf("Escribir a {name}", { name: agent.name }) : tt("Escribir a tu agente");
}

function PriceBlock({ price }) {
  const main = Array.isArray(price?.main) ? price.main : [];
  const extras = Array.isArray(price?.extras) ? price.extras : [];
  if (!main.length) return null;
  const currency = price.currency || "EUR";

  return (
    <div className="cp-prop-price">
      <p className="cp-prop-price__label">{priceBasisLabel(price.basis)}</p>
      <dl className="cp-prop-price__rows">
        {main.map((line, index) => (
          <div className="cp-prop-price__row" key={`m${index}`}>
            <dt>{priceLineLabel(line)}</dt>
            <dd>{formatPrice(line.value, currency)}</dd>
          </div>
        ))}
        {extras.map((line, index) => {
          const isSupplement = line.kind === "single_supplement" || line.kind === "informative_single";
          return (
            <div className="cp-prop-price__row is-extra" key={`e${index}`}>
              <dt>{priceLineLabel(line)}</dt>
              <dd>{isSupplement ? `+ ${formatPrice(line.value, currency)}` : formatPrice(line.value, currency)}</dd>
            </div>
          );
        })}
      </dl>
    </div>
  );
}

function ProposalCard({ proposal, optionNumber = 0, onOpen, onRenew }) {
  const { state, agent } = proposal;
  const isOpen = state === "open";
  const isAccepted = state === "accepted";
  const isExpired = state === "expired";
  const dates = formatDateRange(proposal.start_date, proposal.end_date);
  const nights = nightsLabel(proposal.nights);
  const travellers = travellersLabel(proposal.players, proposal.non_players);
  const title = proposal.title || tt("Tu propuesta de viaje");

  const kicker = isAccepted
    ? (proposal.accepted_on ? ttf("Aceptada el {date}", { date: formatShortDate(proposal.accepted_on) }) : tt("Aceptada"))
    : isExpired
      ? (proposal.expires_on ? ttf("Caducó el {date}", { date: formatShortDate(proposal.expires_on) }) : tt("Caducada"))
      : [agent?.name ? ttf("Preparada por {name}", { name: agent.name }) : "", formatShortDate(proposal.sent_on)].filter(Boolean).join(" · ");

  const openProposal = () => onOpen?.(proposal);

  return (
    <article className={`cp-prop-card ${proposal.is_new ? "is-new" : ""} ${isExpired ? "is-expired" : ""}`.trim()}>
      <div className="cp-prop-card__media">
        {proposal.image ? <img src={proposal.image} alt="" loading="lazy" decoding="async" /> : null}
        <span className="cp-prop-card__status">
          {proposal.is_new ? <span className="cp-badge cp-badge--new">{tt("Nueva")}</span> : null}
          {isAccepted ? <span className="cp-badge cp-badge--success">{tt("Aceptada")}</span> : null}
          {isExpired ? <span className="cp-badge cp-badge--muted">{tt("Caducada")}</span> : null}
          {optionNumber ? <span className="cp-badge cp-badge--glass">{ttf("Opción {n}", { n: optionNumber })}</span> : null}
        </span>
      </div>

      <div className="cp-prop-card__body">
        {kicker ? <p className="cp-prop-card__kicker">{kicker}</p> : null}
        <h4 className="cp-prop-card__title">{title}</h4>
        <p className="cp-prop-card__meta">
          {dates ? <span><Icon name="calendar" size={16} />{[dates, nights].filter(Boolean).join(" · ")}</span> : null}
          {travellers ? <span><Icon name="user" size={16} />{travellers}</span> : null}
        </p>

        {isOpen && proposal.expires_on ? (
          <p className="cp-prop-card__note is-warn">
            <Icon name="clock" size={16} />
            {ttf("Válida hasta el {date}", { date: formatDayMonth(proposal.expires_on) })}
          </p>
        ) : null}
        {isAccepted ? (
          <p className="cp-prop-card__note is-ok">
            <Icon name="success" size={16} />
            {proposal.expediente_id ? tt("Ya la tienes en tus viajes") : tt("Estamos confirmando tus servicios")}
          </p>
        ) : null}
        {isExpired && proposal.renewal_requested ? (
          <p className="cp-prop-card__note is-ok">
            <Icon name="success" size={16} />
            {tt("Ya nos has pedido actualizarla: te escribiremos con la propuesta nueva.")}
          </p>
        ) : isExpired ? (
          <p className="cp-prop-card__note">
            {tt("Los precios ya no están garantizados. Si te sigue interesando, la actualizamos con tus nuevas fechas.")}
          </p>
        ) : (
          <PriceBlock price={proposal.price} />
        )}
      </div>

      <div className="cp-prop-card__foot">
        {isExpired ? (
          <>
            <a className="cp-btn cp-btn--ghost cp-btn--sm" href={proposal.url} onClick={openProposal}>{tt("Ver propuesta")}</a>
            {!proposal.renewal_requested ? (
              <button type="button" className="cp-btn cp-btn--sm" onClick={() => onRenew?.(proposal)}>
                {tt("Actualizar propuesta")}
              </button>
            ) : null}
          </>
        ) : isAccepted ? (
          <>
            <a className="cp-btn cp-btn--ghost cp-btn--sm" href={proposal.url} onClick={openProposal}>{tt("Ver propuesta")}</a>
            {proposal.expediente_id ? (
              <button
                type="button"
                className="cp-btn cp-btn--sm"
                onClick={() => setParams({ view: "trip", expediente: String(proposal.expediente_id), tab: null })}
              >
                {tt("Ver viaje")}
                <Icon name="arrow-right" size={16} />
              </button>
            ) : null}
          </>
        ) : (
          <>
            {agent?.email ? (
              <a className="cp-btn cp-btn--ghost cp-btn--sm" href={mailto(agent.email, ttf("Propuesta: {title}", { title }))}>
                <Icon name="mail" size={16} />
                {agentLabel(agent)}
              </a>
            ) : <span />}
            <a className="cp-btn cp-btn--primary cp-btn--sm" href={proposal.url} onClick={openProposal}>
              {tt("Ver propuesta")}
              <Icon name="arrow-right" size={16} />
            </a>
          </>
        )}
      </div>
    </article>
  );
}

function groupHeading(group) {
  const proposals = group.proposals || [];
  const count = proposals.length;
  const options = count > 1 ? ttf("{count} opciones", { count }) : "";

  if (group.type === "request" && group.request) {
    const title = group.request.title || proposals[0]?.title || tt("Tu solicitud");
    const sent = group.request.created_on ? ttf("Tu solicitud del {date}", { date: formatShortDate(group.request.created_on) }) : "";
    return { title, meta: [sent, options].filter(Boolean).join(" · ") };
  }
  return { title: group.title || tt("Opciones para tu viaje"), meta: options };
}

function ProposalGroup({ group, onOpen, onRenew }) {
  const { title, meta } = groupHeading(group);
  const proposals = group.proposals || [];
  const numbered = proposals.length > 1;

  return (
    <div className="cp-prop-group">
      <div className="cp-prop-group__head">
        <h3 className="cp-prop-group__title">{title}</h3>
        {meta ? <span className="cp-prop-group__meta">{meta}</span> : null}
      </div>
      <div className="cp-prop-grid">
        {proposals.map((proposal, index) => (
          <ProposalCard key={proposal.id} proposal={proposal} optionNumber={numbered ? index + 1 : 0} onOpen={onOpen} onRenew={onRenew} />
        ))}
      </div>
    </div>
  );
}

const STEPS = [
  { key: "received", icon: "check", label: () => tt("Recibida") },
  { key: "preparing", icon: "clock", label: () => tt("Preparando tu propuesta") },
  { key: "ready", icon: "file", label: () => tt("Propuesta lista") },
];

function RequestRow({ request }) {
  const current = Math.max(0, STEPS.findIndex((step) => step.key === request.step));
  const dates = request.end_date ? formatDateRange(request.start_date, request.end_date) : formatMonthYear(request.start_date);
  const travellers = travellersLabel(request.players, request.non_players);
  const message = request.step === "ready"
    ? tt("Tu propuesta está lista: te la hemos enviado por email.")
    : tt("Te avisaremos por email en cuanto tu propuesta esté lista.");

  return (
    <li className="cp-req">
      <div>
        <p className="cp-req__title">{request.title || tt("Tu solicitud")}</p>
        <p className="cp-req__meta">
          {dates ? <span><Icon name="calendar" size={16} />{dates}</span> : null}
          {travellers ? <span><Icon name="user" size={16} />{travellers}</span> : null}
          {request.rooms ? <span><Icon name="bed" size={16} />{request.rooms}</span> : null}
          {request.created_on ? <span>{ttf("Enviada el {date}", { date: formatShortDate(request.created_on) })}</span> : null}
        </p>
        <p className="cp-req__msg">{message}</p>
      </div>
      <ol className="cp-steps" aria-label={tt("Estado de la solicitud")}>
        {STEPS.map((step, index) => {
          const status = index < current ? "is-done" : (index === current ? (step.key === "ready" ? "is-done" : "is-current") : "");
          return (
            <li key={step.key} className={status} aria-current={index === current ? "step" : undefined}>
              <span className="cp-steps__dot">
                <Icon name={status === "is-done" ? "check" : step.icon} size={14} />
              </span>
              {step.label()}
            </li>
          );
        })}
      </ol>
    </li>
  );
}

export default function ProposalsView({
  feed,
  loading = false,
  error = null,
  onOpenProposal,
  onRefresh,
  mock = false,
  profile = null,
  readOnly = false,
  readOnlyMessage = "",
}) {
  // Diálogo de solicitud: { proposalId } para actualizar una caducada, { proposalId: 0 } para una nueva.
  const [dialog, setDialog] = useState(null);
  const [sentNotice, setSentNotice] = useState("");
  // Se recuerda que acaba de confirmar el email para seguir mostrando el aviso de éxito tras recargar.
  const [justVerified, setJustVerified] = useState(false);

  const openRenew = (proposal) => setDialog({ proposalId: proposal.id });
  const openNew = () => setDialog({ proposalId: 0 });
  const handleSubmitted = ({ renewal, agent }) => {
    setSentNotice(renewal
      ? (agent?.name
        ? ttf("Recibido. {name} te preparará una propuesta actualizada y te la enviará por email.", { name: agent.name })
        : tt("Recibido. Te prepararemos una propuesta actualizada y te la enviaremos por email."))
      : tt("Hemos recibido tu solicitud. Te escribiremos en cuanto tengamos tu propuesta."));
    onRefresh?.();
  };
  const dialogNode = dialog ? (
    <RequestDialog
      proposalId={dialog.proposalId}
      mock={mock}
      profile={profile}
      readOnly={readOnly}
      readOnlyMessage={readOnlyMessage}
      onClose={() => setDialog(null)}
      onSubmitted={handleSubmitted}
    />
  ) : null;
  const verifyNode = feed?.email_verification?.needed || justVerified ? (
    <EmailVerifyNotice
      emailMasked={feed?.email_verification?.emailMasked || ""}
      mock={mock}
      onVerified={() => { setJustVerified(true); onRefresh?.(); }}
    />
  ) : null;
  const sentNode = sentNotice ? (
    <Notice variant="success" title={tt("Solicitud enviada")} onClose={() => setSentNotice("")} closeLabel={tt("Cerrar")}>
      {sentNotice}
    </Notice>
  ) : null;

  if (loading && !feed) {
    return (
      <div className="cp-content" aria-busy="true">
        <div className="cp-card"><Skeleton lines={6} /></div>
      </div>
    );
  }

  if (error && !feed) {
    return (
      <div className="cp-content">
        <Notice variant="warn" title={tt("No podemos cargar tus propuestas")}>
          {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
        </Notice>
      </div>
    );
  }

  const groups = Array.isArray(feed?.groups) ? feed.groups : [];
  const requests = Array.isArray(feed?.requests) ? feed.requests : [];
  const grouped = groups.filter((group) => group.type !== "single");
  const singles = groups.filter((group) => group.type === "single").flatMap((group) => group.proposals || []);

  const newRequestButton = (
    <button type="button" className="cp-btn cp-btn--ghost" onClick={openNew}>
      <Icon name="file" size={16} />
      {tt("Nueva solicitud")}
    </button>
  );

  if (!groups.length && !requests.length) {
    return (
      <div className="cp-content">
        {verifyNode}
        {sentNode}
        <EmptyState title={tt("Aún no tienes propuestas")} icon="file" action={(
          <button type="button" className="cp-btn cp-btn--primary" onClick={openNew}>{tt("Pide tu propuesta")}</button>
        )}>
          {tt("Cuando te preparemos una propuesta de viaje, la verás aquí con sus precios y podrás aceptarla desde la propia propuesta.")}
        </EmptyState>
        {dialogNode}
      </div>
    );
  }

  return (
    <div className="cp-content cp-prop">
      {verifyNode}
      {sentNode}
      {dialogNode}
      {groups.length ? (
        <section className="cp-prop-section" aria-labelledby="cp-prop-title">
          <div className="cp-prop-section__head">
            <div>
              <h2 className="cp-prop-section__title" id="cp-prop-title">{tt("Tus propuestas")}</h2>
              <p className="cp-prop-section__lead">
                {tt("Revísalas con calma: puedes aceptarlas desde la propuesta o escribir a tu agente si quieres cambiar algo.")}
              </p>
            </div>
          </div>

          {grouped.map((group) => <ProposalGroup key={group.key} group={group} onOpen={onOpenProposal} onRenew={openRenew} />)}

          {singles.length ? (
            <div className="cp-prop-group">
              {grouped.length ? (
                <div className="cp-prop-group__head">
                  <h3 className="cp-prop-group__title">{tt("Otras propuestas")}</h3>
                </div>
              ) : null}
              <div className="cp-prop-grid">
                {singles.map((proposal) => <ProposalCard key={proposal.id} proposal={proposal} onOpen={onOpenProposal} onRenew={openRenew} />)}
              </div>
            </div>
          ) : null}
        </section>
      ) : null}

      <section className="cp-prop-section" aria-labelledby="cp-req-title">
        <div className="cp-prop-section__head">
          <div>
            <h2 className="cp-prop-section__title" id="cp-req-title">{tt("Solicitudes en curso")}</h2>
            <p className="cp-prop-section__lead">{tt("Lo que nos has pedido y aún estamos preparando.")}</p>
          </div>
          {newRequestButton}
        </div>
        {requests.length ? (
          <ul className="cp-req-list">
            {requests.map((request) => <RequestRow key={request.id} request={request} />)}
          </ul>
        ) : (
          <p className="cp-prop-section__empty">{tt("No tienes solicitudes pendientes.")}</p>
        )}
      </section>
    </div>
  );
}
