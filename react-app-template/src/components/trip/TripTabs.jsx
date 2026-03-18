import React from "react";

import { tt } from "../../i18n/t.js";

export default function Tabs({ tab, onTab }) {
  const items = [
    { k: "summary", label: tt("Resumen") },
    { k: "payments", label: tt("Pagos") },
    { k: "invoices", label: tt("Facturas") },
    { k: "vouchers", label: tt("Bonos") },
    { k: "messages", label: tt("Mensajes") },
  ];

  return (
    <div className="cp-trip-tabs" role="tablist" aria-label={tt("Secciones del viaje")}>
      {items.map((it) => (
        <button
          key={it.k}
          type="button"
          className={`cp-btn cp-trip-tabs__btn ${tab === it.k ? "primary" : ""}`}
          onClick={() => onTab(it.k)}
        >
          {it.label}
        </button>
      ))}
    </div>
  );
}
