import React from "react";

export default function KpiCard({ icon, label, value, sub, colorClass = "", cardClass = "" }) {
  return (
    <div className={`cp-kpi-card ${cardClass}`.trim()}>
      <span className={`cp-kpi-card__icon ${colorClass}`} aria-hidden="true">
        {icon}
      </span>
      <div>
        <div className="cp-kpi-card__label">{label}</div>
        <div className="cp-kpi-card__value">{value}</div>
        {sub ? <div className="cp-kpi-card__sub">{sub}</div> : null}
      </div>
    </div>
  );
}
