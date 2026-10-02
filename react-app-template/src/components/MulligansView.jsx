import React, { useState } from "react";

import Icon from "./Icon.jsx";
import { EmptyState, Notice } from "./ui.jsx";
import { formatCurrency, formatDate, formatNumber, tt } from "../i18n/t.js";

const TIER_ORDER = ["birdie", "eagle", "eagle_plus", "albatross"];

function tierLabel(slug) {
  const map = { birdie: "Birdie", eagle: "Eagle", eagle_plus: "Eagle+", albatross: "Albatross" };
  return map[slug] || (slug ? slug.charAt(0).toUpperCase() + slug.slice(1) : "Birdie");
}

function normalizeTier(value) {
  return String(value || "birdie").toLowerCase().replace(/\s+/g, "_").replace(/\+/g, "_plus").replace(/-/g, "_");
}

export default function MulligansView({ data }) {
  const [historyFilter, setHistoryFilter] = useState("all");
  const m = data?.mulligans || {};
  const points = Number(m.points || 0);
  const tier = normalizeTier(m.tier);
  const spend = Number(m.spend || 0);
  const earned = Number(m.earned || 0);
  const used = Number(m.used || 0);
  const ledger = Array.isArray(m.ledger) ? m.ledger : [];
  const redeemedCount = ledger.filter((item) => String(item?.type || "") === "redeem").length;

  const fmtMoney = (v) => formatCurrency(v || 0, "EUR", { maximumFractionDigits: 0 });
  const fmtPts = (v) => formatNumber(v);
  const fmtDate = (ts) => {
    const n = Number(ts || 0);
    if (!n) return "—";
    return formatDate(new Date(n * 1000), { day: "2-digit", month: "2-digit", year: "numeric" });
  };

  // Niveles por gasto acumulado (euros).
  const TIERS = [
    { slug: "birdie", mult: "x1.00", range: tt("Hasta 4.999 €"), min: 0 },
    { slug: "eagle", mult: "x1.20", range: "5.000 € — 14.999 €", min: 5000 },
    { slug: "eagle_plus", mult: "x1.35", range: "15.000 € — 29.999 €", min: 15000 },
    { slug: "albatross", mult: "x1.50", range: tt("Más de 30.000 €"), min: 30000 },
  ];
  const currentIdx = Math.max(0, TIERS.findIndex((t) => t.slug === tier));
  const currentTier = TIERS[currentIdx];
  const nextTier = TIERS[currentIdx + 1] || null;
  const progressPct = nextTier
    ? Math.min(100, Math.max(0, ((spend - currentTier.min) / (nextTier.min - currentTier.min)) * 100))
    : 100;

  const BENEFITS = [
    { name: tt("Acceso al portal privado"), desc: tt("Consulta tus viajes, pagos y puntos Mulligans desde tu portal personal."), tier: "birdie", icon: "lock" },
    { name: tt("Historial de viajes"), desc: tt("Registro completo de todos tus viajes organizados por Casanova Golf."), tier: "birdie", icon: "list" },
    { name: tt("Welcome Pack Digital"), desc: tt("Pack de bienvenida digital al unirte al programa Mulligans."), tier: "birdie", icon: "download" },
    { name: tt("Bonus de bienvenida"), desc: tt("Mulligans iniciales al crear tu cuenta en el programa."), tier: "birdie", icon: "star" },
    { name: tt("Detalle Mulligans anual"), desc: tt("Cortesía anual: elige tu regalo Mulligans con cada reserva."), tier: "eagle", icon: "gift" },
    { name: tt("Revisión activa de reservas"), desc: tt("Seguimiento proactivo de tu reserva para optimizar tu experiencia."), tier: "eagle", icon: "success" },
    { name: tt("Acceso a canjes básicos"), desc: tt("Canjea tu saldo Mulligans por mejoras preferentes y experiencias sencillas."), tier: "eagle", icon: "dollar" },
    { name: tt("Atención preferente"), desc: tt("Línea directa de atención prioritaria para tu viaje."), tier: "eagle_plus", icon: "phone" },
    { name: tt("Mulligan Operativo"), desc: tt("Perdón de 1 penalización al año en cambios o ajustes de reserva."), tier: "eagle_plus", icon: "shield" },
    { name: tt("Acceso anticipado a ofertas"), desc: tt("Conoce antes que nadie las ofertas y destinos exclusivos."), tier: "eagle_plus", icon: "bolt" },
    { name: tt("Experiencia Mulligans especial"), desc: tt("Canjes de experiencias gastronómicas, culturales y mejoras avanzadas."), tier: "eagle_plus", icon: "sparkles" },
    { name: tt("Máxima prioridad"), desc: tt("Nivel de atención y seguimiento más alto del programa."), tier: "albatross", icon: "crown" },
    { name: tt("Traslado privado aeropuerto"), desc: tt("Transfer privado de salida incluido una vez al año."), tier: "albatross", icon: "car" },
    { name: tt("Experiencia Mulligans incluida"), desc: tt("Una experiencia al año definida por Casanova Golf, sin coste de saldo."), tier: "albatross", icon: "star" },
    { name: tt("Canje experiencia premium"), desc: tt("Acceso a canjes premium: cenas privadas, logística, noches adicionales."), tier: "albatross", icon: "gift" },
  ];
  const userTierOrder = TIER_ORDER.indexOf(tier);
  const benefits = BENEFITS.map((b) => ({ ...b, locked: TIER_ORDER.indexOf(b.tier) > userTierOrder }));

  const FILTERS = [
    { key: "all", label: tt("Todos") },
    { key: "earned", label: tt("Ganados") },
    { key: "bonus", label: tt("Bonus") },
    { key: "redeemed", label: tt("Canjeados") },
  ];
  const filteredLedger = historyFilter === "all"
    ? ledger
    : ledger.filter((it) => {
        const type = String(it.type || "");
        if (historyFilter === "earned") return type === "earn";
        if (historyFilter === "bonus") return type === "bonus";
        if (historyFilter === "redeemed") return type === "redeem";
        return true;
      });

  const typeInfo = (type) => {
    if (type === "bonus") return { label: tt("Bonus"), variant: "warning" };
    if (type === "redeem") return { label: tt("Canje"), variant: "muted" };
    if (type === "earn") return { label: tt("Ganado"), variant: "success" };
    return { label: tt("Movimiento"), variant: "muted" };
  };

  return (
    <div className="cp-content cp-mul">
      <section className="cp-mul-hero">
        <div className="cp-mul-hero__main">
          <span className={`cp-pill is-${tier}`}>{tt("Nivel actual")}: {tierLabel(tier)}</span>
          <p className="cp-mul-hero__points">
            <strong>{fmtPts(points)}</strong>
            <span>{tt("puntos Mulligans acumulados")}</span>
          </p>
          <div className="cp-mul-hero__progress">
            <div
              className="cp-meter"
              role="progressbar"
              aria-label={tt("Progreso de Mulligans")}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.round(progressPct)}
            >
              <span style={{ width: `${progressPct}%` }} />
            </div>
            <div className="cp-mul-hero__scale">
              <span>{tierLabel(currentTier.slug)} · <strong>{fmtMoney(currentTier.min)}</strong></span>
              <span>
                {nextTier
                  ? <>{tierLabel(nextTier.slug)} · <strong>{fmtMoney(nextTier.min)}</strong></>
                  : <strong>{tt("Nivel máximo")}</strong>}
              </span>
            </div>
          </div>
        </div>

        <ol className="cp-mul-ladder" aria-label={tt("Nivel")}>
          {TIERS.map((t, i) => {
            const isCurrent = i === currentIdx;
            const isPast = i < currentIdx;
            return (
              <li key={t.slug} className={`cp-mul-ladder__step ${isCurrent ? "is-current" : ""} ${isPast ? "is-past" : ""}`.trim()} aria-current={isCurrent ? "step" : undefined}>
                <span className="cp-mul-ladder__icon" aria-hidden="true">
                  <Icon name={isPast ? "check" : "star"} size={16} />
                </span>
                <span className="cp-mul-ladder__copy">
                  <span className="cp-mul-ladder__name">{tierLabel(t.slug)} <span>{t.mult}</span></span>
                  <span className="cp-mul-ladder__range">{t.range}</span>
                </span>
                {isCurrent ? <span className="cp-badge cp-badge--success">{tt("Actual")}</span> : null}
              </li>
            );
          })}
        </ol>
      </section>

      <dl className="cp-trips-stats cp-mul-stats">
        <div className="cp-trips-stat">
          <dt>{tt("Balance")}</dt>
          <dd>{fmtPts(points)}</dd>
        </div>
        <div className="cp-trips-stat">
          <dt>{tt("Gasto histórico")}</dt>
          <dd>{fmtMoney(spend)}</dd>
        </div>
        <div className="cp-trips-stat">
          <dt>{tt("Multiplicador")}</dt>
          <dd>{currentTier.mult}</dd>
        </div>
        <div className="cp-trips-stat">
          <dt>{tt("Ganados")}</dt>
          <dd>+{fmtPts(earned)}</dd>
          <span>{ledger.length} {tt("movimientos")}</span>
        </div>
        <div className="cp-trips-stat">
          <dt>{tt("Usados")}</dt>
          <dd>{fmtPts(used)}</dd>
          <span>{fmtPts(redeemedCount)} {tt("canjes")}</span>
        </div>
      </dl>

      <Notice variant="info" title={tt("Cómo funciona")}>
        {tt("Tu nivel se determina por tu gasto histórico acumulado y es vitalicio. Las cortesías anuales se activan al confirmar una reserva. Tu saldo Mulligans no caduca y se puede canjear en futuros viajes según tu nivel.")}
      </Notice>

      <section className="cp-mul-block">
        <header className="cp-pay-block__head">
          <h2 className="cp-pay-block__title">{tt("Beneficios de tu nivel")}</h2>
          <span className={`cp-pill is-${tier}`}>{tierLabel(tier)}</span>
        </header>
        <ul className="cp-mul-benefits">
          {benefits.map((ben) => (
            <li key={ben.name} className={`cp-mul-benefit ${ben.locked ? "is-locked" : ""}`.trim()}>
              <span className="cp-mul-benefit__icon" aria-hidden="true">
                <Icon name={ben.locked ? "lock" : ben.icon} size={18} />
              </span>
              <span className="cp-mul-benefit__copy">
                <span className="cp-mul-benefit__name">{ben.name}</span>
                <span className="cp-mul-benefit__desc">{ben.desc}</span>
                {ben.locked ? <span className="cp-badge cp-badge--muted">{tierLabel(ben.tier)}</span> : null}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section className="cp-mul-block">
        <header className="cp-pay-block__head">
          <h2 className="cp-pay-block__title">{tt("Histórico de movimientos")}</h2>
          <div className="cp-segmented cp-mul-filters" role="radiogroup" aria-label={tt("Histórico de movimientos")}>
            {FILTERS.map((f) => (
              <button
                key={f.key}
                type="button"
                role="radio"
                aria-checked={historyFilter === f.key ? "true" : "false"}
                className={historyFilter === f.key ? "is-active" : ""}
                onClick={() => setHistoryFilter(f.key)}
              >
                {f.label}
              </button>
            ))}
          </div>
        </header>

        {filteredLedger.length === 0 ? (
          <EmptyState title={tt("Aún no hay movimientos")} icon="receipt">
            {tt("Cuando se registren pagos o se aplique un bonus, aparecerán aquí.")}
          </EmptyState>
        ) : (
          <div className="cp-table-wrap">
            <table className="cp-table">
              <thead>
                <tr>
                  <th>{tt("Tipo")}</th>
                  <th>{tt("Descripción")}</th>
                  <th>{tt("Fecha")}</th>
                  <th className="num">{tt("Puntos")}</th>
                </tr>
              </thead>
              <tbody>
                {filteredLedger.map((it, i) => {
                  const pts = Number(it.points || 0);
                  const info = typeInfo(String(it.type || ""));
                  return (
                    <tr key={it.id || `${it.ts}-${i}`}>
                      <td><span className={`cp-badge cp-badge--${info.variant}`}>{info.label}</span></td>
                      <td>{it.note || it.source || "—"}</td>
                      <td className="cp-nowrap">{fmtDate(it.ts)}</td>
                      <td className={`num cp-mul-pts ${pts < 0 ? "is-negative" : "is-positive"}`}>
                        {pts >= 0 ? "+" : "−"}{fmtPts(Math.abs(pts))}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
