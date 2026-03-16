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
    <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 14 }}>
      {items.map((it) => (
        <button
          key={it.k}
          className={`cp-btn ${tab === it.k ? "primary" : ""}`}
          onClick={() => onTab(it.k)}
        >
          {it.label}
        </button>
      ))}
    </div>
  );
}
