import React from "react";

export default function KpiCard({ icon, label, value, colorClass = "" }) {
  return (
    <div className="cp-kpi-card">
      <span className={`cp-kpi-card__icon ${colorClass}`} aria-hidden="true">
        {icon}
      </span>
      <div>
        <div className="cp-kpi-card__label">{label}</div>
        <div className="cp-kpi-card__value">{value}</div>
      </div>
    </div>
  );
}
